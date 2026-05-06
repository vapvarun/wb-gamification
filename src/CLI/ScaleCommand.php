<?php
/**
 * WP-CLI: Scale-readiness commands for WB Gamification.
 *
 * Two commands:
 *   - `wp wb-gamification scale seed`      — populate a real-shape dataset
 *                                            so benchmarks measure against
 *                                            production-scale tables.
 *   - `wp wb-gamification scale benchmark` — run hot-path queries and
 *                                            report per-query timings + a
 *                                            pass/fail against the budget.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\CLI;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\LeaderboardEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Seed-and-measure scale benchmark.
 *
 * Required to claim 100k-readiness — every other tuning is theory until
 * the hot-path queries are timed against a production-shape table.
 *
 * @package WB_Gamification
 */
final class ScaleCommand {

	/**
	 * Per-query timing budgets (milliseconds). A query exceeding its budget
	 * fails the benchmark gate.
	 *
	 * Numbers chosen for a typical Local-by-Flywheel MySQL 8 box; tighten
	 * for production hosts with dedicated MySQL.
	 *
	 * @var array<string,float>
	 */
	private const BUDGETS_MS = array(
		'get_total_pk'           => 5.0,
		'get_totals_by_type_pk'  => 5.0,
		'leaderboard_snapshot'   => 20.0,
		'points_history_user'    => 30.0,
		'rate_limit_today_count' => 15.0,
		'convert_balance_lookup' => 5.0,
	);

	/**
	 * Seed a real-shape dataset.
	 *
	 * ## OPTIONS
	 *
	 * [--users=<n>]
	 * : Number of synthetic users to create. Default: 10000.
	 *
	 * [--events-per-user=<n>]
	 * : Awards per user spread across the last 12 months. Default: 10.
	 *
	 * [--batch=<n>]
	 * : Insert batch size. Default: 5000.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification scale seed --users=10000 --events-per-user=10
	 *   wp wb-gamification scale seed --users=100000 --events-per-user=20
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function seed( array $args, array $assoc_args ): void {
		$user_count   = max( 1, (int) ( $assoc_args['users'] ?? 10000 ) );
		$per_user     = max( 1, (int) ( $assoc_args['events-per-user'] ?? 10 ) );
		$batch_size   = max( 100, (int) ( $assoc_args['batch'] ?? 5000 ) );
		$total_events = $user_count * $per_user;

		\WP_CLI::line( "Seeding {$user_count} users × {$per_user} events = {$total_events} ledger rows…" );

		global $wpdb;
		$now      = current_time( 'mysql' );
		$started  = microtime( true );
		$inserted = 0;

		// Use synthetic user IDs starting at 1_000_000 so we don't collide
		// with real users. Cleanup target: WHERE user_id >= 1000000.
		$base_uid = 1000000;

		for ( $u = 0; $u < $user_count; $u += $batch_size ) {
			$rows         = array();
			$placeholders = array();
			$args_flat    = array();

			$slice_users = min( $batch_size, $user_count - $u );
			for ( $i = 0; $i < $slice_users; $i++ ) {
				$uid = $base_uid + $u + $i;
				for ( $e = 0; $e < $per_user; $e++ ) {
					$placeholders[] = '(%d, %s, %d, %s, %s)';
					$args_flat[]    = $uid;
					$args_flat[]    = 'scale_seed';
					$args_flat[]    = wp_rand( 1, 50 );
					$args_flat[]    = ( $e % 5 === 0 ) ? 'coins' : 'points';
					// Spread across last 12 months so period filters get hit.
					$days_back   = wp_rand( 0, 365 );
					$args_flat[] = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( $days_back * 86400 ) );
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk INSERT seed.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}wb_gam_points (user_id, action_id, points, point_type, created_at) VALUES " . implode( ',', $placeholders ),
					...$args_flat
				)
			);
			$inserted += count( $placeholders );

			if ( ( $u + $slice_users ) % ( $batch_size * 4 ) === 0 ) {
				$elapsed = round( microtime( true ) - $started, 1 );
				\WP_CLI::line( sprintf( '  %d / %d users (%.1fs elapsed)', $u + $slice_users, $user_count, $elapsed ) );
			}
		}

		// Backfill the materialised user-totals from the seeded ledger so
		// get_total benchmarks measure the production read path.
		\WP_CLI::line( 'Backfilling wb_gam_user_totals…' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"INSERT INTO {$wpdb->prefix}wb_gam_user_totals (user_id, point_type, total)
			 SELECT user_id, point_type, COALESCE(SUM(points), 0)
			   FROM {$wpdb->prefix}wb_gam_points
			  WHERE user_id >= {$base_uid}
			  GROUP BY user_id, point_type
			 ON DUPLICATE KEY UPDATE total = VALUES(total)"
		);

		$elapsed = round( microtime( true ) - $started, 1 );
		\WP_CLI::success( "Seeded {$inserted} ledger rows in {$elapsed}s." );
		\WP_CLI::line( "Cleanup: wp wb-gamification scale teardown" );
	}

	/**
	 * Remove all seeded synthetic data.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification scale teardown
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args (unused).
	 */
	public function teardown( array $args, array $assoc_args ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d1 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_points WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d2 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_events WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d3 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_user_totals WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d4 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_leaderboard_cache WHERE user_id >= 1000000" );
		\WP_CLI::success( "Removed: points={$d1}, events={$d2}, user_totals={$d3}, leaderboard_cache={$d4}" );
	}

