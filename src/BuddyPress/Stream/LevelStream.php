<?php
/**
 * WB Gamification — BuddyPress level_changed stream poster.
 *
 * LevelEngine fires:
 *   do_action( 'wb_gam_level_changed', $user_id, $old_level_id, $new_level_id )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;

/**
 * Posts a "Reached new level" entry to the BuddyPress activity stream.
 *
 * @package WB_Gamification
 */
final class LevelStream {

	private const COMPONENT  = 'wb_gamification';
	private const TYPE       = 'level_changed';
	private const OPT_TOGGLE = 'wb_gam_bp_stream_level_changed';

	/**
	 * Wire the engine action to this poster.
	 */
	public static function init(): void {
		add_action( 'wb_gam_level_changed', array( __CLASS__, 'post' ), 10, 3 );
	}

	/**
	 * Post a level_changed activity for the user.
	 *
	 * @param int $user_id      User who levelled up.
	 * @param int $old_level_id Previous level ID (kept for hook signature).
	 * @param int $new_level_id New level ID.
	 */
	public static function post( int $user_id, int $old_level_id, int $new_level_id ): void {
		if ( ! self::is_enabled() || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$level      = ActivityCard::lookup_level( $new_level_id );
		$level_name = $level['name'] ?? get_user_meta( $user_id, 'wb_gam_level_name', true );
		if ( ! $level_name ) {
			$level_name = __( 'a new level', 'wb-gamification' );
		}

		$level_image = ! empty( $level['icon_url'] ) ? $level['icon_url'] : ActivityCard::default_level_image();
		$min_points  = isset( $level['min_points'] ) ? (int) $level['min_points'] : 0;

		$description = $min_points > 0
			? sprintf(
				/* translators: %d: points required for this level */
				_n( 'Awarded for reaching %d point.', 'Awarded for reaching %d points.', $min_points, 'wb-gamification' ),
				$min_points
			)
			: __( 'A new milestone reached.', 'wb-gamification' );

		$user_link = ActivityCard::user_link( $user_id );

		bp_activity_add(
			array(
				'user_id'       => $user_id,
				'component'     => self::COMPONENT,
				'type'          => self::TYPE,
				'action'        => sprintf(
					/* translators: 1: user display name link, 2: level name */
					__( '%1$s reached the <strong>%2$s</strong> level', 'wb-gamification' ),
					$user_link,
					esc_html( $level_name )
				),
				'content'       => ActivityCard::render( 'level', $level_image, $level_name, $description ),
				'item_id'       => $new_level_id,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Whether the level_changed stream type is admin-enabled.
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPT_TOGGLE, 1 );
	}
}
