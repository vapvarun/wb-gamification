<?php
/**
 * WB Gamification — BuddyPress kudos_given stream poster.
 *
 * KudosEngine fires:
 *   do_action( 'wb_gamification_kudos_given', $giver_id, $receiver_id, $message, $kudos_id )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;

/**
 * Posts a "Gave kudos" entry to the BuddyPress activity stream.
 *
 * @package WB_Gamification
 */
final class KudosStream {

	private const COMPONENT  = 'wb_gamification';
	private const TYPE       = 'kudos_given';
	private const OPT_TOGGLE = 'wb_gam_bp_stream_kudos_given';

	/**
	 * Wire the engine action to this poster.
	 */
	public static function init(): void {
		add_action( 'wb_gamification_kudos_given', array( __CLASS__, 'post' ), 10, 4 );
	}

	/**
	 * Post a kudos_given activity to the giver's stream.
	 *
	 * @param int    $giver_id    User who gave kudos.
	 * @param int    $receiver_id User who received kudos.
	 * @param string $message     Optional short message.
	 * @param int    $kudos_id    DB row ID (used as item_id for linking).
	 */
	public static function post( int $giver_id, int $receiver_id, string $message, int $kudos_id ): void {
		if ( ! self::is_enabled() || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$giver_link    = ActivityCard::user_link( $giver_id );
		$receiver_link = ActivityCard::user_link( $receiver_id );
		$receiver_name = ActivityCard::user_display_name( $receiver_id );

		$action = sprintf(
			/* translators: 1: giver display name link, 2: receiver display name link */
			__( '%1$s gave kudos to %2$s', 'wb-gamification' ),
			$giver_link,
			$receiver_link
		);

		$avatar = self::receiver_avatar_url( $receiver_id );

		$description = '' !== $message
			? wp_strip_all_tags( $message )
			: sprintf(
				/* translators: %s: receiver display name */
				__( 'A kudos was sent to %s.', 'wb-gamification' ),
				$receiver_name
			);

		bp_activity_add(
			array(
				'user_id'       => $giver_id,
				'component'     => self::COMPONENT,
				'type'          => self::TYPE,
				'action'        => $action,
				'content'       => ActivityCard::render(
					'kudos',
					$avatar,
					sprintf(
						/* translators: %s: receiver display name */
						__( 'Kudos for %s', 'wb-gamification' ),
						$receiver_name
					),
					$description
				),
				'item_id'       => $kudos_id,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Resolve a 64x64 URL for the receiver's avatar.
	 *
	 * @param int $receiver_id WordPress user ID.
	 */
	public static function receiver_avatar_url( int $receiver_id ): string {
		if ( function_exists( 'bp_core_fetch_avatar' ) ) {
			$url = bp_core_fetch_avatar(
				array(
					'item_id' => $receiver_id,
					'object'  => 'user',
					'type'    => 'full',
					'width'   => 64,
					'height'  => 64,
					'html'    => false,
				)
			);
			if ( $url ) {
				return $url;
			}
		}

		$url = get_avatar_url( $receiver_id, array( 'size' => 64 ) );
		return $url ?: ActivityCard::default_kudos_image();
	}

	/**
	 * Whether the kudos_given stream type is admin-enabled.
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPT_TOGGLE, 1 );
	}
}
