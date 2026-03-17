<?php
/**
 * WB Gamification — WordPress Abilities API Registration
 *
 * @package WB_Gamification
 */

namespace WBGam\Abilities;

defined( 'ABSPATH' ) || exit;

final class AbilitiesRegistrar {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability( 'wb_gam_earn_points',      [ 'label' => __( 'Earn gamification points', 'wb-gamification' ) ] );
		wp_register_ability( 'wb_gam_view_leaderboard', [ 'label' => __( 'View the leaderboard', 'wb-gamification' ) ] );
		wp_register_ability( 'wb_gam_redeem_rewards',   [ 'label' => __( 'Redeem points for rewards', 'wb-gamification' ) ] );
		wp_register_ability( 'wb_gam_manage_settings',  [ 'label' => __( 'Manage gamification settings', 'wb-gamification' ) ] );
		wp_register_ability( 'wb_gam_award_manual',     [ 'label' => __( 'Manually award points to members', 'wb-gamification' ) ] );
	}
}
