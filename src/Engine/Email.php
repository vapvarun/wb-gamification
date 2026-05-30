<?php
/**
 * WB Gamification — Email template renderer
 *
 * Thin convenience wrapper around `Templates::locate()` / `Templates::render()`
 * with email-specific defaults (HTML content-type header, From header
 * sourced from settings option chain).
 *
 * Resolution order (handled by Templates::locate, first match wins):
 *   1. Filter:   wb_gam_template_path
 *   2. Theme:    {child-theme}/wb-gamification/emails/{template}.php
 *   3. Theme:    {parent-theme}/wb-gamification/emails/{template}.php
 *   4. Plugin:   WB_GAM_PATH/templates/emails/{template}.php
 *
 * @package WB_Gamification
 * @since   1.0.0
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
 * Resolves and renders email body templates.
 *
 * @package WB_Gamification
 */
final class Email {

	/**
	 * Render an email template to an HTML string.
	 *
	 * @param string $template Template slug — looks for templates/emails/{slug}.php
	 *                         in the plugin and YOUR-THEME/wb-gamification/emails/{slug}.php
	 *                         in the active theme.
	 * @param array  $vars     Variables to extract into the template scope.
	 *                         The template can reference each key as a local variable.
	 * @return string Rendered HTML, or empty string if no template was found.
	 */
	public static function render( string $template, array $vars = array() ): string {
		$path = self::locate( $template );
		if ( ! $path ) {
			return '';
		}

		ob_start();
		// extract() is the standard WP template-loading pattern (see
		// load_template / wc_get_template). Variables become local to
		// the included template's scope.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		// phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
		include $path;
		return (string) ob_get_clean();
	}

	/**
	 * Locate the absolute path to an email template.
	 *
	 * Resolution:
	 *   1. wb_gam_email_template_path filter (full programmatic override).
	 *   2. {theme}/wb-gamification/emails/{template}.php  (child theme wins).
	 *   3. WB_GAM_PATH/templates/emails/{template}.php   (plugin default).
	 *
	 * @param string $template Template slug.
	 * @return string Absolute path to the template, or '' if not found.
	 */
	public static function locate( string $template ): string {
		$slug = sanitize_key( $template );

		// Single resolver — Templates::locate handles theme override, child→parent
		// fallback, and the wb_gam_template_path filter for programmatic override.
		return Templates::locate( "emails/{$slug}.php" );
	}

	/**
	 * Build a "Name <email>" From header value.
	 *
	 * Centralizes the From-header logic so every email engine uses the
	 * same option chain. Filterable for full override.
	 *
	 * @param string $name_option_key Option key for the From-name.
	 * @return string Formatted From header.
	 */
	public static function from_header( string $name_option_key = 'wb_gam_weekly_email_from_name' ): string {
		$name  = get_option( $name_option_key, get_bloginfo( 'name' ) );
		$email = get_option( 'admin_email' );

		/**
		 * Filter the From header value used by WB Gamification emails.
		 *
		 * @param string $header           Formatted "Name <email>" string.
		 * @param string $name             Resolved From-name.
		 * @param string $email            Resolved From-email.
		 * @param string $name_option_key  The option key the name came from.
		 */
		return (string) apply_filters(
			'wb_gam_email_from_header',
			sprintf( '%s <%s>', $name, $email ),
			$name,
			$email,
			$name_option_key
		);
	}
}
