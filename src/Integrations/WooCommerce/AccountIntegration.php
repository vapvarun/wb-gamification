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

use WBGam\Engine\MemberSurface;

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

		// My Account is always self-scoped, so render the full Hub block for
		// the current user. MemberSurface owns the shared plumbing (assets,
		// mapped "View full dashboard" link, wrapper) — same path as the
		// BuddyPress tab, no duplicated display logic. Block SSR markup is
		// already escaped; re-escaping would corrupt it.
		echo MemberSurface::render( do_shortcode( '[wb_gam_hub]' ), (int) $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
