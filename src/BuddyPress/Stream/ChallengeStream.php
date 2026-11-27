<?php
/**
 * WB Gamification — BuddyPress challenge_completed stream poster.
 *
 * ChallengeEngine fires:
 *   do_action( 'wb_gam_challenge_completed', $user_id, $challenge_array )
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

	private const TYPE       = 'challenge_completed';
	private const OPT_TOGGLE = 'wb_gam_bp_stream_challenge_completed';

	/**
	 * Wire the engine action to this poster.
	 */
	public static function init(): void {
		add_action( 'wb_gam_challenge_completed', \WBGam\Engine\RecipientLocale::site( array( __CLASS__, 'post' ) ), 10, 2 );
	}

	/**
	 * Post a challenge_completed activity for the user.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Full challenge row.
	 */
	public static function post( int $user_id, array $challenge ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$fields = self::fields( $challenge );

		/** This action is documented in src/BuddyPress/Stream/BadgeStream.php */
		do_action(
			'wb_gam_activity_post',
			'challenge',
			$user_id,
			array(
				'challenge_id' => (int) ( $challenge['id'] ?? 0 ),
			) + $fields
		);

		if ( ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$user_link = ActivityCard::user_link( $user_id );

		ActivityCard::add(
			array(
				'user_id'       => $user_id,
				'type'          => self::TYPE,
				'action'        => ActivityCard::action_line( $user_link, 'challenge' ),
				'content'       => ActivityCard::render( 'challenge', $fields['image_url'], $fields['title'], $fields['description'] ),
				'item_id'       => (int) ( $challenge['id'] ?? 0 ),
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Card fields for a challenge: title, the bonus it paid, its icon. Shared with the Backfiller.
	 *
	 * @param array<string, mixed> $challenge Challenge row (title, bonus_points, image_url).
	 * @return array{title:string,description:string,image_url:string}
	 */
	public static function fields( array $challenge ): array {
		$bonus = isset( $challenge['bonus_points'] ) ? (int) $challenge['bonus_points'] : 0;

		return array(
			'title'       => ! empty( $challenge['title'] ) ? (string) $challenge['title'] : __( 'a challenge', 'wb-gamification' ),
			'description' => $bonus > 0
				? sprintf(
					/* translators: %d: bonus points awarded */
					_n( 'Earned a %d-point bonus on completion.', 'Earned a %d-point bonus on completion.', $bonus, 'wb-gamification' ),
					$bonus
				)
				: __( 'Challenge complete!', 'wb-gamification' ),
			'image_url'   => ! empty( $challenge['image_url'] ) ? (string) $challenge['image_url'] : ActivityCard::default_challenge_image(),
		);
	}

	/**
	 * Whether the challenge_completed stream type is admin-enabled.
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPT_TOGGLE, 1 );
	}
}
