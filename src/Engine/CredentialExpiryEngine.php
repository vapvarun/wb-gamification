<?php
/**
 * Credential Expiry Engine — HubSpot-style renewal re-engagement
 *
 * When a badge_def has `validity_days > 0`, credentials earned from that
 * badge expire N days after award. CredentialController returns HTTP 410 Gone
 * for expired credentials, prompting holders to renew.
 *
 * This engine runs a weekly cron to notify members whose credentials expired
 * since the last run, creating the re-engagement cycle:
 *   Earn badge → credential verifiable → credential expires (410 Gone) →
 *   member receives nudge → completes renewal action → re-earns badge.
 *
 * The renewal action is not hardcoded here — the `wb_gam_credential_expired`
 * hook lets the site admin trigger whatever re-qualification flow makes sense.
 *
 * @package WB_Gamification
 * @since   0.3.0
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
 * Runs a weekly cron to notify members whose credentials have expired.
 *
 * @package WB_Gamification
 */
final class CredentialExpiryEngine {

	private const CRON_HOOK = 'wb_gam_credential_expiry_check';
	private const OPT_LAST  = 'wb_gam_credential_expiry_last_run';

	/**
	 * Action Scheduler hook carrying the keyset cursor + fixed window bounds
	 * from page to page.
	 *
	 * @since 1.6.4
	 * @var string
	 */
	private const AS_PAGE_HOOK = 'wb_gam_credential_expiry_page';

	/**
	 * Credentials processed per tick. Bounds the notification loop and the
	 * BuddyPress-notification write it performs per row — previously sized
	 * by however many credentials expired in the window, which is unbounded
	 * on any site with a large credentialed population. Same shape as
	 * StatusRetentionEngine::PAGE_SIZE.
	 *
	 * @since 1.6.4
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Register the credential expiry cron action callbacks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		// Paged continuation — each page schedules the next until the window drains.
		add_action( self::AS_PAGE_HOOK, array( __CLASS__, 'run_page' ), 10, 3 );
	}

	/**
	 * Schedule the weekly credential expiry check on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Every Friday at 06:00 UTC (spread away from Monday cron cluster).
			$next = strtotime( 'next friday 06:00:00 UTC' );
			wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the credential expiry cron on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ── Cron handler ─────────────────────────────────────────────────────────

	/**
	 * Weekly cron entry point. Resolves the (last_run, now] window once and
	 * walks it in bounded pages via Action Scheduler — same shape as
	 * StatusRetentionEngine::run(). The window bounds are fixed for the
	 * whole run and threaded through every page so a mid-window continuation
	 * never drifts onto a different slice of time than the one it started.
	 */
	public static function run(): void {
		// Default: look back 8 days so the first-ever run is not a no-op.
		$last_run = (string) get_option( self::OPT_LAST, gmdate( 'Y-m-d H:i:s', strtotime( '-8 days' ) ) );
		$now      = gmdate( 'Y-m-d H:i:s' );

		self::run_page( 0, $last_run, $now );
	}

