<?php
/**
 * WB Gamification Manifest Loader
 *
 * Auto-discovers gamification manifest files at boot time.
 * No plugin needs to register with WB Gamification — just drop a
 * file named 'wb-gamification.php' in the plugin directory.
 *
 * Discovery order (both run at plugins_loaded priority 5):
 *   1. First-party manifests: WB_GAM_PATH . 'integrations/*.php'
 *   2. Third-party manifests: WP_PLUGIN_DIR/{plugin}/wb-gamification.php
 *
 * Manifest files return a plain PHP array — no dependency on this
 * plugin being active. The file is simply ignored if this plugin
 * is not installed.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-discovers and registers gamification manifest files from plugins at boot time.
 *
 * @package WB_Gamification
 */
final class ManifestLoader {

	/**
	 * Scan all manifest locations and register discovered triggers.
	 *
	 * Runs at plugins_loaded priority 5, before Registry::init() at 6.
	 */
	public static function scan(): void {
		$bp_active = function_exists( 'buddypress' );

		self::load_first_party( $bp_active );
		self::load_from_plugins( $bp_active );
	}

	/**
	 * Load first-party manifests bundled with this plugin.
	 *
	 * @param bool $bp_active Whether BuddyPress is active.
	 */
	private static function load_first_party( bool $bp_active ): void {
		$dir = WB_GAM_PATH . 'integrations/';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '*.php' );
		if ( empty( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			$slug = basename( $file, '.php' );

			// BuddyPress manifest: only load when BP is active.
			if ( 'buddypress' === $slug && ! $bp_active ) {
				continue;
			}

			self::register_from_file( $file, $bp_active );
		}
	}

	/**
	 * Scan all active plugin directories for third-party manifest files.
	 *
	 * @param bool $bp_active Whether BuddyPress is active.
	 */
	private static function load_from_plugins( bool $bp_active ): void {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return;
		}

		$files = glob( WP_PLUGIN_DIR . '/*/wb-gamification.php' );
		if ( empty( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			// Skip our own plugin directory to avoid loading the main plugin file.
			if ( 0 === strpos( $file, WB_GAM_PATH ) ) {
				continue;
			}

			self::register_from_file( $file, $bp_active );
		}
	}

	/**
	 * Load a single manifest file and register its triggers.
	 *
	 * @param string $file      Absolute path to the manifest file.
	 * @param bool   $bp_active Whether BuddyPress is active.
	 */
	private static function register_from_file( string $file, bool $bp_active ): void {
		if ( ! is_readable( $file ) ) {
			return;
		}

		// phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		$manifest = include $file;

		if ( ! is_array( $manifest ) || empty( $manifest['triggers'] ) ) {
			return;
		}

		self::register_manifest( $manifest, $bp_active );
	}

	/**
	 * Register all triggers from a manifest array.
	 *
	 * Trigger flags:
	 *   standalone_only: true     — skip when BuddyPress is active (BP covers the same event).
	 *   requires_buddypress: true — skip when BuddyPress is NOT active.
	 *
	 * @param array $manifest  Manifest data with optional 'plugin', 'version', and 'triggers' keys.
	 * @param bool  $bp_active Whether BuddyPress is active.
	 */
	private static function register_manifest( array $manifest, bool $bp_active ): void {
		foreach ( (array) ( $manifest['triggers'] ?? array() ) as $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}

			// Skip standalone-only triggers when BuddyPress is active.
			if ( ! empty( $trigger['standalone_only'] ) && $bp_active ) {
				continue;
			}

			// Skip BP-only triggers when BuddyPress is not active.
			if ( ! empty( $trigger['requires_buddypress'] ) && ! $bp_active ) {
				continue;
			}

			// Remove manifest-only flags before passing to the Registry.
			unset( $trigger['standalone_only'], $trigger['requires_buddypress'] );

			wb_gamification_register_action( $trigger );
		}
	}
}
