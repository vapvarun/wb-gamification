<?php
/**
 * WB Gamification — WordPress Abilities API Registration
 *
 * @package WB_Gamification
 */

namespace WBGam\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all WB Gamification WordPress Abilities.
 *
 * @package WB_Gamification
 */
final class AbilitiesRegistrar {

	/**
	 * Register all gamification abilities with the WordPress Abilities API.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability( 'wb_gam_earn_points', array( 'label' => __( 'Earn gamification points', 'wb-gamification' ) ) );
		wp_register_ability( 'wb_gam_view_leaderboard', array( 'label' => __( 'View the leaderboard', 'wb-gamification' ) ) );
		wp_register_ability( 'wb_gam_redeem_rewards', array( 'label' => __( 'Redeem points for rewards', 'wb-gamification' ) ) );
		wp_register_ability( 'wb_gam_manage_settings', array( 'label' => __( 'Manage gamification settings', 'wb-gamification' ) ) );
		wp_register_ability( 'wb_gam_award_manual', array( 'label' => __( 'Manually award points to members', 'wb-gamification' ) ) );
	}
}
