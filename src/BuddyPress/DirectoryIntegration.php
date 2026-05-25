<?php
/**
 * WB Gamification — BuddyPress Member Directory Integration
 *
 * Shows rank title next to member name in the member directory.
 *
 * @package WB_Gamification
 */

namespace WBGam\BuddyPress;

defined( 'ABSPATH' ) || exit;

/**
 * Renders member rank in the BuddyPress member directory listing.
 *
 * @package WB_Gamification
 */
final class DirectoryIntegration {

	/**
	 * Register hooks when BuddyPress is active.
	 */
	public static function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}
		add_action( 'bp_directory_members_item', array( __CLASS__, 'render_rank_in_directory' ) );
	}

	/**
	 * Output rank badge in the member directory listing.
	 */
	public static function render_rank_in_directory(): void {
		$user_id = bp_get_member_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Respect opt-out preference.
		global $wpdb;
		$show_rank = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT show_rank FROM {$wpdb->prefix}wb_gam_member_prefs WHERE user_id = %d",
				$user_id
			)
		);
		if ( '0' === $show_rank ) {
			return;
		}

		$level_name   = (string) get_user_meta( $user_id, 'wb_gam_level_name', true );
		$badge_count  = \WBGam\Engine\BadgeEngine::count_user_badges( (int) $user_id );
		$points_total = (int) \WBGam\Engine\PointsEngine::get_total( (int) $user_id );

		if ( '' === $level_name && 0 === $badge_count && 0 === $points_total ) {
			return; // Don't show anything for members with nothing earned yet.
		}

		$parts = array();
		if ( '' !== $level_name ) {
			$parts[] = '<span class="wb-gam-directory-level">' . esc_html( $level_name ) . '</span>';
		}
		if ( $points_total > 0 ) {
			$parts[] = sprintf(
				'<span class="wb-gam-directory-points"><span class="icon-sparkles" aria-hidden="true"></span> %s</span>',
				esc_html( number_format_i18n( $points_total ) )
			);
		}
		if ( $badge_count > 0 ) {
			$badge_text = sprintf(
				/* translators: %d: number of badges earned. */
				_n( '%d badge', '%d badges', $badge_count, 'wb-gamification' ),
				$badge_count
			);
			$parts[] = sprintf(
				'<span class="wb-gam-directory-badges"><span class="icon-medal" aria-hidden="true"></span> %s</span>',
				esc_html( $badge_text )
			);
		}

		echo '<span class="wb-gam-directory-rank">' . implode( ' &middot; ', $parts ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is individually escaped above.
	}
}
