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

use WBGam\Engine\Registry;
use WBGam\Engine\Engine;
use WBGam\Engine\Event;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;

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
 *   daily_cap?: int,
 *   weekly_cap?: int,
 * } $args
 */
function wb_gamification_register_action( array $args ): void {
	Registry::register_action( $args );
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
	Registry::register_badge_trigger( $args );
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
	Registry::register_challenge_type( $args );
}

/**
 * Get total points for a user.
 */
function wb_gam_get_user_points( int $user_id ): int {
	return PointsEngine::get_total( $user_id );
}

/**
 * Get how many times a user has performed a specific action.
 */
function wb_gam_get_user_action_count( int $user_id, string $action_id ): int {
	return PointsEngine::get_action_count( $user_id, $action_id );
}

/**
 * Get current level for a user.
 *
 * @return array{ id: int, name: string, min_points: int }|null
 */
function wb_gam_get_user_level( int $user_id ): ?array {
	return LevelEngine::get_level_for_user( $user_id );
}

/**
 * Award points to a user manually.
 *
 * Bypasses cooldown/cap checks. Routes through Engine::process() so the event
 * is persisted to wb_gam_events and all hooks/webhooks fire normally.
 */
function wb_gam_award_points( int $user_id, int $points, string $action_id = 'manual', int $object_id = 0 ): bool {
	if ( $points <= 0 || $user_id <= 0 ) {
		return false;
	}

	return Engine::process(
		new Event(
			[
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => $object_id,
				'metadata'  => [ 'points' => $points, 'manual' => true ],
			]
		)
	);
}
