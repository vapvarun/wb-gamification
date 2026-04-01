<?php
/**
 * WB Gamification Log Pruner
 *
 * Auto-prunes wb_gam_points rows older than the configured retention period.
 * Scheduled via WP-Cron — never unbounded.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-prunes wb_gam_points rows older than the configured retention period.
 *
 * @package WB_Gamification
 */
final class LogPruner {

	const CRON_HOOK  = 'wb_gam_prune_logs';
	const CRON_RECUR = 'daily';

	/**
	 * Register the cron schedule and hook.
	 * Called on plugins_loaded.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the cron on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cron on plugin deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Delete rows older than the retention period.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function prune(): int {
		global $wpdb;

		$months = (int) get_option( 'wb_gam_log_retention_months', 6 );
		if ( $months <= 0 ) {
			return 0;
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_points WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);

		/**
		 * Fires after the points log is pruned.
		 *
		 * @param int    $deleted Number of rows deleted.
		 * @param string $cutoff  ISO datetime cutoff used.
		 */
		do_action( 'wb_gamification_log_pruned', $deleted, $cutoff );

		// Prune events table.
		$events_months = (int) get_option( 'wb_gam_events_retention_months', 12 );
		if ( $events_months > 0 ) {
			$events_cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$events_months} months" ) );
			$deleted_events = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}wb_gam_events WHERE created_at < %s LIMIT 5000",
					$events_cutoff
				)
			);

			/**
			 * Fires after the events log is pruned.
			 *
			 * @param int    $deleted_events Number of event rows deleted.
			 * @param string $events_cutoff  ISO datetime cutoff used.
			 */
			do_action( 'wb_gamification_events_pruned', $deleted_events, $events_cutoff );
		}

		return $deleted;
	}
}
