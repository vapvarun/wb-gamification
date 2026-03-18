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
 * @param array $args {
 *   Action definition array.
 *
 *   @type string   $id             Unique action identifier.
 *   @type string   $label          Human-readable label.
 *   @type string   $description    Optional description.
 *   @type string   $hook           WordPress hook name to listen on.
 *   @type callable $user_callback  Callback that returns the user ID from hook args.
 *   @type int      $default_points Default points awarded.
 *   @type string   $category       Optional category slug.
 *   @type string   $icon           Optional dashicon class.
 *   @type bool     $repeatable     Whether the action can be awarded multiple times.
 *   @type int      $cooldown       Seconds between repeated awards. 0 = no cooldown.
 *   @type int      $daily_cap      Max awards per day. 0 = unlimited.
 *   @type int      $weekly_cap     Max awards per week. 0 = unlimited.
 * }
 */
function wb_gamification_register_action( array $args ): void {
	Registry::register_action( $args );
}

/**
 * Register a custom badge trigger.
 *
 * @param array $args {
 *   Badge trigger definition array.
 *
 *   @type string   $id          Unique trigger identifier.
 *   @type string   $label       Human-readable label.
 *   @type string   $description Optional description.
 *   @type string   $hook        WordPress hook name to listen on.
 *   @type callable $condition   Callback returning true when the badge should be awarded.
 * }
 */
function wb_gamification_register_badge_trigger( array $args ): void {
	Registry::register_badge_trigger( $args );
}

/**
 * Register a custom challenge type.
 *
 * @param array $args {
 *   Challenge type definition array.
 *
 *   @type string $id          Unique challenge type identifier.
 *   @type string $label       Human-readable label.
 *   @type string $description Optional description.
 *   @type string $action_id   Action ID that this challenge tracks.
 *   @type bool   $countable   Whether progress is tracked by count.
 * }
 */
function wb_gamification_register_challenge_type( array $args ): void {
	Registry::register_challenge_type( $args );
}

/**
 * Get total points for a user.
 *
 * @param int $user_id WordPress user ID.
 * @return int Total accumulated points.
 */
function wb_gam_get_user_points( int $user_id ): int {
	return PointsEngine::get_total( $user_id );
}

/**
 * Get how many times a user has performed a specific action.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $action_id Action identifier to query.
 * @return int Number of times the action has been awarded to the user.
 */
function wb_gam_get_user_action_count( int $user_id, string $action_id ): int {
	return PointsEngine::get_action_count( $user_id, $action_id );
}

/**
 * Get current level for a user.
 *
 * @param int $user_id WordPress user ID.
 * @return array{id: int, name: string, min_points: int}|null Level data or null if no level matched.
 */
function wb_gam_get_user_level( int $user_id ): ?array {
	return LevelEngine::get_level_for_user( $user_id );
}

/**
 * Award points to a user manually.
 *
 * Bypasses cooldown/cap checks. Routes through Engine::process() so the event
 * is persisted to wb_gam_events and all hooks/webhooks fire normally.
 *
 * @param int    $user_id   WordPress user ID to award points to.
 * @param int    $points    Number of points to award. Must be greater than zero.
 * @param string $action_id Action identifier to log against. Defaults to 'manual'.
 * @param int    $object_id Optional related object ID (e.g. post ID). Defaults to 0.
 * @return bool True on success, false if validation fails.
 */
function wb_gam_award_points( int $user_id, int $points, string $action_id = 'manual', int $object_id = 0 ): bool {
	if ( $points <= 0 || $user_id <= 0 ) {
		return false;
	}

	return Engine::process(
		new Event(
			array(
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => $object_id,
				'metadata'  => array(
					'points' => $points,
					'manual' => true,
				),
			)
		)
	);
}
