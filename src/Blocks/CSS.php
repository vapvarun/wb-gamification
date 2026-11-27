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
//   - PrefixAllGlobals.NonPrefixedHooknameFound - plugin uses `wb_gam_*` as its
//     established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
//     Plugin Check auto-detects `wb_gamification` from the text-domain header
//     and doesn't share the .phpcs.xml prefix list; hooks like
//     `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
//   - PrefixAllGlobals.NonPrefixedFunctionFound - same convention. Helper
//     functions exported under `wb_gam_*` are documented in `src/Extensions/`.
//   - PluginCheck.Security.DirectDB.UnescapedDBParameter +
//     WordPress.DB.PreparedSQL.InterpolatedNotPrepared - this file does custom-
//     table work. Table names are interpolated from `{$wpdb->prefix}` plus
//     literal constants (no user input); user-supplied values pass through
//     `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
//     interpolation is unavoidable.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Generates and collects per-instance scoped CSS for standardised blocks.
 *
 * The class operates on the standard attribute schema produced by
 * `src/shared/utils/attributes.js` - per-side spacing objects, three
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
	 * Idempotent - safe to call from each block's bootstrap.
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
		$oklch = array();
		self::collect_colors( $attrs, $desktop, $oklch );

		$css = '';

		if ( ! empty( $desktop ) ) {
			$css .= $selector . " {\n\t" . implode( "\n\t", $desktop ) . "\n}\n";
		}

		// Browsers with relative colours read the accent against the page's scheme (see design-tokens.css).
		if ( ! empty( $oklch ) ) {
			$css .= '@supports (color: oklch(from red l c h)) {' . "\n\t" . $selector . " {\n\t\t" . implode( "\n\t\t", $oklch ) . "\n\t}\n}\n";
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
		$scheme = self::scheme_class( $attrs );
		if ( '' !== $scheme ) {
			$classes[] = $scheme;
		}

		return implode( ' ', $classes );
	}

	/**
	 * The token scheme class for a block whose owner set a Card background: wb-gam-scheme-light on a light
	 * card, wb-gam-scheme-dark on a dark one, whatever the page's own mode. Empty without a measurable card.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	public static function scheme_class( array $attrs ): string {
		$ink = self::ink_for( self::safe_color( $attrs['cardBackground'] ?? '' ) );
		if ( '' === $ink ) {
			return '';
		}
		return self::DARK_INK === $ink ? 'wb-gam-scheme-light' : 'wb-gam-scheme-dark';
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

	/** Owner colours accepted into a style rule: 3, 4, 6 or 8 digit hex, rgb()/rgba(), or a CSS variable. */
	private const COLOR_PATTERN = '/^(#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})|rgba?\([0-9.,%\s]+\)|var\(--[a-z0-9-]+\))$/i';

	/** Minimum contrast for text (WCAG AA). */
	private const MIN_TEXT = 4.5;

	/** Contrast an accent used as text is held to: AA plus room for the tinted pills, podium rows and chips drawn under it. */
	private const MIN_ACCENT = 5.3;

	/** Non-text contrast (WCAG 1.4.11) the icon disc and its art are held to. */
	private const MIN_NON_TEXT_PLATE = 3.2;

	/**
	 * Medal colours before they are made readable on an owner card: [light card, dark card].
	 * The light values are the design-tokens.css defaults, the dark ones its lifted dark-mode mixes.
	 */
	private const MEDALS = array(
		'--wb-gam-color-gold-text' => array( '#8a6409', '#d6b358' ),
		'--wb-gam-color-silver'    => array( '#6b7280', '#b4b8c1' ),
		'--wb-gam-color-bronze'    => array( '#a0522d', '#cfa38e' ),
	);

	/**
	 * Status inks before they are made readable on an owner card: [light card, dark card], with the colour the
	 * token's tint (`-bg`) is mixed from. The light values are the design-tokens.css defaults, the dark ones
	 * its lifted dark-mode mixes.
	 */
	private const STATUS_INKS = array(
		'--wb-gam-color-success-strong' => array( '#046c4e', '#68ae9a', '#0d9f6e' ),
		'--wb-gam-color-warning-strong' => array( '#92400e', '#d9a984', '#c2770e' ),
		'--wb-gam-color-danger-strong'  => array( '#b91c1c', '#d88282', '#ef4444' ),
		'--wb-gam-color-fire-strong'    => array( '#b45309', '#d2986b', '#f59e0b' ),
		'--wb-gam-color-info'           => array( '#1d4ed8', '#7ca1f3', '#1d4ed8' ),
	);

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
		'--gam-accent-text'           => 'var(--wb-gam-color-accent-text)',
		'--wb-gam-focus-ring'         => 'var(--wb-gam-color-accent-text)',
	);

	/**
	 * Return an owner colour that is safe to print into a style rule, or ''.
	 *
	 * @param mixed $value Raw attribute value.
	 */
	public static function safe_color( $value ): string {
		return is_string( $value ) && preg_match( self::COLOR_PATTERN, trim( $value ) ) ? trim( $value ) : '';
	}

	/**
	 * Append the colour settings every block shares: Accent, Card background, Card border.
	 *
	 * Set as the design tokens the block's CSS reads, on the block root, so the setting reaches
	 * every part of the block that uses that token, and as the root's own background and border.
	 *
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param array<int, string>   $desktop Desktop bucket (modified in place).
	 * @param array<int, string>   $oklch   Declarations that need relative colours (modified in place).
	 */
	private static function collect_colors( array $attrs, array &$desktop, array &$oklch ): void {
		$accent     = self::safe_color( $attrs['accentColor'] ?? '' );
		$background = self::safe_color( $attrs['cardBackground'] ?? '' );
		$border     = self::safe_color( $attrs['cardBorderColor'] ?? '' );
		// With a card colour of the owner's, every token below is derived from that card; see card_tokens().
		$derived = '' !== $background ? self::card_tokens( $background, $accent ) : array();

		if ( '' !== $accent ) {
			$desktop[] = '--wb-gam-color-accent: ' . $accent . ';';
			// Tokens derived from the accent are declared on :root, so they resolve against the
			// site accent there. Declared again here, they follow this block's accent (and its card
			// background): text in the accent, tints, rings, bars.
			foreach ( self::ACCENT_DERIVED as $token => $value ) {
				$desktop[] = $token . ': ' . $value . ';';
			}
		}
		if ( '' !== $accent && ! isset( $derived['--wb-gam-color-accent-text'] ) ) {
			// Accent only: the accent's lightness is held to what reads on the scheme's surface (the light
			// scheme bounds it from above, the dark scheme from below), the hue and chroma stay the owner's.
			$oklch[] = '--wb-gam-color-accent-text: oklch(from var(--wb-gam-color-accent) clamp(var(--wb-gam-accent-l-min, 0), l, var(--wb-gam-accent-l-max, 0.5)) min(c, 0.11) h);';
		}
		if ( '' !== $background ) {
			$desktop[] = '--wb-gam-color-surface: ' . $background . ';';
			$desktop[] = '--wb-gam-color-white: ' . $background . ';';
			$desktop[] = 'background-color: ' . $background . ';';
			// The block carries .wb-gam-scheme-light/-dark (get_visibility_classes), which re-applies that
			// token set on it; below, every token that holds a colour on the card is set from the card itself.
			foreach ( $derived as $token => $value ) {
				$desktop[] = $token . ': ' . $value . ';';
			}
			if ( isset( $derived['--wb-gam-color-text'] ) ) {
				$desktop[] = 'color: ' . $derived['--wb-gam-color-text'] . ';';
			}
		}
		if ( '' !== $border ) {
			$desktop[] = '--wb-gam-color-border: ' . $border . ';';
			// Hub and Year Recap draw no border of their own, so set the whole border: the colour shows on
			// every block (the card blocks already use 1px solid).
			$desktop[] = 'border: 1px solid ' . $border . ';';
		}
	}

	/**
	 * Parse an owner colour into 0-255 channels.
	 *
	 * @param string $color Hex (3, 4, 6, 8 digits) or rgb()/rgba(). A CSS variable cannot be measured,
	 *                      and a translucent colour has no fixed contrast, so both give null.
	 * @return array<int, float>|null
	 */
	private static function parse_color( string $color ): ?array {
		$color = trim( $color );
		if ( preg_match( '/^#([0-9a-f]{3,8})$/i', $color, $m ) ) {
			$h = $m[1];
			if ( 3 === strlen( $h ) || 4 === strlen( $h ) ) {
				$h = preg_replace( '/(.)/', '$1$1', $h );
			}
			if ( 6 !== strlen( $h ) && 8 !== strlen( $h ) ) {
				return null;
			}
			if ( 8 === strlen( $h ) && hexdec( substr( $h, 6, 2 ) ) < 255 ) {
				return null;
			}
			return array( (float) hexdec( substr( $h, 0, 2 ) ), (float) hexdec( substr( $h, 2, 2 ) ), (float) hexdec( substr( $h, 4, 2 ) ) );
		}
		if ( preg_match( '/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $color, $m ) ) {
			if ( isset( $m[4] ) && (float) $m[4] < 1 ) {
				return null;
			}
			return array( min( 255.0, (float) $m[1] ), min( 255.0, (float) $m[2] ), min( 255.0, (float) $m[3] ) );
		}
		return null;
	}

	/**
	 * WCAG relative luminance of 0-255 channels.
	 *
	 * @param array<int, float> $c Channels.
	 */
	private static function luminance( array $c ): float {
		$c = array_map(
			static fn( $v ) => ( $v /= 255 ) <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4,
			$c
		);
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/**
	 * WCAG contrast ratio between two colours.
	 *
	 * @param array<int, float> $a Channels.
	 * @param array<int, float> $b Channels.
	 */
	public static function contrast( array $a, array $b ): float {
		$x = self::luminance( $a );
		$y = self::luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/**
	 * Channel-wise sRGB mix, as color-mix( in srgb, a p%, b ).
	 *
	 * @param array<int, float> $a Channels.
	 * @param float             $p Share of $a, 0 to 1.
	 * @param array<int, float> $b Channels.
	 * @return array<int, float>
	 */
	private static function mix( array $a, float $p, array $b ): array {
		return array( $a[0] * $p + $b[0] * ( 1 - $p ), $a[1] * $p + $b[1] * ( 1 - $p ), $a[2] * $p + $b[2] * ( 1 - $p ) );
	}

	/**
	 * Channels as #rrggbb.
	 *
	 * @param array<int, float> $c Channels.
	 */
	private static function to_hex( array $c ): string {
		return sprintf( '#%02x%02x%02x', (int) round( $c[0] ), (int) round( $c[1] ), (int) round( $c[2] ) );
	}

	/**
	 * The colour closest to $fg (keeping as much of its hue as it can) that reads at $min on every surface:
	 * $fg moved toward $ink in 4% steps.
	 *
	 * @param array<int, float>             $fg       Channels.
	 * @param array<int, array<int, float>> $surfaces Backgrounds the text can sit on.
	 * @param array<int, float>             $ink      Channels the colour is moved toward.
	 * @param float                         $min      Contrast to reach.
	 * @return array<int, float>
	 */
	private static function text_safe( array $fg, array $surfaces, array $ink, float $min ): array {
		for ( $keep = 1.0; $keep >= 0; $keep -= 0.04 ) {
			$c  = self::mix( $fg, $keep, $ink );
			$ok = true;
			foreach ( $surfaces as $surface ) {
				$ok = $ok && self::contrast( $c, $surface ) >= $min;
			}
			if ( $ok ) {
				return $c;
			}
		}
		return $ink;
	}

	/**
	 * Every colour token a block reads on an owner's card, derived from that card alone.
	 *
	 * The theme's scheme (light or dark) never enters: a cream card is a light card on a dark page, and a
	 * navy card is a dark card on a light page. Text, muted text, borders, row surfaces, medals and the
	 * focus ring come from the card's own luminance; with an Accent as well, the accent's text colour is
	 * moved toward the card's ink until it reads on the card and on the rows drawn over it. Pure: the same
	 * colours give the same tokens.
	 *
	 * @param string $card   Card background (hex or rgb()).
	 * @param string $accent Accent colour, or '' when the owner set none.
	 * @return array<string, string> Token => value; empty when the card colour cannot be measured.
	 */
	public static function card_tokens( string $card, string $accent = '' ): array {
		$bg = self::parse_color( $card );
		if ( null === $bg ) {
			return array();
		}
		// Rows on the card are the card moved 5-7% toward the ink, so text must read on those too. The darker or
		// lighter ink wins when it does; otherwise black or white, whichever reads better.
		$preferred = self::ink_for( $card );
		$reads     = static fn( array $c ) => self::contrast( $c, self::mix( $c, 0.07, $bg ) ) >= self::MIN_TEXT;
		$ink       = self::parse_color( $preferred );
		if ( ! $reads( $ink ) ) {
			$black = array( 0.0, 0.0, 0.0 );
			$white = array( 255.0, 255.0, 255.0 );
			$ink   = self::contrast( $black, $bg ) >= self::contrast( $white, $bg ) ? $black : $white;
		}
		$ink_hex  = self::to_hex( $ink );
		$is_light = self::luminance( $ink ) < 0.5; // Dark ink: a light card.
		$acc      = '' !== $accent ? self::parse_color( $accent ) : null;
		$rows     = array(
			$bg,
			self::mix( $ink, 0.05, $bg ),
			self::mix( $ink, 0.07, $bg ),
		);
		// With an Accent, pills and chips are the accent tinted over the card.
		if ( null !== $acc ) {
			$rows[] = self::mix( $acc, 0.14, $bg );
		}
		// Muted and light text are the ink faded toward the card: 84% / 80% where that still reads, more ink where it does not.
		$fade = static function ( int $share ) use ( $ink, $bg, $rows ): int {
			for ( ; $share < 100; $share += 4 ) {
				$c = self::mix( $ink, $share / 100, $bg );
				if ( min( array_map( static fn( $r ) => self::contrast( $c, $r ), $rows ) ) >= self::MIN_TEXT ) {
					return $share;
				}
			}
			return 100;
		};

		$tokens = array(
			'--wb-gam-color-text'           => $ink_hex,
			'--wb-gam-color-text-muted'     => 'color-mix(in srgb, ' . $ink_hex . ' ' . $fade( 84 ) . '%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-text-light'     => 'color-mix(in srgb, ' . $ink_hex . ' ' . $fade( 80 ) . '%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-surface-hover'  => 'color-mix(in srgb, ' . $ink_hex . ' 5%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-surface-alt'    => 'color-mix(in srgb, ' . $ink_hex . ' 7%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-border'         => 'color-mix(in srgb, ' . $ink_hex . ' 16%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-border-hover'   => 'color-mix(in srgb, ' . $ink_hex . ' 24%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-border-strong'  => 'color-mix(in srgb, ' . $ink_hex . ' 24%, ' . self::to_hex( $bg ) . ')',
			'--wb-gam-color-accent-ink'     => $ink_hex,
		);
		foreach ( self::MEDALS as $token => $pair ) {
			$medal = self::parse_color( $pair[ $is_light ? 0 : 1 ] );
			// A medal also sits on the podium row's own tint of itself.
			$tokens[ $token ] = self::to_hex( self::text_safe( $medal, array_merge( $rows, array( self::mix( $medal, 0.15, $bg ) ) ), $ink, self::MIN_TEXT ) );
		}
		foreach ( self::STATUS_INKS as $token => $set ) {
			$ink_color = self::parse_color( $set[ $is_light ? 0 : 1 ] );
			$tint      = self::mix( self::parse_color( $set[2] ), 0.12, $bg );
			$tokens[ $token ] = self::to_hex( self::text_safe( $ink_color, array_merge( $rows, array( $tint ) ), $ink, self::MIN_TEXT ) );
		}
		// Badge art is drawn black (an <img> does not inherit currentColor), so on a card dark enough for a pale
		// disc to stand out 3:1 from it, the icon disc becomes that pale plate with dark art; see plate_tokens().
		$tokens = array_merge( $tokens, self::plate_tokens( $bg ) );
		// The theme's own accent, used as text on this card, is held to a lightness that reads on it (see
		// design-tokens.css): at most this on a light card, at least this on a dark one. Judged on the card
		// and its rows with a 10% margin; OKLCH lightness is close to the cube root of relative luminance.
		$worst = $is_light ? min( array_map( array( self::class, 'luminance' ), $rows ) ) : max( array_map( array( self::class, 'luminance' ), $rows ) );
		if ( $is_light ) {
			$tokens['--wb-gam-accent-l-min'] = '0';
			$tokens['--wb-gam-accent-l-max'] = (string) round( max( 0.0, ( $worst + 0.05 ) / ( self::MIN_TEXT * 1.1 ) - 0.05 ) ** ( 1 / 3 ), 3 );
		} else {
			$tokens['--wb-gam-accent-l-min'] = (string) round( min( 1.0, self::MIN_TEXT * 1.15 * ( $worst + 0.05 ) - 0.05 ) ** ( 1 / 3 ), 3 );
			$tokens['--wb-gam-accent-l-max'] = '1';
		}
		// Focus ring: the accent's text colour when the owner set an accent, else the ink; both read 4.5:1 on the card, so 3:1 holds with room.
		$ring = $ink;
		if ( null !== $acc ) {
			$text     = self::text_safe( $acc, $rows, $ink, self::MIN_ACCENT );
			$tokens['--wb-gam-color-accent-text'] = self::to_hex( $text );
			$ring                                 = $text;
		}
		$tokens['--wb-gam-focus-ring'] = self::to_hex( $ring );
		return $tokens;
	}

	/**
	 * Icon-disc tokens for a card that a pale plate stands out from (WCAG 1.4.11: disc against card, art
	 * against disc, 3:1 each). Badge art is black line-art or the owner's own colours, so the disc is the
	 * pale plate and the glyph ink is near-black. Earned is the plate with a hint of green, locked a hint of
	 * grey (the art there is also faded to half, which a pale plate still keeps above 3:1). A card too light
	 * for a pale plate to stand out keeps the tint, where black art already reads. Pure.
	 *
	 * @param array<int, float> $bg Card channels.
	 * @return array<string, string> Token => value; empty when the card is too light for a plate.
	 */
	private static function plate_tokens( array $bg ): array {
		$white = array( 255.0, 255.0, 255.0 );
		$art   = array( 17.0, 24.0, 39.0 );
		$min   = self::MIN_NON_TEXT_PLATE;
		if ( self::contrast( $white, $bg ) < $min ) {
			return array();
		}
		$plate = static function ( array $hint, float $share ) use ( $white, $bg, $min, $art ): array {
			// Start from the tinted plate and lighten toward white until it stands out from the card, and the
			// art at half strength (locked) still reads on it.
			for ( $keep = $share; $keep > 0; $keep -= 0.02 ) {
				$c     = self::mix( $hint, $keep, $white );
				$faded = self::mix( $art, 0.5, $c );
				if ( self::contrast( $c, $bg ) >= $min && self::contrast( $faded, $c ) >= $min ) {
					return $c;
				}
			}
			return $white;
		};
		return array(
			'--wb-gam-badge-plate'        => self::to_hex( $plate( array( 13.0, 159.0, 110.0 ), 0.16 ) ),
			'--wb-gam-badge-plate-locked' => self::to_hex( $plate( array( 107.0, 114.0, 128.0 ), 0.14 ) ),
			'--wb-gam-badge-art-ink'      => self::to_hex( $art ),
		);
	}

	/**
	 * Ink that reads on a card background: the darker or lighter text, whichever has more contrast.
	 *
	 * @param string $color Hex or rgb()/rgba() colour; a CSS variable cannot be measured.
	 * @return string self::DARK_INK, self::LIGHT_INK, or '' when the colour cannot be read.
	 */
	public static function ink_for( string $color ): string {
		$rgb = self::parse_color( $color );
		if ( null === $rgb ) {
			return '';
		}
		$bg    = self::luminance( $rgb );
		$dark  = ( $bg + 0.05 ) / ( self::luminance( array( 0x1a, 0x1a, 0x2e ) ) + 0.05 );
		$light = ( self::luminance( array( 0xf5, 0xf5, 0xf7 ) ) + 0.05 ) / ( $bg + 0.05 );
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
