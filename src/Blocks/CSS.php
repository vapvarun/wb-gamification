<?php
/**
 * Per-instance CSS generator for wb-gamification Gutenberg blocks.
 *
 * Phase B of the Wbcom Block Quality Standard migration ports the
 * canonical CSS generator from wbcom-essential v4.5.0
 * (`includes/class-wbe-css.php`) into the wb-gamification PSR-4 tree.
 * Every standardised block consumes this class from its `render.php` to
 * emit a unique-ID-scoped <style> tag in the page footer instead of
 * hardcoding inline styles.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase B.5
 *
 * @package WBGam\Blocks
 */

namespace WBGam\Blocks;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
//   - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
//     established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
//     Plugin Check auto-detects `wb_gamification` from the text-domain header
//     and doesn't share the .phpcs.xml prefix list; hooks like
//     `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
//   - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
//     functions exported under `wb_gam_*` are documented in `src/Extensions/`.
//   - PluginCheck.Security.DirectDB.UnescapedDBParameter +
//     WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
//     table work. Table names are interpolated from `{$wpdb->prefix}` plus
//     literal constants (no user input); user-supplied values pass through
//     `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
//     interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Generates and collects per-instance scoped CSS for standardised blocks.
 *
 * The class operates on the standard attribute schema produced by
 * `src/shared/utils/attributes.js` — per-side spacing objects, three
 * responsive variants, box-shadow + border-radius, typography, and the
 * `uniqueId` attribute that scopes each block instance.
 */
final class CSS {

	/**
	 * Tablet breakpoint upper bound, in pixels.
	 *
	 * Matches the JavaScript `useResponsiveValue` hook so editor preview
	 * and frontend rendering agree.
	 */
	const TABLET_BREAKPOINT = 1024;

	/**
	 * Mobile breakpoint upper bound, in pixels.
	 */
	const MOBILE_BREAKPOINT = 767;

	/**
	 * Collected per-instance CSS keyed by unique id, accumulated across the page.
	 *
	 * @var array<string, string>
	 */
	private static $styles = array();

	/**
	 * Whether the wp_footer hook has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook the footer CSS emitter.
	 *
	 * Idempotent — safe to call from each block's bootstrap.
	 */
	public static function init(): void {
		if ( self::$registered ) {
			return;
		}
		add_action( 'wp_footer', array( __CLASS__, 'output' ) );
		add_action( 'admin_footer', array( __CLASS__, 'output' ) );
		self::$registered = true;
	}

	/**
	 * Generate scoped CSS for a single block instance.
	 *
	 * @param string               $unique_id Block instance ID, sanitised via `sanitize_html_class`.
	 * @param array<string, mixed> $attrs     Block attributes (standard schema).
	 * @return string CSS string. Empty when there is nothing to emit.
	 */
	public static function generate( string $unique_id, array $attrs ): string {
		if ( '' === $unique_id ) {
			return '';
		}

		$selector = '.wb-gam-block-' . sanitize_html_class( $unique_id );

		$desktop = array();
		$tablet  = array();
		$mobile  = array();

		self::collect_spacing( $attrs, 'padding', $desktop, $tablet, $mobile );
		self::collect_spacing( $attrs, 'margin', $desktop, $tablet, $mobile );
		self::collect_border_radius( $attrs, $desktop );
		self::collect_box_shadow( $attrs, $desktop );
		self::collect_typography( $attrs, $desktop, $tablet, $mobile );
		self::collect_colors( $attrs, $desktop );

		$css = '';

		if ( ! empty( $desktop ) ) {
			$css .= $selector . " {\n\t" . implode( "\n\t", $desktop ) . "\n}\n";
		}

		if ( ! empty( $tablet ) ) {
			$css .= sprintf(
				"@media (max-width: %dpx) {\n\t%s {\n\t\t%s\n\t}\n}\n",
				self::TABLET_BREAKPOINT,
				$selector,
				implode( "\n\t\t", $tablet )
			);
		}

		if ( ! empty( $mobile ) ) {
			$css .= sprintf(
				"@media (max-width: %dpx) {\n\t%s {\n\t\t%s\n\t}\n}\n",
				self::MOBILE_BREAKPOINT,
				$selector,
				implode( "\n\t\t", $mobile )
			);
		}

		/**
		 * Filter the generated per-instance CSS for a block.
		 *
		 * @param string               $css       The generated CSS, possibly empty.
		 * @param string               $unique_id Block instance ID.
		 * @param array<string, mixed> $attrs     Block attributes.
		 */
		return (string) apply_filters( 'wb_gam_block_css', $css, $unique_id, $attrs );
	}

