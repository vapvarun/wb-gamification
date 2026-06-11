<?php
/**
 * WB Gamification — BuddyPress level_changed stream poster.
 *
 * LevelEngine fires the canonical 1.0.0 signature:
 *   do_action( 'wb_gam_level_changed', $user_id, $new_level, $old_level )
 *
 * Where $new_level / $old_level are arrays (id, name, min_points, icon_url)
 * or null. Pre-1.0.0 the hook fired a second time with int IDs; that
 * legacy fire was removed (see LevelEngine docblock).
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
	 * @param int        $user_id   User who levelled up.
	 * @param array|null $new_level New level data (id, name, min_points, icon_url) or null.
	 * @param array|null $old_level Previous level data or null.
	 */
	public static function post( int $user_id, ?array $new_level = null, ?array $old_level = null ): void {
		if ( ! self::is_enabled() || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$new_level_id = is_array( $new_level ) ? (int) ( $new_level['id'] ?? 0 ) : 0;
		if ( $new_level_id <= 0 ) {
			return;
		}

		// Prefer the array payload — fall back to ActivityCard lookup only if
		// the caller passed a partial array (resilience against hook re-fires).
		$level      = is_array( $new_level ) && isset( $new_level['name'] )
			? $new_level
			: ActivityCard::lookup_level( $new_level_id );
		$level_name = $level['name'] ?? get_user_meta( $user_id, 'wb_gam_level_name', true );
		if ( ! $level_name ) {
			$level_name = __( 'a new level', 'wb-gamification' );
		}

		$level_image = ! empty( $level['icon_url'] ) ? $level['icon_url'] : ActivityCard::default_level_image();
		$min_points  = isset( $level['min_points'] ) ? (int) $level['min_points'] : 0;

		$description = $min_points > 0
			? sprintf(
				/* translators: %d: points required for this level. */
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
				'action'        => ActivityCard::action_line( $user_link, 'level' ),
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
