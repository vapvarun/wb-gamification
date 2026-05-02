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
 * Today: a single cap (wb_gam_award_manual) consumed by
 * PointsController::admin_permission_check as a fallback after
 * manage_options. Future granular caps land here too.
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
		'wb_gam_award_manual' => array( 'administrator' ),
	);

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
}
