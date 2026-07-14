<?php
/**
 * ActivityPub federation — publishes gamification events as ActivityStreams
 * 2.0 activities to the user's outbox via the WP ActivityPub plugin
 * (https://wordpress.org/plugins/activitypub/).
 *
 * Activates only when:
 *   1. The WP ActivityPub plugin is loaded (function_exists check below)
 *   2. The wb_gam_activitypub_publish option is truthy (default OFF —
 *      federation is opt-in per site so the noise floor of gamification
 *      events doesn't auto-spam the fediverse)
 *   3. The user has opted in to federation via wb_gam_federate_events
 *      user_meta (default unset; explicit per-user opt-in)
 *
 * No-op when ActivityPub isn't installed. Drop-in when it is.
 *
 * Activity mapping:
 *   wb_gam_badge_awarded     → Add (Achievement)
 *   wb_gam_level_changed     → Update (Profile, custom property)
 *   wb_gam_challenge_completed → Create (Note describing the milestone)
 *
 * Note: points_awarded is deliberately NOT mapped — the average user
 * awards points dozens of times per day; firing one AP activity per
 * award would saturate every follower's timeline. Aggregated weekly
 * digests are a future enhancement.
 *
 * @package WB_Gamification
 * @since   1.5.0
 */

namespace WBGam\Integrations;

use WBGam\Engine\BadgeEngine;
use WBGam\Engine\Event;

defined( 'ABSPATH' ) || exit;

final class ActivityPub {

	public const PUBLISH_OPTION = 'wb_gam_activitypub_publish';
	public const USER_OPT_IN    = 'wb_gam_federate_events';

