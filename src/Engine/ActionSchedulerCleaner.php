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
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

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
	 * Default retention horizon in days for OUR OWN completed/failed rows.
	 *
	 * 7 days: enough to debug a job that failed yesterday, not enough to
	 * grow the table past a few hundred thousand rows on a busy site.
	 *
	 * Applies to `complete` and `failed` only. Pending work is never aged out
	 * as routine housekeeping — see cleanup() and self::HOOK_PREFIX.
	 */
	const DEFAULT_RETENTION_DAYS = 7;

	/**
	 * Row count above which the cleaner switches to panic mode and drops
	 * retention to 1 hour for the dominant runaway hook. A healthy site
	 * shouldn't see more than ~50k AS rows even on a busy week; crossing
	 * 250k means something is recursively enqueueing and the daily
	 * 7-day-retention pass can't catch up.
	 *
	 * See PERF-002 (audit/PERF-DIAG-2026-05-27.yaml) — the LeaderboardNudge
	 * recursion grew the AS table to 3.6M rows in 40 hours and the cleaner
	 * was structurally unable to defend against it.
	 */
	private const RUNAWAY_ROW_THRESHOLD = 250000;

	/**
	 * Transient key that records the most recent runaway detection so
	 * admin-facing tooling can surface the alert. Set on detection,
	 * cleared when row count returns under the threshold.
	 */
	private const RUNAWAY_TRANSIENT_KEY = 'wb_gam_as_runaway_detected';

	/**
	 * Per-query batch size for the DELETE loop.
	 */
	private const BATCH_SIZE = 1000;

	/**
	 * Per-query batch size used while the cleaner is in panic mode.
	 * Larger because we're racing the runaway hook, smaller than full
	 * unbounded so each statement still completes quickly.
	 */
	private const PANIC_BATCH_SIZE = 5000;

	/**
	 * Hard runtime budget per cron tick (seconds). Stops the loop before
	 * WP-Cron's 60s default expires so the next tick can pick up where
	 * we left off rather than colliding.
	 */
	private const MAX_RUNTIME_SECONDS = 50;

	/**
	 * Ownership fence. This cleaner may ONLY ever delete rows whose hook
	 * carries this prefix.
	 *
	 * Action Scheduler is SHARED INFRASTRUCTURE. Its tables hold WooCommerce
	 * orders, Subscriptions renewals, Jetpack sync jobs, and every other
	 * plugin's queued work alongside ours. Until 1.6.4 this class deleted by
	 * `status` + `scheduled_date_gmt` with NO ownership filter at all — so a
	 * plugin whose job is to award points was, every single day, deleting
	 * WooCommerce's queue. On a busy store "pending and past-due by >7 days"
	 * is exactly what a backed-up queue looks like, and our response to a
	 * backed-up queue was to destroy the backlog.
	 *
	 * We fence on the HOOK prefix rather than the AS group because our own
	 * group slugs are historically inconsistent (`wb_gam_email`,
	 * `wb-gamification-emails`, `wb_gamification`, `wb-gamification-nudge`),
	 * whereas every hook we enqueue is `wb_gam_*` without exception. A hook
	 * prefix cannot drift silently the way a group slug can.
	 *
	 * @since 1.6.4
	 */
	private const HOOK_PREFIX = 'wb_gam_';

	/**
	 * Register the cron schedule and hook.
	 * Called on plugins_loaded.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );

		// Arm the recurring event on init, never at plugins_loaded: wp_schedule_event
		// resolves schedules via wp_get_schedules(), which fires the
		// cron_schedules filter — that must not run before init on WP 6.7+.
		if ( did_action( 'init' ) ) {
			self::maybe_schedule();
		} else {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		}
	}

	/**
	 * Arm the recurring event if not already scheduled. Idempotent — safe to
	 * call on every init.
	 */
	public static function maybe_schedule(): void {
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
	 * Cron action callback. Runs the cleanup and discards the return value
	 * so the hooked callback path returns void (action callbacks must not
	 * return a value). The CLI calls cleanup() directly when it needs the
	 * per-status counts.
	 *
	 * @return void
	 */
	public static function run_cron(): void {
		self::cleanup();
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

		// Circuit breaker. If the table has crossed the runaway threshold,
		// switch to a 1-hour retention horizon for `complete` + `failed`
		// so we can claw back disk + worker pressure quickly. The normal
		// daily cleaner can't keep up with a runaway hook spawning
		// hundreds of jobs/sec.
		$panic_mode = self::detect_runaway( $table );

		if ( $panic_mode ) {
			$days   = 0;
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		} else {
			/**
			 * Filter the AS retention horizon in days.
			 *
			 * Applies to this plugin's own `complete` and `failed` rows (hook
			 * prefixed `wb_gam_`). Other plugins' rows are never touched, and
			 * pending work is never aged out by routine housekeeping.
			 *
			 * @param int $days Retention horizon. Default 7. Minimum 1.
			 */
			$days   = (int) apply_filters( 'wb_gam_as_retention_days', self::DEFAULT_RETENTION_DAYS );
			$days   = max( 1, $days );
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		}

		$started    = microtime( true );
		$batch_size = $panic_mode ? self::PANIC_BATCH_SIZE : self::BATCH_SIZE;

		// `complete` and `failed` are HISTORY — deleting them is housekeeping.
		// `pending` is QUEUED WORK — deleting it is destroying it.
		//
		// Until 1.6.4 we pruned `pending` unconditionally, every day. That is
		// not retention, it is data loss: a job sitting pending is a weekly
		// email that has not been sent, a webhook that has not been delivered,
		// a nudge nobody has received. Retention horizons apply to records of
		// what happened, never to instructions for what is still to happen.
		//
		// The one case where dropping our own pending rows is right is the
		// PERF-002 shape: one of OUR hooks recursively self-enqueueing until
		// the table is unusable. Then dropping that backlog is the recovery,
		// not the damage. So pending is pruned only when the circuit breaker
		// has tripped AND the dominant runaway hook is ours — never as routine
		// housekeeping, and never for another plugin's work.
		$results = array(
			'complete' => self::prune_status( 'complete', $cutoff, $started, $batch_size ),
			'failed'   => self::prune_status( 'failed', $cutoff, $started, $batch_size ),
			'pending'  => ( $panic_mode && self::runaway_hook_is_ours() )
				? self::prune_status( 'pending', $cutoff, $started, $batch_size )
				: 0,
		);

		/**
		 * Fires after a single cleanup tick. Useful for monitoring + alerts.
		 *
		 * @param array  $results    Per-status delete counts.
		 * @param string $cutoff     ISO datetime cutoff used.
		 * @param bool   $panic_mode True if the cleaner was in panic mode this tick.
		 */
		do_action( 'wb_gam_as_cleaned', $results, $cutoff, $panic_mode );

		return $results;
	}

	/**
	 * Detect whether the Action Scheduler tables are in runaway state.
	 *
	 * Sets / clears the runaway transient and fires `wb_gam_as_runaway_detected`
	 * for monitoring integrations.
	 *
	 * @param string $table Fully-qualified actions table name.
	 * @return bool True if the cleaner should run in panic mode.
	 */
	private static function detect_runaway( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( $row_count < self::RUNAWAY_ROW_THRESHOLD ) {
			delete_transient( self::RUNAWAY_TRANSIENT_KEY );
			return false;
		}

		// Identify the dominant hook so the alert payload is actionable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top = (array) $wpdb->get_row(
			"SELECT hook, COUNT(*) AS n FROM `{$table}` GROUP BY hook ORDER BY n DESC LIMIT 1",
			ARRAY_A
		);

		$payload = array(
			'rows'        => $row_count,
			'threshold'   => self::RUNAWAY_ROW_THRESHOLD,
			'top_hook'    => $top['hook'] ?? '',
			'top_hook_n'  => isset( $top['n'] ) ? (int) $top['n'] : 0,
			'detected_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		set_transient( self::RUNAWAY_TRANSIENT_KEY, $payload, DAY_IN_SECONDS );

		/**
		 * Fires when the Action Scheduler tables cross the runaway threshold.
		 *
		 * Wire monitoring / paging / Slack alerts here. Payload includes
		 * the current row count, the dominant hook, and how many rows it
		 * holds. The cleaner switches to a 1-hour panic retention horizon
		 * for the rest of this tick to start clawing back rows.
		 *
		 * @since 1.4.1
		 *
		 * @param array{rows:int,threshold:int,top_hook:string,top_hook_n:int,detected_at:string} $payload
		 */
		do_action( 'wb_gam_as_runaway_detected', $payload );

		return true;
	}

	/**
	 * Read the latest runaway-detection payload, or false if the AS tables
	 * are currently healthy. Useful for admin notices + Doctor CLI output.
	 *
	 * @return array{rows:int,threshold:int,top_hook:string,top_hook_n:int,detected_at:string}|false
	 */
	public static function get_runaway_state() {
		$payload = get_transient( self::RUNAWAY_TRANSIENT_KEY );
		return is_array( $payload ) ? $payload : false;
	}

	/**
	 * Is the hook currently flooding the AS table one of OURS?
	 *
	 * Gates the panic-mode `pending` prune. A runaway caused by another plugin
	 * is that plugin's incident — we alert on it (the row-count threshold is a
	 * whole-table health signal, deliberately), but we do not get to delete
	 * anyone else's queue to recover from it.
	 *
	 * @since 1.6.4
	 *
	 * @return bool True when the dominant hook is prefixed wb_gam_.
	 */
	private static function runaway_hook_is_ours(): bool {
		$state = self::get_runaway_state();
		if ( ! is_array( $state ) ) {
			return false;
		}
		return 0 === strpos( (string) ( $state['top_hook'] ?? '' ), self::HOOK_PREFIX );
	}

	/**
	 * Batched DELETE loop for one AS status, fenced to this plugin's own hooks.
	 *
	 * Every DELETE issued here carries `AND hook LIKE 'wb_gam_%'`. Action
	 * Scheduler is shared infrastructure; the tables are not ours to prune.
	 * See self::HOOK_PREFIX for what happened without that fence.
	 *
	 * @since 1.6.4 Ownership fence added. Previously deleted ANY plugin's rows.
	 *
	 * @param string $status     AS status: complete, failed, pending.
	 * @param string $cutoff     ISO datetime; rows older than this are removed.
	 * @param float  $started    Timestamp from microtime(true) at cleanup start.
	 * @param int    $batch_size Per-query row limit. Larger in panic mode.
	 * @return int Rows deleted from actionscheduler_actions in this tick.
	 */
	private static function prune_status( string $status, string $cutoff, float $started, int $batch_size = self::BATCH_SIZE ): int {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$total         = 0;
		$hook_like     = $wpdb->esc_like( self::HOOK_PREFIX ) . '%';

		do {
			// Find a batch of doomed action_ids first so the logs delete
			// below can target them without a sub-query the optimiser will
			// choose to rewrite into a slow join.
			//
			// `AND hook LIKE 'wb_gam_%'` is the ownership fence. Do not remove
			// it: without it this statement selects WooCommerce's rows too.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM `{$actions_table}`
					  WHERE status = %s AND scheduled_date_gmt < %s AND hook LIKE %s
					  LIMIT %d",
					$status,
					$cutoff,
					$hook_like,
					$batch_size
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

			if ( $deleted < $batch_size ) {
				break;
			}
			if ( ( microtime( true ) - $started ) >= self::MAX_RUNTIME_SECONDS ) {
				break;
			}
		} while ( true );

		return $total;
	}
}
