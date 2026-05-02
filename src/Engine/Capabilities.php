<?php
/**
 * WB Gamification — Capabilities
 *
 * Owns all custom capability registration. Granted on activation,
 * removed on uninstall. The administrator role gets every cap; site
 * owners can grant individual caps to other roles via a role-manager
 * plugin (User Role Editor, Members, etc.) or programmatically.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Manages plugin custom capabilities.
 *
 * Provides granular caps so site owners can delegate plugin operation
 * to non-admin roles (e.g. community managers, support staff). Each
 * REST controller and admin-area handler accepts EITHER `manage_options`
 * (the default WP admin gate) OR the corresponding granular cap. Admins
 * keep working without any reconfiguration; non-admins gain access only
 * for the specific surface their cap unlocks.
 *
 * @package WB_Gamification
 */
final class Capabilities {

	/**
	 * Capabilities granted by this plugin.
	 *
	 * Each cap maps to the roles that receive it on activation.
	 * `administrator` always receives every cap (matches the existing
	 * manage_options gating). Non-admin roles can be granted individual
	 * caps via add_cap by site owners.
	 *
	 * @var array<string,string[]>
	 */
	private const CAPS = array(
		// Manual point award + revoke (PointsController).
		'wb_gam_award_manual'      => array( 'administrator' ),
		// Badge library + rule management (BadgesController, RulesController, BadgeAdminPage).
		'wb_gam_manage_badges'     => array( 'administrator' ),
		// Individual + community challenges (ChallengesController).
		'wb_gam_manage_challenges' => array( 'administrator' ),
		// Redemption store catalog (RedemptionController).
		'wb_gam_manage_rewards'    => array( 'administrator' ),
		// Outbound webhooks (WebhooksController).
		'wb_gam_manage_webhooks'   => array( 'administrator' ),
		// Analytics dashboard view (AnalyticsDashboard).
		'wb_gam_view_analytics'    => array( 'administrator' ),
	);

	/**
	 * Option key tracking the version of CAPS that has been applied.
	 *
	 * Bumped when CAPS changes. The `sync()` method checks this and
	 * re-runs `register()` on existing installs that haven't received
	 * the new caps yet.
	 *
	 * @var string
	 */
	public const CAPS_VERSION_OPTION = 'wb_gam_caps_version';

	/**
	 * Current CAPS version. Bump when CAPS changes.
	 *
	 * @var string
	 */
	public const CAPS_VERSION = '1.1';

	/**
	 * Grant every plugin cap to its default roles.
	 *
	 * Idempotent — calling repeatedly is a no-op (WP_Role::add_cap
	 * checks if the cap already exists before re-storing).
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::CAPS as $cap => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
		update_option( self::CAPS_VERSION_OPTION, self::CAPS_VERSION );
	}

	/**
	 * Re-run register() if the stored CAPS version is older than current.
	 *
	 * Called from plugins_loaded so existing installs that don't get an
	 * activation cycle still receive newly-introduced caps. No-op once
	 * the option matches CAPS_VERSION.
	 *
	 * @return void
	 */
	public static function sync(): void {
		if ( get_option( self::CAPS_VERSION_OPTION ) !== self::CAPS_VERSION ) {
			self::register();
		}
	}

	/**
	 * Remove every plugin cap from every role.
	 *
	 * Called from uninstall.php — leaves no plugin-issued caps
	 * behind on the site.
	 *
	 * @return void
	 */
	public static function unregister(): void {
		foreach ( wp_roles()->roles as $role_name => $_role_data ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_keys( self::CAPS ) as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		delete_option( self::CAPS_VERSION_OPTION );
	}

	/**
	 * The list of caps owned by this plugin.
	 *
	 * Useful for diagnostics, the Doctor CLI command, and tests.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::CAPS );
	}

	/**
	 * Check whether the current user has the given plugin cap OR
	 * the WP admin fallback (manage_options).
	 *
	 * Use from REST permission_callback and admin-screen gates.
	 * Returns true for administrators (who have manage_options) AND
	 * for users granted the specific plugin cap.
	 *
	 * @param string $cap Plugin cap name (e.g. 'wb_gam_manage_badges').
	 * @return bool
	 */
	public static function user_can( string $cap ): bool {
		return current_user_can( 'manage_options' ) || current_user_can( $cap );
	}
}
