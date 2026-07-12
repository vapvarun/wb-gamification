<?php
/**
 * Status Retention Engine
 *
 * Sends end-of-period nudges to members who are at risk of falling below
 * their current level threshold before the period resets.
 *
 * Airline model: "Earn 500 more points this month to keep your Champion status."
 *
 * Checks weekly (Thursday evening UTC) so members have ~3 days to act.
 * Only sends if the user is within a configurable gap_pct of their current
 * level threshold and has not been nudged in the last 7 days.
 *
 * Currently targets the "weekly" leaderboard period. Future: monthly.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Sends weekly nudges to members at risk of falling below their current level threshold.
 *
 * @package WB_Gamification
 */
final class StatusRetentionEngine {

	private const CRON_HOOK  = 'wb_gam_status_retention_check';
	private const NUDGE_META = 'wb_gam_last_retention_nudge';

	/**
	 * Action Scheduler hook carrying the keyset cursor from page to page.
	 *
	 * @since 1.6.4
	 * @var string
	 */
	private const AS_PAGE_HOOK = 'wb_gam_retention_page';

	/**
	 * Members processed per tick. Bounds the meta prime, the IN() list, and the
	 * per-member loop — all three of which were previously sized by the member base.
	 *
	 * @since 1.6.4
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Register the WP-Cron hook for the weekly retention check.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		// Paged continuation — each page schedules the next until the keyset drains.
		add_action( self::AS_PAGE_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the weekly retention cron event on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Next Wednesday at 18:00 UTC (spread away from Monday cron cluster).
			$next = strtotime( 'next wednesday 18:00:00 UTC' );
			wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the weekly retention cron event on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ── Run ──────────────────────────────────────────────────────────────────

	/**
	 * Run the weekly status retention check for all recently active users.
	 */
	public static function run( int $cursor = 0 ): void {
		if ( ! FeatureFlags::is_enabled( 'status_retention' ) ) {
			return;
		}

		global $wpdb;

		// Get all levels sorted ascending by min_points.
		$levels = $wpdb->get_results(
			"SELECT id, name, min_points FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		);

		if ( count( $levels ) < 2 ) {
			return; // Need at least two levels for threshold logic.
		}

		$week_start    = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
		$four_wk_start = gmdate( 'Y-m-d H:i:s', strtotime( '-4 weeks' ) );
		$cutoff        = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		// ONE PAGE of active members, walked by keyset cursor.
		//
		// Every member's decision here is independent, so this paginates cleanly.
		// Before 1.6.4 it did not, and each of these alone was fatal at 100k:
		//
		// 1. `SELECT DISTINCT user_id ... WHERE created_at >= week_start` with no
		// LIMIT — every active member into one PHP array.
		// 2. `update_meta_cache( 'user', $active_users )` on that whole array —
		// SELECT * FROM wp_usermeta WHERE user_id IN (...tens of thousands...),
		// loading EVERY meta row for EVERY active member (~25 each) into memory.
		// 3. A `WHERE user_id IN ($placeholders)` aggregate built from one %d per
		// member — a multi-hundred-KB prepared statement that breaks on
		// max_allowed_packet rather than merely running slowly.
		// 4. Then, per member: PointsEngine::get_total() (a query each, despite
		// prime_totals() existing for exactly this) and
		// LevelEngine::get_level_for_user(), which does up to TWO
		// update_user_meta WRITES on a read path — turning a read loop over the
		// member base into a write loop.
		//
		// Now: PAGE_SIZE members per tick, meta primed for the page only, one
		// aggregate bound to the page, totals primed in two queries, and the PURE
		// level helpers (get_level_for_points / get_next_level_for_points) which take
		// the total we already have and perform no writes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active_users = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE created_at >= %s AND user_id > %d
				  ORDER BY user_id ASC
				  LIMIT %d",
				$week_start,
				$cursor,
				self::PAGE_SIZE
			)
		);

		if ( empty( $active_users ) ) {
			return;
		}

		$ids_ints = array_map( 'intval', $active_users );
		$last     = (int) max( $ids_ints );

		// Prime meta + totals for THIS PAGE only (bounded by PAGE_SIZE).
		update_meta_cache( 'user', $ids_ints );
		PointsEngine::prime_totals( $ids_ints );

