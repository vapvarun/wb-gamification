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

final class StatusRetentionEngine {

	private const CRON_HOOK  = 'wb_gam_status_retention_check';
	private const NUDGE_META = 'wb_gam_last_retention_nudge';

	/** Send nudge if user has earned < this fraction of level threshold this week. */
	private const GAP_THRESHOLD = 0.85;

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Next Thursday at 18:00 UTC.
			$next = strtotime( 'next thursday 18:00:00 UTC' );
			wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ── Run ──────────────────────────────────────────────────────────────────

	public static function run(): void {
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

		// Get all users who earned at least 1 point this week.
		$active_users = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->prefix}wb_gam_points WHERE created_at >= %s",
				$week_start
			)
		);

		if ( empty( $active_users ) ) {
			return;
		}

		// Prime the object cache for all nudge-meta and user-meta at once.
		update_meta_cache( 'user', $active_users );

		// Batch-fetch 4-week point sums for all active users (one query replaces N).
		$ids_ints     = array_map( 'intval', $active_users );
		$placeholders = implode( ',', array_fill( 0, count( $ids_ints ), '%d' ) );
		$avg_rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COALESCE(SUM(points), 0) / 4 AS avg_pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id IN ($placeholders) AND created_at >= %s
				 GROUP BY user_id",
				array_merge( $ids_ints, [ $four_wk_start ] )
			),
			ARRAY_A
		);
		$avg_map = array_fill_keys( $ids_ints, 0 );
		foreach ( $avg_rows as $row ) {
			$avg_map[ (int) $row['user_id'] ] = (int) $row['avg_pts'];
		}

		foreach ( $active_users as $user_id ) {
			$user_id = (int) $user_id;

			// Skip if nudged recently (meta loaded from primed cache above).
			$last_nudge = get_user_meta( $user_id, self::NUDGE_META, true );
			if ( $last_nudge && strtotime( $last_nudge ) >= strtotime( $cutoff ) ) {
				continue;
			}

			// Determine current level from all-time total.
			$total = PointsEngine::get_total( $user_id );
			$level = LevelEngine::get_level_for_user( $user_id );
			$next  = LevelEngine::get_next_level( $user_id );

			if ( ! $level || ! $next ) {
				continue; // Already at max or no levels defined.
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
	}

	// ── Nudge dispatch ───────────────────────────────────────────────────────

	private static function send_nudge( int $user_id, array $level, array $next, int $pts_needed ): void {
		$message = sprintf(
			/* translators: 1: points needed, 2: next level name */
			__( 'Earn %1$s more points this week to reach %2$s!', 'wb-gamification' ),
			number_format_i18n( $pts_needed ),
			$next['name']
		);

		// BP notification (non-blocking).
		if ( function_exists( 'bp_notifications_add_notification' ) ) {
			bp_notifications_add_notification( [
				'user_id'           => $user_id,
				'item_id'           => $user_id,
				'component_name'    => 'wb_gamification',
				'component_action'  => 'retention_nudge',
				'date_notified'     => bp_core_current_time(),
				'is_new'            => 1,
			] );
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
		do_action( 'wb_gamification_retention_nudge', $user_id, $level, $next, $pts_needed, $message );
	}
}
