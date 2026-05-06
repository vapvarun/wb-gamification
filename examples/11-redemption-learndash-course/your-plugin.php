<?php
/**
 * Plugin Name: Redemption — LearnDash course unlock
 * Description: Listens for `custom`-type WB Gamification redemptions whose admin-defined description starts with "course:" and grants access to the matching LearnDash course. Drop this file into wp-content/plugins/redemption-learndash-course/your-plugin.php.
 * Version: 1.0.0
 *
 * Why this lives in examples/ — WB Gamification's RedemptionEngine fires
 * `wb_gam_points_redeemed` after every successful redemption. For
 * the built-in WooCommerce + Wbcom Credits types the engine fulfils itself.
 * For `custom` rewards it parks the row at status='pending_fulfillment' and
 * defers to listeners like this one.
 *
 * Pairing convention used by this listener:
 *
 *   Admin creates a reward of type `custom` with the description prefixed
 *   `course:<id>` (e.g. "course:42"). This listener parses that prefix.
 *   It's a UX shortcut so admins don't have to wait for a structured
 *   reward_config UI for every integration — descriptions are free text.
 *
 *   For a production plugin you'd extend the admin form to capture
 *   `reward_config = { kind: "learndash_course", course_id: 42 }` and
 *   read $item['reward_config'] instead.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wb_gam_points_redeemed',
	function ( $redemption_id, $user_id, $item, $coupon_code ) {
		// Bail unless this is a custom-type reward.
		if ( 'custom' !== ( $item['reward_type'] ?? '' ) ) {
			return;
		}

		// Bail if LearnDash isn't loaded.
		if ( ! function_exists( 'ld_update_course_access' ) ) {
			return;
		}

		// Parse `course:<id>` out of the description.
		$desc = $item['description'] ?? '';
		if ( ! preg_match( '/\bcourse:(\d+)\b/', $desc, $m ) ) {
			return;
		}

		$course_id = (int) $m[1];
		if ( $course_id <= 0 ) {
			return;
		}

		// Grant access. ld_update_course_access(user_id, course_id, $remove = false).
		ld_update_course_access( $user_id, $course_id, false );

		// Mark the redemption as fulfilled. Use the engine's own status column.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_redemptions',
			array( 'status' => 'fulfilled' ),
			array( 'id' => (int) $redemption_id ),
			array( '%s' ),
			array( '%d' )
		);
	},
	10,
	4
);
