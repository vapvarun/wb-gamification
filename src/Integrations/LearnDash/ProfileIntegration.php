<?php
/**
 * WB Gamification — LearnDash profile integration.
 *
 * LearnDash only exposes a "before template" hook on its profile, so rather
 * than stacking stat blocks or columns on the course profile, we add a single
 * clean link to the gamification dashboard (the mapped Hub page) at the top of
 * the profile. One unobtrusive CTA - the full detail lives on the Hub. Loads
 * only when LearnDash is active.
 *
 * @package WB_Gamification
 * @since   1.5.2
 */

namespace WBGam\Integrations\LearnDash;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a single "My Achievements" hub link to the LearnDash profile.
 *
 * @package WB_Gamification
 */
final class ProfileIntegration {

	/**
	 * Boot only when LearnDash is active.
	 */
	public static function init(): void {
		if ( ! defined( 'LEARNDASH_VERSION' ) ) {
			return;
		}

		/**
		 * Whether to add the gamification link to the LearnDash profile.
		 *
		 * OFF by default. LearnDash's only profile extension point is a
		 * top-of-page hook, so the link can't be placed as natively as the
		 * BuddyPress tab or WooCommerce endpoint. Sites that want it opt in:
		 *
		 *   add_filter( 'wb_gam_learndash_profile_link', '__return_true' );
		 *
		 * @since 1.5.2
		 *
		 * @param bool $enabled Whether to show the link. Default false.
		 */
		if ( ! apply_filters( 'wb_gam_learndash_profile_link', false ) ) {
			return;
		}

		add_action( 'learndash_shortcode_profile_before_template', array( __CLASS__, 'render' ) );
	}

	/**
	 * Output one link to the gamification dashboard. Resolves from the MAPPED
	 * hub page (wb_gam_hub_page_id), never a hardcoded slug; renders nothing
	 * when no hub page is mapped or the viewer is logged out.
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
			'<p class="wb-gam-ld-link"><a class="wb-gam-ld-link__btn" href="%s">%s</a></p>',
			esc_url( $hub_url ),
			esc_html__( 'My Achievements', 'wb-gamification' )
		);
	}
}
