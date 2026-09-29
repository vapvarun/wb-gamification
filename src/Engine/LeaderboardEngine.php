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
	 * Extra candidate rows fetched inside the derived table, so orphans cannot shorten a board.
	 *
	 * A totals row whose member has been deleted is still picked by the inner LIMIT and then dropped
	 * by the JOIN to wp_users -- so without this, a board asked for 10 quietly renders 8, and the
	 * members who should have been 9th and 10th never appear.
	 *
	 * 25 is chosen against the real failure: orphans are transient (deleted_user purges a member's
	 * totals now, and `wp wb-gamification member purge-orphans` clears the historical ones), so the
	 * buffer only has to absorb the handful that can accumulate between a deletion and a cleanup. It
	 * is not a substitute for having no orphans; it is what stops a board lying while some exist.
	 *
	 * @var int
	 */
	private const ORPHAN_OVERFETCH = 25;

	/**
	 * How many ranked members the snapshot keeps per period and currency, and so how deep a
	 * day, week or month board can be paged. The writer and the pager read this one number.
	 *
	 * The all-time board is not bound by it: it pages the materialised totals table by keyset.
	 *
	 * @var int
	 */
	public const SNAPSHOT_DEPTH = 500;

	/**
	 * Cursor format version. A cursor with another version is rejected, never guessed at.
	 *
	 * @var int
	 */
	private const CURSOR_VERSION = 1;

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

		$rows   = self::fetch_raw( $period, $limit, $scope_type, $scope_id, $resolved_type, null );
		$result = $rows ? self::hydrate_rows( $rows ) : array();

		// Store in object cache with 2-minute TTL.
		wp_cache_set( $cache_key, $result, 'wb_gamification', 120 );

		return $result;
	}

	/**
	 * One page of a board, plus what a pager needs: has_more, the cursor for the next page, how many
	 * members precede this page, and the board's total.
	 *
	 * Paging is FORWARD-ONLY by keyset. There is deliberately no page number or offset input: random
	 * access into a 100k-member board is the O(offset) read this exists to avoid. The cursor carries the
	 * last member's points and id (where the next page starts) and the rank state (how many were served
	 * and the last rank), so ranks stay absolute and tied members keep the same rank across a boundary.
	 *
	 * Day, week and month boards end at SNAPSHOT_DEPTH ranks (their aggregate is O(window) per page,
	 * the all-time board is not bounded and reads the indexed totals table).
	 *
	 * A cursor that is malformed, from another version, or from another board is not guessed at: the
	 * result is an empty page flagged `invalid_cursor`.
	 *
	 * @param string $period     Period: 'all' | 'month' | 'week' | 'day'.
	 * @param int    $limit      Page size (1-100).
	 * @param string $scope_type Scope type, empty = site-wide.
	 * @param int    $scope_id   Scope id.
	 * @param string $point_type Currency slug, empty = primary.
	 * @param string $cursor     Opaque cursor from a previous page's `next_cursor`, empty = first page.
	 * @return array{rows: array<int, array<string, mixed>>, has_more: bool, next_cursor: string, offset: int, total: int, invalid_cursor?: bool}
	 * @since 1.6.5
	 */
	public static function get_leaderboard_page(
		string $period = 'all',
		int $limit = 25,
		string $scope_type = '',
		int $scope_id = 0,
		string $point_type = '',
		string $cursor = ''
	): array {
		$limit = max( 1, min( 100, $limit ) );
		$type  = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );
		$board = self::board_hash( $period, $scope_type, $scope_id, $type );
		$empty = array(
			'rows'        => array(),
			'has_more'    => false,
			'next_cursor' => '',
			'offset'      => 0,
			'total'       => self::get_total( $period, $scope_type, $scope_id, $type ),
		);

		$state = null;
		if ( '' !== $cursor ) {
			$state = self::decode_cursor( $cursor, $board );
			if ( null === $state ) {
				return array_merge( $empty, array( 'invalid_cursor' => true ) );
			}
		}

		$served    = $limit;
		$lookahead = true;
		if ( null !== self::get_period_start( $period ) ) {
			$room = self::SNAPSHOT_DEPTH - ( null !== $state ? $state['n'] : 0 );
			if ( $room <= 0 ) {
				return array_merge( $empty, array( 'offset' => null !== $state ? $state['n'] : 0 ) );
			}
			if ( $limit >= $room ) {
				$served    = $room;
				$lookahead = false; // The board ends at its depth: no next page to look for.
			}
		}

		$raw      = self::fetch_raw( $period, $served + ( $lookahead ? 1 : 0 ), $scope_type, $scope_id, $type, $state, true );
		$has_more = count( $raw ) > $served;
		$raw      = array_slice( $raw, 0, $served );

		$next = '';
		if ( $has_more && $raw ) {
			[ $seen, $last_points, $last_rank ] = self::rank_state( $raw, $state );
			$last_row                           = end( $raw );
			$next                               = self::encode_cursor( $board, (int) $last_points, (int) $last_row['user_id'], $seen, $last_rank );
		}

		return array(
			'rows'        => $raw ? self::hydrate_rows( $raw, $state ) : array(),
			'has_more'    => '' !== $next,
			'next_cursor' => $next,
			'offset'      => null !== $state ? $state['n'] : 0,
			'total'       => $empty['total'],
		);
	}

	/**
	 * How many members a board holds: the same eligibility as the rows, so a pager's "page X of Y"
	 * cannot promise a member the board would never show.
	 *
	 * Cached for five minutes and never invalidated per award (a count that changed on every award
	 * would be recomputed constantly, and a pager does not need to be exact to the second).
	 * Day, week and month boards count at most SNAPSHOT_DEPTH, the depth they can be paged to.
	 *
	 * @param string $period     Period: 'all' | 'month' | 'week' | 'day'.
	 * @param string $scope_type Scope type, empty = site-wide.
	 * @param int    $scope_id   Scope id.
	 * @param string $point_type Currency slug, empty = primary.
	 * @return int
	 * @since 1.6.5
	 */
	public static function get_total(
		string $period = 'all',
		string $scope_type = '',
		int $scope_id = 0,
		string $point_type = ''
	): int {
		global $wpdb;

		$type      = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );
		$cache_key = sprintf( 'wb_gam_lbt_%s', self::board_hash( $period, $scope_type, $scope_id, $type ) );
		$cached    = wp_cache_get( $cache_key, 'wb_gamification' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$scope_ids = self::resolve_scope( $scope_type, $scope_id );
		if ( '' !== $scope_type && $scope_id > 0 && empty( $scope_ids ) ) {
			$total = 0; // A scope that resolves to nobody holds nobody.
		} else {
			$period_start = self::get_period_start( $period );

			if ( null === $period_start ) {
				[ $excl_clause, $excl_values ] = self::exclusion_sql( 'ut' );
				$balance                       = self::positive_balance_sql( 'ut.earned' );
				$scope_clause                  = empty( $scope_ids )
					? ''
					: 'AND ut.user_id IN (' . implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) ) . ')';

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_totals ut WHERE ut.point_type = %s AND {$balance} {$excl_clause} {$scope_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						array_merge( array( $type ), $excl_values, $scope_ids )
					)
				);
			} else {
				// A period board's total must come from wherever the walk's OWN first page would come
				// from, or the two can disagree: the walk reads the snapshot (fresh for up to
				// wb_gam_leaderboard_max_snapshot_age) while this used to count the live ledger on its
				// own, unrelated 300s cache -- two independently-aging answers, each correct on its own
				// terms, free to name a different number of members (Basecamp 10304072907: walk served
				// 15, total said 16, after a snapshot rebuild and this cache fell out of step with it).
				// Scoped boards (BP group, cohort) never read the snapshot at all -- see fetch_raw() --
				// so this only tries it for the same global boards the walk does.
				$total = ( '' === $scope_type && 0 === $scope_id )
					? self::count_from_snapshot( $period, $type )
					: null;
				if ( null === $total ) {
					[ $excl_clause, $excl_values ] = self::exclusion_sql( 'p' );
					$total                         = self::count_users_above( 0, $period_start, $excl_clause, $excl_values, $scope_ids, $type );
				}
				$total = min( self::SNAPSHOT_DEPTH, $total );
			}
		}

		// ponytail: an O(members) count, cached 300s in the object cache and recomputed per request on
		// a host without a persistent one (already a hosting requirement above 10k members). If that
		// ever matters, keep the count in a transient or a maintained counter row instead.
		wp_cache_set( $cache_key, $total, 'wb_gamification', 300 ); // Aggregate, TTL-only by design.
		return $total;
	}

	/**
	 * Identify a board, so a cursor can only ever be replayed on the board it came from.
	 *
	 * @param string $period     Period key (an unknown one is the all-time board, as everywhere else).
	 * @param string $scope_type Scope type.
	 * @param int    $scope_id   Scope id.
	 * @param string $type       Resolved currency slug.
	 * @return string Short stable hash.
	 */
	private static function board_hash( string $period, string $scope_type, int $scope_id, string $type ): string {
		$period = in_array( $period, array( 'month', 'week', 'day' ), true ) ? $period : 'all';
		return substr( md5( implode( '|', array( $period, $scope_type, $scope_id, $type ) ) ), 0, 8 );
	}

	/**
	 * Encode the position after a page: last member's points and id, members served, last rank.
	 *
	 * @param string $board  Board hash.
	 * @param int    $points Last member's points.
	 * @param int    $user   Last member's id.
	 * @param int    $served Members served so far, including this page.
	 * @param int    $rank   Last member's competition rank.
	 * @return string URL-safe opaque token.
	 *
	 * @internal Public so the scale benchmark can build a cursor deep in a board without walking to it.
	 */
	public static function encode_cursor( string $board, int $points, int $user, int $served, int $rank ): string {
		$json = (string) wp_json_encode(
			array(
				'v' => self::CURSOR_VERSION,
				'b' => $board,
				'p' => $points,
				'u' => $user,
				'n' => $served,
				'r' => $rank,
			)
		);
		// An opaque URL-safe token for a page position, not obfuscation of code.
		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode and validate a cursor for a board.
	 *
	 * @internal Public so the REST layer and the unit tests can validate without a database.
	 *
	 * @param string $cursor Token from encode_cursor().
	 * @param string $board  Board hash the caller is paging.
	 * @return array{p: int, u: int, n: int, r: int}|null Null when malformed, another version, or another board.
	 */
	public static function decode_cursor( string $cursor, string $board ): ?array {
		if ( '' === $cursor || strlen( $cursor ) > 200 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $cursor ) ) {
			return null;
		}
		$json = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own page token, strictly.
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ( $data['v'] ?? null ) !== self::CURSOR_VERSION || ( $data['b'] ?? null ) !== $board ) {
			return null;
		}
		foreach ( array( 'p', 'u', 'n', 'r' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_int( $data[ $key ] ) || $data[ $key ] < 0 ) {
				return null;
			}
		}
		if ( $data['u'] < 1 || $data['n'] < 1 || $data['r'] < 1 || $data['r'] > $data['n'] ) {
			return null;
		}
		return array(
			'p' => $data['p'],
			'u' => $data['u'],
			'n' => $data['n'],
			'r' => $data['r'],
		);
	}

	/**
	 * Board hash for the REST layer, which has to decode a cursor before it calls the engine.
	 *
	 * @internal
	 *
	 * @param string $period     Period key.
	 * @param string $scope_type Scope type.
	 * @param int    $scope_id   Scope id.
	 * @param string $point_type Currency slug, empty = primary.
	 * @return string
	 */
	public static function board_key( string $period, string $scope_type, int $scope_id, string $point_type ): string {
		$type = ( new \WBGam\Services\PointTypeService() )->resolve( $point_type ?: null );
		return self::board_hash( $period, $scope_type, $scope_id, $type );
	}

	/**
	 * Where the ranking stands after a run of rows: members served, the last row's points, its rank.
	 * The same competition-rank rule as hydrate_rows(), on RAW rows, so a cursor is never built from
	 * rows a results filter reshaped.
	 *
	 * @param array<int, array<string, mixed>>           $rows Raw rows.
	 * @param array{p: int, u: int, n: int, r: int}|null $seed Where the previous page ended.
	 * @return array{0: int, 1: int, 2: int} [ served, last points, last rank ].
	 */
	private static function rank_state( array $rows, ?array $seed ): array {
		$rank = null !== $seed ? $seed['r'] : 0;
		$seen = null !== $seed ? $seed['n'] : 0;
		$prev = null !== $seed ? $seed['p'] : null;

		foreach ( $rows as $row ) {
			++$seen;
			$points = (int) $row['total_points'];
			if ( null === $prev || $points !== $prev ) {
				$rank = $seen;
				$prev = $points;
			}
		}

		return array( $seen, (int) $prev, $rank );
	}

	/**
	 * The unhydrated board rows: the one place the three read paths live.
	 *
	 * Every caller hydrates once, after this, so ranks, avatars and the results filter are computed
	 * in one spot. With a cursor the page starts strictly after that member (points DESC, user_id
	 * DESC), and NEVER through OFFSET: OFFSET is O(rows skipped) and the all-time path has to skip
	 * orphaned members, for which a raw OFFSET is also wrong.
	 *
	 * @param string                                     $period        Period key.
	 * @param int                                        $limit         Rows wanted, not clamped here.
	 * @param string                                     $scope_type    Scope type, empty = site-wide.
	 * @param int                                        $scope_id      Scope id.
	 * @param string                                     $resolved_type Resolved currency slug.
	 * @param array{p: int, u: int, n: int, r: int}|null $cursor        Decoded cursor, or null for the first page.
	 * @param bool                                       $paged         True when the rows are one page of a paged walk.
	 * @return array<int, array<string, mixed>> Raw rows: user_id, total_points, display_name (and snapshot ranks).
	 */
	private static function fetch_raw(
		string $period,
		int $limit,
		string $scope_type,
		int $scope_id,
		string $resolved_type,
		?array $cursor,
		bool $paged = false
	): array {
		global $wpdb;

		$period_start = self::get_period_start( $period );

		// ── Try snapshot table for global scopes ──────────────────────────────
		// Snapshot now covers EVERY active currency (Phase 3b) — only scoped
		// requests (BP groups, cohorts) still fall through to the live query.
		// A PAGED walk of the ALL-TIME board never uses it, on any page: the snapshot is only
		// SNAPSHOT_DEPTH deep, and it ranks on the ledger sum while the totals table ranks on `earned`.
		// If page one came from one and page two from the other, a member the two disagree about could
		// be skipped or served twice at the boundary. One source for every page of a walk.
		if ( '' === $scope_type && 0 === $scope_id && ( $period_start || ! $paged ) ) {
			$snapshot_rows = self::read_from_snapshot( $period, $limit, $resolved_type, $cursor );
			if ( null !== $snapshot_rows ) {
				return $snapshot_rows;
			}
		}

		// ── Full query fallback ───────────────────────────────────────────────
		// true = existence is enforced by this query's own JOIN to wp_users.
		[ $opt_out_clause, $opt_out_values ] = self::exclusion_sql( 'p', true );

		// The FOURTH predicate. Every path that ranks members must ask it, and ask it the same way.
		$balance_sum = self::positive_balance_sql( 'SUM(p.points)' );
		$scope_ids   = self::resolve_scope( $scope_type, $scope_id );

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
			return array();
		}

		// Build WHERE clause.
		$where_parts  = array();
		$where_values = array();

		// Always scope by point_type so per-currency leaderboards work even
		// without the cache table being keyed by type yet (Phase 3b).
		// Rank by points EARNED: a spend (reward, currency exchange) never costs a member their place.
		$where_parts[]  = 'p.point_type = %s AND p.is_spend = 0';
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
		$cursor_binds = array();

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
			// true = existence is checked in PHP by totals_board(); an EXISTS here wrecks the plan.
			[ $totals_excl_clause, $totals_excl_values ] = self::exclusion_sql( 'ut', true );

			$totals_scope_clause = empty( $scope_ids )
				? ''
				: 'AND ut.user_id IN (' . implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) ) . ')';

			// Orphaned totals rows -- a member deleted, their totals row left behind -- rank like
			// anybody else, so they win slots in the top-N and then vanish when the users JOIN drops
			// them. A board asked for 10 rendered 8.
			//
			// The first fix was a fixed over-fetch: take limit + 25 candidates and trim after the join.
			// QA was right to fail it. A constant cushion cannot survive a variable it does not know:
			// this database carries 174 orphans, so the cushion was exceeded at every size that mattered
			// (limit=25 -> 17 rows, limit=50 -> 20, limit=100 -> 50), and the original repro only passed
			// by luck of where the orphans happened to rank. `limit` is a public REST parameter. A fix
			// that holds at one value of it and not the next is not a fix.
			//
			// So: no cushion, no guess. Take a slice of candidates from the indexed totals table, ask
			// the users table which of them still exist, and if that leaves the board short, take the
			// next slice. It terminates when the board is full or the totals table runs out, and it is
			// correct for ANY number of orphans -- including a number nobody has measured yet.
			//
			// Why not EXISTS against wp_users inside the derived table, which would need no loop at all:
			// EXPLAIN says it hands the optimiser the wp_users-driven plan this derived table exists to
			// avoid (type=index on wp_users, Using temporary, Using filesort). Correct board, O(members)
			// plan. Tried it first; reverted it.
			//
			// Cost in the normal case is one extra primary-key lookup: deleted_user purges a member's
			// rows now (MemberData::on_user_deleted), so on a site with no orphan backlog the first
			// slice fills the board and the loop runs exactly once.
			return self::totals_board(
				$wpdb->prefix . 'wb_gam_user_totals',
				$resolved_type,
				$totals_excl_clause,
				$totals_excl_values,
				$totals_scope_clause,
				$scope_ids,
				$limit,
				$cursor
			);
		} else {
			// The ledger board is keyset-paged the same way: strictly after (points, user_id).
			$having_cursor = '';
			if ( null !== $cursor ) {
				$having_cursor = ' AND ( SUM(p.points) < %d OR ( SUM(p.points) = %d AND p.user_id < %d ) )';
				$cursor_binds  = array( $cursor['p'], $cursor['p'], $cursor['u'] );
			}

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
				HAVING {$balance_sum}{$having_cursor}
				 ORDER BY total_points DESC, p.user_id DESC
				 LIMIT %d
			";
			// phpcs:enable
		}

		// The cursor binds sit between the WHERE binds and the LIMIT, matching the SQL text.
		$where_values   = array_merge( $where_values, $cursor_binds );
		$where_values[] = $limit;

		// $where_values always carries the point_type bind plus the LIMIT
		// appended above, so the query is always prepared.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $where_values ), ARRAY_A );

		return $rows ? $rows : array();
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
				 WHERE user_id = %d AND point_type = %s AND is_spend = 0 AND created_at >= %s",
				$user_id,
				$resolved_type,
				$period_start
			);
		} else {
			$user_total_sql = $wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
				 WHERE user_id = %d AND point_type = %s AND is_spend = 0",
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
		// One UTC stamp from PHP for every row of this rebuild, bound into the INSERT and the
		// straggler DELETE alike, so the prune can never disagree with the rows it just wrote.
		$started = current_time( 'mysql', true );

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
				// The table is aliased `p` so it can take the SAME eligibility fragment every other
				// path takes. It could not before, which is how it ended up with the existence half and
				// not the exclusion half: a member who had opted out was still written into the
				// snapshot, and RANK() still counted them, so snapshot_standing() served a rank the
				// fallback disagreed with. One opt-out was enough to make the two paths differ for 153
				// of 154 members.
				$where = $wpdb->prepare( 'WHERE p.point_type = %s AND p.is_spend = 0', $slug );
				if ( null !== $period_start ) {
					$where .= $wpdb->prepare( ' AND p.created_at >= %s', $period_start );
				}

				// Existence + opt-out + owner-excluded users/roles, in one fragment, from the one place
				// that knows what "eligible" means. No second argument: this query has no JOIN to
				// wp_users, so it wants the existence check too.
				[ $snap_excl_sql, $snap_excl_values ] = self::exclusion_sql( 'p' );

				// The FOURTH predicate -- the one the last fix left behind. Without it the writer stored
				// zero-balance members (rank 155, 0 points) that totals_board drops, so a member who spent
				// their points in the rewards store was ON the warm board and OFF the stale one.
				$balance_sum = self::positive_balance_sql( 'SUM(p.points)' );
				$where      .= $snap_excl_values
					? $wpdb->prepare( $snap_excl_sql, $snap_excl_values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					: $snap_excl_sql;

				// UPSERT — insert new rows, update existing rows in place.
				// The UNIQUE KEY (user_id, period, point_type) on the cache
				// table is what makes ON DUPLICATE KEY UPDATE work; it was
				// added by DbUpgrader::ensure_leaderboard_cache_unique_key.
				// updated_at is $started (UTC), the same value the straggler DELETE prunes against.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// The existence check belongs HERE, in the WRITER -- and this is the default path.
				//
				// The last fix put it in the fallback and never followed it up one layer. This query has
				// the identical bug: the top 500 is chosen from the ledger with no idea whether those
				// members still exist, and read_from_snapshot() joins wp_users and drops the orphans
				// afterwards -- so a board asked for 25 rendered 15. It is hidden on this site only
				// because the snapshot holds fewer rows than the 500 cap; any site with more than 500
				// point-earning members sits on that cap by definition.
				//
				// RANK() counted the ghosts too, so snapshot_standing() served a rank with more people in
				// front of it than the community has -- the exact sentence from the last commit, still
				// true on the path members actually hit. And because the fallback had stopped counting
				// them, a member's rank FLIPPED every time the cron rebuilt. Two paths wrong-but-consistent
				// was less harmful than one path right; now both are right.
				//
				// The eligibility fragment is in $where (see above) and carries all three predicates. It
				// is safe in this query -- unlike inside the totals derived table, where an EXISTS wrecks
				// the plan -- because this one already aggregates the whole ledger and the checks are
				// eq_ref on primary keys.
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$cache_table} (user_id, period, point_type, total_points, `rank`, updated_at)
					 SELECT p.user_id, %s AS period, %s AS point_type, SUM(p.points) AS total_points,
					        RANK() OVER (ORDER BY SUM(p.points) DESC) AS `rank`,
					        %s AS updated_at
					   FROM {$points_table} p
					   {$where}
					  GROUP BY p.user_id
					 HAVING {$balance_sum}
					  ORDER BY total_points DESC, p.user_id DESC
					  LIMIT %d
					ON DUPLICATE KEY UPDATE
					   prev_rank    = `rank`,
					   total_points = VALUES(total_points),
					   `rank`       = VALUES(`rank`),
					   updated_at   = VALUES(updated_at)",
						$period_key,
						$slug,
						$started,
						self::SNAPSHOT_DEPTH
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
	 * @param string                                     $period     Period key: 'all', 'month', 'week', 'day'.
	 * @param int                                        $limit      Maximum rows to return.
	 * @param string                                     $point_type Resolved currency slug.
	 * @param array{p: int, u: int, n: int, r: int}|null $cursor     Start strictly after this member, or null.
	 * @return array<int, array<string, mixed>>|null Raw rows (the caller hydrates), or null when the snapshot is stale or empty.
	 */
	private static function read_from_snapshot( string $period, int $limit, string $point_type = 'points', ?array $cursor = null ): ?array {
		global $wpdb;

		$cache_table = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		// true = existence is enforced by this query's own JOIN to wp_users.
		[ $excl_sql, $excl_values ] = self::exclusion_sql( 'c', true );

		if ( null === self::snapshot_freshness() ) {
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

		// A page after the first starts strictly after (points, user_id), in the same order the board
		// is ranked: points DESC, user_id DESC. The snapshot holds at most SNAPSHOT_DEPTH rows per
		// partition, so ordering by points here is a small sort, not a scale hazard.
		$cursor_clause = '';
		$order_by      = 'c.`rank` ASC, c.user_id DESC';
		if ( null !== $cursor ) {
			$cursor_clause  = ' AND ( c.total_points < %d OR ( c.total_points = %d AND c.user_id < %d ) )';
			$order_by       = 'c.total_points DESC, c.user_id DESC';
			$query_values[] = $cursor['p'];
			$query_values[] = $cursor['p'];
			$query_values[] = $cursor['u'];
		}

		$query_values[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.user_id, c.total_points, u.display_name, c.`rank` AS snapshot_rank, c.prev_rank
				   FROM {$cache_table} c
				   JOIN {$wpdb->users} u ON u.ID = c.user_id
				  WHERE c.period = %s AND c.point_type = %s {$opt_out_clause}{$cursor_clause}
				  -- Deterministic, and that costs a filesort. Measured: ORDER BY `rank` alone is
				  -- index-ordered (idx_type_period_rank, no filesort), and ANY tiebreaker introduces one,
				  -- because user_id is not in that index. But RANK() gives tied members the SAME rank, so
				  -- ordering by rank alone leaves LIMIT n to pick arbitrarily among the eleven members who
				  -- all hold 91 points -- while the fallback breaks the tie deterministically. That is the
				  -- membership flip this card is about, and no plan is worth reintroducing it.
				  --
				  -- The sort is over the snapshot, which the writer caps at 500 rows (295 here). A filesort
				  -- over a few hundred cached rows is not a scale hazard; a board whose membership changes
				  -- when the cron runs is.
				  --
				  -- `rank` ASC is the same ordering as total_points DESC (RANK() is derived from it), so
				  -- this matches the fallback exactly while keeping the index leading.
				  ORDER BY {$order_by}
				  LIMIT %d",
				$query_values
			),
			ARRAY_A
		);
		// phpcs:enable

		// An empty page is a real answer for a cursor (the end of the board). Only a first-page miss
		// means "no usable snapshot", and sends the caller to the live query.
		if ( ! $rows ) {
			return null !== $cursor ? array() : null;
		}

		return $rows;
	}

	/**
	 * Whether the snapshot table holds a usable (fresh enough) generation right now, and when.
	 *
	 * The ONE freshness rule every snapshot reader shares: {@see read_from_snapshot()} (the walk) and
	 * {@see count_from_snapshot()} (the total) would otherwise each judge staleness on their own, and
	 * a board whose walk reads one generation while its total reads another is exactly the class of
	 * bug this file keeps relearning (see exclusion_sql()) — two paths, each locally correct, free to
	 * disagree about how many members there are.
	 *
	 * @since 1.6.5
	 *
	 * @return int|null UNIX timestamp the current snapshot was built at, or null if there is none or
	 *                   it is older than `wb_gam_leaderboard_max_snapshot_age` (default 600s, floor 60s).
	 */
	private static function snapshot_freshness(): ?int {
		global $wpdb;

		$cache_table = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		// updated_at is UTC.
		$built_at = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$cache_table}" );
		if ( null === $built_at ) {
			return null;
		}
		$built_at = (int) strtotime( $built_at . ' UTC' );

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
		return ( time() - $built_at ) < max( 60, $max_age ) ? $built_at : null;
	}

	/**
	 * Member count for a global period board, read from the snapshot — the same generation of data
	 * the walk's own first page would read.
	 *
	 * Returns null under the exact conditions {@see read_from_snapshot()} would send its caller to
	 * the live ledger instead: no snapshot yet, or the current one is stale. A caller must fall back
	 * to {@see count_users_above()} in that case, the same way fetch_raw() falls back for the walk.
	 *
	 * @since 1.6.5
	 *
	 * @param string $period     'week'|'month'|'day' (an 'all' caller uses the totals table instead).
	 * @param string $point_type Resolved currency slug.
	 * @return int|null Member count on the current snapshot, or null if it is unusable.
	 */
	private static function count_from_snapshot( string $period, string $point_type ): ?int {
		if ( null === self::snapshot_freshness() ) {
			return null;
		}

		global $wpdb;

		$cache_table = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		// true = existence is enforced by this query's own JOIN to wp_users, matching read_from_snapshot().
		[ $excl_sql, $excl_values ] = self::exclusion_sql( 'c', true );
		$period_key                 = in_array( $period, array( 'all', 'month', 'week', 'day' ), true ) ? $period : 'all';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$cache_table} c
				   JOIN {$wpdb->users} u ON u.ID = c.user_id
				  WHERE c.period = %s AND c.point_type = %s {$excl_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $period_key, $point_type ), $excl_values )
			)
		);
	}

	/**
	 * Hydrate raw DB rows into the leaderboard result format.
	 *
	 * Adds rank, avatar_url, and properly types all fields. Uses cache_users()
	 * to eliminate N+1 avatar/user-meta queries.
	 *
	 * @param array<int, array{user_id: int|string, total_points: int|string|float, display_name: string}> $rows
	 *                    Rows to hydrate. The ledger path hands over raw DB rows (everything a string);
	 *                    totals_board() builds them itself and its ids are already int. Both are fine --
	 *                    this method casts what it needs -- but the docblock claimed only the first shape,
	 *                    which is how it described one caller instead of the contract.
	 * @param array{p: int, u: int, n: int, r: int}|null                                                   $seed Where a later page picks up: members served so far
	 *                                                                      (n), the last one's points (p) and rank (r). Ranks then continue from there, so a
	 *                                                                      page is numbered as it would be inside one long board.
	 * @return array<int, array{rank: int, user_id: int, display_name: string, avatar_url: string, points: int}>
	 */
	private static function hydrate_rows( array $rows, ?array $seed = null ): array {
		// Pre-cache all user objects to avoid N+1 queries in the avatar loop.
		$user_ids = array_column( $rows, 'user_id' );
		if ( ! empty( $user_ids ) ) {
			cache_users( array_map( 'intval', $user_ids ) );
		}

		$result = array();
		// COMPETITION rank, not the array offset.
		//
		// The board printed $rank_zero + 1 -- a position in a list -- while get_user_rank() returns
		// count-of-members-above + 1, which is a competition rank: tied members SHARE it. Eleven members
		// hold 91 points on this database, so the board numbered them 11, 12, 13 ... and the member's
		// own rank strip said #8 for every one of them. On the top-100 board, 79 of 100 rows showed a
		// different number in the two places.
		//
		// The comment on get_user_rank() says the page must not show two numbers for one metric. This is
		// that, for rank: the two surfaces have to compute it the same way, so the board computes it the
		// same way -- ties share a rank, and the next distinct score skips.
		$rank        = null !== $seed ? $seed['r'] : 0;
		$seen        = null !== $seed ? $seed['n'] : 0;
		$prev_points = null !== $seed ? $seed['p'] : null;

		foreach ( $rows as $rank_zero => $row ) {
			$user_id = (int) $row['user_id'];

			// Rank-change trend: only the snapshot path carries the stored current +
			// previous rank; the live-query fallback has no history, so both stay 0
			// (no arrow). prev_rank 0 = brand-new to the board this snapshot.
			$snapshot_rank = isset( $row['snapshot_rank'] ) ? (int) $row['snapshot_rank'] : 0;
			$prev_rank     = isset( $row['prev_rank'] ) ? (int) $row['prev_rank'] : 0;
			$rank_change   = ( $snapshot_rank > 0 && $prev_rank > 0 ) ? ( $prev_rank - $snapshot_rank ) : 0;

			++$seen;
			$points_now = (int) $row['total_points'];
			if ( null === $prev_points || $points_now !== $prev_points ) {
				$rank        = $seen;
				$prev_points = $points_now;
			}

			$result[] = array(
				'rank'         => $rank,
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
		// The SITE's day, week and month, as UTC instants for the UTC created_at column.
		switch ( $period ) {
			case 'day':
				return Clock::site_day_start( 'today' );
			case 'week':
				// Monday of the current ISO week, in the site's timezone.
				return Clock::site_cutoff( 'monday this week' );
			case 'month':
				return Clock::site_day_start( 'first day of this month' );
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

		// The one shared gate — see snapshot_freshness(). If the board would not serve the snapshot,
		// neither does this strip; a second copy of the same check is how the two came to disagree.
		if ( null === self::snapshot_freshness() ) {
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
	 * @param string $excl_clause   Exclusion fragment, built for the `ut` alias.
	 * @param string $scope_clause  Scope fragment, built for the `ut` alias.
	 * @param bool   $keyset        True to start strictly after a (earned, user_id) cursor: adds three
	 *                              placeholders (earned, earned, user_id) after the scope binds.
	 * @return string The SQL, with %s / %d placeholders for prepare().
	 */
	public static function build_totals_query(
		string $totals_table,
		string $excl_clause,
		string $scope_clause,
		bool $keyset = false
	): string {
		// Ranked by points earned (1.6.5): idx_type_earned is shaped for it as idx_type_total was.
		$balance = self::positive_balance_sql( 'ut.earned' );

		// The candidates, and ONLY the candidates: the top rows of the indexed totals table, with no
		// users join at all.
		//
		// Keeping wp_users out is the whole optimisation. Join it up front and the optimiser drives
		// from wp_users (eq_ref into totals), scanning every member and forcing a temporary + filesort,
		// because ORDER BY total can no longer use idx_type_total. EXPLAIN confirms it: `u: type=index
		// ... Using temporary; Using filesort`. Correct board, O(members) plan.
		//
		// It used to select the top-N in a derived table and JOIN wp_users around it, with the LIMIT
		// inside. That put the LIMIT before anything had checked those members still exist, so an
		// orphaned totals row (member deleted, totals row left behind) won a slot in the N and the JOIN
		// then dropped it -- a board asked for 10 rendered 8. Over-fetching a fixed cushion of extra
		// candidates only moved the cliff: with 174 orphans on the database, limit=100 still returned
		// 50 rows.
		//
		// Now the caller (totals_board) takes a slice of these candidates, asks wp_users which ones
		// still exist, and takes another slice if the board came up short. The existence check is a
		// primary-key lookup on a handful of ids, so it cannot drag the plan onto wp_users, and the
		// board is right for any number of orphans rather than for fewer than some constant.
		//
		// The user_id tiebreaker is what makes keyset paging safe: without it, two members on equal
		// totals have no defined order between one slice and the next, so a member can be served twice
		// or skipped entirely. There is no OFFSET here: it is O(rows skipped), and a page deep in a
		// 100k board would read every row before it. It is DESC, matching total, on purpose -- the primary key is
		// (user_id, point_type), so user_id rides along inside idx_type_total and a same-direction sort
		// is a backward index scan. Mixing directions (total DESC, user_id ASC) costs a filesort over
		// the whole index instead: EXPLAIN goes from `Backward index scan; Using index` to
		// `Using filesort`, which at 100k members is the very plan this query is shaped to avoid.
		$cursor_clause = $keyset
			? 'AND ( ut.earned < %d OR ( ut.earned = %d AND ut.user_id < %d ) )'
			: '';

		return "
			SELECT ut.user_id, ut.earned AS total_points
			  FROM {$totals_table} ut
			 WHERE ut.point_type = %s
			   AND {$balance}
			  {$excl_clause}
			  {$scope_clause}
			  {$cursor_clause}
		  ORDER BY ut.earned DESC, ut.user_id DESC
			 LIMIT %d
		";
	}

	/**
	 * The all-time board: candidates from the totals table, topped up until it is full.
	 *
	 * Loops only when orphaned totals rows ate slots. With no orphan backlog -- the normal state, since
	 * deleted_user purges a member's rows -- the first slice fills the board and this runs once.
	 *
	 * @param string                                     $totals_table Fully-qualified totals table.
	 * @param string                                     $point_type   Resolved currency slug.
	 * @param string                                     $excl_clause  Exclusion fragment, built for the `ut` alias.
	 * @param array<int, mixed>                          $excl_values  Binds for the exclusion fragment.
	 * @param string                                     $scope_clause Scope fragment, built for the `ut` alias.
	 * @param array<int, int>                            $scope_ids    Binds for the scope fragment.
	 * @param int                                        $limit        Rows the caller asked for.
	 * @param array{p: int, u: int, n: int, r: int}|null $cursor Start strictly after this member, or null.
	 * @return array<int, array<string, mixed>> Raw board rows (the caller hydrates).
	 */
	private static function totals_board(
		string $totals_table,
		string $point_type,
		string $excl_clause,
		array $excl_values,
		string $scope_clause,
		array $scope_ids,
		int $limit,
		?array $cursor = null
	): array {
		global $wpdb;

		// Slice a little wider than the board so the common case (a few orphans, or none) is satisfied
		// in one pass. This is a round-trip optimisation, NOT a correctness assumption -- unlike the
		// cushion it replaces, being wrong about it costs a second query, not a short board.
		//
		// And the slice GROWS. The first version walked a fixed slice up to 20 times, which put the
		// ceiling at (limit + 25) * 20 -- a function of $limit, so the SMALLEST boards failed first, and
		// they failed by going BLANK rather than short: with 700 orphans ranked above everyone, a board
		// of 5 examined 600 candidates, found nothing real, and rendered nothing at all. I had written
		// that the loop was "correct for ANY number of orphans"; it was correct for fewer than a number
		// I had not computed. Doubling makes the reach exponential rather than linear, so a bounded
		// number of round trips clears any orphan count the table can actually hold, and the loop exits
		// when the TABLE is exhausted rather than when a counter I picked runs out.
		$slice     = $limit + self::ORPHAN_OVERFETCH;
		$survivors = array();
		// The walk is a KEYSET: every slice starts strictly after the last candidate of the one before
		// (or after the caller's cursor). OFFSET would re-read everything it skips.
		$after = null !== $cursor ? array( $cursor['p'], $cursor['u'] ) : null;
		$found = 0;

		// A hard stop so a pathological table cannot spin. It exists to bound the QUERY COUNT, not to
		// bound how far we are willing to look: with the slice doubling each pass, 24 round trips reach
		// past any totals table MySQL will hold, so this can only be hit by a bug, never by data.
		$max_slices = 24;

		for ( $i = 0; $i < $max_slices && $found < $limit; $i++ ) {
			$sql   = self::build_totals_query( $totals_table, $excl_clause, $scope_clause, null !== $after );
			$binds = array_merge(
				array( $point_type ),
				$excl_values,
				$scope_ids,
				null !== $after ? array( $after[0], $after[0], $after[1] ) : array(),
				array( $slice )
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$candidates = $wpdb->get_results( $wpdb->prepare( $sql, $binds ), ARRAY_A );
			if ( ! $candidates ) {
				break;
			}

			$ids = array_map( 'intval', wp_list_pluck( $candidates, 'user_id' ) );

			// Which of them still exist? A primary-key IN() over at most $slice ids -- eq_ref, no scan.
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$real = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ids
				),
				ARRAY_A
			);

			$names = array();
			foreach ( (array) $real as $u ) {
				$names[ (int) $u['ID'] ] = (string) $u['display_name'];
			}

			// Rebuild in the candidates' order -- the totals table decided the ranking, not wp_users.
			foreach ( $candidates as $c ) {
				$uid = (int) $c['user_id'];
				if ( ! isset( $names[ $uid ] ) ) {
					continue; // Orphan: a totals row whose member is gone.
				}
				$survivors[] = array(
					'user_id'      => $uid,
					'total_points' => $c['total_points'],
					'display_name' => $names[ $uid ],
				);
				++$found;
				if ( $found >= $limit ) {
					break;
				}
			}

			// The totals table is exhausted; there is no next slice to take.
			if ( count( $candidates ) < $slice ) {
				break;
			}

			// Walk on, and reach further each time. A board whose top rows are all orphans is exactly
			// the case a fixed stride cannot escape.
			$last  = end( $candidates );
			$after = array( (int) $last['total_points'], (int) $last['user_id'] );
			$slice = min( $slice * 2, 5000 );
		}

		return $survivors;
	}

	/**
	 * The FOURTH eligibility predicate: a member needs a positive balance to be on a board.
	 *
	 * Three predicates were unified into exclusion_sql() -- exists, not opted out, not owner-excluded --
	 * and this one was left behind, answered differently by every path that asked:
	 *
	 *   totals_board          AND ut.earned > 0
	 *   ledger board          (nothing)
	 *   write_snapshot()      (nothing)
	 *   the doctor's oracle   HAVING SUM(p.points) > 0
	 *
	 * So a member who spends their points to zero in the rewards store -- an ordinary, supported thing
	 * to do -- was ON the warm board (the snapshot writer had no filter, so it stored them at 0 points)
	 * and OFF the stale one (totals_board drops them). Membership flipped on the cron tick, which is the
	 * bug this card was filed for, arriving through the one predicate the last fix did not unify. It
	 * also turned the doctor RED on a healthy site, because the oracle asked the question two of the
	 * three read paths were not asking.
	 *
	 * The answer is yes, a board is for people who have points: zero-balance members are not ranked.
	 * What matters far more than which answer is that there is only ONE, and it lives here.
	 *
	 * It cannot go in exclusion_sql() because it is an AGGREGATE, and each path aggregates differently
	 * (a materialised column, or a SUM). So this returns the comparison for whatever expression the
	 * caller aggregates with, and every caller uses it.
	 *
	 * @param string $expr The path's balance expression, e.g. `ut.earned` or `SUM(p.points)`.
	 * @return string SQL fragment, e.g. `ut.earned > 0`.
	 */
	private static function positive_balance_sql( string $expr ): string {
		return $expr . ' > 0';
	}

	private static function exclusion_sql( string $alias, bool $existence_enforced_elsewhere = false ): array {
		global $wpdb;

		$with_existence = ! $existence_enforced_elsewhere;

		// WHO IS ELIGIBLE FOR A BOARD is one question, and it now has one answer.
		//
		// It used to have three, handed out separately: a member must EXIST, must not have OPTED OUT,
		// and must not be an owner-EXCLUDED user or role. Every path that serves a board or a rank
		// needs all three -- and each path collected them from a different place, so each path was free
		// to have two of the three.
		//
		// That is the entire reason this bug has been fixed five times. Every round I added the
		// predicate the last bounce named to the path the last bounce named, and the next round found
		// the next path missing the next predicate. Most recently write_snapshot() got the existence
		// check and never got the exclusion check -- so its RANK() column was computed over members the
		// rest of the system excludes, snapshot_standing() served that rank verbatim, and one member
		// opting out made the warm and stale paths disagree for 153 of 154 members.
		//
		// A caller can no longer take one and forget the other, because there is only one to take.
		//
		// It is safe BY DEFAULT: say nothing and you get all three. $existence_enforced_elsewhere is an
		// opt-OUT, and a caller may only set it when its own query already guarantees the member exists:
		//
		// The ledger board and read_from_snapshot both JOIN wp_users, which enforces it. totals_board()
		// checks it in PHP, because an EXISTS inside that derived table hands the optimiser a
		// wp_users-driven filesort and destroys the plan the derived table exists to create
		// (EXPLAIN-verified, twice).
		//
		// Anything else -- including any path written next year -- gets the existence check whether its
		// author thought about it or not. That is the property that matters: the default is correct, and
		// being wrong requires an explicit argument.
		$sql    = '';
		$values = array();

		if ( $with_existence ) {
			$sql .= ' AND EXISTS ( SELECT 1 FROM ' . $wpdb->users . ' wu WHERE wu.ID = ' . $alias . '.user_id )';
		}

		// Members who opted out. An anti-join, not a list: whether there are five opt-outs or
		// fifty thousand, this fragment is the same length.
		$sql .= ' AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->prefix . 'wb_gam_member_prefs mp'
			. ' WHERE mp.user_id = ' . $alias . '.user_id AND mp.leaderboard_opt_out = 1 )';

		// Owner-excluded accounts (Settings > Access): explicit ids stay a short IN(), and
		// excluded ROLES become a predicate rather than an expanded id list.
		[ $owner_sql, $owner_values ] = PointsEngine::exclusion_sql( $alias );

		return array( $sql . $owner_sql, array_merge( $values, $owner_values ) );
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
		$where  = ' AND p.point_type = %s AND p.is_spend = 0';

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

		// Count only members who still EXIST. Without this, every member the site ever deleted still
		// stands ahead of you: the ledger keeps their rows, they group like anyone else, and they are
		// counted. On this database that meant a member was told "Your rank #215" on a board with 161
		// ranked members -- a rank with more people in front of it than the community has.
		//
		// Unlike the top-N board, an EXISTS here costs nothing to fear: that query is a LIMITed range
		// scan whose plan an EXISTS would wreck, but this one already aggregates the whole ledger, and
		// the check is eq_ref against the users primary key on each grouped row.
		//
		// (deleted_user purges a member's rows now, so no NEW ghosts appear -- but every site that
		// deleted a member before 1.6.4 is still carrying them, and their ranks are still wrong.)
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
		$where  = ' AND p.point_type = %s AND p.is_spend = 0';

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
		// Same existence check as count_users_above(), for the same reason: "3 points from the next
		// rank" must be 3 points from a rank held by somebody who is still here. Chasing a score set by
		// a member who was deleted is a target that never moves and never explains itself.
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