	/**
	 * Collect a block instance's CSS into the page-level bucket.
	 *
	 * @param string               $unique_id Block instance ID.
	 * @param array<string, mixed> $attrs     Block attributes.
	 */
	public static function add( string $unique_id, array $attrs ): void {
		self::init();
		$css = self::generate( $unique_id, $attrs );
		if ( '' !== $css ) {
			self::$styles[ $unique_id ] = $css;
		}
	}

	/**
	 * Emit the collected CSS as a single <style> tag in the footer.
	 */
	public static function output(): void {
		if ( empty( self::$styles ) ) {
			return;
		}

		$bundle = implode( "\n", self::$styles );

		// Reset so callers re-emitting (AJAX, REST in the same request) don't double-print.
		self::$styles = array();

		echo "<style id=\"wb-gam-block-styles\">\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS bundle is internally generated from sanitised attribute values.
		echo $bundle;
		echo "\n</style>\n";
	}

	/**
	 * Resolve the visibility class list for a block wrapper.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string Space-joined list of visibility classes (may be empty).
	 */
	public static function get_visibility_classes( array $attrs ): string {
		$classes = array();

		if ( ! empty( $attrs['hideOnDesktop'] ) ) {
			$classes[] = 'wb-gam-hide-desktop';
		}
		if ( ! empty( $attrs['hideOnTablet'] ) ) {
			$classes[] = 'wb-gam-hide-tablet';
		}
		if ( ! empty( $attrs['hideOnMobile'] ) ) {
			$classes[] = 'wb-gam-hide-mobile';
		}
		// A card colour of the owner's own sets the token scheme on the block (see design-tokens.css).
		$card_ink = isset( $attrs['cardBackground'] ) && is_string( $attrs['cardBackground'] ) ? self::ink_for( trim( $attrs['cardBackground'] ) ) : '';
		if ( '' !== $card_ink ) {
			$classes[] = self::DARK_INK === $card_ink ? 'wb-gam-scheme-light' : 'wb-gam-scheme-dark';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Test-only helper: reset internal state between assertions.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$styles     = array();
		self::$registered = false;
	}

	/**
	 * Append per-side spacing rules (padding or margin) to the breakpoint buckets.
	 *
	 * @param array<string, mixed> $attrs    Block attributes.
	 * @param string               $kind     Either 'padding' or 'margin'.
	 * @param array<int, string>   $desktop  Desktop bucket (modified in place).
	 * @param array<int, string>   $tablet   Tablet bucket (modified in place).
	 * @param array<int, string>   $mobile   Mobile bucket (modified in place).
	 */
	private static function collect_spacing( array $attrs, string $kind, array &$desktop, array &$tablet, array &$mobile ): void {
		$unit_key = $kind . 'Unit';
		$unit     = isset( $attrs[ $unit_key ] ) && '' !== $attrs[ $unit_key ] ? (string) $attrs[ $unit_key ] : 'px';

		$variants = array(
			$kind            => &$desktop,
			$kind . 'Tablet' => &$tablet,
			$kind . 'Mobile' => &$mobile,
		);

		foreach ( $variants as $attr_key => &$bucket ) {
			if ( empty( $attrs[ $attr_key ] ) || ! is_array( $attrs[ $attr_key ] ) ) {
				continue;
			}

			$bucket[] = sprintf(
				'%s: %d%s %d%s %d%s %d%s;',
				$kind,
				(int) ( $attrs[ $attr_key ]['top'] ?? 0 ),
				$unit,
				(int) ( $attrs[ $attr_key ]['right'] ?? 0 ),
				$unit,
				(int) ( $attrs[ $attr_key ]['bottom'] ?? 0 ),
				$unit,
				(int) ( $attrs[ $attr_key ]['left'] ?? 0 ),
				$unit
			);
		}
		unset( $bucket );
	}

	/**
	 * Append border-radius rules.
	 *
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param array<int, string>   $desktop Desktop bucket (modified in place).
	 */
	private static function collect_border_radius( array $attrs, array &$desktop ): void {
		if ( empty( $attrs['borderRadius'] ) || ! is_array( $attrs['borderRadius'] ) ) {
			return;
		}

		$unit = isset( $attrs['borderRadiusUnit'] ) && '' !== $attrs['borderRadiusUnit']
			? (string) $attrs['borderRadiusUnit']
			: 'px';

		$radius = $attrs['borderRadius'];

		$desktop[] = sprintf(
			'border-radius: %d%s %d%s %d%s %d%s;',
			absint( $radius['top'] ?? 0 ),
			$unit,
			absint( $radius['right'] ?? 0 ),
			$unit,
			absint( $radius['bottom'] ?? 0 ),
			$unit,
			absint( $radius['left'] ?? 0 ),
			$unit
		);
	}

	/** Text on a light owner card. */
	private const DARK_INK = '#1a1a2e';

	/** Text on a dark owner card. */
	private const LIGHT_INK = '#f5f5f7';

	/**
	 * Accent-derived tokens, as design-tokens.css declares them on :root.
	 */
	private const ACCENT_DERIVED = array(
		'--wb-gam-color-accent-light' => 'color-mix(in srgb, var(--wb-gam-color-accent) 14%, var(--wb-gam-color-surface))',
		'--wb-gam-color-accent-text'  => 'color-mix(in srgb, var(--wb-gam-color-accent) var(--wb-gam-owner-accent-mix, 50%), var(--wb-gam-color-accent-ink))',
		'--wb-gam-color-accent-ring'  => 'color-mix(in srgb, var(--wb-gam-color-accent) 20%, transparent)',
		'--wb-gam-color-primary-soft' => 'color-mix(in srgb, var(--wb-gam-color-accent) 10%, transparent)',
		'--wb-gam-primary'            => 'var(--wb-gam-color-accent)',
		'--wb-gam-primary-light'      => 'var(--wb-gam-color-accent-light)',
		'--wb-gam-primary-text'       => 'var(--wb-gam-color-accent-text)',
		'--wb-gam-fill'               => 'var(--wb-gam-color-accent)',
		'--wb-gam-recap-accent'       => 'var(--wb-gam-color-accent)',
		'--wb-gam-pop-accent'         => 'var(--wb-gam-color-accent)',
		'--wb-gam-pop-accent-fg'      => 'color(from var(--wb-gam-color-accent) srgb-linear calc(1 - round(clamp(0, calc((0.2126 * r + 0.7152 * g + 0.0722 * b - 0.1791) * 1000), 1))) calc(1 - round(clamp(0, calc((0.2126 * r + 0.7152 * g + 0.0722 * b - 0.1791) * 1000), 1))) calc(1 - round(clamp(0, calc((0.2126 * r + 0.7152 * g + 0.0722 * b - 0.1791) * 1000), 1))))',
		'--wb-gam-pop-accent-hover'   => 'color-mix(in srgb, var(--wb-gam-color-accent) 88%, oklch(from var(--wb-gam-pop-accent-fg) round(1 - l) 0 0))',
		'--gam-accent-text'           => 'color-mix(in srgb, var(--wb-gam-color-accent) var(--wb-gam-owner-accent-mix, 50%), var(--wb-gam-color-accent-ink))',
	);

	/**
	 * Append the colour settings every block shares: Accent, Card background, Card border.
	 *
	 * Set as the design tokens the block's CSS reads, on the block root, so the setting reaches
	 * every part of the block that uses that token, and as the root's own background and border.
	 *
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param array<int, string>   $desktop Desktop bucket (modified in place).
	 */
	private static function collect_colors( array $attrs, array &$desktop ): void {
		// Hex, rgb()/rgba() or a CSS variable; anything else is dropped so it cannot break out of the rule.
		$color = static fn( string $key ): string => isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] )
			&& preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\)|var\(--[a-z0-9-]+\))$/i', trim( $attrs[ $key ] ) )
			? trim( $attrs[ $key ] ) : '';

		$accent = $color( 'accentColor' );
		if ( '' !== $accent ) {
			$desktop[] = '--wb-gam-color-accent: ' . $accent . ';';
			// Tokens derived from the accent are declared on :root, so they resolve against the
			// site accent there. Declared again here, they follow this block's accent (and its card
			// background): text in the accent, tints, rings, bars.
			foreach ( self::ACCENT_DERIVED as $token => $value ) {
				$desktop[] = $token . ': ' . $value . ';';
			}
		}
		$background = $color( 'cardBackground' );
		if ( '' !== $background ) {
			$desktop[] = '--wb-gam-color-surface: ' . $background . ';';
			$desktop[] = '--wb-gam-color-white: ' . $background . ';';
			$desktop[] = 'background-color: ' . $background . ';';
			// The block carries .wb-gam-scheme-light/-dark (get_visibility_classes), which re-applies that
			// token set on it; here the text and surfaces are pinned to the owner's card itself.
			$ink = self::ink_for( $background );
			if ( '' !== $ink ) {
				$desktop[] = '--wb-gam-color-text: ' . $ink . ';';
				$desktop[] = '--wb-gam-color-text-muted: color-mix(in srgb, ' . $ink . ' 84%, ' . $background . ');';
				$desktop[] = '--wb-gam-color-text-light: color-mix(in srgb, ' . $ink . ' 80%, ' . $background . ');';
				$desktop[] = '--wb-gam-color-surface-hover: color-mix(in srgb, ' . $ink . ' 5%, ' . $background . ');';
				$desktop[] = '--wb-gam-color-surface-alt: color-mix(in srgb, ' . $ink . ' 7%, ' . $background . ');';
				$desktop[] = 'color: ' . $ink . ';';
			}
		}
		$border = $color( 'cardBorderColor' );
		if ( '' !== $border ) {
			$desktop[] = '--wb-gam-color-border: ' . $border . ';';
			// Hub and Year Recap draw no border of their own, so set the whole border: the colour shows on
			// every block (the card blocks already use 1px solid).
			$desktop[] = 'border: 1px solid ' . $border . ';';
		}
	}

	/**
	 * Ink that reads on a card background: the darker or lighter text, whichever has more contrast.
	 *
	 * @param string $color Hex or rgb()/rgba() colour; a CSS variable cannot be measured.
	 * @return string self::DARK_INK, self::LIGHT_INK, or '' when the colour cannot be read.
	 */
	public static function ink_for( string $color ): string {
		$rgb = null;
		if ( preg_match( '/^#([0-9a-f]{3,8})$/i', $color, $m ) ) {
			$h = $m[1];
			if ( strlen( $h ) <= 4 ) {
				$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
			}
			if ( strlen( $h ) >= 6 ) {
				$rgb = array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
			}
		} elseif ( preg_match( '/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i', $color, $m ) ) {
			$rgb = array( (float) $m[1], (float) $m[2], (float) $m[3] );
		}
		if ( null === $rgb ) {
			return '';
		}
		$lum = static function ( array $c ): float {
			$c = array_map(
				static fn( $v ) => ( $v /= 255 ) <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4,
				$c
			);
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		$bg    = $lum( $rgb );
		$dark  = ( $bg + 0.05 ) / ( $lum( array( 0x1a, 0x1a, 0x2e ) ) + 0.05 );
		$light = ( $lum( array( 0xf5, 0xf5, 0xf7 ) ) + 0.05 ) / ( $bg + 0.05 );
		return $dark >= $light ? self::DARK_INK : self::LIGHT_INK;
	}

	/**
	 * Append box-shadow rule.
	 *
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param array<int, string>   $desktop Desktop bucket (modified in place).
	 */
	private static function collect_box_shadow( array $attrs, array &$desktop ): void {
		if ( empty( $attrs['boxShadow'] ) ) {
			return;
		}

		$desktop[] = sprintf(
			'box-shadow: %dpx %dpx %dpx %dpx %s;',
			(int) ( $attrs['shadowHorizontal'] ?? 0 ),
			(int) ( $attrs['shadowVertical'] ?? 4 ),
			absint( $attrs['shadowBlur'] ?? 8 ),
			(int) ( $attrs['shadowSpread'] ?? 0 ),
			sanitize_text_field( (string) ( $attrs['shadowColor'] ?? 'rgba(0,0,0,0.12)' ) )
		);
	}

	/**
	 * Append typography rules.
	 *
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param array<int, string>   $desktop Desktop bucket (modified in place).
	 * @param array<int, string>   $tablet  Tablet bucket (modified in place).
	 * @param array<int, string>   $mobile  Mobile bucket (modified in place).
	 */
	private static function collect_typography( array $attrs, array &$desktop, array &$tablet, array &$mobile ): void {
		$font_unit = isset( $attrs['fontSizeUnit'] ) && '' !== $attrs['fontSizeUnit']
			? (string) $attrs['fontSizeUnit']
			: 'px';

		if ( isset( $attrs['fontSize'] ) && '' !== $attrs['fontSize'] ) {
			$desktop[] = sprintf( 'font-size: %s%s;', (float) $attrs['fontSize'], $font_unit );
		}
		if ( isset( $attrs['fontSizeTablet'] ) && '' !== $attrs['fontSizeTablet'] ) {
			$tablet[] = sprintf( 'font-size: %s%s;', (float) $attrs['fontSizeTablet'], $font_unit );
		}
		if ( isset( $attrs['fontSizeMobile'] ) && '' !== $attrs['fontSizeMobile'] ) {
			$mobile[] = sprintf( 'font-size: %s%s;', (float) $attrs['fontSizeMobile'], $font_unit );
		}

		if ( ! empty( $attrs['fontFamily'] ) ) {
			$desktop[] = sprintf( 'font-family: %s;', sanitize_text_field( (string) $attrs['fontFamily'] ) );
		}

		if ( ! empty( $attrs['fontWeight'] ) ) {
			$desktop[] = sprintf( 'font-weight: %s;', sanitize_text_field( (string) $attrs['fontWeight'] ) );
		}

		if ( isset( $attrs['lineHeight'] ) && '' !== $attrs['lineHeight'] ) {
			$line_unit = isset( $attrs['lineHeightUnit'] ) && '' !== $attrs['lineHeightUnit']
				? (string) $attrs['lineHeightUnit']
				: '';
			$desktop[] = sprintf( 'line-height: %s%s;', (float) $attrs['lineHeight'], $line_unit );
		}

		if ( isset( $attrs['letterSpacing'] ) && '' !== $attrs['letterSpacing'] ) {
			$desktop[] = sprintf( 'letter-spacing: %spx;', (float) $attrs['letterSpacing'] );
		}

		if ( ! empty( $attrs['textTransform'] ) ) {
			$desktop[] = sprintf( 'text-transform: %s;', sanitize_text_field( (string) $attrs['textTransform'] ) );
		}
	}
}
