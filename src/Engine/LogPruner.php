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
	 * Per-query batch size. Larger batches finish faster but each statement
	 * holds a longer row-lock; 1000 is the sweet spot on InnoDB for both
	 * shared-host and dedicated-MySQL deployments.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Hard runtime budget per cron tick (seconds). Stops the loop before
	 * WP-Cron's 60s default expires so the next tick can pick up where
	 * we left off rather than colliding.
	 */
	private const MAX_RUNTIME_SECONDS = 50;

	/**
	 * Delete rows older than the retention period.
	 *
	 * Batched until empty (or runtime budget reached), so a 100k-user site
	 * that adds millions of rows between cron ticks doesn't fall behind.
	 * Each statement deletes BATCH_SIZE rows; the loop continues until
	 * either no more rows match or MAX_RUNTIME_SECONDS elapses.
	 *
	 * IMPORTANT — balance semantics under retention:
	 *   The pruner deliberately does NOT decrement `wb_gam_user_totals`.
	 *   The materialised user-total represents the lifetime accumulated
	 *   balance and is the source of truth for `PointsEngine::get_total()`.
	 *   Pruning the audit ledger does not "burn" earned points, it only
	 *   removes per-row history beyond the retention horizon.
	 *
	 *   This intentionally fixes a legacy bug where SUM-based get_total
	 *   silently shrunk users' displayed balance after every prune.
	 *
	 * @return int Total rows deleted from wb_gam_points (back-compat return).
	 */
	public static function prune(): int {
		$started = microtime( true );

		$points_deleted = self::prune_table(
			'wb_gam_points',
			(int) get_option( 'wb_gam_log_retention_months', 6 ),
			$started,
			'wb_gam_log_pruned'
		);

		self::prune_table(
			'wb_gam_events',
			(int) get_option( 'wb_gam_events_retention_months', 12 ),
			$started,
			'wb_gam_events_pruned'
		);

		return $points_deleted;
	}

	/**
	 * Batched DELETE loop for one table.
	 *
	 * @param string $table_suffix `wb_gam_points` or `wb_gam_events`.
	 * @param int    $months       Retention months; 0 disables.
	 * @param float  $started      Timestamp from microtime(true) at cron start.
	 * @param string $hook         Action hook fired with (deleted, cutoff).
	 * @return int Total rows deleted across all batches in this tick.
	 */
	private static function prune_table( string $table_suffix, int $months, float $started, string $hook ): int {
		if ( $months <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table  = $wpdb->prefix . $table_suffix;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
		$total  = 0;

		do {
			$batch = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix.
					$cutoff,
					self::BATCH_SIZE
				)
			);
			$total += $batch;

			// Stop when the table is drained OR runtime budget is spent.
			if ( $batch < self::BATCH_SIZE ) {
				break;
			}
			if ( ( microtime( true ) - $started ) >= self::MAX_RUNTIME_SECONDS ) {
				break;
			}
		} while ( true );

		/**
		 * Fires after a log table is pruned (one fire per cron tick, per table).
		 *
		 * @param int    $total  Number of rows deleted in this tick.
		 * @param string $cutoff ISO datetime cutoff used.
		 */
		do_action( $hook, $total, $cutoff );

		return $total;
	}
}
