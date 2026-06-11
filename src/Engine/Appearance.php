<?php
/**
 * Appearance — site-owner control over the member-facing accent color.
 *
 * The frontend gamification UI (badges, hub, progress bars, buttons, toasts)
 * is tinted by a single design token, `--wb-gam-color-accent`, defined in
 * src/shared/design-tokens.css. Out of the box that token resolves to the
 * host theme's link color and falls back to the plugin's default purple
 * (#5b4cdb) when the theme exposes none. Everything else in the accent family
 * (accent-light, accent-text, accent-hover, accent-ring) derives from it via
 * color-mix, so overriding this one token re-tints the whole UI.
 *
 * This class lets a site owner pick a custom accent in Settings. When set, it
 * emits a `:root` override (light + dark) on the frontend so the whole
 * gamification surface adopts the chosen color. When unset (empty option),
 * NOTHING is emitted and the existing theme-token / purple fallback applies —
 * the "override if set, else theme default" contract.
 *
 * @package WB_Gamification
 * @since   1.5.5
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Member-facing accent color override.
 */
final class Appearance {

	/**
	 * Option storing the admin-chosen accent hex. Empty string = theme default.
	 *
	 * @var string
	 */
	public const OPTION = 'wb_gam_accent_color';

	/**
	 * Curated preset accents offered in the admin picker (label => hex).
	 * These are quick choices; the picker also accepts any custom hex.
	 *
	 * @return array<string, string>
	 */
	public static function presets(): array {
		return array(
			__( 'Indigo (default)', 'wb-gamification' ) => '#5b4cdb',
			__( 'Blue', 'wb-gamification' )             => '#2563eb',
			__( 'Emerald', 'wb-gamification' )          => '#059669',
			__( 'Amber', 'wb-gamification' )            => '#d97706',
			__( 'Rose', 'wb-gamification' )             => '#e11d48',
			__( 'Slate', 'wb-gamification' )            => '#475569',
		);
	}

	/**
	 * Boot — emit the override on the frontend when an accent is set.
	 *
	 * Hooked at wp_enqueue_scripts priority 20 so the `wb-gam-tokens` style is
	 * already registered (wb-gamification.php registers it during
	 * enqueue_assets). We attach the override as inline CSS on that handle, so
	 * it loads through the normal style pipeline (no inline <style> in PHP) and
	 * always after the base token definitions it overrides.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_override' ), 20 );
	}

	/**
	 * The current accent hex, or '' when the site uses the theme default.
	 *
	 * @return string Lowercase 7-char hex (e.g. "#5b4cdb"), or empty string.
	 */
	public static function get_accent(): string {
		$raw = (string) get_option( self::OPTION, '' );
		if ( '' === $raw ) {
			return '';
		}
		// sanitize_hex_color() returns null for anything that is not a valid
		// #rrggbb / #rgb hex, so a corrupted option degrades to theme default.
		$hex = sanitize_hex_color( $raw );
		return is_string( $hex ) ? strtolower( $hex ) : '';
	}

	/**
	 * Persist the accent choice. Empty/invalid clears it (back to theme default).
	 *
	 * @param string $value Hex string, or '' to reset to theme default.
	 * @return void
	 */
	public static function set_accent( string $value ): void {
		$hex = sanitize_hex_color( $value );
		if ( is_string( $hex ) && '' !== $hex ) {
			update_option( self::OPTION, strtolower( $hex ) );
		} else {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Build the `:root` override CSS for the current accent.
	 *
	 * Returns an empty string when no accent is set — the caller emits nothing,
	 * so the theme-token / purple fallback in design-tokens.css applies.
	 *
	 * accent-light and accent-text already derive from --wb-gam-color-accent
	 * via color-mix in the base stylesheet, so they re-tint automatically; we
	 * only need to restate accent, accent-hover and accent-ring (which the base
	 * file pins to theme vars / a literal rgba). The dark-mode selectors mirror
	 * the base file's dark treatment so a custom accent stays consistent in
	 * dark mode instead of snapping back to the purple default.
	 *
	 * @return string CSS, or '' when no override is active.
	 */
	public static function inline_css(): string {
		$accent = self::get_accent();
		if ( '' === $accent ) {
			return '';
		}

		return sprintf(
			':root{' .
				'--wb-gam-color-accent:%1$s;' .
				'--wb-gam-color-accent-hover:color-mix(in srgb,%1$s 82%%,#000);' .
				'--wb-gam-color-accent-ring:color-mix(in srgb,%1$s 25%%,transparent);' .
			'}' .
			':root[data-bx-mode="dark"],body.buddyx-dark-theme{' .
				'--wb-gam-color-accent:color-mix(in srgb,%1$s 60%%,#fff);' .
				'--wb-gam-color-accent-hover:color-mix(in srgb,%1$s 45%%,#fff);' .
			'}' .
			'@media(prefers-color-scheme:dark){:root[data-bx-mode="auto"]{' .
				'--wb-gam-color-accent:color-mix(in srgb,%1$s 60%%,#fff);' .
				'--wb-gam-color-accent-hover:color-mix(in srgb,%1$s 45%%,#fff);' .
			'}}',
			$accent
		);
	}

	/**
	 * Attach the override to the registered token stylesheet (frontend).
	 *
	 * @return void
	 */
	public static function enqueue_override(): void {
		$css = self::inline_css();
		if ( '' === $css ) {
			return;
		}
		// Ensure the base token style is enqueued so our inline CSS has a host
		// handle and loads after it. wb-gamification.php registers it; blocks
		// normally enqueue it, but a page may show only a shortcode that
		// enqueues a different handle — enqueue defensively.
		if ( wp_style_is( 'wb-gam-tokens', 'registered' ) ) {
			wp_enqueue_style( 'wb-gam-tokens' );
			wp_add_inline_style( 'wb-gam-tokens', $css );
		}
	}
}
