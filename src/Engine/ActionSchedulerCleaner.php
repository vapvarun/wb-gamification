<?php
/**
 * WB Gamification Action Scheduler Cleaner
 *
 * Action Scheduler is a task runner — long-term job history bloats the
 * `actionscheduler_actions` table and slows every page load that touches
 * it (every WP-Cron tick, every admin page that lists pending hooks,
 * every block render that schedules a follow-up). AS's own cleanup only
 * touches `complete` actions older than 30 days. Pending + failed
 * accumulate forever, and one runaway enqueue loop can put a site into
 * a permanent slow state.
 *
 * This cleaner runs daily and enforces a 7-day retention horizon across
 * complete / failed / pending. Tunable via `wb_gam_as_retention_days`
 * filter for sites that want longer or shorter history.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Daily Action Scheduler retention pruner.
 *
 * Mirrors the LogPruner pattern: batched DELETEs with a runtime budget
 * so a single cron tick never holds row-locks long enough to interfere
 * with normal site traffic.
 *
 * @package WB_Gamification
 */
final class ActionSchedulerCleaner {

	const CRON_HOOK  = 'wb_gam_as_cleanup';
	const CRON_RECUR = 'daily';

	/**
	 * Default retention horizon in days. Anything older — regardless of
	 * status — gets removed.
	 *
	 * 7 days: enough to debug a job that failed yesterday, not enough to
	 * grow the table past a few hundred thousand rows on a busy site.
	 */
	const DEFAULT_RETENTION_DAYS = 7;

	/**
	 * Per-query batch size for the DELETE loop.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Hard runtime budget per cron tick (seconds). Stops the loop before
	 * WP-Cron's 60s default expires so the next tick can pick up where
	 * we left off rather than colliding.
	 */
	private const MAX_RUNTIME_SECONDS = 50;

	/**
	 * Register the cron schedule and hook.
	 * Called on plugins_loaded.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the cron on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::CRON_RECUR, self::CRON_HOOK );
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
	 * Run the daily cleanup. Removes complete / failed / pending actions
	 * older than the retention horizon, batched until empty or runtime
	 * budget is reached.
	 *
	 * Logs table rows referencing the deleted action_id are removed in
	 * the same batch — otherwise the logs table grows unbounded.
	 *
	 * @return array{complete:int, failed:int, pending:int} Per-status delete counts.
	 */
	public static function cleanup(): array {
		global $wpdb;

		// Sanity guard — AS may be deactivated on a site that once had it.
		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array(
				'complete' => 0,
				'failed'   => 0,
				'pending'  => 0,
			);
		}

		/**
		 * Filter the AS retention horizon in days.
		 *
		 * Anything older than this — regardless of status (complete,
		 * failed, pending) — gets removed on the daily cleanup tick.
		 *
		 * @param int $days Retention horizon. Default 7. Minimum 1.
		 */
		$days   = (int) apply_filters( 'wb_gam_as_retention_days', self::DEFAULT_RETENTION_DAYS );
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$started = microtime( true );
		$results = array(
			'complete' => self::prune_status( 'complete', $cutoff, $started ),
			'failed'   => self::prune_status( 'failed', $cutoff, $started ),
			'pending'  => self::prune_status( 'pending', $cutoff, $started ),
		);

		/**
		 * Fires after a single cleanup tick. Useful for monitoring + alerts.
		 *
		 * @param array  $results Per-status delete counts.
		 * @param string $cutoff  ISO datetime cutoff used.
		 */
		do_action( 'wb_gam_as_cleaned', $results, $cutoff );

		return $results;
	}

	/**
	 * Batched DELETE loop for one AS status.
	 *
	 * @param string $status  AS status: complete, failed, pending.
	 * @param string $cutoff  ISO datetime; rows older than this are removed.
	 * @param float  $started Timestamp from microtime(true) at cleanup start.
	 * @return int Rows deleted from actionscheduler_actions in this tick.
	 */
	private static function prune_status( string $status, string $cutoff, float $started ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$total         = 0;

		do {
			// Find a batch of doomed action_ids first so the logs delete
			// below can target them without a sub-query the optimiser will
			// choose to rewrite into a slow join.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM `{$actions_table}` WHERE status = %s AND scheduled_date_gmt < %s LIMIT %d",
					$status,
					$cutoff,
					self::BATCH_SIZE
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			// Cascade-style delete from logs first to keep referential
			// shape clean. AS doesn't declare an FK, so we own the order.
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$logs_table}` WHERE action_id IN ({$placeholders})", $ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$actions_table}` WHERE action_id IN ({$placeholders})", $ids ) );

			$total += $deleted;

			if ( $deleted < self::BATCH_SIZE ) {
				break;
			}
			if ( ( microtime( true ) - $started ) >= self::MAX_RUNTIME_SECONDS ) {
				break;
			}
		} while ( true );

		return $total;
	}
}
