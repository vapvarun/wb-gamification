<?php
/**
 * WB Gamification — BuddyPress badge_earned stream poster.
 *
 * BadgeEngine fires:
 *   do_action( 'wb_gam_badge_awarded', $user_id, $def, $badge_id )
 *
 * @package WB_Gamification
 * @since   1.2.1
 */

namespace WBGam\BuddyPress\Stream;

defined( 'ABSPATH' ) || exit;

/**
 * Posts a "Badge earned" entry to the BuddyPress activity stream.
 *
 * @package WB_Gamification
 */
final class BadgeStream {

	private const COMPONENT  = 'wb_gamification';
	private const TYPE       = 'badge_earned';
	private const OPT_TOGGLE = 'wb_gam_bp_stream_badge_earned';

	/**
	 * Wire the engine action to this poster.
	 */
	public static function init(): void {
		add_action( 'wb_gam_badge_awarded', array( __CLASS__, 'post' ), 10, 3 );
	}

	/**
	 * Post a badge_earned activity for the user.
	 *
	 * @param int          $user_id  User who earned the badge.
	 * @param array|object $def      Badge definition row (id, name, description, image_url, …).
	 * @param string       $badge_id Badge slug (matches $def['id']).
	 */
	public static function post( int $user_id, $def = array(), string $badge_id = '' ): void {
		// A migration replays history; it is not news. Without this, importing a member's three-year-old
		// badge tells them they just earned it -- QA proved a member who had not logged in for a year got
		// a congratulations email because an admin ran a migration. The badge still lands; only the
		// announcement stands down. See WBGam\Engine\ImportMode.
		if ( \WBGam\Engine\ImportMode::is_active() ) {
			return;
		}

		if ( ! self::is_enabled() || ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$def = is_array( $def ) ? $def : (array) $def;

		// Engine sometimes fires before the def is hydrated — backfill from DB.
		if ( '' !== $badge_id && empty( $def['name'] ) ) {
			$def = ActivityCard::lookup_badge_def( $badge_id ) ?: $def;
		}

		$badge_name        = ! empty( $def['name'] ) ? $def['name'] : ActivityCard::humanize_slug( $badge_id );
		$badge_description = $def['description'] ?? '';
		$badge_image       = ! empty( $def['image_url'] ) ? $def['image_url'] : ActivityCard::default_badge_image();
		$user_link         = ActivityCard::user_link( $user_id );

		bp_activity_add(
			array(
				'user_id'       => $user_id,
				'component'     => self::COMPONENT,
				'type'          => self::TYPE,
				'action'        => ActivityCard::action_line( $user_link, 'badge' ),
				'content'       => ActivityCard::render( 'badge', $badge_image, $badge_name, $badge_description ),
				'item_id'       => 0,
				'hide_sitewide' => false,
				'is_spam'       => false,
			)
		);
	}

	/**
	 * Whether the badge_earned stream type is admin-enabled.
	 */
	private static function is_enabled(): bool {
		return (bool) get_option( self::OPT_TOGGLE, 1 );
	}
}
