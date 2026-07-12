<?php
/**
 * Path utility class.
 *
 * @package EasyDigitalDownloads\Updater
 * @since 1.0.1
 */

namespace EasyDigitalDownloads\Updater\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit; // @codeCoverageIgnore

/**
 * Path utility class for managing SDK paths and URLs.
 */
class Path {

	/**
	 * Stores the current SDK directory.
	 *
	 * @var string
	 */
	private static $sdk_dir = '';

	/**
	 * Stores the current SDK URL.
	 *
	 * @var string
	 */
	private static $sdk_url = '';

	/**
	 * Stores the current SDK version.
	 *
	 * @var string
	 */
	private static $sdk_version = '';

	/**
	 * Sets the SDK paths and version based on a file location.
	 *
	 * @since 1.0.1
	 * @param string $file    The __FILE__ constant from the SDK main plugin file.
	 * @param string $version The version number.
	 * @return void
	 */
	public static function set( $file, $version = '1.0.0' ) {
		self::$sdk_dir     = dirname( $file );
		self::$sdk_version = $version;

		/*
		 * WBCOM PATCH (Basecamp #10081934029) — derive the URL with plugins_url()
		 * instead of hand-building it from $_SERVER['DOCUMENT_ROOT'].
		 *
		 * Upstream did:
		 *
		 *   $relative_path = str_replace( realpath( $_SERVER['DOCUMENT_ROOT'] ), '', self::$sdk_dir );
		 *   self::$sdk_url = trailingslashit( "$protocol://$host/$relative_path" );
		 *
		 * That assumes DOCUMENT_ROOT is a string prefix of the SDK's real path. It
		 * is not, on any host where the plugin directory is a symlink (PHP's
		 * __FILE__ resolves symlinks, so sdk_dir is the link TARGET) or where the
		 * document root simply doesn't prefix-match the plugin path — LocalWP,
		 * tastewp, and many live hosts. The str_replace then matches nothing,
		 * NOTHING is stripped, and the full filesystem path is pasted into the
		 * URL, e.g.
		 *
		 *   http://example.test/Users/me/dev/repos/wb-gamification/libs/.../
		 *
		 * so edd-sl-sdk.js and style-edd-sl-sdk.css 404 and the licence UI breaks.
		 *
		 * plugins_url() is symlink-safe: WordPress records the realpath→plugin-dir
		 * mapping in wp_register_plugin_realpath(), so it returns the correct URL
		 * whether it is handed the symlinked path or the resolved one. It also
		 * honours WP_PLUGIN_URL / content-dir relocation and gets the scheme right
		 * without sniffing $_SERVER.
		 *
		 * Keep this patch when re-vendoring the SDK until upstream fixes it.
		 * Same fix shipped in BuddyNext 1.0.7 (commit 40effc78).
		 */
		self::$sdk_url = trailingslashit( plugins_url( '', $file ) );
	}

	/**
	 * Gets the SDK directory.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_dir() {
		return self::$sdk_dir;
	}

	/**
	 * Gets the SDK URL.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_url() {
		return self::$sdk_url;
	}

	/**
	 * Gets the SDK version.
	 *
	 * @since 1.0.1
	 * @return string
	 */
	public static function get_version() {
		return self::$sdk_version;
	}
}
