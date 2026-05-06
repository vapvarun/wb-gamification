<?php
/**
 * WB Gamification — Generic template loader.
 *
 * Resolves any plugin-shipped template to a filesystem path with full
 * theme-override support, modelled on WooCommerce's `wc_get_template()`.
 *
 * Resolution order (first hit wins):
 *   1. `wb_gam_template_path` filter — full programmatic override.
 *   2. Theme override via `locate_template`:
 *        {child-theme}/wb-gamification/{relative}
 *        {parent-theme}/wb-gamification/{relative}
 *   3. Plugin default: `WB_GAM_PATH/templates/{relative}`
 *
 * Use this for any template a plugin extension might want to override —
 * email bodies (already wired via Email::render), partial fragments
 * inside block render PHP, member-facing share pages, etc.
 *
 * Block render.php files themselves are NOT theme-overridable (the
 * Gutenberg block API doesn't permit it). Use `wb_gam_block_<slug>_data`
 * to mutate block input, or the BlockHooks before/after actions to
 * inject HTML.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Theme-overridable template resolver.
 *
 * @package WB_Gamification
 */
final class Templates {

	/**
	 * Resolve a relative template path to an absolute filesystem path.
	 *
	 * @param string $relative Relative path under templates/ — e.g.
	 *                         'emails/weekly-recap.php',
	 *                         'partials/badge-share.php'.
	 * @return string Absolute path, or empty string if not found.
	 */
	public static function locate( string $relative ): string {
		$relative      = ltrim( $relative, '/' );
		$theme_subpath = "wb-gamification/{$relative}";
		$plugin_path   = WB_GAM_PATH . "templates/{$relative}";

		// Theme override (child → parent fallback handled by core).
		$theme_match = locate_template( $theme_subpath );
		$default     = $theme_match ?: $plugin_path;

		/**
		 * Filter the resolved template path for any plugin template.
		 *
		 * Use to point at a file anywhere on the filesystem (e.g. shared
		 * library template store) regardless of theme/plugin layout.
		 *
		 * @since 1.0.0
		 *
		 * @param string $path     Resolved path (theme override or plugin default).
		 * @param string $relative Relative request path (e.g. 'emails/weekly-recap.php').
		 * @param array  $context  ['theme_match' => string|false, 'plugin_path' => string].
		 */
		$path = (string) apply_filters(
			'wb_gam_template_path',
			$default,
			$relative,
			array(
				'theme_match' => $theme_match,
				'plugin_path' => $plugin_path,
			)
		);

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string $relative Relative path under templates/.
	 * @param array  $vars     Variables to extract into the template scope.
	 * @return string Rendered output, or empty string if not found.
	 */
	public static function render( string $relative, array $vars = array() ): string {
		$path = self::locate( $relative );
		if ( '' === $path ) {
			return '';
		}

		ob_start();
		// Standard WP template-loading pattern (see load_template).
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		// phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		include $path;
		return (string) ob_get_clean();
	}

	/**
	 * Echo a template directly (skips the buffer round-trip).
	 *
	 * Prefer this over render() when the caller is already inside a
	 * render context and doesn't need the output as a string.
	 *
	 * @param string $relative Relative path under templates/.
	 * @param array  $vars     Variables to extract into the template scope.
	 */
	public static function output( string $relative, array $vars = array() ): void {
		$path = self::locate( $relative );
		if ( '' === $path ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		// phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		include $path;
	}
}
