<?php
/**
 * WB Gamification Leaderboard Engine
 *
 * Generates leaderboard data from the wb_gam_points ledger with opt-out
 * filtering, period scoping, and extensible scope support.
 *
 * Periods: all | month | week | day
 *
 * Scope: by default the leaderboard is site-wide. Pass scope_type + scope_id
 * to filter to a defined set of users. Scope resolution is extensible via the
 * `wb_gam_leaderboard_scope_user_ids` filter — BuddyPress integration
 * and third-party plugins hook in here to return the relevant user IDs.
 *
 * Opt-out: users with `leaderboard_opt_out = 1` in wb_gam_member_prefs are
 * never shown on the leaderboard (not even their rank shown to others).
 * They can still retrieve their own private rank.
 *
 * Performance:
 *   - Object cache (2 min TTL) on get_leaderboard() and get_user_rank()
 *   - cache_users() call before avatar loop to eliminate N+1 queries
 *   - Snapshot cron writes top 500 to wb_gam_leaderboard_cache every 5 minutes
 *   - get_leaderboard() reads from snapshot when fresh (< 10 min old)
 *
 * @package WB_Gamification
 * @since   0.1.0
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
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Generates leaderboard data from the points ledger with opt-out filtering and period scoping.
 *
 * @package WB_Gamification
 */
final class LeaderboardEngine {

	/**
	 * Action Scheduler group for the recurring snapshot. The wb_gam_ prefix
	 * keeps it isolated from any host plugin's AS group on a shared install.
	 *
	 * @var string
	 */
	public const AS_GROUP = 'wb_gam_leaderboard';

	/**
	 * Initialize cron hooks and arm the recurring snapshot.
	 *
	 * Called from plugins_loaded via FeatureFlags or directly.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Cache invalidation — bump the wb_gamification group's last-changed
		// stamp on every points-awarded event so leaderboard cache keys (which
		// embed the stamp) auto-orphan instead of serving stale data for up
		// to 120 seconds. Per skill Part 2.7 (incrementor pattern).
		add_action( 'wb_gam_points_awarded', array( __CLASS__, 'invalidate_cache' ), 5, 0 );
		add_action( 'wb_gam_points_awarded_batch', array( __CLASS__, 'invalidate_cache' ), 5, 0 );

		// Hook the snapshot writer to the recurring event.
		add_action( 'wb_gam_leaderboard_snapshot', array( __CLASS__, 'write_snapshot' ) );

		// Arm the recurring snapshot on Action Scheduler. AS owns the cadence,
		// so there is no custom WP-Cron interval to register (which previously
		// tripped WP 6.7+'s _load_textdomain_just_in_time notice). AS is not
		// initialised until init, so defer arming to it.
		if ( did_action( 'init' ) ) {
			self::maybe_schedule();
		} else {
			add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		}
	}

	/**
	 * Arm the recurring snapshot (every 5 minutes) on Action Scheduler and
	 * remove any legacy WP-Cron event so the snapshot can't double-fire.
	 * Idempotent — safe to call on every init.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		// Legacy WP-Cron event from versions <= 1.6.1.
		wp_clear_scheduled_hook( 'wb_gam_leaderboard_snapshot' );

		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Guarded with as_has_scheduled_action() per the AS-schedule guard
		// contract (bin/check-as-schedule-guard.php) so re-arming on every init
		// never stacks duplicate recurring actions.
		if ( ! as_has_scheduled_action( 'wb_gam_leaderboard_snapshot', array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action( time(), 300, 'wb_gam_leaderboard_snapshot', array(), self::AS_GROUP );
		}
	}

	/**
	 * Activation hook — arm the leaderboard snapshot.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::maybe_schedule();
	}

	/**
	 * Deactivation hook — clear the leaderboard snapshot schedule.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wb_gam_leaderboard_snapshot', array(), self::AS_GROUP );
		}
		// Legacy WP-Cron event from versions <= 1.6.1.
		wp_clear_scheduled_hook( 'wb_gam_leaderboard_snapshot' );
	}

	/**
	 * Invalidate every leaderboard cache key in one call.
	 *
	 * Bumps the wb_gamification cache group's last-changed stamp. Every
	 * cache key in get_leaderboard() and get_user_rank() embeds that
	 * stamp, so a bump here orphans every key reachable via either path
	 * — Redis / Memcached evict via LRU, in-memory cache resets next
	 * request. No manual key tracking needed.
	 *
	 * Hooked to `wb_gam_points_awarded` and `wb_gam_points_awarded_batch`
	 * in init() at priority 5 so it runs before badge / challenge / streak
	 * listeners that might re-read the leaderboard.
	 *
	 * Also exposed publicly so admin tools (the recompute CLI flag,
	 * Settings rescue button) can call it explicitly.
	 */
	public static function invalidate_cache(): void {
		// Bump the object-cache last-changed stamp. Every cache key in
		// get_leaderboard() / get_user_rank() embeds this stamp, so all prior
		// keys become unreachable in one operation. This is an in-memory /
		// Redis write — cheap enough for the award hot path.
		wp_cache_set_last_changed( 'wb_gamification' );

		// NOTE: this deliberately no longer writes
		// `wb_gam_leaderboard_invalidated_at`.
		//
		// That option did two damaging things, on every single award:
		//
		// 1. It disabled the snapshot. read_from_snapshot() refused to serve any
		// snapshot older than the option, so the first award after each
		// rebuild sent every subsequent read to a full-table SUM. See
		// read_from_snapshot() — the materialised leaderboard was never
		// actually allowed to be read on a busy site.
		//
		// 2. It made ONE wp_options ROW a write-serialisation point across every
		// award on the site. An UPDATE on a single row, per award, at 100k
		// members, is a lock convoy on the hottest path in the plugin.
		//
		// A materialised leaderboard is eventually consistent BY DESIGN, bounded
		// by the rebuild interval (5 min) — see the `wb_gam_leaderboard_max_snapshot_age`
		// filter. Invalidating it on every write is a contradiction in terms.

		/**
		 * Fires after the leaderboard cache is invalidated.
		 *
		 * Lets other modules clear their own derived caches that depend on
		 * leaderboard freshness (top-N member tiles, monthly digest emails,
		 * etc.) without coupling them to the points-awarded hook directly.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wb_gam_leaderboard_cache_invalidated' );
	}

	/**
	 * Get the top-N members for a period, respecting opt-outs.
	 *
	 * @param string $period     Period: 'all' | 'month' | 'week' | 'day'.
	 * @param int    $limit      Maximum rows to return (1–100).
	 * @param string $scope_type Scope type identifier (e.g. 'bp_group'). Empty = site-wide.
	 * @param int    $scope_id   Scope object ID (e.g. group_id).
	 * @return array<int, array{rank: int, user_id: int, display_name: string, avatar_url: string, points: int}>
	 */
	public static function get_leaderboard(
		string $period = 'all',
		int $limit = 10,
		string $scope_type = '',
		int $scope_id = 0,
		string $point_type = ''
	): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// Resolve the requested point type — empty string = primary, unknown
		// slug also falls back to primary via PointTypeService.
		$resolved_type = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );

