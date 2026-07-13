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
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

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
		// ── Award hot path (measured since 2026-05-28) ───────────────────────
		'get_total_pk'               => 5.0,
		'get_totals_by_type_pk'      => 5.0,
		'leaderboard_snapshot'       => 20.0,
		'points_history_user'        => 30.0,
		'rate_limit_today_count'     => 15.0,
		'convert_balance_lookup'     => 5.0,

		// ── Added 1.6.4 (S-03). The budgets above only ever covered the paths
		// we already knew were fast. Everything below is a path the scale
		// register flagged as unmeasured — which is precisely where a large
		// site breaks. A budget here is not a guess: it is the ceiling above
		// which the surface is unusable at 100k members.
		//
		// TWO OF THESE ARE EXPECTED TO FAIL ON A SEEDED DATASET TODAY. That is
		// the point — a gate that only measures what already passes proves
		// nothing. They turn green when S-01 and the idx_badge_id ALTER land.

		// S-01: badge rarity aggregation. COUNT(DISTINCT user_id) GROUP BY over
		// wb_gam_user_badges + COUNT(*) over wp_users, uncached, on EVERY
		// request that renders a badge. Big scan, small result: a LIMIT cannot
		// help it — only caching or materialisation can.
		'badge_rarity_map'           => 50.0,

		// max_earners guard. COUNT(*) ... WHERE badge_id = %s, on the AWARD hot
		// path, and wb_gam_user_badges has NO index leading with badge_id
		// (PK(id), UNIQUE(user_id,badge_id), idx_expires_at) -- a composite
		// UNIQUE cannot serve a badge_id-only predicate. Full table scan of an
		// EVENT-scaled table. Fixed by adding idx_badge_id.
		'badge_max_earners_count'    => 20.0,

		// 1.6.4 notifications queue: the single reader every surface goes
		// through (footer, heartbeat, REST, SSE). Regression here is a toast
		// flood, which is what #10086171887 was.
		'notifications_fetch_unseen' => 10.0,

		// Member's earned-badge list -- read on every profile/hub render.
		'earned_badges_user'         => 15.0,

		// Streak read: PK lookup on a MEMBER-scaled table. Should be trivially
		// fast; budgeted so a regression to a scan is caught.
		'streak_read_user'           => 5.0,
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

		// THE MEMBERS HAVE TO ACTUALLY EXIST, and until now they did not.
		//
		// This seeded 100,000 ledger rows for 10,000 members and never created a single one of them in
		// wp_users. Every query that JOINs the users table -- the leaderboard, the analytics dashboard,
		// the rank strip -- therefore threw all of them away and measured a result set of about 150
		// rows. The benchmark then reported "100k-ready" against a board that never had 100k people on
		// it. A benchmark that measures the wrong thing is worse than no benchmark, because it is
		// believed.
		//
		// Inserted directly, in batches: wp_insert_user() fires hooks (including OUR OWN award hooks)
		// and would take minutes and pollute the very ledger we are seeding.
		$user_rows = array();
		$user_ph   = array();
		$user_args = array();

		for ( $u = 0; $u < $user_count; $u++ ) {
			$uid         = $base_uid + $u;
			$user_ph[]   = '(%d, %s, %s, %s, %s, %s)';
			$user_args[] = $uid;
			$user_args[] = 'scaleuser' . $uid;
			$user_args[] = 'scaleuser' . $uid;
			$user_args[] = 'scaleuser' . $uid . '@scale.test';
			$user_args[] = $now;
			$user_args[] = 'Scale User ' . $uid;

			if ( count( $user_ph ) >= 500 || $u === $user_count - 1 ) {
				// The placeholders are literal '(%d, %s, ...)' groups built above; every value binds through
				// $user_args. disable/enable, not ignore -- the sniff reports on the interpolated line
				// INSIDE the call, which an ignore above it does not cover.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->users} (ID, user_login, user_nicename, user_email, user_registered, display_name) VALUES "
						. implode( ',', $user_ph ),
						$user_args
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$user_ph   = array();
				$user_args = array();
			}
		}

		\WP_CLI::log( sprintf( 'Created %s members in wp_users (the ledger rows need someone to belong to).', number_format_i18n( $user_count ) ) );

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

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk INSERT seed (CLI scale benchmark only).
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}wb_gam_points (user_id, action_id, points, point_type, created_at) VALUES " . implode( ',', $placeholders ),
					...$args_flat
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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

		// ── Seed the OTHER tables the budgets measure ────────────────────────
		//
		// Until 1.6.4 the seed populated wb_gam_points (and user_totals) and nothing
		// else. So every budget touching wb_gam_user_badges, wb_gam_streaks or
		// wb_gam_notifications_queue was timed against a table holding a few hundred
		// rows and passed in well under a millisecond — a green light that measured
		// nothing. A benchmark that only seeds the tables it already knows are fast
		// is not a gate, it is a formality.
		//
		// badge rows are what make badge_rarity_map and badge_max_earners_count real:
		// both aggregate over wb_gam_user_badges, and the idx_badge_id fix is
		// meaningless to measure on 370 rows.
		$badge_ids = (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}wb_gam_badge_defs LIMIT 20" );
		if ( empty( $badge_ids ) ) {
			$badge_ids = array( 'scale_seed_badge' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'wb_gam_badge_defs',
				array(
					'id'          => 'scale_seed_badge',
					'name'        => 'Scale Seed Badge',
					'description' => 'Synthetic badge for the scale benchmark.',
				),
				array( '%s', '%s', '%s' )
			);
		}

		$badge_rows = 0;
		for ( $u = 0; $u < $user_count; $u += $batch_size ) {
			$values = array();
			$args   = array();
			$slice  = min( $batch_size, $user_count - $u );
			for ( $i = 0; $i < $slice; $i++ ) {
				$uid = $base_uid + $u + $i;
				// A few badges each, so COUNT(DISTINCT user_id) GROUP BY badge_id has
				// real cardinality on both axes.
				foreach ( array_slice( $badge_ids, 0, 3 ) as $bid ) {
					$values[] = '(%d, %s, %s)';
					array_push( $args, $uid, (string) $bid, gmdate( 'Y-m-d H:i:s' ) );
				}
			}
			if ( ! empty( $values ) ) {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}wb_gam_user_badges (user_id, badge_id, earned_at) VALUES " . implode( ',', $values ),
						...$args
					)
				);
				// phpcs:enable
				$badge_rows += count( $values );
			}
		}

		// Streaks: one row per member (MEMBER-scaled), so streak_read_user is a PK
		// lookup against a real 10k-row table rather than a 298-row one.
		for ( $u = 0; $u < $user_count; $u += $batch_size ) {
			$values = array();
			$args   = array();
			$slice  = min( $batch_size, $user_count - $u );
			for ( $i = 0; $i < $slice; $i++ ) {
				$uid      = $base_uid + $u + $i;
				$values[] = '(%d, %d, %d, %s)';
				array_push( $args, $uid, wp_rand( 1, 40 ), wp_rand( 1, 90 ), gmdate( 'Y-m-d' ) );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}wb_gam_streaks (user_id, current_streak, longest_streak, last_active) VALUES " . implode( ',', $values ),
					...$args
				)
			);
			// phpcs:enable
		}

		// Notifications queue: EVENT-scaled. notifications_fetch_unseen must be timed
		// against a table with real depth, since a toast flood (Basecamp #10086171887)
		// is exactly what happens when this read path degrades.
		$notif_users = min( $user_count, 2000 );
		for ( $u = 0; $u < $notif_users; $u += $batch_size ) {
			$values = array();
			$args   = array();
			$slice  = min( $batch_size, $notif_users - $u );
			for ( $i = 0; $i < $slice; $i++ ) {
				$uid = $base_uid + $u + $i;
				for ( $e = 0; $e < 5; $e++ ) {
					$values[] = '(%d, %s, %s, %s)';
					array_push( $args, $uid, 'points', '{"type":"points","message":"seed"}', gmdate( 'Y-m-d H:i:s' ) );
				}
			}
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}wb_gam_notifications_queue (user_id, event_type, payload_json, created_at) VALUES " . implode( ',', $values ),
					...$args
				)
			);
			// phpcs:enable
		}

		// Build the leaderboard snapshot over the seeded data.
		//
		// The benchmark has a `leaderboard_snapshot` budget, and on production the
		// 5-minute cron keeps that snapshot fresh — so the read path being measured
		// is the snapshot read. Without this, the seeded members are absent from the
		// snapshot, read_from_snapshot() correctly declines to serve a stale board,
		// and the benchmark silently measures the LIVE-AGGREGATE FALLBACK instead:
		// 46ms against a 20ms budget, versus 2.27ms once the snapshot exists.
		//
		// A benchmark that measures a different code path depending on when cron last
		// ran is not a gate, it is a coin toss. Seeding must leave the site in the
		// state production is actually in.
		\WBGam\Engine\LeaderboardEngine::write_snapshot();

		$elapsed = round( microtime( true ) - $started, 1 );
		\WP_CLI::success( "Seeded {$inserted} ledger rows + {$badge_rows} badge rows + {$user_count} streaks in {$elapsed}s." );
		\WP_CLI::line( 'Leaderboard snapshot built (the benchmark measures the snapshot read path).' );
		\WP_CLI::line( 'Cleanup: wp wb-gamification scale teardown' );
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
		// The synthetic members themselves. They are created by seed() so the leaderboard's JOIN against
		// wp_users has someone to find; they must leave with their data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$du = (int) $wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id >= 1000000" );
		\WP_CLI::log( sprintf( 'Removed %s synthetic members.', number_format_i18n( $du ) ) );

		$d1 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_points WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d2 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_events WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d3 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_user_totals WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d4 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_leaderboard_cache WHERE user_id >= 1000000" );
		// Tables added to the seed in 1.6.4 — teardown MUST track the seed, or a
		// benchmark run leaves synthetic badges and streaks on the site forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d5 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d6 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_streaks WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d7 = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_notifications_queue WHERE user_id >= 1000000" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_badge_defs WHERE id = 'scale_seed_badge'" );
		\WP_CLI::success( "Removed: points={$d1}, events={$d2}, user_totals={$d3}, leaderboard_cache={$d4}, user_badges={$d5}, streaks={$d6}, notifications={$d7}" );
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
		\WP_CLI::line( sprintf( 'Dataset: %s ledger rows across %s users. Test uid=%d.', number_format( $ledger_rows ), number_format( $user_rows ), $uid ) );
		\WP_CLI::line( str_repeat( '─', 70 ) );

		$results = array();

		// 1. Materialised get_total — the most-called read in the plugin.
		$results['get_total_pk'] = self::time_op(
			function () use ( $uid ) {
				wp_cache_flush();
				return PointsEngine::get_total( $uid, 'points' );
			}
		);

		// 2. Multi-currency breakdown for the hub.
		$results['get_totals_by_type_pk'] = self::time_op(
			function () use ( $uid ) {
				wp_cache_flush();
				return PointsEngine::get_totals_by_type( $uid );
			}
		);

		// 3. Leaderboard from snapshot (top 10, this week).
		$results['leaderboard_snapshot'] = self::time_op(
			function () {
				wp_cache_flush();
				return LeaderboardEngine::get_leaderboard( 'week', 10, '', 0, 'points' );
			}
		);

		// 4. Points history pagination — 20 rows, latest first.
		$results['points_history_user'] = self::time_op(
			function () use ( $uid ) {
				wp_cache_flush();
				return PointsEngine::get_history( $uid, 20 );
			}
		);

		// 5. Rate-limit today count — hot on every action that fires.
		$results['rate_limit_today_count'] = self::time_op(
			function () use ( $uid, $wpdb ) {
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
			}
		);

		// 6. Convert pre-flight balance lookup with FOR UPDATE locking.
		$results['convert_balance_lookup'] = self::time_op(
			function () use ( $uid, $wpdb ) {
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT total FROM {$wpdb->prefix}wb_gam_user_totals
                      WHERE user_id = %d AND point_type = %s",
						$uid,
						'points'
					)
				);
			}
		);

		// ── Added 1.6.4 (S-03): the surfaces the register flagged as unmeasured ──

		// S-01. Mirrors BadgesController::get_rarity_map() (private). Big scan,
		// small result: the cost is the GROUP BY over an EVENT-scaled table plus
		// a COUNT(*) over wp_users, on every request that renders a badge.
		$results['badge_rarity_map'] = self::time_op(
			function () use ( $wpdb ) {
				wp_cache_flush();
				$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
				return $wpdb->get_results(
					"SELECT badge_id, COUNT(DISTINCT user_id) AS earner_count
					   FROM {$wpdb->prefix}wb_gam_user_badges
					  GROUP BY badge_id",
					ARRAY_A
				);
			}
		);

		// max_earners guard on the AWARD hot path. wb_gam_user_badges has no
		// index leading with badge_id, so this is a full table scan today.
		$results['badge_max_earners_count'] = self::time_op(
			function () use ( $wpdb ) {
				wp_cache_flush();
				$badge = (string) $wpdb->get_var( "SELECT badge_id FROM {$wpdb->prefix}wb_gam_user_badges LIMIT 1" );
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id = %s",
						$badge
					)
				);
			}
		);

		// 1.6.4 notifications reader — every toast surface goes through this.
		$results['notifications_fetch_unseen'] = self::time_op(
			function () use ( $uid ) {
				wp_cache_flush();
				return \WBGam\Engine\NotificationBridge::fetch_unseen( $uid, 0 );
			}
		);

		$results['earned_badges_user'] = self::time_op(
			function () use ( $uid ) {
				wp_cache_flush();
				return \WBGam\Engine\BadgeEngine::get_user_earned_badge_ids( $uid );
			}
		);

		$results['streak_read_user'] = self::time_op(
			function () use ( $uid, $wpdb ) {
				wp_cache_flush();
				return $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wb_gam_streaks WHERE user_id = %d", $uid ),
					ARRAY_A
				);
			}
		);

		// Render.
		$failures = 0;
		$failed   = array();
		\WP_CLI::line( str_pad( 'Query', 30 ) . str_pad( 'Time', 12 ) . str_pad( 'Budget', 12 ) . 'Status' );
		\WP_CLI::line( str_repeat( '─', 72 ) );
		foreach ( $results as $key => $ms ) {
			$budget = self::BUDGETS_MS[ $key ];
			$pass   = $ms <= $budget;
			if ( ! $pass ) {
				++$failures;
				$failed[] = $key;
			}
			\WP_CLI::line(
				sprintf(
					'%s%s%s%s',
					str_pad( $key, 30 ),
					str_pad( number_format( $ms, 2 ) . 'ms', 12 ),
					str_pad( number_format( $budget, 1 ) . 'ms', 12 ),
					$pass ? "\033[32mPASS\033[0m" : "\033[31mFAIL (over by " . number_format( $ms - $budget, 2 ) . "ms)\033[0m"
				)
			);
		}
		\WP_CLI::line( str_repeat( '─', 72 ) );

		// S-02: write the machine-readable result the release gate reads. Without
		// this the benchmark only runs when a human remembers to type it, which
		// means the 100k claim is re-verified never.
		$report = array(
			'version'      => defined( 'WB_GAM_VERSION' ) ? WB_GAM_VERSION : 'unknown',
			'generated_at' => gmdate( 'c' ),
			'dataset'      => array(
				'ledger_rows' => $ledger_rows,
				'users'       => $user_rows,
				'seeded'      => $uid >= 1000000,
			),
			'results_ms'   => $results,
			'budgets_ms'   => self::BUDGETS_MS,
			'failed'       => $failed,
			'pass'         => 0 === $failures,
		);
		$path   = WB_GAM_PATH . 'audit/.last-scale-pass.json';
		file_put_contents( $path, wp_json_encode( $report, JSON_PRETTY_PRINT ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\WP_CLI::line( 'Wrote audit/.last-scale-pass.json (read by the release gate).' );

		if ( 0 === $failures ) {
			\WP_CLI::success( 'All queries within budget — 100k-ready against this dataset.' );
		} else {
			\WP_CLI::error( "{$failures} query(s) over budget (" . implode( ', ', $failed ) . ') — investigate before shipping to a large site.' );
		}
	}

	/**
	 * Time a closure and return the MEDIAN of several runs, in milliseconds.
	 *
	 * A single-shot timing is not a measurement, it is a sample of one — and it made
	 * this gate flaky. Benchmarking right after seeding 100k rows caught MySQL still
	 * busy and reported `get_total_pk` at 9.06ms against a 5ms budget; the median of
	 * seven runs of the same query was 0.08ms. A gate that fails on a cold outlier
	 * gets ignored, and an ignored gate is worse than no gate.
	 *
	 * The median (not the mean) so one slow outlier cannot drag the result, and not
	 * the minimum so we are not quoting a best case we would never see in production.
	 * Each run still flushes the object cache inside the closure, so every sample is
	 * a cold read.
	 *
	 * @since 1.6.4 Median of RUNS samples. Was a single shot.
	 *
	 * @param callable $op Closure to time.
	 * @return float Median milliseconds.
	 */
	private static function time_op( callable $op ): float {
		$runs    = 5;
		$samples = array();

		for ( $i = 0; $i < $runs; $i++ ) {
			$started = microtime( true );
			$op();
			$samples[] = ( microtime( true ) - $started ) * 1000;
		}

		sort( $samples );

		return round( $samples[ (int) floor( $runs / 2 ) ], 3 );
	}
}
