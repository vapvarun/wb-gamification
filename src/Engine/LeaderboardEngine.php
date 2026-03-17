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
 * `wb_gamification_leaderboard_scope_user_ids` filter — BuddyPress integration
 * and third-party plugins hook in here to return the relevant user IDs.
 *
 * Opt-out: users with `leaderboard_opt_out = 1` in wb_gam_member_prefs are
 * never shown on the leaderboard (not even their rank shown to others).
 * They can still retrieve their own private rank.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class LeaderboardEngine {

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
		int $scope_id = 0
	): array {
		global $wpdb;

		$limit        = max( 1, min( 100, $limit ) );
		$period_start = self::get_period_start( $period );
		$opt_out_ids  = self::get_opted_out_ids();
		$scope_ids    = self::resolve_scope( $scope_type, $scope_id );

		// Build WHERE clause.
		$where_parts  = array();
		$where_values = array();

		if ( $period_start ) {
			$where_parts[]  = 'p.created_at >= %s';
			$where_values[] = $period_start;
		}

		if ( ! empty( $opt_out_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			$where_parts  = array_merge( $where_parts, array() );
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
			return array();
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

		return $result;
	}

	/**
	 * Get a user's private rank within a period.
	 *
	 * Returned even if the user has opted out of public leaderboard display —
	 * this is private data for the member themselves.
	 *
	 * @param int    $user_id  User to calculate rank for.
	 * @param string $period   Period: 'all' | 'month' | 'week' | 'day'.
	 * @param string $scope_type Optional scope type.
	 * @param int    $scope_id   Optional scope ID.
	 * @return array{rank: int, points: int, points_to_next: int|null}
	 */
	public static function get_user_rank(
		int $user_id,
		string $period = 'all',
		string $scope_type = '',
		int $scope_id = 0
	): array {
		global $wpdb;

		$period_start = self::get_period_start( $period );
		$opt_out_ids  = self::get_opted_out_ids();
		// Remove the current user from opt-outs so we can count them too.
		$opt_out_ids = array_filter( $opt_out_ids, fn( $id ) => $id !== $user_id );
		$scope_ids   = self::resolve_scope( $scope_type, $scope_id );

		// Get user's own total for the period.
		$user_total = (int) $wpdb->get_var(
			$period_start
				? $wpdb->prepare(
					"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
					 WHERE user_id = %d AND created_at >= %s",
					$user_id,
					$period_start
				)
				: $wpdb->prepare(
					"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
					 WHERE user_id = %d",
					$user_id
				)
		);

		// Count users with strictly more points (their count + 1 = our rank).
		$above_rank = self::count_users_above( $user_total, $period_start, $opt_out_ids, $scope_ids );

		// Find the lowest total above ours to calculate gap.
		$next_total = self::get_next_threshold( $user_total, $period_start, $opt_out_ids, $scope_ids );

		return array(
			'rank'           => $above_rank + 1,
			'points'         => $user_total,
			'points_to_next' => $next_total !== null ? ( $next_total - $user_total ) : null,
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Return the MySQL datetime string for the start of a period.
	 * Returns null for 'all' (no time filter).
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
	 * @param string $scope_type e.g. 'bp_group'
	 * @param int    $scope_id   e.g. group ID
	 * @return int[]             Empty array = no scope restriction.
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
			'wb_gamification_leaderboard_scope_user_ids',
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
	 */
	private static function count_users_above(
		int $threshold,
		?string $period_start,
		array $opt_out_ids,
		array $scope_ids
	): int {
		global $wpdb;

		$values = array();
		$where  = '';

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
	 * Find the lowest points total strictly above $threshold (= next rank's score).
	 * Returns null if $threshold is already at the top.
	 */
	private static function get_next_threshold(
		int $threshold,
		?string $period_start,
		array $opt_out_ids,
		array $scope_ids
	): ?int {
		global $wpdb;

		$values = array();
		$where  = '';

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
		return $result !== null ? (int) $result : null;
	}
}
