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

	/**
	 * Most members decayed in a single run.
	 *
	 * @var int
	 */
	private const BATCH = 500;

	/**
	 * Candidate members examined per page while looking for those BATCH.
	 *
	 * Distinct from BATCH on purpose: most members with a balance are ACTIVE and will not decay, so
	 * the number we have to look at to find 500 who qualify is much larger than 500. Paging the search
	 * is what keeps the cost proportional to what we examine rather than to the whole site.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Seconds a single sweep may run before it stops and leaves the rest for tomorrow.
	 *
	 * Same budget LogPruner uses, for the same reason: a cron tick that never ends is its own outage,
	 * and on a site without a real system cron it runs inline on some unlucky visitor's page load.
	 *
	 * @var int
	 */
	private const MAX_RUNTIME_SECONDS = 50;

	/**
	 * Register the cron handler + ensure the daily event is scheduled.
	 */
	public static function init(): void {
		// Void wrapper: run() returns a count for CLI/tests, but an action
		// callback must not return anything.
		add_action(
			self::CRON_HOOK,
			static function (): void {
				self::run();
			}
		);
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
		$type = PointsEngine::resolve_type( null );

		// The cutoff is compared against MAX(created_at) further down -- in PHP, not in SQL -- and
		// created_at is written with current_time( 'mysql' ), so it is the SITE's clock. This was
		// gmdate(), i.e. UTC, so the inactivity window was wrong by the site's offset in whichever
		// direction the site sits: on a site behind UTC a member's last activity looked older than it
		// was and their points decayed hours early.
		//
		// It survived every previous sweep because the comparison happens in PHP after the rows come
		// back, and the gate only inspected $wpdb calls. It does not any more (Check D).
		$cutoff  = Clock::site_cutoff( "-{$days} days" );
		$started = microtime( true );

		// This used to be ONE query: join the ledger to the totals table, GROUP BY member, take
		// MAX(created_at) as their last activity, HAVING it older than the cutoff, LIMIT 500.
		//
		// The LIMIT bounded the RESULT and not the WORK. MySQL cannot know which members qualify until
		// it has computed MAX(created_at) for every member holding a positive balance, so it built the
		// whole aggregate first and threw away all but 500 rows -- EXPLAIN: `Using temporary`, plus a
		// full index scan of user_totals. At 100k members that is 100k members' worth of aggregation
		// every night to decay 500 of them, and this engine is opt-in, so the owner who switches point
		// decay on is exactly the owner who finds out.
		//
		// Two bounded queries per page instead of one unbounded one. First, keyset-page the totals
		// table by user_id (PRIMARY KEY (user_id, point_type)) -- 500 candidate members at a time, no
		// aggregate, no temp table. Then ask the ledger for THOSE members' last activity, scoped by an
		// IN() over the page: that rides idx_user_type_created (user_id, point_type, created_at), so
		// each member's MAX is read off the tail of the index rather than computed. EXPLAIN now says
		// `Using index for group-by` -- a loose index scan, the best case there is.
		//
		// Cost is now proportional to the members we actually look at, and stops at the same BATCH cap
		// of decays (or the runtime budget, borrowed from LogPruner: a cron tick that never ends is its
		// own outage).
		$decayed = 0;
		$cursor  = 0;

		while ( $decayed < self::BATCH ) {
			if ( ( microtime( true ) - $started ) >= self::MAX_RUNTIME_SECONDS ) {
				break;
			}

			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
					"SELECT user_id, total
					   FROM {$wpdb->prefix}wb_gam_user_totals
					  WHERE point_type = %s AND total > 0 AND user_id > %d
					  ORDER BY user_id ASC
					  LIMIT %d",
					$type,
					$cursor,
					self::PAGE
				)
			);

			if ( empty( $candidates ) ) {
				break;
			}

			$totals = array();
			foreach ( $candidates as $candidate ) {
				$totals[ (int) $candidate->user_id ] = (int) $candidate->total;
				$cursor                              = max( $cursor, (int) $candidate->user_id );
			}

			$ids          = array_keys( $totals );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$activity = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; ids bound below.
					"SELECT user_id, MAX(created_at) AS last_activity
					   FROM {$wpdb->prefix}wb_gam_points
					  WHERE point_type = %s AND user_id IN ({$placeholders})
					  GROUP BY user_id",
					array_merge( array( $type ), $ids )
				)
			);

			foreach ( (array) $activity as $row ) {
				if ( $decayed >= self::BATCH ) {
					break;
				}

				$user_id       = (int) $row->user_id;
				$last_activity = (string) $row->last_activity;

				if ( $last_activity >= $cutoff ) {
					continue;
				}

				$total = $totals[ $user_id ] ?? 0;

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