	/**
	 * Time the hot-path queries against the current dataset.
	 *
	 * Pass criteria: every query under its budget. Fails CI if any query
	 * exceeds the budget — that's the gate for shipping to a 100k site.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification scale benchmark
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args (unused).
	 */
	public function benchmark( array $args, array $assoc_args ): void {
		global $wpdb;
		wp_cache_flush();

		// Pick a representative seeded user (first uid with rows).
		$uid = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}wb_gam_points WHERE user_id >= 1000000 ORDER BY user_id ASC LIMIT 1" );
		if ( $uid <= 0 ) {
			\WP_CLI::warning( 'No seeded data — running benchmark against real users (less reliable). Run `wp wb-gamification scale seed` first.' );
			$uid = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}wb_gam_points ORDER BY id DESC LIMIT 1" );
		}

		$ledger_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points" );
		$user_rows   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wb_gam_points" );
		\WP_CLI::line( sprintf( "Dataset: %s ledger rows across %s users. Test uid=%d.", number_format( $ledger_rows ), number_format( $user_rows ), $uid ) );
		\WP_CLI::line( str_repeat( '─', 70 ) );

		$results = array();

		// 1. Materialised get_total — the most-called read in the plugin.
		$results['get_total_pk'] = self::time_op( function () use ( $uid ) {
			wp_cache_flush();
			return PointsEngine::get_total( $uid, 'points' );
		} );

		// 2. Multi-currency breakdown for the hub.
		$results['get_totals_by_type_pk'] = self::time_op( function () use ( $uid ) {
			wp_cache_flush();
			return PointsEngine::get_totals_by_type( $uid );
		} );

		// 3. Leaderboard from snapshot (top 10, this week).
		$results['leaderboard_snapshot'] = self::time_op( function () {
			wp_cache_flush();
			return LeaderboardEngine::get_leaderboard( 'week', 10, '', 0, 'points' );
		} );

		// 4. Points history pagination — 20 rows, latest first.
		$results['points_history_user'] = self::time_op( function () use ( $uid ) {
			wp_cache_flush();
			return PointsEngine::get_history( $uid, 20 );
		} );

		// 5. Rate-limit today count — hot on every action that fires.
		$results['rate_limit_today_count'] = self::time_op( function () use ( $uid, $wpdb ) {
			$today = gmdate( 'Y-m-d 00:00:00' );
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
					  WHERE user_id = %d AND action_id = %s AND created_at >= %s",
					$uid,
					'scale_seed',
					$today
				)
			);
		} );

		// 6. Convert pre-flight balance lookup with FOR UPDATE locking.
		$results['convert_balance_lookup'] = self::time_op( function () use ( $uid, $wpdb ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT total FROM {$wpdb->prefix}wb_gam_user_totals
					  WHERE user_id = %d AND point_type = %s",
					$uid,
					'points'
				)
			);
		} );

		// Render.
		$failures = 0;
		\WP_CLI::line( str_pad( 'Query', 28 ) . str_pad( 'Time', 12 ) . str_pad( 'Budget', 12 ) . 'Status' );
		\WP_CLI::line( str_repeat( '─', 70 ) );
		foreach ( $results as $key => $ms ) {
			$budget = self::BUDGETS_MS[ $key ] ?? 100.0;
			$pass   = $ms <= $budget;
			if ( ! $pass ) {
				$failures++;
			}
			\WP_CLI::line( sprintf(
				'%s%s%s%s',
				str_pad( $key, 28 ),
				str_pad( number_format( $ms, 2 ) . 'ms', 12 ),
				str_pad( number_format( $budget, 1 ) . 'ms', 12 ),
				$pass ? "\033[32mPASS\033[0m" : "\033[31mFAIL (over by " . number_format( $ms - $budget, 2 ) . "ms)\033[0m"
			) );
		}
		\WP_CLI::line( str_repeat( '─', 70 ) );
		if ( 0 === $failures ) {
			\WP_CLI::success( 'All queries within budget — 100k-ready against this dataset.' );
		} else {
			\WP_CLI::error( "{$failures} query(s) over budget — investigate before shipping to a large site." );
		}
	}

	/**
	 * Time a closure in milliseconds.
	 *
	 * @param callable $op Closure to time.
	 * @return float Milliseconds elapsed.
	 */
	private static function time_op( callable $op ): float {
		$started = microtime( true );
		$op();
		return round( ( microtime( true ) - $started ) * 1000, 3 );
	}
}
