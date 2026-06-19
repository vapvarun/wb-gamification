<?php
namespace Wbcom\Family;

defined( 'ABSPATH' ) || exit;

/**
 * Local, network-free install-state detection for family members.
 */
class State {

	/**
	 * @param array $member A registry member (uses slug_free).
	 * @return string not_installed|installed_inactive|active
	 */
	public static function member_state( array $member ): string {
		$free = $member['slug_free'] ?? '';
		if ( '' === $free ) {
			return 'not_installed';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( (array) \get_plugins() );
		if ( ! in_array( $free, $installed, true ) ) {
			return 'not_installed';
		}
		return \is_plugin_active( $free ) ? 'active' : 'installed_inactive';
	}

	/**
	 * @param array  $registry Full registry.
	 * @param string $outcome  Outcome key.
	 */
	public static function outcome_available( array $registry, string $outcome ): bool {
		$requires = $registry['outcomes'][ $outcome ]['requires'] ?? array();
		foreach ( $requires as $slug ) {
			$member = $registry['members'][ $slug ] ?? null;
			if ( null === $member || 'active' !== self::member_state( $member ) ) {
				return false;
			}
		}
		return ! empty( $requires );
	}
}
