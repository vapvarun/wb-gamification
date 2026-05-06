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
	 * Static cache of all level rows for the current request.
	 * Avoids repeated object-cache lookups within a single page load.
	 *
	 * @var array<int, array{id: int, name: string, min_points: int, icon_url: string|null}>|null
	 */
	private static ?array $levels_cache = null;

	/**
	 * Load all level rows, using a static cache (per-request) backed by object cache (cross-request).
	 *
	 * Levels are admin-only data that almost never changes, so a 1-hour TTL is safe.
	 *
	 * @return array<int, array{id: int, name: string, min_points: int, icon_url: string|null}>
	 */
	private static function get_all_levels(): array {
		if ( null !== self::$levels_cache ) {
			return self::$levels_cache;
		}

		$cached = wp_cache_get( 'wb_gam_levels_all', 'wb_gamification' );
		if ( false !== $cached ) {
			self::$levels_cache = (array) $cached;
			return self::$levels_cache;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, name, min_points, icon_url FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		) ?: array();

		self::$levels_cache = array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'name'       => $row['name'],
					'min_points' => (int) $row['min_points'],
					'icon_url'   => $row['icon_url'] ?: null,
				);
			},
			$rows
		);

		wp_cache_set( 'wb_gam_levels_all', self::$levels_cache, 'wb_gamification', 3600 ); // 1 hr TTL.

		return self::$levels_cache;
	}

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
			do_action( 'wb_gam_level_changed', $user_id, $current_level_id, $new_level['id'] );

			// Resolve old level data from all levels by ID.
			$old_level_data = null;
			foreach ( self::get_all_levels() as $lvl ) {
				if ( $lvl['id'] === $current_level_id ) {
					$old_level_data = $lvl;
					break;
				}
			}

			/**
			 * Fires after a user's level changes.
			 *
			 * @since 1.0.0
			 *
			 * @param int        $user_id   User whose level changed.
			 * @param array|null $new_level New level data (id, name, min_points) or null.
			 * @param array|null $old_level Previous level data or null.
			 */
			do_action( 'wb_gam_level_changed', $user_id, $new_level, $old_level_data );
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
		$levels = self::get_all_levels();
		$match  = null;

		// Levels are sorted by min_points ASC, so walk forward and keep the
		// last one whose threshold the user has reached.
		foreach ( $levels as $level ) {
			if ( $level['min_points'] <= $points ) {
				$match = $level;
			} else {
				break; // Sorted ASC — no further matches possible.
			}
		}

		return $match;
	}

	/**
	 * Return the next level above a user's current level, or null if max level.
	 *
	 * @param int $user_id User to look up.
	 * @return array{ id: int, name: string, min_points: int, icon_url: string|null }|null
	 */
	public static function get_next_level( int $user_id ): ?array {
		$points = PointsEngine::get_total( $user_id );
		$levels = self::get_all_levels();

		// Levels are sorted by min_points ASC — return the first one above the user's total.
		foreach ( $levels as $level ) {
			if ( $level['min_points'] > $points ) {
				return $level;
			}
		}

		return null; // Already at max level.
	}

	/**
	 * Return all levels ordered by threshold, with the user's current one flagged.
	 *
	 * @param int $user_id User to look up.
	 * @return array<int, array{ id: int, name: string, min_points: int, is_current: bool }>
	 */
	public static function get_all_levels_for_user( int $user_id ): array {
		$levels = self::get_all_levels();

		if ( empty( $levels ) ) {
			return array();
		}

		$current = self::get_level_for_user( $user_id );

		return array_map(
			static function ( array $row ) use ( $current ): array {
				return array(
					'id'         => $row['id'],
					'name'       => $row['name'],
					'min_points' => $row['min_points'],
					'is_current' => $current && ( $row['id'] === $current['id'] ),
				);
			},
			$levels
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
