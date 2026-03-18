<?php
/**
 * WB Gamification Level Engine
 *
 * Level state is derived from the points ledger, not stored separately.
 * On each point award, Engine calls maybe_level_up() which compares the
 * user's current points against the wb_gam_levels thresholds. If the level
 * changed, user_meta is updated and the level_changed hook fires.
 *
 * Levels are admin-configurable via wb_gam_levels DB table.
 * Defaults seeded by Installer: Newcomer → Member → Contributor → Regular → Champion.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Derives and updates member level state from the points ledger.
 *
 * @package WB_Gamification
 */
final class LevelEngine {

	/**
	 * Check whether a user's points put them in a new level, and promote if so.
	 *
	 * Called by Engine::process() after every successful point award.
	 *
	 * @param int $user_id User to check.
	 */
	public static function maybe_level_up( int $user_id ): void {
		$new_level = self::get_level_for_user( $user_id );
		if ( ! $new_level ) {
			return;
		}

		$current_level_id = (int) get_user_meta( $user_id, 'wb_gam_level_id', true );

		if ( $new_level['id'] === $current_level_id ) {
			return; // No change.
		}

		// Update user meta so profile/directory integrations can read it cheaply.
		update_user_meta( $user_id, 'wb_gam_level_id', $new_level['id'] );
		update_user_meta( $user_id, 'wb_gam_level_name', $new_level['name'] );

		// Only fire the level-changed hook when upgrading from a previous level
		// (not on the initial Newcomer assignment).
		if ( $current_level_id > 0 ) {
			/**
			 * Fires when a member moves to a new level.
			 *
			 * @param int $user_id       User who levelled up.
			 * @param int $old_level_id  Previous level ID.
			 * @param int $new_level_id  New level ID.
			 */
			do_action( 'wb_gamification_level_changed', $user_id, $current_level_id, $new_level['id'] );
		}
	}

	/**
	 * Return the current level for a user based on their total points.
	 *
	 * @param int $user_id User to look up.
	 * @return array{ id: int, name: string, min_points: int, icon_url: string|null }|null
	 *         Null only if no levels are configured (fresh install before seeding).
	 */
	public static function get_level_for_user( int $user_id ): ?array {
		return self::get_level_for_points( PointsEngine::get_total( $user_id ) );
	}

	/**
	 * Return the level that corresponds to a given points total.
	 *
	 * @param int $points Points total.
	 * @return array{ id: int, name: string, min_points: int, icon_url: string|null }|null
	 */
	public static function get_level_for_points( int $points ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, min_points, icon_url
				   FROM {$wpdb->prefix}wb_gam_levels
				  WHERE min_points <= %d
				  ORDER BY min_points DESC
				  LIMIT 1",
				$points
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'         => (int) $row['id'],
			'name'       => $row['name'],
			'min_points' => (int) $row['min_points'],
			'icon_url'   => $row['icon_url'] ?: null,
		);
	}

	/**
	 * Return the next level above a user's current level, or null if max level.
	 *
	 * @param int $user_id User to look up.
	 * @return array{ id: int, name: string, min_points: int, icon_url: string|null }|null
	 */
	public static function get_next_level( int $user_id ): ?array {
		global $wpdb;

		$points = PointsEngine::get_total( $user_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, min_points, icon_url
				   FROM {$wpdb->prefix}wb_gam_levels
				  WHERE min_points > %d
				  ORDER BY min_points ASC
				  LIMIT 1",
				$points
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'         => (int) $row['id'],
			'name'       => $row['name'],
			'min_points' => (int) $row['min_points'],
			'icon_url'   => $row['icon_url'] ?: null,
		);
	}

	/**
	 * Return all levels ordered by threshold, with the user's current one flagged.
	 *
	 * @param int $user_id User to look up.
	 * @return array<int, array{ id: int, name: string, min_points: int, is_current: bool }>
	 */
	public static function get_all_levels_for_user( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, name, min_points FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$current = self::get_level_for_user( $user_id );

		return array_map(
			static function ( array $row ) use ( $current ): array {
				return array(
					'id'         => (int) $row['id'],
					'name'       => $row['name'],
					'min_points' => (int) $row['min_points'],
					'is_current' => $current && ( (int) $row['id'] === $current['id'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Calculate progress percentage toward the next level.
	 *
	 * @param int $user_id User to calculate for.
	 * @return int  0–100 (100 = max level reached).
	 */
	public static function get_progress_percent( int $user_id ): int {
		$points  = PointsEngine::get_total( $user_id );
		$current = self::get_level_for_points( $points );
		$next    = self::get_next_level( $user_id );

		if ( ! $current ) {
			return 0;
		}

		if ( ! $next ) {
			return 100; // Max level.
		}

		$span = $next['min_points'] - $current['min_points'];
		if ( $span <= 0 ) {
			return 100;
		}

		return min( 100, (int) round( ( ( $points - $current['min_points'] ) / $span ) * 100 ) );
	}
}
