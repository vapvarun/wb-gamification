<?php
/**
 * WB Gamification — BuddyPress Activity Integration
 *
 * Two responsibilities:
 *
 * 1. Quality-weighted reaction points — reactions on activity_update posts
 *    award 5 pts instead of the default 3. Captures "quality over volume" via
 *    the wb_gamification_points_for_action filter.
 *
 * 2. Activity stream posts — badge earned, level-up, and kudos events
 *    auto-post to the BP activity stream (each individually toggleable).
 *
 * Stream toggle options (1 = enabled, 0 = disabled):
 *   wb_gam_bp_stream_badge_earned   (default 1)
 *   wb_gam_bp_stream_level_changed  (default 1)
 *   wb_gam_bp_stream_kudos_given    (default 1)
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\BuddyPress;

use WBGam\Engine\Event;

defined( 'ABSPATH' ) || exit;

final class ActivityIntegration {

	private const COMPONENT = 'wb_gamification';

	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		// Quality-weighted points.
		add_filter( 'wb_gamification_points_for_action', array( __CLASS__, 'quality_weight_reactions' ), 10, 4 );

		// Register BP activity types once BP activity component is loaded.
		add_action( 'bp_register_activity_actions', array( __CLASS__, 'register_activity_types' ) );

		// Activity stream posts.
		add_action( 'wb_gamification_badge_awarded', array( __CLASS__, 'post_badge_to_stream' ), 10, 3 );
		add_action( 'wb_gamification_level_changed', array( __CLASS__, 'post_level_up_to_stream' ), 10, 3 );
		add_action( 'wb_gamification_kudos_given', array( __CLASS__, 'post_kudos_to_stream' ), 10, 4 );
		add_action( 'wb_gamification_challenge_completed', array( __CLASS__, 'post_challenge_to_stream' ), 10, 2 );
	}

	// ── Quality weighting ───────────────────────────────────────────────────────

	/**
	 * Award higher points for reactions received on activity_update posts.
	 *
	 * @param int    $points    Base points.
	 * @param string $action_id Action being processed.
	 * @param int    $user_id   User receiving points.
	 * @param Event  $event     Full event (metadata includes activity_type).
	 * @return int
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
	 * Register custom activity types with BuddyPress.
	 */
	public static function register_activity_types(): void {
		bp_activity_set_action(
			self::COMPONENT,
			'badge_earned',
			__( 'Earned a badge', 'wb-gamification' ),
			null,
			__( 'Gamification', 'wb-gamification' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			self::COMPONENT,
			'level_changed',
			__( 'Reached a new level', 'wb-gamification' ),
			null,
			__( 'Gamification', 'wb-gamification' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			self::COMPONENT,
			'kudos_given',
			__( 'Gave kudos', 'wb-gamification' ),
			null,
			__( 'Gamification', 'wb-gamification' ),
			array( 'activity', 'member' )
		);

		bp_activity_set_action(
			self::COMPONENT,
			'challenge_completed',
			__( 'Completed a challenge', 'wb-gamification' ),
			null,
			__( 'Gamification', 'wb-gamification' ),
			array( 'activity', 'member' )
		);
	}

	// ── Stream posting ──────────────────────────────────────────────────────────

	/**
	 * Post a badge-earned activity when a member earns a badge.
	 *
	 * @param int        $user_id  User who earned the badge.
	 * @param string     $badge_id Badge identifier.
	 * @param array|null $def      Badge definition row.
	 */
	public static function post_badge_to_stream( int $user_id, string $badge_id, ?array $def ): void {
		if ( ! self::stream_enabled( 'badge_earned' ) || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$badge_name = $def['name'] ?? $badge_id;
		$user_link  = self::user_link( $user_id );

		bp_activity_add(
			array(
				'user_id'       => $user_id,
				'component'     => self::COMPONENT,
				'type'          => 'badge_earned',
				/* translators: 1: user display name link, 2: badge name */
				'action'        => sprintf(
					__( '%1$s earned the <strong>%2$s</strong> badge', 'wb-gamification' ),
					$user_link,
					esc_html( $badge_name )
				),
				'item_id'       => 0,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Post a level-up activity when a member advances to a new level.
	 *
	 * @param int $user_id      User who levelled up.
	 * @param int $old_level_id Previous level ID (unused but part of hook signature).
	 * @param int $new_level_id New level ID.
	 */
	public static function post_level_up_to_stream( int $user_id, int $old_level_id, int $new_level_id ): void {
		if ( ! self::stream_enabled( 'level_changed' ) || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$level_name = get_user_meta( $user_id, 'wb_gam_level_name', true ) ?: __( 'a new level', 'wb-gamification' );
		$user_link  = self::user_link( $user_id );

		bp_activity_add(
			array(
				'user_id'       => $user_id,
				'component'     => self::COMPONENT,
				'type'          => 'level_changed',
				/* translators: 1: user display name link, 2: level name */
				'action'        => sprintf(
					__( '%1$s reached the <strong>%2$s</strong> level', 'wb-gamification' ),
					$user_link,
					esc_html( $level_name )
				),
				'item_id'       => $new_level_id,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Post a kudos-given activity to the giver's BP stream.
	 *
	 * @param int    $giver_id    User who gave kudos.
	 * @param int    $receiver_id User who received kudos.
	 * @param string $message     Optional short message.
	 * @param int    $kudos_id    DB row ID (used as item_id for linking).
	 */
	public static function post_kudos_to_stream( int $giver_id, int $receiver_id, string $message, int $kudos_id ): void {
		if ( ! self::stream_enabled( 'kudos_given' ) || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$giver_link    = self::user_link( $giver_id );
		$receiver_link = self::user_link( $receiver_id );

		/* translators: 1: giver display name link, 2: receiver display name link */
		$action = sprintf(
			__( '%1$s gave kudos to %2$s', 'wb-gamification' ),
			$giver_link,
			$receiver_link
		);

		if ( '' !== $message ) {
			$action .= ': <em>' . esc_html( $message ) . '</em>';
		}

		bp_activity_add(
			array(
				'user_id'       => $giver_id,
				'component'     => self::COMPONENT,
				'type'          => 'kudos_given',
				'action'        => $action,
				'item_id'       => $kudos_id,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Post a challenge-completed activity to the member's BP stream.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Full challenge row.
	 */
	public static function post_challenge_to_stream( int $user_id, array $challenge ): void {
		if ( ! self::stream_enabled( 'challenge_completed' ) || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$user_link = self::user_link( $user_id );

		bp_activity_add(
			array(
				'user_id'       => $user_id,
				'component'     => self::COMPONENT,
				'type'          => 'challenge_completed',
				/* translators: 1: user display name link, 2: challenge title */
				'action'        => sprintf(
					__( '%1$s completed the <strong>%2$s</strong> challenge', 'wb-gamification' ),
					$user_link,
					esc_html( $challenge['title'] ?? '' )
				),
				'item_id'       => (int) ( $challenge['id'] ?? 0 ),
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Check whether a given stream event type is enabled by admin option.
	 *
	 * @param string $type e.g. 'badge_earned', 'level_changed', 'kudos_given'
	 * @return bool
	 */
	private static function stream_enabled( string $type ): bool {
		return (bool) get_option( 'wb_gam_bp_stream_' . $type, 1 );
	}

	/**
	 * Build an HTML link to a user's BP profile, or their display name if no BP.
	 */
	private static function user_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$name = esc_html( $user->display_name );

		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			$url = esc_url( bp_core_get_user_domain( $user_id ) );
			return "<a href=\"{$url}\">{$name}</a>";
		}

		return $name;
	}
}
