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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Auto-discovers and registers gamification manifest files from plugins at boot time.
 *
 * @package WB_Gamification
 */
final class ManifestLoader {

	/**
	 * All validated action definitions collected during the current scan.
	 *
	 * @var array<int, array>
	 */
	private static array $loaded_actions = array();

	/**
	 * Triggers buffered during the scan, keyed by action id, awaiting the
	 * supersession pass before they are handed to the Registry.
	 *
	 * Buffering (rather than registering inline) makes supersession resolution
	 * independent of manifest load order: `integrations/buddypress.php` is
	 * globbed before `integrations/wordpress.php`, yet `wp_publish_post` must
	 * still be able to supersede `bp_publish_post`.
	 *
	 * @var array<string, array>
	 */
	private static array $buffered = array();

	/**
	 * Action ids that a buffered trigger has declared it supersedes.
	 *
	 * Any id in this set is dropped from the buffer before registration so the
	 * same real-world event is never awarded twice (e.g. BP Member Blog's
	 * `bp_publish_post` yields to the always-on core `wp_publish_post`).
	 *
	 * @var array<string, true>
	 */
	private static array $superseded = array();

	/**
	 * Scan all manifest locations and register discovered triggers.
	 *
	 * Runs at plugins_loaded priority 5, before Registry::init() at 6.
	 */
	public static function scan(): void {
		self::$loaded_actions = array();
		self::$buffered       = array();
		self::$superseded     = array();

		$bp_active = function_exists( 'buddypress' );

		self::load_first_party( $bp_active );
		self::load_from_plugins( $bp_active );

		// Resolve supersession across every buffered trigger (order-independent),
		// then register what survives.
		self::flush_buffer();

		/**
		 * Fires after all manifest files have been loaded and validated.
		 *
		 * Use this hook to inspect, modify, or extend the set of discovered
		 * action definitions before they are registered with the engine.
		 *
		 * @since 1.0.0
		 *
		 * @param array $actions All loaded action definitions.
		 */
		do_action( 'wb_gam_manifests_loaded', self::$loaded_actions );
	}

	/**
	 * Register every buffered trigger except those a sibling trigger superseded.
	 *
	 * Supersession lets a canonical trigger claim a real-world event that an
	 * otherwise-redundant trigger also listens for, guaranteeing a single award
	 * per event regardless of which manifest loaded first. The superseded
	 * trigger is dropped entirely (never registered, never hooked), so there is
	 * no closure left behind to double-fire.
	 */
	private static function flush_buffer(): void {
		foreach ( self::$buffered as $id => $trigger ) {
			if ( isset( self::$superseded[ $id ] ) ) {
				Log::warning(
					'ManifestLoader: trigger superseded by a canonical sibling — skipped.',
					array( 'action_id' => $id )
				);
				continue;
			}

			// `supersedes` is a manifest-only directive; strip it before the
			// Registry sees the action.
			unset( $trigger['supersedes'] );

			self::$loaded_actions[] = $trigger;
			wb_gam_register_action( $trigger );
		}
	}

	/**
	 * Whether the in-flight scan is processing a third-party manifest.
	 *
	 * Set by load_from_plugins() before each register_from_file() call and
	 * cleared after. Used by register_manifest() to silently skip duplicate
	 * action_ids that third-party manifests redeclare — first-party (in-tree)
	 * manifests always win precedence on collision so this plugin can ship
	 * the canonical definition and bundled manifests gracefully fill gaps.
	 *
	 * @var bool
	 */
	private static bool $loading_third_party = false;