		// ── Object cache check ────────────────────────────────────────────────
		// Cache key embeds the wb_gamification group's last-changed stamp so
		// invalidate_cache() (called on wb_gam_points_awarded) auto-orphans
		// every key when any award fires — no manual delete walk needed.
		$last_changed = wp_cache_get_last_changed( 'wb_gamification' );
		$cache_key    = sprintf(
			'wb_gam_lb_%s_%d_%s_%d_%s_%s',
			$period,
			$limit,
			$scope_type ? $scope_type : 'global',
			$scope_id,
			$resolved_type,
			$last_changed
		);
		$cached       = wp_cache_get( $cache_key, 'wb_gamification' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		// ── Try snapshot table for global scopes ──────────────────────────────
		// Snapshot now covers EVERY active currency (Phase 3b) — only scoped
		// requests (BP groups, cohorts) still fall through to the live query.
		if ( '' === $scope_type && 0 === $scope_id ) {
			$snapshot_result = self::read_from_snapshot( $period, $limit, $resolved_type );
			if ( null !== $snapshot_result ) {
				wp_cache_set( $cache_key, $snapshot_result, 'wb_gamification', 120 );
				return $snapshot_result;
			}
		}

		// ── Full query fallback ───────────────────────────────────────────────
		$period_start                        = self::get_period_start( $period );
		[ $opt_out_clause, $opt_out_values ] = self::exclusion_sql( 'p' );
		$scope_ids                           = self::resolve_scope( $scope_type, $scope_id );

		// A scope that resolves to NOBODY means nobody -- it does not mean everybody.
		//
		// Downstream, an empty $scope_ids means "do not restrict", which is correct when no scope was
		// asked for. But it is the same empty array a REQUESTED scope produces when it resolves to no
		// members (an empty group, or a scope type no integration provides). Those two cases were
		// indistinguishable, so a group leaderboard on a site without the BuddyPress bridge quietly
		// rendered the SITE-WIDE board under the group's name.
		//
		// Empty is the honest answer. A global board wearing a group's name is a wrong answer that
		// looks like a right one, which is the worse failure of the two.
		if ( '' !== $scope_type && $scope_id > 0 && empty( $scope_ids ) ) {
			wp_cache_set( $cache_key, array(), 'wb_gamification', 120 );
			return array();
		}

		// Build WHERE clause.
		$where_parts  = array();
		$where_values = array();

		// Always scope by point_type so per-currency leaderboards work even
		// without the cache table being keyed by type yet (Phase 3b).
		$where_parts[]  = 'p.point_type = %s';
		$where_values[] = $resolved_type;

		if ( $period_start ) {
			$where_parts[]  = 'p.created_at >= %s';
			$where_values[] = $period_start;
		}

		// The exclusion fragment carries its own binds, and its placeholder count is a function
		// of what the ADMIN configured -- never of how many members the site has. That is the
		// whole fix: excluding "subscriber" on a 100k-member site used to build a NOT IN() with
		// a hundred thousand placeholders, blow past max_allowed_packet, and take the leaderboard
		// down completely.
		$where_values = array_merge( $where_values, $opt_out_values );

		if ( ! empty( $scope_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$scope_clause = "AND p.user_id IN ($placeholders)";
			$where_values = array_merge( $where_values, $scope_ids );
		} else {
			$scope_clause = '';
		}

		// $where_parts always carries at least the point_type clause, so the
		// WHERE keyword is always emitted.
		$where_clause = 'WHERE ' . implode( ' AND ', $where_parts );

		// period='all' reads the MATERIALISED TOTALS, not the ledger.
		//
		// wb_gam_user_totals already holds every member's running total per
		// currency. It is maintained transactionally on every award
		// (PointsEngine::bump_user_total) and carries KEY idx_type_total
		// (point_type, total) — an index that could not be more precisely shaped
		// for "top N by total for this currency".
		//
		// The leaderboard never read it. For the all-time board — the DEFAULT
		// period, and by far the most-rendered — it instead ran SUM(points)
		// GROUP BY user_id over the entire ledger: a full-table aggregate, a temp
		// table, and a filesort over every member, to produce the same numbers
		// that were already sitting in an indexed table one row per member.
		//
		// Period boards (day/week/month) genuinely need the ledger — a total does
		// not carry a date — so those keep the SUM, bounded by idx_created.
		if ( ! $period_start ) {
			// The top-N is selected from the totals table ALONE, in a derived
			// table, BEFORE the users join.
			//
			// Joining wp_users up front lets the optimiser drive from wp_users
			// (eq_ref into totals) — which scans the whole user table and forces
			// a temporary + filesort, because ORDER BY t.total cannot then use
			// idx_type_total. Verified with EXPLAIN: the naive JOIN form gives
			// `u: type=index ... Using temporary; Using filesort`. Correct result,
			// wrong plan, and the wrong plan is O(members).
			//
			// Selecting the 500-odd candidate rows from the indexed totals table
			// first, then joining names onto that handful, keeps the range scan on
			// idx_type_total (point_type, total) and the LIMIT where it belongs.
			// The clauses are BUILT for this table's alias. They are never string-rewritten.
			//
			// They used to be: the ledger's clauses were composed for alias `p`, and this branch
			// ran str_replace( 'p.user_id', 'user_id', ... ) over them because the totals table was
			// not aliased. That worked only while the fragment was a plain NOT IN(). The moment it
			// became an anti-join it contained `mp.user_id = p.user_id` -- and `p.user_id` matches
			// INSIDE `mp.user_id`, rewriting it to `muser_id`. MySQL: Unknown column 'muser_id'.
			// The query returned nothing, so every scoped leaderboard was blank on every site, and
			// the all-time board went blank whenever the snapshot was missing (a fresh install, and
			// permanently on a host with WP-Cron disabled).
			//
			// A fragment that carries an alias must be given the alias it is composing against.
			// Rewriting SQL with string search-and-replace is not a way to change an alias.
			[ $totals_excl_clause, $totals_excl_values ] = self::exclusion_sql( 'ut' );

			$totals_scope_clause = empty( $scope_ids )
				? ''
				: 'AND ut.user_id IN (' . implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) ) . ')';

			$query = self::build_totals_query(
				$wpdb->prefix . 'wb_gam_user_totals',
				$wpdb->users,
				$totals_excl_clause,
				$totals_scope_clause
			);

			// Rebuild the value list: the totals path has no created_at bind, and it binds the
			// fragment built for `ut` -- not the one built for `p`.
			$where_values = array( $resolved_type );
			$where_values = array_merge( $where_values, $totals_excl_values );
			if ( ! empty( $scope_ids ) ) {
				$where_values = array_merge( $where_values, $scope_ids );
			}
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = "
				SELECT p.user_id,
				       SUM(p.points) AS total_points,
				       u.display_name
				  FROM {$wpdb->prefix}wb_gam_points p
				  JOIN {$wpdb->users} u ON u.ID = p.user_id
				  {$where_clause}
				  {$opt_out_clause}
				  {$scope_clause}
				 GROUP BY p.user_id
				 ORDER BY total_points DESC
				 LIMIT %d
			";
			// phpcs:enable
		}

