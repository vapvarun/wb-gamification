<?php
/**
 * WB Gamification — Learnomy profile integration.
 *
 * Adds a single "My Achievements" link to the member's Learnomy account page,
 * mirroring the LearnDash profile integration. Learnomy fires
 * `learnomy_account_fields` in three templates: the logged-in account-details
 * page (real user id) and the two registration forms (user id 0, logged out).
 * The `is_user_logged_in()` guard limits the render to a single fire on the
 * account page. One unobtrusive CTA — the full detail lives on the Hub. Loads
 * only when Learnomy is active.
 *
 * @package WB_Gamification
 * @since   1.6.3
 */

namespace WBGam\Integrations\Learnomy;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a single "My Achievements" hub link to the Learnomy account page.
 *
 * @package WB_Gamification
 */
final class ProfileIntegration {

	/**
	 * Boot only when Learnomy is active.
	 */
	public static function init(): void {
		if ( ! defined( 'LEARNOMY_VERSION' ) && ! class_exists( '\\Learnomy\\Plugin' ) ) {
			return;
		}

		/**
		 * Whether to add the gamification link to the Learnomy account page.
		 *
		 * OFF by default, matching the LearnDash integration. Learnomy's
		 * account extension point is a shared template hook, so sites opt in:
		 *
		 *   add_filter( 'wb_gam_learnomy_profile_link', '__return_true' );
		 *
		 * @since 1.6.3
		 *
		 * @param bool $enabled Whether to show the link. Default false.
		 */
		if ( ! apply_filters( 'wb_gam_learnomy_profile_link', false ) ) {
			return;
		}

		add_action( 'learnomy_account_fields', array( __CLASS__, 'render' ) );
	}

	/**
	 * Output one link to the gamification dashboard. Resolves from the MAPPED
	 * hub page (wb_gam_hub_page_id), never a hardcoded slug; renders nothing
	 * when no hub page is mapped or the viewer is logged out.
	 *
	 * `learnomy_account_fields` passes the account owner's user id (0 on the
	 * logged-out registration forms); the login guard makes those a no-op.
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$hub_page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		$hub_url     = $hub_page_id ? get_permalink( $hub_page_id ) : '';
		if ( ! $hub_url ) {
			return;
		}

		wp_enqueue_style( 'wb-gam-tokens' );
		wp_enqueue_style( 'wb-gamification' );

		printf(
			'<p class="wb-gam-lrn-link"><a class="wb-gam-lrn-link__btn" href="%s">%s</a></p>',
			esc_url( $hub_url ),
			esc_html__( 'My Achievements', 'wb-gamification' )
		);
	}
}