	/**
	 * Load first-party manifests bundled with this plugin.
	 *
	 * @param bool $bp_active Whether BuddyPress is active.
	 */
	private static function load_first_party( bool $bp_active ): void {
		// Two-tier first-party manifest layout:
		// integrations/            — direct-vendor integrations (BP, WC, WP-core)
		// integrations/contrib/    — third-party-shipped contrib manifests
		// (LifterLMS, MemberPress, GiveWP, The Events Calendar)
		// Both directories are scanned at top level (no recursive descent).
		$paths = array(
			WB_GAM_PATH . 'integrations/',
			WB_GAM_PATH . 'integrations/contrib/',
		);

		/**
		 * Filters the directories scanned for gamification manifest files.
		 *
		 * Add custom directory paths to this array so the ManifestLoader
		 * discovers manifest files in non-standard locations (e.g. themes
		 * or mu-plugins).
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $paths Array of directory paths to scan for *.php manifest files.
		 */
		$paths = apply_filters( 'wb_gam_manifest_paths', $paths );

		foreach ( $paths as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '*.php' );
			if ( empty( $files ) ) {
				continue;
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
	}

	/**
	 * Scan all active plugin directories for third-party manifest files.
	 *
	 * A third-party plugin ships its own `wb-gamification.php` manifest in its
	 * plugin directory. The file is only loaded when that plugin is active —
	 * deactivated plugins leave the file on disk, but their manifest must not
	 * register actions (otherwise the Registry stays polluted with stale IDs
	 * after deactivation).
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

		// Build the active-plugin directory set once (handles both single-site
		// and multisite network-activated plugins).
		$active_basenames = array_merge(
			(array) get_option( 'active_plugins', array() ),
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);
		$active_dirs      = array();
		foreach ( $active_basenames as $basename ) {
			if ( false !== strpos( $basename, '/' ) ) {
				$active_dirs[ strstr( $basename, '/', true ) ] = true;
			}
		}

		$own_path = realpath( WB_GAM_PATH );

		foreach ( $files as $file ) {
			// Skip our own plugin directory to avoid re-including the main plugin
			// file (which would redeclare WB_Gamification). Compare resolved real
			// paths: under a symlinked plugin dir (common in local dev) WB_GAM_PATH
			// resolves to the link target while glob() returns the symlink path, so
			// a raw strpos() would miss the match and re-include the main file.
			$real_file = realpath( $file );
			if ( false !== $real_file && false !== $own_path && 0 === strpos( $real_file, $own_path ) ) {
				continue;
			}
			if ( 0 === strpos( $file, WB_GAM_PATH ) ) {
				continue;
			}

			// Skip manifests belonging to inactive plugins. Files on disk persist
			// across deactivation; only the active set should populate the registry.
			$plugin_dir = basename( dirname( $file ) );
			if ( empty( $active_dirs[ $plugin_dir ] ) ) {
				continue;
			}

			self::$loading_third_party = true;
			try {
				self::register_from_file( $file, $bp_active );
			} finally {
				self::$loading_third_party = false;
			}
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

		// Validate manifest return value.
		if ( ! is_array( $manifest ) ) {
			Log::warning(
				'ManifestLoader: manifest did not return an array.',
				array( 'file' => $file )
			);
			return;
		}

		if ( empty( $manifest['triggers'] ) ) {
			return;
		}

		self::register_manifest( $manifest, $file, $bp_active );
	}

	/**
	 * Register all triggers from a manifest array.
	 *
	 * Trigger flags:
	 *   standalone_only: true     — skip when BuddyPress is active (BP covers the same event).
	 *   requires_buddypress: true — skip when BuddyPress is NOT active.
	 *   supersedes: [id, ...]     — once this trigger survives validation, drop the
	 *                               listed action ids from the registration set so
	 *                               the same event is never awarded twice.
	 *
	 * Triggers are buffered here and registered later by flush_buffer() so that
	 * supersession resolves regardless of manifest load order.
	 *
	 * @param array  $manifest  Manifest data with optional 'plugin', 'version', and 'triggers' keys.
	 * @param string $file      Absolute path to the manifest file (used in debug messages).
	 * @param bool   $bp_active Whether BuddyPress is active.
	 */
	private static function register_manifest( array $manifest, string $file, bool $bp_active ): void {
		$required_keys = array( 'id', 'hook', 'default_points' );

		foreach ( (array) ( $manifest['triggers'] ?? array() ) as $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}

			// Validate required keys exist on each trigger.
			$skip = false;
			foreach ( $required_keys as $key ) {
				if ( empty( $trigger[ $key ] ) ) {
					Log::warning(
						'ManifestLoader: trigger missing required key.',
						array(
							'file' => $file,
							'key'  => $key,
						)
					);
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
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

			// Remove the BP-gating flags before passing to the Registry. The
			// `supersedes` directive is kept on the buffered trigger and resolved
			// in flush_buffer(); it is stripped there before registration.
			unset( $trigger['standalone_only'], $trigger['requires_buddypress'] );

			// Inject the manifest's top-level plugin key so the Registry
			// can report which plugin registered the action on collision.
			if ( ! empty( $manifest['plugin'] ) && ! isset( $trigger['plugin'] ) ) {
				$trigger['plugin'] = $manifest['plugin'];
			}

			$action_id = (string) $trigger['id'];

			// Third-party manifests defer to first-party on collision. The
			// in-tree manifest in this plugin is the canonical source for any
			// integration we ship for; third-party bundled manifests (e.g.
			// WPMediaVerse Pro <= 1.1.3 still ships its own wb-gamification.php)
			// silently yield on duplicate ids so the in-tree definition wins.
			// First-party-vs-first-party collisions still trip Registry's
			// _doing_it_wrong path because they signal a genuine bug. The buffer
			// (not the live Registry) is the duplicate source of truth now that
			// registration is deferred to flush_buffer().
			if ( self::$loading_third_party && isset( self::$buffered[ $action_id ] ) ) {
				continue;
			}

			// Record any ids this trigger supersedes so flush_buffer() can drop
			// them. A superseded id is removed even if it was buffered earlier
			// (load order independent).
			if ( ! empty( $trigger['supersedes'] ) ) {
				foreach ( (array) $trigger['supersedes'] as $superseded_id ) {
					self::$superseded[ (string) $superseded_id ] = true;
				}
			}

			// Buffer the validated trigger; flush_buffer() registers survivors.
			self::$buffered[ $action_id ] = $trigger;
		}
	}
}
