<?php
/**
 * WB Gamification - inactivity-based point decay.
 *
 * OPT-IN. When an admin enables it (Settings > Points > Point expiry), a daily
 * cron decays the primary-currency balance of members who have not earned any
 * points for the configured number of days. The decay is applied ONCE per
 * inactivity streak (tracked via the wb_gam_decayed_at user meta) so a member
 * is not drained every day - they lose the configured percentage once, then
 * must re-earn; earning again re-arms the timer.
 *
 * Off by default and never touches anyone until an admin turns it on and sets
 * a threshold, so existing sites see no behaviour change on upgrade.
 *
 * @package WB_Gamification
 * @since   1.5.3
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Custom-table maintenance query for the decay sweep.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Daily inactivity decay of member point balances.
 *
 * @package WB_Gamification
 */
final class PointsExpiry {

	public const CRON_HOOK   = 'wb_gam_points_decay';
	private const CRON_RECUR = 'daily';

	private const OPT_ENABLED = 'wb_gam_points_decay_enabled';
	private const OPT_DAYS    = 'wb_gam_points_decay_days';
	private const OPT_PERCENT = 'wb_gam_points_decay_percent';
	private const META_LAST   = 'wb_gam_decayed_at';
	private const BATCH       = 500;

	/**
	 * Register the cron handler + ensure the daily event is scheduled.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule on activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule on deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Whether decay is enabled.
	 */
	public static function enabled(): bool {
		return (bool) (int) get_option( self::OPT_ENABLED, 0 );
	}

	/**
	 * Decay the configured percentage of the balance for members inactive
	 * beyond the threshold. No-op when disabled. Returns the number of members
	 * decayed (for CLI / tests).
	 *
	 * @return int
	 */
	public static function run(): int {
		if ( ! self::enabled() ) {
			return 0;
		}

		$days    = max( 1, (int) get_option( self::OPT_DAYS, 90 ) );
		$percent = min( 100, max( 1, (int) get_option( self::OPT_PERCENT, 100 ) ) );

		global $wpdb;
		$type   = PointsEngine::resolve_type( null );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Members with a positive primary-currency balance whose most recent
		// points activity is older than the cutoff.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.user_id AS user_id, t.total AS total, MAX(p.created_at) AS last_activity
				   FROM {$wpdb->prefix}wb_gam_user_totals t
				   JOIN {$wpdb->prefix}wb_gam_points p
				     ON p.user_id = t.user_id AND p.point_type = t.point_type
				  WHERE t.point_type = %s AND t.total > 0
				  GROUP BY t.user_id, t.total
				 HAVING last_activity < %s
				  LIMIT %d",
				$type,
				$cutoff,
				self::BATCH
			)
		);

		$decayed = 0;
		foreach ( (array) $rows as $row ) {
			$user_id       = (int) $row->user_id;
			$total         = (int) $row->total;
			$last_activity = (string) $row->last_activity;

			// Apply once per inactivity streak: skip if we already decayed since
			// the member's last activity.
			$decayed_at = (string) get_user_meta( $user_id, self::META_LAST, true );
			if ( '' !== $decayed_at && $decayed_at >= $last_activity ) {
				continue;
			}

			$amount = (int) floor( $total * $percent / 100 );
			if ( $amount < 1 ) {
				continue;
			}

			$result = PointsEngine::debit( $user_id, $amount, 'points_decay', '', $type );
			if ( ! empty( $result['success'] ) ) {
				update_user_meta( $user_id, self::META_LAST, gmdate( 'Y-m-d H:i:s' ) );
				++$decayed;
			}
		}

		/**
		 * Fires after a decay sweep.
		 *
		 * @since 1.5.3
		 *
		 * @param int $decayed Number of members decayed this run.
		 */
		do_action( 'wb_gam_points_decayed', $decayed );

		return $decayed;
	}
}
