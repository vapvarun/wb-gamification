<?php
/**
 * WB Gamification Points Engine
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

final class WB_Gam_Points_Engine {

	/**
	 * Process a registered action — checks enabled, cooldown, then awards.
	 */
	public static function process_action( string $action_id, int $user_id ): bool {
		$action = WB_Gam_Registry::get_action( $action_id );
		if ( ! $action ) {
			return false;
		}

		// Check if action is enabled by site owner.
		if ( ! (bool) get_option( 'wb_gam_enabled_' . $action_id, true ) ) {
			return false;
		}

		/**
		 * Allow extensions to block point awarding.
		 *
		 * @param bool   $should    Whether to award points.
		 * @param string $action_id Action ID.
		 * @param int    $user_id   User ID.
		 */
		$should = apply_filters( 'wb_gamification_should_award', true, $action_id, $user_id );
		if ( ! $should ) {
			return false;
		}

		// Cooldown check.
		$cooldown = (int) ( $action['cooldown'] ?? 0 );
		if ( $cooldown > 0 && self::is_on_cooldown( $user_id, $action_id, $cooldown ) ) {
			return false;
		}

		// Repeatable check.
		if ( ! ( $action['repeatable'] ?? true ) && self::get_action_count( $user_id, $action_id ) > 0 ) {
			return false;
		}

		$points = (int) get_option( 'wb_gam_points_' . $action_id, $action['default_points'] );

		/**
		 * Filter points before awarding.
		 *
		 * @param int    $points    Points to award.
		 * @param string $action_id Action ID.
		 * @param int    $user_id   User ID.
		 */
		$points = (int) apply_filters( 'wb_gamification_points_for_action', $points, $action_id, $user_id );

		return self::award( $user_id, $action_id, $points );
	}

	/**
	 * Award points directly.
	 */
	public static function award( int $user_id, string $action_id, int $points, int $object_id = 0 ): bool {
		global $wpdb;

		if ( $points <= 0 || $user_id <= 0 ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_points',
			[
				'user_id'    => $user_id,
				'action_id'  => $action_id,
				'points'     => $points,
				'object_id'  => $object_id ?: null,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%d', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			return false;
		}

		// Bust user total cache.
		wp_cache_delete( "wb_gam_total_{$user_id}", 'wb_gamification' );

		/**
		 * Fires after points are awarded.
		 *
		 * @param int    $user_id   User ID.
		 * @param string $action_id Action ID.
		 * @param int    $points    Points awarded.
		 */
		do_action( 'wb_gamification_points_awarded', $user_id, $action_id, $points );

		// Check for level-up.
		WB_Gam_Level_Engine::maybe_level_up( $user_id );

		// Update streak.
		WB_Gam_Streak_Engine::record_activity( $user_id );

		return true;
	}

	/**
	 * Get total points for a user.
	 */
	public static function get_total( int $user_id ): int {
		$cache_key = "wb_gam_total_{$user_id}";
		$cached    = wp_cache_get( $cache_key, 'wb_gamification' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d",
				$user_id
			)
		);

		wp_cache_set( $cache_key, $total, 'wb_gamification', 300 );

		return $total;
	}

	/**
	 * Get how many times a user has performed a specific action.
	 */
	public static function get_action_count( int $user_id, string $action_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d AND action_id = %s",
				$user_id,
				$action_id
			)
		);
	}

	/**
	 * Check if a user is within the cooldown period for an action.
	 */
	private static function is_on_cooldown( int $user_id, string $action_id, int $cooldown_seconds ): bool {
		global $wpdb;

		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s
				ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$action_id
			)
		);

		if ( ! $last ) {
			return false;
		}

		return ( time() - strtotime( $last ) ) < $cooldown_seconds;
	}
}
