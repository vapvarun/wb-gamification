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

/**
 * Generates leaderboard data from the points ledger with opt-out filtering and period scoping.
 *
 * @package WB_Gamification
 */
final class LeaderboardEngine {

	/**
	 * Initialize cron hooks and custom schedule interval.
	 *
	 * Called from plugins_loaded via FeatureFlags or directly.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register the custom five-minute cron interval.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

		// Schedule the snapshot cron if not already scheduled.
		if ( ! wp_next_scheduled( 'wb_gam_leaderboard_snapshot' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'wb_gam_leaderboard_snapshot' );
		}

		// Hook the snapshot writer to the cron event.
		add_action( 'wb_gam_leaderboard_snapshot', array( __CLASS__, 'write_snapshot' ) );
	}

	/**
	 * Register the five-minute cron interval.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing cron schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			$schedules['five_minutes'] = array(
				'interval' => 300,
				'display'  => esc_html__( 'Every 5 Minutes', 'wb-gamification' ),
			);
		}
		return $schedules;
	}

	/**
	 * Activation hook — schedule the leaderboard snapshot cron.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Register the schedule first so wp_schedule_event can find it.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

		if ( ! wp_next_scheduled( 'wb_gam_leaderboard_snapshot' ) ) {
			wp_schedule_event( time(), 'five_minutes', 'wb_gam_leaderboard_snapshot' );
		}
	}

	/**
	 * Deactivation hook — clear the leaderboard snapshot cron.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'wb_gam_leaderboard_snapshot' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wb_gam_leaderboard_snapshot' );
		}
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
		$cache_key = sprintf(
			'wb_gam_lb_%s_%d_%s_%d_%s',
			$period,
			$limit,
			$scope_type ? $scope_type : 'global',
			$scope_id,
			$resolved_type
		);
		$cached    = wp_cache_get( $cache_key, 'wb_gamification' );
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
		$period_start = self::get_period_start( $period );
		$opt_out_ids  = self::get_opted_out_ids();
		$scope_ids    = self::resolve_scope( $scope_type, $scope_id );

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

		if ( ! empty( $opt_out_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			$where_values = array_merge( $where_values, $opt_out_ids );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$opt_out_clause = "AND p.user_id NOT IN ($placeholders)";
		} else {
			$opt_out_clause = '';
		}

		if ( ! empty( $scope_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$scope_clause = "AND p.user_id IN ($placeholders)";
			$where_values = array_merge( $where_values, $scope_ids );
		} else {
			$scope_clause = '';
		}

		$where_clause = ! empty( $where_parts )
			? 'WHERE ' . implode( ' AND ', $where_parts )
			: '';

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

		$where_values[] = $limit;

		$rows = ! empty( $where_values )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			? $wpdb->get_results( $wpdb->prepare( $query, $where_values ), ARRAY_A )
			: $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore

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
		$cache_key = sprintf(
			'wb_gam_rank_%d_%s_%s_%d_%s',
			$user_id,
			$period,
			$scope_type ? $scope_type : 'global',
			$scope_id,
			$resolved_type
		);
		$cached    = wp_cache_get( $cache_key, 'wb_gamification' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$period_start = self::get_period_start( $period );
		$opt_out_ids  = self::get_opted_out_ids();
		// Remove the current user from opt-outs so we can count them too.
		$opt_out_ids = array_filter( $opt_out_ids, fn( $id ) => $id !== $user_id );
		$scope_ids   = self::resolve_scope( $scope_type, $scope_id );

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

		// Count users with strictly more points (their count + 1 = our rank).
		$above_rank = self::count_users_above( $user_total, $period_start, $opt_out_ids, $scope_ids, $resolved_type );

		// Find the lowest total above ours to calculate gap.
		$next_total = self::get_next_threshold( $user_total, $period_start, $opt_out_ids, $scope_ids, $resolved_type );

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

		// Truncate existing snapshot data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$cache_table}" );

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

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$cache_table} (user_id, period, point_type, total_points, `rank`, updated_at)
					 SELECT user_id, %s AS period, %s AS point_type, SUM(points) AS total_points,
					        RANK() OVER (ORDER BY SUM(points) DESC) AS `rank`,
					        NOW() AS updated_at
					   FROM {$points_table}
					   {$where}
					  GROUP BY user_id
					  ORDER BY total_points DESC
					  LIMIT 500",
					$period_key,
					$slug
				) );
				// phpcs:enable
			}
		}
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

		$cache_table = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		$opt_out_ids = self::get_opted_out_ids();

		// Check snapshot freshness — must be less than 10 minutes old.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$snapshot_age = $wpdb->get_var( "SELECT TIMESTAMPDIFF(MINUTE, MAX(updated_at), NOW()) FROM {$cache_table}" );

		if ( null === $snapshot_age || (int) $snapshot_age >= 10 ) {
			return null;
		}

		$period_key = in_array( $period, array( 'all', 'month', 'week', 'day' ), true ) ? $period : 'all';

		// Build opt-out exclusion for snapshot read.
		$opt_out_clause = '';
		$query_values   = array( $period_key, $point_type );

		if ( ! empty( $opt_out_ids ) ) {
			$placeholders   = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			$opt_out_clause = "AND c.user_id NOT IN ($placeholders)";
			$query_values   = array_merge( $query_values, $opt_out_ids );
		}

		$query_values[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.user_id, c.total_points, u.display_name
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
			$user_id  = (int) $row['user_id'];
			$result[] = array(
				'rank'         => $rank_zero + 1,
				'user_id'      => $user_id,
				'display_name' => $row['display_name'],
				'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 48 ) ),
				'points'       => (int) $row['total_points'],
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
	 * Return all user IDs that have opted out of the public leaderboard.
	 *
	 * @return int[]
	 */
	private static function get_opted_out_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->prefix}wb_gam_member_prefs WHERE leaderboard_opt_out = 1"
		);
		return array_map( 'intval', $ids ?: array() );
	}

	/**
	 * Count users with a points total strictly higher than $threshold.
	 *
	 * @param int         $threshold    Points total to compare against.
	 * @param string|null $period_start MySQL datetime for period start, or null for all-time.
	 * @param int[]       $opt_out_ids  User IDs excluded from the leaderboard.
	 * @param int[]       $scope_ids    User IDs to restrict to (empty = all users).
	 * @return int Number of users ranked above the threshold.
	 */
	private static function count_users_above(
		int $threshold,
		?string $period_start,
		array $opt_out_ids,
		array $scope_ids,
		string $point_type = 'points'
	): int {
		global $wpdb;

		// Always scope by point_type so multi-currency rank counts match the
		// public leaderboard which also filters per-currency.
		$values   = array( $point_type );
		$where    = ' AND p.point_type = %s';

		if ( $period_start ) {
			$where   .= ' AND p.created_at >= %s';
			$values[] = $period_start;
		}
		if ( ! empty( $opt_out_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where .= " AND p.user_id NOT IN ($ph)";
			$values = array_merge( $values, $opt_out_ids );
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
	 * @param int[]       $opt_out_ids  User IDs excluded from the leaderboard.
	 * @param int[]       $scope_ids    User IDs to restrict to (empty = all users).
	 * @return int|null The next threshold, or null if already at the top.
	 */
	private static function get_next_threshold(
		int $threshold,
		?string $period_start,
		array $opt_out_ids,
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
		if ( ! empty( $opt_out_ids ) ) {
			$ph = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$where .= " AND p.user_id NOT IN ($ph)";
			$values = array_merge( $values, $opt_out_ids );
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