		$where_values[] = $limit;

		// $where_values always carries the point_type bind plus the LIMIT
		// appended above, so the query is always prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $where_values ), ARRAY_A );

		if ( ! $rows ) {
			$result = array();
			wp_cache_set( $cache_key, $result, 'wb_gamification', 120 );
			return $result;
		}

		$result = self::hydrate_rows( $rows );

		// Store in object cache with 2-minute TTL.
		wp_cache_set( $cache_key, $result, 'wb_gamification', 120 );

		return $result;
	}

	/**
	 * Get a user's private rank within a period.
	 *
	 * Returned even if the user has opted out of public leaderboard display —
	 * this is private data for the member themselves.
	 *
	 * @param int    $user_id    User to calculate rank for.
	 * @param string $period     Period: 'all' | 'month' | 'week' | 'day'.
	 * @param string $scope_type Optional scope type.
	 * @param int    $scope_id   Optional scope ID.
	 * @param string $point_type Optional currency slug — defaults to primary. Without
	 *                           this filter, multi-currency sites compute rank
	 *                           against the SUM of all currencies, which inflates
	 *                           rank vs the public leaderboard which DOES filter.
	 * @return array{rank: int, points: int, points_to_next: int|null}
	 */
	public static function get_user_rank(
		int $user_id,
		string $period = 'all',
		string $scope_type = '',
		int $scope_id = 0,
		string $point_type = ''
	): array {
		global $wpdb;

		// Resolve the currency once so cache key + queries match.
		$resolved_type = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );

		// ── Object cache check ────────────────────────────────────────────────
		// Cache key embeds the wb_gamification group's last-changed stamp —
		// see get_leaderboard() for the rationale.
		$last_changed = wp_cache_get_last_changed( 'wb_gamification' );
		$cache_key    = sprintf(
			'wb_gam_rank_%d_%s_%s_%d_%s_%s',
			$user_id,
			$period,
			$scope_type ? $scope_type : 'global',
			$scope_id,
			$resolved_type,
			$last_changed
		);
		$cached       = wp_cache_get( $cache_key, 'wb_gamification' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$period_start = self::get_period_start( $period );
		// Both consumers below count members STRICTLY above this member's total (HAVING total >
		// %d), and nobody's total exceeds itself -- so the old "remove the current user from the
		// opt-out list so we can count them too" filter could never change an answer. Dropped
		// rather than ported.
		[ $excl_sql, $excl_values ] = self::exclusion_sql( 'p' );
		$scope_ids                  = self::resolve_scope( $scope_type, $scope_id );

		// Same conflation as the board above, and the same answer. A rank WITHIN a scope that has no
		// members is not "1st on the whole site" -- it is no rank at all. Falling through here would
		// tell a member they are 4th in a group they are not ranked in.
		if ( '' !== $scope_type && $scope_id > 0 && empty( $scope_ids ) ) {
			$result = array(
				'rank'           => 0,
				'points'         => 0,
				'points_to_next' => null,
			);
			wp_cache_set( $cache_key, $result, 'wb_gamification', 120 );
			return $result;
		}

		// Get user's own total for the period — scoped by currency so the
		// rank computation matches what the public leaderboard sees.
		if ( $period_start ) {
			$user_total_sql = $wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
				 WHERE user_id = %d AND point_type = %s AND created_at >= %s",
				$user_id,
				$resolved_type,
				$period_start
			);
		} else {
			$user_total_sql = $wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
				 WHERE user_id = %d AND point_type = %s",
				$user_id,
				$resolved_type
			);
		}
		$user_total = (int) $wpdb->get_var( $user_total_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// THE PAGE MUST NOT SHOW TWO NUMBERS FOR ONE METRIC.
		//
		// The board rows come from the snapshot. This strip summed the LEDGER. Between rebuilds the two
		// disagree, so the same block showed a member their row saying 660 and, directly underneath,
		// "your points: 1160". Same member, same period, same page. QA found it; it is indefensible.
		//
		// When the board is serving the snapshot AND the member is in it, this reads THAT ROW -- rank
		// and points together, from one place. A leaderboard is eventually consistent by design; that
		// is the deal a snapshot buys. But it has to be consistently stale, not stale in one corner of
		// the block and live in the other.
		//
		// A member outside the snapshot (it holds the top 500) is not on the board at all, so there is
		// nothing to contradict: they fall through to the live figures below.
		$from_snapshot = self::snapshot_standing( $user_id, $period, $resolved_type, $scope_type, $scope_id );

		if ( null !== $from_snapshot ) {
			wp_cache_set( $cache_key, $from_snapshot, 'wb_gamification', 120 );
			return $from_snapshot;
		}

		// Count users with strictly more points (their count + 1 = our rank).
		$above_rank = self::count_users_above( $user_total, $period_start, $excl_sql, $excl_values, $scope_ids, $resolved_type );

		// Find the lowest total above ours to calculate gap.
		$next_total = self::get_next_threshold( $user_total, $period_start, $excl_sql, $excl_values, $scope_ids, $resolved_type );

		$result = array(
			'rank'           => $above_rank + 1,
			'points'         => $user_total,
			'points_to_next' => null !== $next_total ? ( $next_total - $user_total ) : null,
		);

		// Store in object cache with 2-minute TTL.
		wp_cache_set( $cache_key, $result, 'wb_gamification', 120 );

		return $result;
	}

	// ── Snapshot writer ────────────────────────────────────────────────────────

	/**
	 * Write leaderboard snapshot to the cache table.
	 *
	 * Called by WP-Cron every 5 minutes. Truncates and rewrites the top 500
	 * users for each period into `wb_gam_leaderboard_cache`.
	 *
	 * @return void
	 */
	public static function write_snapshot(): void {
		global $wpdb;

		$cache_table  = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		$points_table = $wpdb->prefix . 'wb_gam_points';

		// Snapshot start time — every row written this tick will have
		// updated_at >= $started. After all (period × currency) inserts
		// finish, anything older than $started is a straggler from the
		// previous snapshot whose user dropped out of the top-500 — purge
		// in one DELETE at the end. Reads during the rebuild always see
		// SOME valid data (old or new), eliminating the read-through
		// window that the legacy TRUNCATE pattern had on every cron tick.
		//
		// READ FROM THE DATABASE'S OWN CLOCK. The rows below are stamped by
		// MySQL's NOW(); the straggler DELETE compares against $started. If the
		// two come from different clocks the comparison is meaningless.
		//
		// Until 1.6.4 this was current_time('mysql') — WordPress SITE-LOCAL time —
		// while the rows were stamped NOW(), which on virtually every host is UTC.
		// On any site ahead of UTC (IST +5:30, CET, all of Asia and Australia)
		// $started was HOURS AHEAD of the rows it had just written, so the final
		// DELETE matched every one of them: the snapshot table was emptied at the
		// end of every single rebuild, and every read fell through to the live
		// full-table SUM. Forever, silently.
		//
		// It survived because it is invisible on a UTC dev box (gmt_offset = 0),
		// where the two clocks happen to agree. Taking both stamps from the DB
		// removes the class of bug, not just this instance.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// @clock-ok: both sides are the DATABASE clock. The snapshot rows are stamped NOW() (see
		// the INSERT below) and the straggler DELETE prunes against this same value, so the two
		// never disagree. Stamping with current_time() and pruning with NOW() is exactly what made
		// the rebuild delete the rows it had just written on every site ahead of UTC.
		$started = (string) $wpdb->get_var( 'SELECT NOW()' );

		$periods = array(
			'all'   => null,
			'month' => self::get_period_start( 'month' ),
			'week'  => self::get_period_start( 'week' ),
			'day'   => self::get_period_start( 'day' ),
		);

		// One snapshot per (period × currency). Without this loop, every
		// non-primary leaderboard read at 100k users would fall through to
		// the live SUM query against wb_gam_points (full-table aggregation).
		$pt_service = new \WBGam\Services\PointTypeService();
		$currencies = array_map( static fn( $row ) => (string) $row['slug'], $pt_service->list() );
		if ( empty( $currencies ) ) {
			$currencies = array( $pt_service->default_slug() );
		}

		foreach ( $currencies as $slug ) {
			foreach ( $periods as $period_key => $period_start ) {
				$where = $wpdb->prepare( 'WHERE point_type = %s', $slug );
				if ( null !== $period_start ) {
					$where .= $wpdb->prepare( ' AND created_at >= %s', $period_start );
				}

				// UPSERT — insert new rows, update existing rows in place.
				// The UNIQUE KEY (user_id, period, point_type) on the cache
				// table is what makes ON DUPLICATE KEY UPDATE work; it was
				// added by DbUpgrader::ensure_leaderboard_cache_unique_key.
				//
				// @clock-ok: updated_at is stamped NOW() (the DATABASE clock) and the straggler
				// DELETE below prunes against $started, which came from the same SELECT NOW(). Both
				// sides of that comparison are the database's clock, so they cannot disagree.
				// Stamping with current_time() and pruning with NOW() is precisely what made the
				// rebuild delete the rows it had just written on every site ahead of UTC.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$cache_table} (user_id, period, point_type, total_points, `rank`, updated_at)
					 SELECT user_id, %s AS period, %s AS point_type, SUM(points) AS total_points,
					        RANK() OVER (ORDER BY SUM(points) DESC) AS `rank`,
					        NOW() AS updated_at
					   FROM {$points_table}
					   {$where}
					  GROUP BY user_id
					  ORDER BY total_points DESC
					  LIMIT 500
					ON DUPLICATE KEY UPDATE
					   prev_rank    = `rank`,
					   total_points = VALUES(total_points),
					   `rank`       = VALUES(`rank`),
					   updated_at   = VALUES(updated_at)",
						$period_key,
						$slug
					)
				);
				// phpcs:enable
			}
		}

		// Purge stragglers — rows from the previous snapshot whose user
		// dropped out of the top-500 this tick. Their updated_at is older
		// than the start of this rebuild, so a single bounded DELETE clears
		// them without affecting any concurrent reads.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$cache_table} WHERE updated_at < %s",
				$started
			)
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Try to read leaderboard data from the snapshot cache table.
	 *
	 * Returns null if the snapshot is stale (> 10 minutes old) or empty.
	 * Only used for global (unscoped) leaderboard requests since the snapshot
	 * does not respect per-request opt-outs or scopes.
	 *
	 * @param string $period    Period key: 'all', 'month', 'week', 'day'.
	 * @param int    $limit     Maximum rows to return.
	 * @return array<int, array{rank: int, user_id: int, display_name: string, avatar_url: string, points: int}>|null
	 */
	private static function read_from_snapshot( string $period, int $limit, string $point_type = 'points' ): ?array {
		global $wpdb;

		$cache_table                = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		[ $excl_sql, $excl_values ] = self::exclusion_sql( 'c' );

		// Check snapshot freshness — must be less than 10 minutes old AND
		// not older than the most recent cache invalidation. The latter
		// covers the gap between a points award (which calls
		// invalidate_cache) and the next 5-minute snapshot cron — without
		// this check, the snapshot would still serve stale data for up
		// to 10 minutes after every award.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$snapshot_built_at = $wpdb->get_var( "SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM {$cache_table}" );
		if ( null === $snapshot_built_at ) {
			return null;
		}
		$snapshot_built_at = (int) $snapshot_built_at;

		// Bounded staleness is the ONLY freshness rule. A leaderboard is a
		// materialised view: it is allowed to be up to one rebuild-interval
		// behind. That is not a compromise, it is the entire reason it exists.
		//
		// Until 1.6.4 there was a SECOND gate here: read_from_snapshot() also
		// bailed whenever `wb_gam_leaderboard_invalidated_at` was newer than the
		// snapshot — and that option was written on EVERY points award. So the
		// first award after each rebuild disabled the snapshot, and on a busy site
		// awards land many times per second. The snapshot was readable for
		// milliseconds per five-minute cycle; ~100% of reads fell through to a
		// full-table SUM over wb_gam_points. The cron built a cache that nothing
		// was ever allowed to read.
		//
		// The old comment called the live fallback "correct (just slower)". At
		// 100k members it is not slower, it is the difference between an indexed
		// read of a 500-row table and a GROUP BY over millions of rows — on a
		// route (GET /leaderboard) whose permission_callback is __return_true, so
		// any anonymous visitor could trigger it in a loop.
		//
		// Staleness window is filterable for owners who want a tighter or looser
		// trade than the 5-minute rebuild interval.
		$max_age = (int) apply_filters( 'wb_gam_leaderboard_max_snapshot_age', 600 );
		if ( ( time() - $snapshot_built_at ) >= max( 60, $max_age ) ) {
			return null;
		}

		$period_key = in_array( $period, array( 'all', 'month', 'week', 'day' ), true ) ? $period : 'all';

		// Build opt-out exclusion for snapshot read.
		$opt_out_clause = '';
		$query_values   = array( $period_key, $point_type );

		if ( '' !== $excl_sql ) {
			$opt_out_clause = $excl_sql;
			$query_values   = array_merge( $query_values, $excl_values );
		}

		$query_values[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.user_id, c.total_points, u.display_name, c.`rank` AS snapshot_rank, c.prev_rank
				   FROM {$cache_table} c
				   JOIN {$wpdb->users} u ON u.ID = c.user_id
				  WHERE c.period = %s AND c.point_type = %s {$opt_out_clause}
				  ORDER BY c.`rank` ASC
				  LIMIT %d",
				$query_values
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! $rows ) {
			return null;
		}

		return self::hydrate_rows( $rows );
	}

	/**
	 * Hydrate raw DB rows into the leaderboard result format.
	 *
	 * Adds rank, avatar_url, and properly types all fields. Uses cache_users()
	 * to eliminate N+1 avatar/user-meta queries.
	 *
	 * @param array<int, array{user_id: string, total_points: string, display_name: string}> $rows Raw DB rows.
	 * @return array<int, array{rank: int, user_id: int, display_name: string, avatar_url: string, points: int}>
	 */
	private static function hydrate_rows( array $rows ): array {
		// Pre-cache all user objects to avoid N+1 queries in the avatar loop.
		$user_ids = array_column( $rows, 'user_id' );
		if ( ! empty( $user_ids ) ) {
			cache_users( array_map( 'intval', $user_ids ) );
		}

		$result = array();
		foreach ( $rows as $rank_zero => $row ) {
			$user_id = (int) $row['user_id'];

			// Rank-change trend: only the snapshot path carries the stored current +
			// previous rank; the live-query fallback has no history, so both stay 0
			// (no arrow). prev_rank 0 = brand-new to the board this snapshot.
			$snapshot_rank = isset( $row['snapshot_rank'] ) ? (int) $row['snapshot_rank'] : 0;
			$prev_rank     = isset( $row['prev_rank'] ) ? (int) $row['prev_rank'] : 0;
			$rank_change   = ( $snapshot_rank > 0 && $prev_rank > 0 ) ? ( $prev_rank - $snapshot_rank ) : 0;

			$result[] = array(
				'rank'         => $rank_zero + 1,
				'user_id'      => $user_id,
				'display_name' => $row['display_name'],
				'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 48 ) ),
				'points'       => (int) $row['total_points'],
				'rank_change'  => $rank_change,
				'is_new'       => ( $snapshot_rank > 0 && 0 === $prev_rank ),
			);
		}

		/**
		 * Filter leaderboard results before they are returned.
		 *
		 * Modify rankings, add custom fields, or filter out specific members.
		 *
		 * @since 1.0.0
		 * @param array $result Hydrated leaderboard rows (rank, user_id, display_name, avatar_url, points).
		 * @param array $rows   Raw DB rows before hydration.
		 */
		return (array) apply_filters( 'wb_gam_leaderboard_results', $result, $rows );
	}

	/**
	 * Return the MySQL datetime string for the start of a period.
	 * Returns null for 'all' (no time filter).
	 *
	 * @param string $period Period identifier: 'all' | 'month' | 'week' | 'day'.
	 * @return string|null MySQL datetime string, or null for 'all'.
	 */
	private static function get_period_start( string $period ): ?string {
		switch ( $period ) {
			case 'day':
				return gmdate( 'Y-m-d' ) . ' 00:00:00';
			case 'week':
				// Monday of the current ISO week.
				return gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
			case 'month':
				return gmdate( 'Y-m-01' ) . ' 00:00:00';
			default:
				return null; // 'all'
		}
	}

	/**
	 * Resolve a scope type + ID to a list of user IDs.
	 *
	 * @param string $scope_type Scope type identifier, e.g. 'bp_group'.
	 * @param int    $scope_id   Scope object ID, e.g. group ID.
	 * @return int[]             Empty array means no scope restriction.
	 */
	private static function resolve_scope( string $scope_type, int $scope_id ): array {
		if ( '' === $scope_type || $scope_id <= 0 ) {
			return array();
		}

		/**
		 * Resolve a leaderboard scope to a list of user IDs.
		 *
		 * BuddyPress integration hooks in here to return group member IDs.
		 * Return an empty array to disable scope filtering (allow all users).
		 *
		 * @param int[]  $user_ids   Starting user ID list (empty).
		 * @param string $scope_type Scope type identifier.
		 * @param int    $scope_id   Scope object ID.
		 */
		return (array) apply_filters(
			'wb_gam_leaderboard_scope_user_ids',
			array(),
			$scope_type,
			$scope_id
		);
	}

	/**
	 * The member's standing AS THE BOARD SEES IT — or null if the board is not serving the snapshot.
	 *
	 * This is what keeps the two halves of the leaderboard block telling the same story. It answers
	 * from the SAME snapshot row the board renders, so the "your standing" strip cannot contradict the
	 * member's own row three lines above it.
	 *
	 * Returns null -- and the caller falls through to the live ledger -- in the two cases where there
	 * is nothing to contradict:
	 *
	 *   - the snapshot is too stale for the board to serve it, so the board is live too;
	 *   - the member is not IN the snapshot (it holds the top 500), so they are not on the board.
	 *
	 * Scoped boards bypass the snapshot entirely, so they are excluded here for the same reason.
	 *
	 * @param int    $user_id    Member.
	 * @param string $period     all|day|week|month.
	 * @param string $point_type Resolved currency.
	 * @param string $scope_type Scope type ('' = site-wide).
	 * @param int    $scope_id   Scope id.
	 * @return array{rank:int,points:int,points_to_next:int|null}|null
	 */
	private static function snapshot_standing(
		int $user_id,
		string $period,
		string $point_type,
		string $scope_type = '',
		int $scope_id = 0
	): ?array {
		global $wpdb;

		// A scoped board never reads the snapshot, so its strip must not either.
		if ( '' !== $scope_type || $scope_id > 0 ) {
			return null;
		}

		$cache_table = $wpdb->prefix . 'wb_gam_leaderboard_cache';

		// The same freshness gate the board uses. If the board would not serve the snapshot, neither
		// does this — otherwise we would have swapped one disagreement for another.
		$built_at = (int) $wpdb->get_var( "SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM {$cache_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- @clock-ok: MAX(updated_at) is compared with time() only through the same $max_age window the board applies; both sides are epoch seconds.

		if ( $built_at <= 0 ) {
			return null;
		}

		/** This filter is documented in src/Engine/LeaderboardEngine.php — see read_from_snapshot(). */
		$max_age = (int) apply_filters( 'wb_gam_leaderboard_max_snapshot_age', 600 );

		if ( ( time() - $built_at ) >= max( 60, $max_age ) ) {
			return null;
		}

		$period_key = in_array( $period, array( 'all', 'month', 'week', 'day' ), true ) ? $period : 'all';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT total_points, `rank` FROM {$cache_table}
				  WHERE user_id = %d AND period = %s AND point_type = %s",
				$user_id,
				$period_key,
				$point_type
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$points = (int) $row['total_points'];

		// The gap to the next member up, read from the same snapshot — so "points to next" cannot
		// disagree with the board either.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(total_points) FROM {$cache_table}
				  WHERE period = %s AND point_type = %s AND total_points > %d",
				$period_key,
				$point_type,
				$points
			)
		);

		return array(
			'rank'           => (int) $row['rank'],
			'points'         => $points,
			'points_to_next' => null !== $next ? ( (int) $next - $points ) : null,
		);
	}

	/**
	 * Compose the all-time (materialised totals) query.
	 *
	 * Split out and PURE so the composed SQL can be asserted without a database. That is the whole
	 * reason it exists: the exclusion fragment was tested in isolation -- its placeholder count was
	 * checked, and it passed -- while the query it was composed INTO was never looked at. So a
	 * str_replace that mangled `mp.user_id` into `muser_id` shipped behind a green test suite, and
	 * every scoped leaderboard on every site returned nothing.
	 *
	 * A fragment is not the query. Test the thing you actually run.
	 *
	 * @internal Public only so the test can compose it without a database.
	 *
	 * @param string $totals_table  Fully-qualified `wb_gam_user_totals` table name.
	 * @param string $users_table   Fully-qualified users table name.
	 * @param string $excl_clause   Exclusion fragment, built for the `ut` alias.
	 * @param string $scope_clause  Scope fragment, built for the `ut` alias.
	 * @return string The SQL, with %s / %d placeholders for prepare().
	 */
	public static function build_totals_query(
		string $totals_table,
		string $users_table,
		string $excl_clause,
		string $scope_clause
	): string {
		// The top-N is selected from the totals table ALONE, in a derived table, BEFORE the users
		// join -- joining wp_users up front makes the optimiser drive from wp_users and forces a
		// temporary + filesort over every member.
		return "
			SELECT t.user_id,
			       t.total_points,
			       u.display_name
			  FROM (
			        SELECT ut.user_id, ut.total AS total_points
			          FROM {$totals_table} ut
			         WHERE ut.point_type = %s
			           AND ut.total > 0
			          {$excl_clause}
			          {$scope_clause}
			      ORDER BY ut.total DESC
			         LIMIT %d
			       ) t
			  JOIN {$users_table} u ON u.ID = t.user_id
			 ORDER BY t.total_points DESC
		";
	}

	private static function exclusion_sql( string $alias ): array {
		global $wpdb;

		// Members who opted out. An anti-join, not a list: whether there are five opt-outs or
		// fifty thousand, this fragment is the same length.
		$sql = ' AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->prefix . 'wb_gam_member_prefs mp'
			. ' WHERE mp.user_id = ' . $alias . '.user_id AND mp.leaderboard_opt_out = 1 )';

		// Owner-excluded accounts (Settings > Access): explicit ids stay a short IN(), and
		// excluded ROLES become a predicate rather than an expanded id list.
		[ $owner_sql, $values ] = PointsEngine::exclusion_sql( $alias );

		return array( $sql . $owner_sql, $values );
	}

	/**
	 * Count users with a points total strictly higher than $threshold.
	 *
	 * @param int         $threshold    Points total to compare against.
	 * @param string|null $period_start MySQL datetime for period start, or null for all-time.
	 * @param string      $excl_sql     Exclusion SQL fragment (bounded placeholders).
	 * @param array       $excl_values  Values bound by that fragment, in order.
	 * @param int[]       $scope_ids    User IDs to restrict to (empty = all users).
	 * @return int Number of users ranked above the threshold.
	 */
	private static function count_users_above(
		int $threshold,
		?string $period_start,
		string $excl_sql,
		array $excl_values,
		array $scope_ids,
		string $point_type = 'points'
	): int {
		global $wpdb;

		// Always scope by point_type so multi-currency rank counts match the
		// public leaderboard which also filters per-currency.
		$values = array( $point_type );
		$where  = ' AND p.point_type = %s';

		if ( $period_start ) {
			$where   .= ' AND p.created_at >= %s';
			$values[] = $period_start;
		}
		if ( '' !== $excl_sql ) {
			$where .= $excl_sql;
			$values = array_merge( $values, $excl_values );
		}
		if ( ! empty( $scope_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where .= " AND p.user_id IN ($ph)";
			$values = array_merge( $values, $scope_ids );
		}
		$values[] = $threshold;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM (
			SELECT user_id, SUM(points) AS total
			  FROM {$wpdb->prefix}wb_gam_points p
			 WHERE 1=1 {$where}
			 GROUP BY p.user_id
			HAVING total > %d
		) ranked";
		// phpcs:enable

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore
	}

	/**
	 * Find the lowest points total strictly above $threshold (the next rank's score).
	 * Returns null if $threshold is already at the top.
	 *
	 * @param int         $threshold    Points total to compare against.
	 * @param string|null $period_start MySQL datetime for period start, or null for all-time.
	 * @param string      $excl_sql     Exclusion SQL fragment (bounded placeholders).
	 * @param array       $excl_values  Values bound by that fragment, in order.
	 * @param int[]       $scope_ids    User IDs to restrict to (empty = all users).
	 * @return int|null The next threshold, or null if already at the top.
	 */
	private static function get_next_threshold(
		int $threshold,
		?string $period_start,
		string $excl_sql,
		array $excl_values,
		array $scope_ids,
		string $point_type = 'points'
	): ?int {
		global $wpdb;

		$values = array( $point_type );
		$where  = ' AND p.point_type = %s';

		if ( $period_start ) {
			$where   .= ' AND p.created_at >= %s';
			$values[] = $period_start;
		}
		if ( '' !== $excl_sql ) {
			$where .= $excl_sql;
			$values = array_merge( $values, $excl_values );
		}
		if ( ! empty( $scope_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where .= " AND p.user_id IN ($ph)";
			$values = array_merge( $values, $scope_ids );
		}
		$values[] = $threshold;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT MIN(total) FROM (
			SELECT user_id, SUM(points) AS total
			  FROM {$wpdb->prefix}wb_gam_points p
			 WHERE 1=1 {$where}
			 GROUP BY p.user_id
			HAVING total > %d
		) ranked";
		// phpcs:enable

		$result = $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore
		return null !== $result ? (int) $result : null;
	}
}
