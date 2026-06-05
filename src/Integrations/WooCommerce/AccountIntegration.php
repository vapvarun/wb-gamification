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
	 * Register the rewrite endpoint and self-heal the stored rewrite rules
	 * when the endpoint is missing from them.
	 *
	 * Rewrite rules are normally flushed on plugin activation. A one-time
	 * option guard is not enough on its own: the option can survive while the
	 * actual `rewrite_rules` option does not contain the endpoint rule — site
	 * restores / migration clones that keep the options table but lose
	 * regenerated rules, another plugin flushing rules after WooCommerce has
	 * already built its query-var rules, or the upgrade flush firing before the
	 * WC endpoint rules were assembled. Once the guard option is set, the old
	 * code could never recover and the endpoint 404s forever.
	 *
	 * Instead, probe the stored rewrite rules for the registered endpoint and
	 * flush only when it is actually absent. This mirrors the Installer's
	 * SHOW TABLES self-heal: cheap probe, fix the real broken state, no-op on
	 * healthy sites. The probe runs on `init` after WooCommerce has registered
	 * its own account rules, so a present rule means the endpoint resolves.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( self::endpoint_rule_missing() ) {
			flush_rewrite_rules( false );

			// Keep the legacy guard option in sync for back-compat with sites
			// that already set it; the probe is now the source of truth.
			if ( ! get_option( 'wb_gam_wc_account_endpoint_v1' ) ) {
				update_option( 'wb_gam_wc_account_endpoint_v1', 1, false );
			}
		}
	}

	/**
	 * Whether the achievements endpoint is absent from the stored rewrite
	 * rules and therefore needs a flush to start resolving.
	 *
	 * Reads the persisted `rewrite_rules` option directly rather than the
	 * runtime rules so the probe reflects what the front-end router will
	 * actually match on the next request.
	 *
	 * Important: when NO rules are stored at all (pretty permalinks disabled,
	 * or rules genuinely never generated) this returns false. WooCommerce
	 * account endpoints rely on the rewrite system, and forcing a flush on
	 * every request when permalinks are off would be a per-request flush storm
	 * that fixes nothing. The self-heal only fires when rules exist but ours is
	 * missing — the genuine "rules regenerated without our endpoint" case.
	 *
	 * @return bool True when stored rules exist but lack the endpoint rule.
	 */
	private static function endpoint_rule_missing(): bool {
		$rules = get_option( 'rewrite_rules' );

		// No stored rules: permalinks are likely plain. Nothing to self-heal
		// here, and flushing every request would be a no-win loop. The legacy
		// activation flush still covers the first transition to pretty links.
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return false;
		}

		$needle = '/' . self::ENDPOINT . '(/(.*))?/?$';
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( is_string( $pattern ) && false !== strpos( $pattern, $needle ) ) {
				return false;
			}
		}

		return true;
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