	public static function boot(): void {
		// We hook UNCONDITIONALLY — handlers check is_enabled() before
		// touching the AP plugin. This keeps the integration testable
		// (handlers run; just no-op when AP isn't there).
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'on_badge_awarded' ), 30, 3 );
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'on_level_changed' ), 30, 3 );
		add_action( 'wb_gam_challenge_completed', array( __CLASS__, 'on_challenge_completed' ), 30, 2 );
	}

	/**
	 * True when this install + this user have BOTH opted in to
	 * federation AND the ActivityPub plugin is loaded.
	 */
	public static function is_enabled( int $user_id ): bool {
		if ( ! get_option( self::PUBLISH_OPTION ) ) {
			return false;
		}
		if ( ! self::activitypub_loaded() ) {
			return false;
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		// Per-user opt-in. Members must explicitly enable federation
		// (UI lives in their profile when AP is active).
		$opted_in = get_user_meta( $user_id, self::USER_OPT_IN, true );
		return ! empty( $opted_in );
	}

	/**
	 * Probe for the ActivityPub plugin's main entry function. Different
	 * AP plugin versions expose different APIs; the canonical check is
	 * for `\Activitypub\add_to_outbox()` (added in 1.0). Falling back to
	 * checking for the namespace covers older builds where the helper
	 * was a class method.
	 */
	private static function activitypub_loaded(): bool {
		return function_exists( '\\Activitypub\\add_to_outbox' )
			|| class_exists( '\\Activitypub\\Activity\\Activity' );
	}

	/**
	 * Badge awarded → AS2 `Add` activity. The badge becomes part of the
	 * user's collection. AS2 vocabulary is intentionally compatible
	 * with OpenBadges where they overlap.
	 *
	 * BadgeEngine fires the hook as: do_action( 'wb_gam_badge_awarded', $user_id, $def_array, $badge_id_string )
	 * matching NotificationBridge::on_badge_awarded($user_id, $badge, $badge_id).
	 *
	 * @param int    $user_id  Recipient.
	 * @param array  $badge    Badge metadata (name, description, image_url, ...).
	 * @param string $badge_id Badge slug.
	 */
	public static function on_badge_awarded( int $user_id, array $badge, string $badge_id = '' ): void {
		if ( ! self::is_enabled( $user_id ) ) {
			return;
		}
		$wp_user = get_user_by( 'id', $user_id );
		if ( ! $wp_user ) {
			return;
		}

		$activity = array(
			'@context' => array(
				'https://www.w3.org/ns/activitystreams',
				'https://w3id.org/openbadges/v3',
			),
			'type'     => 'Add',
			'actor'    => self::actor_url_for( $wp_user ),
			'object'   => array(
				'type'       => 'Achievement',
				'name'       => (string) ( $badge['name'] ?? $badge_id ),
				'summary'    => (string) ( $badge['description'] ?? '' ),
				'image'      => (string) ( $badge['image_url'] ?? '' ),
				'wb:badgeId' => $badge_id,
			),
			'target'   => self::actor_url_for( $wp_user ) . '/collection/badges',
		);

		self::publish( $activity, $user_id );
	}

	/**
	 * Level changed → AS2 `Update` on the user's profile, signalling
	 * the new level. Federated observers can show "X just leveled up
	 * to Y".
	 *
	 * @param int        $user_id   Member.
	 * @param array|null $new_level New level descriptor.
	 * @param array|null $old_level Previous descriptor (may be null).
	 */
	public static function on_level_changed( int $user_id, $new_level, $old_level ): void {
		if ( ! self::is_enabled( $user_id ) ) {
			return;
		}
		if ( ! is_array( $new_level ) ) {
			return;
		}
		$wp_user = get_user_by( 'id', $user_id );
		if ( ! $wp_user ) {
			return;
		}

		$activity = array(
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'type'     => 'Update',
			'actor'    => self::actor_url_for( $wp_user ),
			'object'   => array(
				'type'         => 'Person',
				'id'           => self::actor_url_for( $wp_user ),
				'wb:level'     => (string) ( $new_level['name'] ?? '' ),
				'wb:levelId'   => (int) ( $new_level['id'] ?? 0 ),
				'wb:minPoints' => (int) ( $new_level['min_points'] ?? 0 ),
			),
		);

		self::publish( $activity, $user_id );
	}

	/**
	 * Challenge completed → AS2 `Create` of a short `Note` describing
	 * the milestone. Visible to followers as a regular post-like event.
	 *
	 * @param int   $user_id   Member.
	 * @param array $challenge Challenge descriptor.
	 */
	public static function on_challenge_completed( int $user_id, array $challenge ): void {
		if ( ! self::is_enabled( $user_id ) ) {
			return;
		}
		$wp_user = get_user_by( 'id', $user_id );
		if ( ! $wp_user ) {
			return;
		}

		$activity = array(
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'type'     => 'Create',
			'actor'    => self::actor_url_for( $wp_user ),
			'object'   => array(
				'type'           => 'Note',
				'content'        => sprintf(
					/* translators: 1: challenge title, 2: bonus points */
					__( 'Completed the "%1$s" challenge - earned %2$d bonus points.', 'wb-gamification' ),
					(string) ( $challenge['title'] ?? '' ),
					(int) ( $challenge['bonus_points'] ?? 0 )
				),
				'wb:challengeId' => (int) ( $challenge['id'] ?? 0 ),
			),
		);

		self::publish( $activity, $user_id );
	}

	/**
	 * Hand off an activity to the WP ActivityPub plugin's outbox.
	 *
	 * The integration is deliberately loose — we apply a filter
	 * (`wb_gam_activitypub_activity`) so a site can rewrite or veto
	 * activities before they're submitted to ActivityPub. Then we
	 * call the AP plugin's add_to_outbox() helper if it exists.
	 */
	private static function publish( array $activity, int $user_id ): void {
		/**
		 * Filter the outgoing ActivityPub activity. Return null/empty
		 * to suppress publishing.
		 *
		 * @param array $activity AS2 activity.
		 * @param int   $user_id  Acting user.
		 */
		$activity = (array) apply_filters( 'wb_gam_activitypub_activity', $activity, $user_id );
		if ( empty( $activity ) ) {
			return;
		}

		if ( function_exists( '\\Activitypub\\add_to_outbox' ) ) {
			try {
				\Activitypub\add_to_outbox( $activity, $activity['type'] ?? 'Create', $user_id );
			} catch ( \Throwable $e ) {
				// Federation failures must never break the gamification
				// flow that triggered them. Log + move on; the
				// SideEffectDispatcher pattern would be even cleaner
				// long-term but we're hooking into AP via standard
				// add_action so we're already isolated by WordPress's
				// hook-error semantics.
				if ( class_exists( '\\WBGam\\Engine\\Log' ) ) {
					\WBGam\Engine\Log::warning(
						'ActivityPub publish failed.',
						array(
							'user_id' => $user_id,
							'error'   => $e->getMessage(),
							'type'    => $activity['type'] ?? '',
						)
					);
				}
			}
		}
	}

	/**
	 * Derive the user's ActivityPub actor URL. AP plugin exposes
	 * this via author archive URL with an `application/activity+json`
	 * mime type. We don't need to fetch it — just construct the
	 * canonical form.
	 */
	private static function actor_url_for( \WP_User $user ): string {
		return (string) get_author_posts_url( $user->ID );
	}
}