		// The IN() list is now bounded by PAGE_SIZE, not by the member base.
		$placeholders = implode( ',', array_fill( 0, count( $ids_ints ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- implode of %d placeholders only, bounded by PAGE_SIZE.
		$avg_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COALESCE(SUM(points), 0) / 4 AS avg_pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id IN ($placeholders) AND created_at >= %s
				 GROUP BY user_id",
				array_merge( $ids_ints, array( $four_wk_start ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg_map = array_fill_keys( $ids_ints, 0 );
		foreach ( $avg_rows as $row ) {
			$avg_map[ (int) $row['user_id'] ] = (int) $row['avg_pts'];
		}

		foreach ( $ids_ints as $user_id ) {
			// Skip if nudged recently (meta primed for the page above).
			$last_nudge = get_user_meta( $user_id, self::NUDGE_META, true );
			if ( $last_nudge && strtotime( $last_nudge ) >= strtotime( $cutoff ) ) {
				continue;
			}

			// Primed by prime_totals() — a cache hit, not a query each.
			$total = PointsEngine::get_total( $user_id );

			// PURE resolvers: they take the total we already have and, unlike
			// get_level_for_user(), do not write user_meta on a read path.
			$level = LevelEngine::get_level_for_points( $total );
			$next  = LevelEngine::get_next_level_for_points( $total );

			if ( ! $level || ! $next ) {
				continue; // Already at max, or no levels defined.
			}

			// Check if next-level threshold is within reach this week.
			$pts_needed = $next['min_points'] - $total;
			if ( $pts_needed <= 0 ) {
				continue; // Already at next level.
			}

			// Only nudge if they're close (within one weekly velocity of the threshold).
			$avg_weekly = $avg_map[ $user_id ] ?? 0;
			if ( $pts_needed > max( $avg_weekly, 100 ) * 1.5 ) {
				continue;
			}

			self::send_nudge( $user_id, $level, $next, $pts_needed );
		}

		// Short page: the keyset is exhausted, the run is done.
		if ( count( $ids_ints ) < self::PAGE_SIZE ) {
			return;
		}

		// More members to go — hand the next page to Action Scheduler rather than
		// looping here, so no single tick carries the whole site.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 60,
				self::AS_PAGE_HOOK,
				array( 'cursor' => $last ),
				'wb_gam_retention'
			);
			return;
		}

		// No Action Scheduler — continue inline, still bounded per iteration.
		self::run( $last );
	}

	// ── Nudge dispatch ───────────────────────────────────────────────────────

	/**
	 * Dispatch a retention nudge notification for a user approaching their level threshold.
	 *
	 * @param int   $user_id    User to nudge.
	 * @param array $level      Current level data.
	 * @param array $next       Next level data.
	 * @param int   $pts_needed Points gap to the next level.
	 */
	private static function send_nudge( int $user_id, array $level, array $next, int $pts_needed ): void {
		$message = sprintf(
			/* translators: 1: points needed, 2: next level name */
			__( 'Earn %1$s more points this week to reach %2$s!', 'wb-gamification' ),
			number_format_i18n( $pts_needed ),
			$next['name']
		);

		// BP notification (non-blocking).
		if ( function_exists( 'bp_notifications_add_notification' ) ) {
			bp_notifications_add_notification(
				array(
					'user_id'          => $user_id,
					'item_id'          => $user_id,
					'component_name'   => 'wb_gamification',
					'component_action' => 'retention_nudge',
					'date_notified'    => bp_core_current_time(),
					'is_new'           => 1,
				)
			);
		}

		update_user_meta( $user_id, self::NUDGE_META, current_time( 'mysql' ) );

		/**
		 * Fires when a status-retention nudge is dispatched.
		 *
		 * @param int    $user_id   User being nudged.
		 * @param array  $level     Current level data.
		 * @param array  $next      Next level data.
		 * @param int    $pts_needed Points gap to next level.
		 * @param string $message   Human-readable nudge message.
		 */
		do_action( 'wb_gam_retention_nudge', $user_id, $level, $next, $pts_needed, $message );
	}
}
