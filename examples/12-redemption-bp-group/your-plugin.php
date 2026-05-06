<?php
/**
 * Plugin Name: Redemption — BuddyPress group access
 * Description: Listens for `custom`-type WB Gamification redemptions whose description includes "group:<id>" and joins the user to that BuddyPress group. Drop this file into wp-content/plugins/redemption-bp-group/your-plugin.php.
 * Version: 1.0.0
 *
 * Why: lets you sell premium-group access for points (e.g. an "Insider"
 * group, an experts-only space) without writing custom REST endpoints.
 *
 * Convention used here: description contains the substring `group:<id>`,
 * e.g. "Join the Insiders group. group:7".
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wb_gam_points_redeemed',
	function ( $redemption_id, $user_id, $item, $coupon_code ) {
		if ( 'custom' !== ( $item['reward_type'] ?? '' ) ) {
			return;
		}

		// Bail if BuddyPress Groups isn't active.
		if ( ! function_exists( 'groups_join_group' ) ) {
			return;
		}

		$desc = $item['description'] ?? '';
		if ( ! preg_match( '/\bgroup:(\d+)\b/', $desc, $m ) ) {
			return;
		}

		$group_id = (int) $m[1];
		if ( $group_id <= 0 ) {
			return;
		}

		$joined = groups_join_group( $group_id, $user_id );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_redemptions',
			array( 'status' => $joined ? 'fulfilled' : 'failed' ),
			array( 'id' => (int) $redemption_id ),
			array( '%s' ),
			array( '%d' )
		);
	},
	10,
	4
);