	/**
	 * Process one PAGE_SIZE page of the (window_start, window_end] range,
	 * keyset-paged by `id`, and hand the next page to Action Scheduler when
	 * the page comes back full.
	 *
	 * Before 1.6.4 run() issued a single unbounded SELECT over every
	 * credential that expired in the window and then looped every row —
	 * including a BuddyPress notification insert per row — in one cron
	 * tick. Fine at the handful of expiries a small community sees in a
	 * week; unbounded on a site with a large credentialed population, the
	 * same class of bug every other engine in this file already paid down
	 * (see StatusRetentionEngine::run()).
	 *
	 * OPT_LAST is only advanced once the window is fully drained (the short
	 * final page) — a mid-window continuation or an overlapping run must
	 * never move the watermark past credentials this run hasn't reached
	 * yet, or the next weekly tick would silently skip them.
	 *
	 * @param int    $cursor       Last wb_gam_user_badges.id processed. 0 to start.
	 * @param string $window_start Fixed window start for this run (exclusive, MySQL datetime).
	 * @param string $window_end   Fixed window end for this run (inclusive, MySQL datetime).
	 */
	public static function run_page( int $cursor = 0, string $window_start = '', string $window_end = '' ): void {
		if ( '' === $window_start || '' === $window_end ) {
			return; // Malformed continuation args — nothing safe to process.
		}

		global $wpdb;

		// Credentials that expired in the fixed window, keyset-paged past
		// the cursor. `id` is the table's AUTO_INCREMENT PK, so ordering by
		// it is a stable, gapless cursor regardless of how many badges any
		// one member holds.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, badge_id, expires_at
				   FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE expires_at > %s AND expires_at <= %s AND id > %d
				  ORDER BY id ASC
				  LIMIT %d",
				$window_start,
				$window_end,
				$cursor,
				self::PAGE_SIZE
			),
			ARRAY_A
		);

		$last = $cursor;

		foreach ( (array) $rows as $row ) {
			$user_id  = (int) $row['user_id'];
			$badge_id = (string) $row['badge_id'];
			$last     = (int) $row['id'];

			// Add a BP notification for the expired credential.
			if ( function_exists( 'bp_notifications_add_notification' ) ) {
				bp_notifications_add_notification(
					array(
						'user_id'          => $user_id,
						'item_id'          => $user_id,
						'component_name'   => 'wb_gamification',
						'component_action' => 'credential_expired',
						'date_notified'    => bp_core_current_time(),
						'is_new'           => 1,
					)
				);
			}

			/**
			 * Fires when a credential badge expires.
			 *
			 * Hook this to trigger renewal flows: send email, remove role,
			 * redirect member to re-qualification challenge, etc.
			 *
			 * @param int    $user_id    User whose credential expired.
			 * @param string $badge_id   Badge identifier.
			 * @param string $expired_at MySQL datetime of expiry.
			 */
			do_action( 'wb_gam_credential_expired', $user_id, $badge_id, $row['expires_at'] );
		}

		// Short page: the window is drained. Advance the watermark now — not
		// before the sweep started — so a run that dies partway through
		// resumes from the true last-processed point on its next tick
		// instead of silently skipping the remainder of the window.
		if ( count( (array) $rows ) < self::PAGE_SIZE ) {
			update_option( self::OPT_LAST, $window_end );
			return;
		}

		// More rows in this window — hand the next page to Action Scheduler
		// rather than looping here, so no single tick carries a large
		// backlog of expiries (e.g. a badge with validity_days set on a
		// large cohort that all expire in the same week).
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$page_args = array( $last, $window_start, $window_end );

			// Guarded: without this, a cron overlap (or a retry of this same
			// tick) would queue the same page twice and double-notify every
			// member on it.
			$queued = function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::AS_PAGE_HOOK, $page_args, 'wb_gam_credential_expiry' );

			if ( ! $queued ) {
				as_schedule_single_action( time() + 60, self::AS_PAGE_HOOK, $page_args, 'wb_gam_credential_expiry' );
			}
			return;
		}

		// No Action Scheduler — continue inline, still bounded per iteration.
		self::run_page( $last, $window_start, $window_end );
	}

	// ── Public helpers ────────────────────────────────────────────────────────

	/**
	 * Check whether a specific user+badge credential has expired.
	 *
	 * Returns false when expires_at is NULL (credential never expires).
	 *
	 * @param int    $user_id  User to check.
	 * @param string $badge_id Badge identifier.
	 * @return bool
	 */
	public static function is_expired( int $user_id, string $badge_id ): bool {
		global $wpdb;

		$expires_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT expires_at FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d AND badge_id = %s",
				$user_id,
				$badge_id
			)
		);

		if ( ! $expires_at ) {
			return false;
		}

		return strtotime( $expires_at ) <= time();
	}
}
