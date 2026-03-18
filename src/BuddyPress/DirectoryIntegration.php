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

		$level_name = get_user_meta( $user_id, 'wb_gam_level_name', true );
		if ( ! $level_name ) {
			return; // Don't show anything for members with no level yet.
		}

		echo '<span class="wb-gam-directory-rank">' . esc_html( $level_name ) . '</span>';
	}
}
