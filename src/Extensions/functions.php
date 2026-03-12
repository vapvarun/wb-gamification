<?php
/**
 * WB Gamification — Public Extension API
 *
 * These functions are the developer-facing API for registering custom
 * gamification triggers, badge conditions, and challenge types.
 *
 * Usage:
 *   wb_gamification_register_action( [ 'id' => '...', 'hook' => '...', ... ] );
 *
 * @package WB_Gamification
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register a custom action that awards points.
 *
 * @param array{
 *   id: string,
 *   label: string,
 *   description?: string,
 *   hook: string,
 *   user_callback: callable,
 *   default_points: int,
 *   category?: string,
 *   icon?: string,
 *   repeatable?: bool,
 *   cooldown?: int,
 * } $args
 */
function wb_gamification_register_action( array $args ): void {
	WB_Gam_Registry::register_action( $args );
}

/**
 * Register a custom badge trigger.
 *
 * @param array{
 *   id: string,
 *   label: string,
 *   description?: string,
 *   hook: string,
 *   condition: callable,
 * } $args
 */
function wb_gamification_register_badge_trigger( array $args ): void {
	WB_Gam_Registry::register_badge_trigger( $args );
}

/**
 * Register a custom challenge type.
 *
 * @param array{
 *   id: string,
 *   label: string,
 *   description?: string,
 *   action_id: string,
 *   countable?: bool,
 * } $args
 */
function wb_gamification_register_challenge_type( array $args ): void {
	WB_Gam_Registry::register_challenge_type( $args );
}

/**
 * Get total points for a user.
 *
 * @param int $user_id
 * @return int
 */
function wb_gam_get_user_points( int $user_id ): int {
	return WB_Gam_Points_Engine::get_total( $user_id );
}

/**
 * Get how many times a user has performed a specific action.
 *
 * @param int    $user_id
 * @param string $action_id
 * @return int
 */
function wb_gam_get_user_action_count( int $user_id, string $action_id ): int {
	return WB_Gam_Points_Engine::get_action_count( $user_id, $action_id );
}

/**
 * Get current level for a user.
 *
 * @param int $user_id
 * @return array{ id: int, name: string, min_points: int }|null
 */
function wb_gam_get_user_level( int $user_id ): ?array {
	return WB_Gam_Level_Engine::get_level_for_user( $user_id );
}

/**
 * Award points to a user manually.
 *
 * @param int    $user_id
 * @param int    $points
 * @param string $action_id
 * @param int    $object_id Optional related object (post ID, etc.)
 * @return bool
 */
function wb_gam_award_points( int $user_id, int $points, string $action_id = 'manual', int $object_id = 0 ): bool {
	return WB_Gam_Points_Engine::award( $user_id, $action_id, $points, $object_id );
}
