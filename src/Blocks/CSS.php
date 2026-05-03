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
