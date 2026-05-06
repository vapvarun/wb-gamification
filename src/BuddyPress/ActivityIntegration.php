<?php
/**
 * WB Gamification — BuddyPress activity integration orchestrator.
 *
 * Two responsibilities:
 *
 * 1. Quality-weighted reaction points — reactions on activity_update posts
 *    award 5 pts instead of the default 3, via `wb_gam_points_for_action`.
 *
 * 2. BP activity-type registration + delegation. Each event type lives in a
 *    dedicated poster under `WBGam\BuddyPress\Stream\`:
 *
 *      - BadgeStream     ← wb_gam_badge_awarded
 *      - LevelStream     ← wb_gam_level_changed
 *      - KudosStream     ← wb_gam_kudos_given
 *      - ChallengeStream ← wb_gam_challenge_completed
 *
 *    Stream toggles (1 = enabled, 0 = disabled, default 1):
 *      wb_gam_bp_stream_badge_earned
 *      wb_gam_bp_stream_level_changed
 *      wb_gam_bp_stream_kudos_given
 *      wb_gam_bp_stream_challenge_completed
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\BuddyPress;

use WBGam\BuddyPress\Stream\BadgeStream;
use WBGam\BuddyPress\Stream\ChallengeStream;
use WBGam\BuddyPress\Stream\KudosStream;
use WBGam\BuddyPress\Stream\LevelStream;
use WBGam\Engine\Event;

defined( 'ABSPATH' ) || exit;

/**
 * BuddyPress activity-stream orchestrator.
 *
 * @package WB_Gamification
 */
final class ActivityIntegration {

	private const COMPONENT = 'wb_gamification';

	/**
	 * Register hooks when BuddyPress is active.
	 */
	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		// Quality-weighted points (reactions on activity_update worth more).
		add_filter( 'wb_gam_points_for_action', array( __CLASS__, 'quality_weight_reactions' ), 10, 4 );

		// Register custom BP activity types so the activity directory filters list them.
		add_action( 'bp_register_activity_actions', array( __CLASS__, 'register_activity_types' ) );

		// Delegate each event type to its dedicated stream poster.
		BadgeStream::init();
		LevelStream::init();
		KudosStream::init();
		ChallengeStream::init();
	}

	// ── Quality weighting ───────────────────────────────────────────────────────

	/**
	 * Award higher points for reactions received on activity_update posts.
	 *
	 * @param int    $points    Base points.
	 * @param string $action_id Action being processed.
	 * @param int    $user_id   User receiving points.
	 * @param Event  $event     Full event (metadata includes activity_type).
	 */
	public static function quality_weight_reactions( int $points, string $action_id, int $user_id, Event $event ): int {
		if ( 'bp_reactions_received' !== $action_id ) {
			return $points;
		}

		$activity_type = $event->metadata['activity_type'] ?? '';

		if ( 'activity_update' === $activity_type ) {
			return max( $points, 5 );
		}

		return $points;
	}

	// ── Activity type registration ──────────────────────────────────────────────

	/**
	 * Register custom activity types with BuddyPress so they show up in directory filters.
	 */
	public static function register_activity_types(): void {
		$types = array(
			'badge_earned'        => __( 'Earned a badge', 'wb-gamification' ),
			'level_changed'       => __( 'Reached a new level', 'wb-gamification' ),
			'kudos_given'         => __( 'Gave kudos', 'wb-gamification' ),
			'challenge_completed' => __( 'Completed a challenge', 'wb-gamification' ),
		);

		foreach ( $types as $key => $label ) {
			bp_activity_set_action(
				self::COMPONENT,
				$key,
				$label,
				null,
				__( 'Gamification', 'wb-gamification' ),
				array( 'activity', 'member' )
			);
		}
	}
}
