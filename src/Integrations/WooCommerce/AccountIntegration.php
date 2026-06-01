<?php
/**
 * WB Gamification — WooCommerce My Account integration.
 *
 * Adds an "Achievements" endpoint to the WooCommerce My Account area
 * (/my-account/achievements/) so stores that run WooCommerce WITHOUT
 * BuddyPress still get a member-facing gamification surface. My Account is
 * always the logged-in customer's own account, so this renders the full Hub
 * block (the self dashboard) by reuse — no duplicated display logic — plus a
 * link to the mapped Hub page.
 *
 * Mirrors src/BuddyPress/ProfileIntegration.php for the WooCommerce context.
 *
 * @package WB_Gamification
 */

namespace WBGam\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce My Account "Achievements" endpoint.
 *
 * @package WB_Gamification
 */
final class AccountIntegration {

	/**
	 * My Account endpoint + query-var slug.
	 */
	private const ENDPOINT = 'achievements';

	/**
	 * Boot only when WooCommerce is active.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
	}

	/**
	 * Register the rewrite endpoint. Rewrite rules are flushed on plugin
	 * activation; an already-active install gains the endpoint after the
	 * next flush.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		// One-time flush so the endpoint resolves on an existing install that
		// upgraded into this version (a fresh endpoint isn't in the stored
		// rewrite rules yet). Guarded by an option so it runs exactly once,
		// not on every request.
		if ( ! get_option( 'wb_gam_wc_account_endpoint_v1' ) ) {
			flush_rewrite_rules( false );
			update_option( 'wb_gam_wc_account_endpoint_v1', 1, false );
		}
	}

	/**
	 * Register the endpoint as a WooCommerce query var so the account router
	 * resolves /my-account/achievements/.
	 *
	 * @param array<string,string> $vars Existing WC query vars.
	 * @return array<string,string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Insert the "Achievements" item into the My Account nav, just before
	 * Logout so it sits with the member's own surfaces.
	 *
	 * @param array<string,string> $items Existing menu items.
	 * @return array<string,string>
	 */
	public static function add_menu_item( array $items ): array {
		$logout = array();
		if ( isset( $items['customer-logout'] ) ) {
			$logout = array( 'customer-logout' => $items['customer-logout'] );
			unset( $items['customer-logout'] );
		}
		$items[ self::ENDPOINT ] = __( 'Achievements', 'wb-gamification' );

		return array_merge( $items, $logout );
	}

	/**
	 * Render the endpoint body: the member's full Hub dashboard (reused block)
	 * plus a link to the mapped Hub page. My Account is always self-scoped, so
	 * the Hub block's current-user view is exactly right.
	 */
	public static function render(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		wp_enqueue_style( 'wb-gam-tokens' );
		wp_enqueue_style( 'wb-gamification' );
		wp_enqueue_style( 'lucide-icons' );
		if ( wp_style_is( 'wb-gamification-hub', 'registered' ) ) {
			wp_enqueue_style( 'wb-gamification-hub' );
		}

		$html = do_shortcode( '[wb_gam_hub]' );

		$hub_page_id = (int) get_option( 'wb_gam_hub_page_id', 0 );
		$hub_url     = $hub_page_id ? get_permalink( $hub_page_id ) : '';
		$more        = '';
		if ( $hub_url ) {
			$more = sprintf(
				'<p class="wb-gam-bp-achievements__more"><a href="%s">%s</a></p>',
				esc_url( $hub_url ),
				esc_html__( 'View full dashboard', 'wb-gamification' )
			);
		}

		// $html is block-rendered markup, already escaped by the hub block's
		// render callback (SSR). Re-escaping would corrupt it. Reuses the
		// shared .wb-gam-bp-achievements wrapper (no profile-only class).
		echo '<div class="wb-gam-bp-achievements">' . $html . $more . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
