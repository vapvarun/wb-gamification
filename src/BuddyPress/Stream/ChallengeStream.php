<?php
/**
 * WB Gamification — BuddyPress challenge_completed stream poster.
 *
 * ChallengeEngine fires:
 *   do_action( 'wb_gamification_challenge_completed', $user_id, $challenge_array )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;

/**
 * Posts a "Completed challenge" entry to the BuddyPress activity stream.
 *
 * @package WB_Gamification
 */
final class ChallengeStream {

	private const COMPONENT  = 'wb_gamification';
	private const TYPE       = 'challenge_completed';
	private const OPT_TOGGLE = 'wb_gam_bp_stream_challenge_completed';

	/**
	 * Wire the engine action to this poster.
	 */
	public static function init(): void {
		add_action( 'wb_gamification_challenge_completed', array( __CLASS__, 'post' ), 10, 2 );
	}

	/**
	 * Post a challenge_completed activity for the user.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Full challenge row.
	 */
	public static function post( int $user_id, array $challenge ): void {
		if ( ! self::is_enabled() || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$title = ! empty( $challenge['title'] ) ? $challenge['title'] : __( 'a challenge', 'wb-gamification' );
		$bonus = isset( $challenge['bonus_points'] ) ? (int) $challenge['bonus_points'] : 0;
		$image = ! empty( $challenge['image_url'] ) ? $challenge['image_url'] : ActivityCard::default_challenge_image();

		$description = $bonus > 0
			? sprintf(
				/* translators: %d: bonus points awarded */
				_n( 'Earned a %d-point bonus on completion.', 'Earned a %d-point bonus on completion.', $bonus, 'wb-gamification' ),
				$bonus
			)
			: __( 'Challenge complete!', 'wb-gamification' );

		$user_link = ActivityCard::user_link( $user_id );

		bp_activity_add(
			array(
				'user_id'       => $user_id,
				'component'     => self::COMPONENT,
				'type'          => self::TYPE,
				'action'        => sprintf(
					/* translators: 1: user display name link, 2: challenge title */
					__( '%1$s completed the <strong>%2$s</strong> challenge', 'wb-gamification' ),
					$user_link,
					esc_html( $title )
				),
				'content'       => ActivityCard::render( 'challenge', $image, $title, $description ),
				'item_id'       => (int) ( $challenge['id'] ?? 0 ),
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Whether the challenge_completed stream type is admin-enabled.
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPT_TOGGLE, 1 );
	}
}
