<?php
/**
 * Unit tests for the per-instance CSS generator.
 *
 * Covers the Phase B Wbcom Block Quality Standard contract: every
 * standardised block calls `WBGam\Blocks\CSS::generate()` with a
 * unique id and the standard attribute schema, then expects scoped
 * rules wrapped in tablet/mobile @media queries when responsive
 * variants are present.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Blocks\CSS;

#[CoversClass( \WBGam\Blocks\CSS::class )]
class CSSTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CSS::reset();

		Functions\stubs(
			array(
				'sanitize_html_class' => static fn ( $cls ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $cls ),
				'sanitize_text_field' => static fn ( $value ) => trim( (string) $value ),
				'absint'              => static fn ( $value ) => abs( (int) $value ),
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static fn ( $hook, $value ) => $value
		);

		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		CSS::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_unique_id_returns_empty_string(): void {
		$this->assertSame( '', CSS::generate( '', array( 'padding' => array( 'top' => 16 ) ) ) );
	}

	public function test_empty_attributes_return_empty_string(): void {
		$this->assertSame( '', CSS::generate( 'abc123', array() ) );
	}

	public function test_padding_emits_desktop_rule_with_unique_id_selector(): void {
		$css = CSS::generate(
			'redempt-1',
			array(
				'padding' => array(
					'top'    => 16,
					'right'  => 12,
					'bottom' => 16,
					'left'   => 12,
				),
			)
		);

		$this->assertStringContainsString( '.wb-gam-block-redempt-1', $css );
		$this->assertStringContainsString( 'padding: 16px 12px 16px 12px;', $css );
	}

	public function test_responsive_padding_emits_three_breakpoint_groups(): void {
		$css = CSS::generate(
			'r2',
			array(
				'padding'       => array( 'top' => 32, 'right' => 32, 'bottom' => 32, 'left' => 32 ),
				'paddingTablet' => array( 'top' => 24, 'right' => 24, 'bottom' => 24, 'left' => 24 ),
				'paddingMobile' => array( 'top' => 16, 'right' => 16, 'bottom' => 16, 'left' => 16 ),
			)
		);

		$this->assertStringContainsString( '@media (max-width: 1024px)', $css );
		$this->assertStringContainsString( '@media (max-width: 767px)', $css );
		$this->assertStringContainsString( 'padding: 32px 32px 32px 32px;', $css );
		$this->assertStringContainsString( 'padding: 24px 24px 24px 24px;', $css );
		$this->assertStringContainsString( 'padding: 16px 16px 16px 16px;', $css );
	}

	public function test_border_radius_uses_per_corner_object(): void {
		$css = CSS::generate(
			'r3',
			array(
				'borderRadius' => array( 'top' => 8, 'right' => 4, 'bottom' => 0, 'left' => 4 ),
			)
		);

		$this->assertStringContainsString( 'border-radius: 8px 4px 0px 4px;', $css );
	}

	public function test_box_shadow_uses_default_color_when_missing(): void {
		$css = CSS::generate(
			'r4',
			array(
				'boxShadow'        => true,
				'shadowHorizontal' => 0,
				'shadowVertical'   => 6,
				'shadowBlur'       => 12,
				'shadowSpread'     => 0,
			)
		);

		$this->assertStringContainsString( 'box-shadow: 0px 6px 12px 0px rgba(0,0,0,0.12);', $css );
	}

	public function test_responsive_font_size_emits_per_breakpoint(): void {
		$css = CSS::generate(
			'r5',
			array(
				'fontSize'       => 18,
				'fontSizeTablet' => 16,
				'fontSizeMobile' => 14,
			)
		);

		$this->assertStringContainsString( 'font-size: 18px;', $css );
		$this->assertStringContainsString( 'font-size: 16px;', $css );
		$this->assertStringContainsString( 'font-size: 14px;', $css );
		$this->assertStringContainsString( '@media (max-width: 1024px)', $css );
		$this->assertStringContainsString( '@media (max-width: 767px)', $css );
	}

	public function test_visibility_classes_emit_when_flags_set(): void {
		$this->assertSame(
			'wb-gam-hide-desktop wb-gam-hide-mobile',
			CSS::get_visibility_classes(
				array(
					'hideOnDesktop' => true,
					'hideOnTablet'  => false,
					'hideOnMobile'  => true,
				)
			)
		);
	}

	public function test_visibility_returns_empty_string_when_no_flags(): void {
		$this->assertSame( '', CSS::get_visibility_classes( array() ) );
	}

	public function test_filter_can_override_generated_css(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$rest ) {
				return $hook === 'wb_gam_block_css' ? '/* filtered */' : $value;
			}
		);

		$css = CSS::generate( 'r6', array( 'padding' => array( 'top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8 ) ) );

		$this->assertSame( '/* filtered */', $css );
	}

	public function test_ink_for_picks_the_readable_text_for_a_card(): void {
		$this->assertSame( '#1a1a2e', CSS::ink_for( '#ffeedd' ) );
		$this->assertSame( '#1a1a2e', CSS::ink_for( '#fff' ) );
		$this->assertSame( '#f5f5f7', CSS::ink_for( '#1f1f1f' ) );
		$this->assertSame( '#f5f5f7', CSS::ink_for( 'rgb(204, 0, 0)' ) );
		$this->assertSame( '', CSS::ink_for( 'var(--brand)' ) );
	}

	public function test_light_card_background_pins_dark_text_in_every_mode(): void {
		$css = CSS::generate( 'c1', array( 'cardBackground' => '#ffeedd' ) );

		$this->assertStringContainsString( '--wb-gam-color-text: #1a1a2e;', $css );
		$this->assertStringContainsString( '--wb-gam-color-text-muted: color-mix(in srgb, #1a1a2e 84%, #ffeedd);', $css );
		$this->assertStringContainsString( 'background-color: #ffeedd;', $css );
		$this->assertSame( 'wb-gam-scheme-light', CSS::get_visibility_classes( array( 'cardBackground' => '#ffeedd' ) ) );
		$this->assertSame( 'wb-gam-scheme-dark', CSS::get_visibility_classes( array( 'cardBackground' => '#101418' ) ) );
	}

	public function test_card_background_from_a_css_variable_keeps_the_theme_text(): void {
		$css = CSS::generate( 'c2', array( 'cardBackground' => 'var(--brand-surface)' ) );

		$this->assertStringContainsString( 'background-color: var(--brand-surface);', $css );
		$this->assertStringNotContainsString( '--wb-gam-color-text:', $css );
		$this->assertSame( '', CSS::get_visibility_classes( array( 'cardBackground' => 'var(--brand-surface)' ) ) );
	}

	/**
	 * Resolve a token value the PHP side emits (#hex, or color-mix(in srgb, #a N%, #b)) to channels.
	 *
	 * @param string $value Token value.
	 * @return array<int, float>
	 */
	private function channels( string $value ): array {
		if ( preg_match( '/^color-mix\(in srgb, (#[0-9a-f]{6}) (\d+)%, (#[0-9a-f]{6})\)$/', $value, $m ) ) {
			$a = $this->channels( $m[1] );
			$b = $this->channels( $m[3] );
			$p = (int) $m[2] / 100;
			return array( $a[0] * $p + $b[0] * ( 1 - $p ), $a[1] * $p + $b[1] * ( 1 - $p ), $a[2] * $p + $b[2] * ( 1 - $p ) );
		}
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $value );
		return array( (float) hexdec( substr( $value, 1, 2 ) ), (float) hexdec( substr( $value, 3, 2 ) ), (float) hexdec( substr( $value, 5, 2 ) ) );
	}

	/**
	 * Assert every derived token reads on the card: text, muted and light text and the medals and status
	 * inks at 4.5:1, the accent's text colour at 4.5:1, the focus ring at 3:1.
	 *
	 * @param string $card   Card colour.
	 * @param string $accent Accent colour or ''.
	 */
	private function assertReadsOn( string $card, string $accent = '' ): void {
		$t    = CSS::card_tokens( $card, $accent );
		$bg   = $this->channels( $card );
		$name = $card . ( '' !== $accent ? ' + ' . $accent : '' );
		$this->assertNotEmpty( $t, $name );
		foreach ( array( '--wb-gam-color-text', '--wb-gam-color-text-muted', '--wb-gam-color-text-light', '--wb-gam-color-gold-text', '--wb-gam-color-silver', '--wb-gam-color-bronze', '--wb-gam-color-success-strong', '--wb-gam-color-warning-strong', '--wb-gam-color-danger-strong', '--wb-gam-color-fire-strong', '--wb-gam-color-info' ) as $token ) {
			$this->assertGreaterThanOrEqual( 4.5, CSS::contrast( $this->channels( $t[ $token ] ), $bg ), $name . ' ' . $token . ' ' . $t[ $token ] );
		}
		if ( '' !== $accent ) {
			$this->assertGreaterThanOrEqual( 4.5, CSS::contrast( $this->channels( $t['--wb-gam-color-accent-text'] ), $bg ), $name . ' accent text' );
		}
		$this->assertGreaterThanOrEqual( 3.0, CSS::contrast( $this->channels( $t['--wb-gam-focus-ring'] ), $bg ), $name . ' focus ring' );
	}

	public function test_card_tokens_read_on_the_card_from_the_card_alone(): void {
		// The exact cases of card 10523784929: a cream card with a red accent, and a navy and a black card.
		$this->assertReadsOn( '#ffeedd', '#cc0000' );
		$this->assertReadsOn( '#ffeedd' );
		$this->assertReadsOn( '#14213d' );
		$this->assertReadsOn( '#14213d', '#fca311' );
		$this->assertReadsOn( '#000000' );
		$this->assertReadsOn( '#000000', '#660000' );
		$this->assertReadsOn( '#ffffff', '#ffee00' );
		$this->assertReadsOn( '#808080', '#0000ff' );

		$cream = CSS::card_tokens( '#ffeedd', '#cc0000' );
		$navy  = CSS::card_tokens( '#14213d' );
		$black = CSS::card_tokens( '#000000' );
		$this->assertSame( '#1a1a2e', $cream['--wb-gam-color-text'] );
		$this->assertSame( '#f5f5f7', $navy['--wb-gam-color-text'] );
		$this->assertSame( '#f5f5f7', $black['--wb-gam-color-text'] );
		// A dark card gets the lifted medals, a light card the deep ones.
		$this->assertNotSame( $cream['--wb-gam-color-silver'], $navy['--wb-gam-color-silver'] );
	}

	public function test_card_tokens_sweep_of_cards_and_accents(): void {
		$cards   = array( '#ffffff', '#000000', '#ffeedd', '#14213d', '#fafafa', '#101418', '#f0f4ff', '#223344', '#ffd54a', '#00695c', '#7b1fa2', '#ff5722', '#2e7d32', '#cfd8dc' );
		for ( $g = 0; $g <= 255; $g += 17 ) {
			$cards[] = sprintf( '#%02x%02x%02x', $g, $g, $g );
		}
		$accents = array( '', '#cc0000', '#fca311', '#ffee00', '#0000ff', '#660000', '#00ff00', '#5b4cdb', '#ffffff', '#000000', '#ff00ff' );
		foreach ( $cards as $card ) {
			foreach ( $accents as $accent ) {
				$this->assertReadsOn( $card, $accent );
			}
		}
	}

	/**
	 * WCAG 1.4.11: on a card a pale plate stands out from, the badge disc stands out from the card and the
	 * art (black line-art, or the glyph ink; locked art is faded to half) stands out from the disc, 3:1.
	 */
	public function test_badge_icon_disc_reads_on_dark_and_mid_cards(): void {
		$cards   = array( '#14213d', '#000000', '#101418', '#223344', '#00695c', '#7b1fa2', '#2e7d32', '#808080', '#666666', '#333333' );
		$accents = array( '', '#cc0000', '#fca311', '#0000ff', '#00ff00' );
		foreach ( $cards as $card ) {
			$bg = $this->channels( $card );
			foreach ( $accents as $accent ) {
				$t    = CSS::card_tokens( $card, $accent );
				$name = $card . ' + ' . $accent;
				$this->assertArrayHasKey( '--wb-gam-badge-plate', $t, $name );
				$art = $this->channels( $t['--wb-gam-badge-art-ink'] );
				foreach ( array( '--wb-gam-badge-plate', '--wb-gam-badge-plate-locked' ) as $token ) {
					$plate = $this->channels( $t[ $token ] );
					$this->assertGreaterThanOrEqual( 3.0, CSS::contrast( $plate, $bg ), $name . ' ' . $token . ' against the card' );
					$this->assertGreaterThanOrEqual( 3.0, CSS::contrast( $art, $plate ), $name . ' ink against ' . $token );
					$faded = array( ( $art[0] + $plate[0] ) / 2, ( $art[1] + $plate[1] ) / 2, ( $art[2] + $plate[2] ) / 2 );
					$this->assertGreaterThanOrEqual( 3.0, CSS::contrast( $faded, $plate ), $name . ' faded art against ' . $token );
				}
				$this->assertNotSame( $t['--wb-gam-badge-plate'], $t['--wb-gam-badge-plate-locked'], $name . ' earned and locked stay distinct' );
			}
		}
		// A card too light for a pale plate to stand out keeps the tint (black art already reads on it).
		$this->assertArrayNotHasKey( '--wb-gam-badge-plate', CSS::card_tokens( '#ffeedd', '#cc0000' ) );
		$this->assertArrayNotHasKey( '--wb-gam-badge-plate', CSS::card_tokens( '#ffffff' ) );
	}

	public function test_card_tokens_are_scheme_independent_and_pure(): void {
		$this->assertSame( CSS::card_tokens( '#ffeedd', '#cc0000' ), CSS::card_tokens( '#ffeedd', '#cc0000' ) );
		// Short hex and rgb() read the same card.
		$this->assertSame( CSS::card_tokens( '#ffeedd' ), CSS::card_tokens( '#fed' ) );
		$this->assertSame( CSS::card_tokens( '#ffeedd' ), CSS::card_tokens( 'rgb(255, 238, 221)' ) );
	}

	public function test_a_dark_owner_card_on_a_light_page_is_a_dark_scheme_card(): void {
		foreach ( array( '#14213d', '#000000' ) as $card ) {
			$css = CSS::generate( 'm1', array( 'cardBackground' => $card ) );
			$this->assertStringContainsString( '--wb-gam-color-text: #f5f5f7;', $css );
			$this->assertStringContainsString( '--wb-gam-focus-ring: #f5f5f7;', $css );
			$this->assertSame( 'wb-gam-scheme-dark', CSS::scheme_class( array( 'cardBackground' => $card ) ) );
		}
	}

	public function test_accent_only_holds_the_accent_text_to_the_scheme_surface(): void {
		$css = CSS::generate( 'a1', array( 'accentColor' => '#cc0000' ) );

		$this->assertStringContainsString( '@supports (color: oklch(from red l c h))', $css );
		$this->assertStringContainsString( '--wb-gam-color-accent-text: oklch(from var(--wb-gam-color-accent) clamp(', $css );
		$this->assertStringContainsString( '--wb-gam-focus-ring: var(--wb-gam-color-accent-text);', $css );
		$this->assertStringNotContainsString( '--wb-gam-color-text:', $css );
		// With a card too, the accent text is a measured colour, not the relative-colour rule.
		$both = CSS::generate( 'a2', array( 'accentColor' => '#cc0000', 'cardBackground' => '#ffeedd' ) );
		$this->assertStringNotContainsString( '@supports', $both );
		$this->assertMatchesRegularExpression( '/--wb-gam-color-accent-text: #[0-9a-f]{6};/', $both );
	}

	public function test_no_owner_colours_leave_the_output_unchanged(): void {
		$attrs = array( 'padding' => array( 'top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8 ) );
		$css   = CSS::generate( 'n1', $attrs );

		$this->assertSame( ".wb-gam-block-n1 {\n\tpadding: 8px 8px 8px 8px;\n}\n", $css );
		$this->assertSame( '', CSS::generate( 'n2', array() ) );
		$this->assertSame( '', CSS::get_visibility_classes( $attrs ) );
		$this->assertSame( '', CSS::scheme_class( $attrs ) );
		// A border colour alone changes nothing about the card's tokens.
		$border = CSS::generate( 'n3', array( 'cardBorderColor' => '#cccccc' ) );
		$this->assertStringNotContainsString( '--wb-gam-color-text', $border );
		$this->assertStringNotContainsString( 'focus-ring', $border );
	}

	public function test_owner_colours_are_validated_before_they_reach_a_style_rule(): void {
		foreach ( array( 'red', '#12', '#12345', '#ggg', '#fff;}body{display:none', 'url(x)', 'expression(1)', '#fff</style>', "#fff\n}", 'var(--x);color:red' ) as $bad ) {
			$this->assertSame( '', CSS::safe_color( $bad ), $bad );
			$css = CSS::generate( 'v1', array( 'cardBackground' => $bad, 'accentColor' => $bad, 'cardBorderColor' => $bad ) );
			$this->assertSame( '', $css, $bad );
		}
		foreach ( array( '#fff', '#ffff', '#ffeedd', '#ffeedd80', 'rgb(1, 2, 3)', 'rgba(1,2,3,0.5)', 'var(--brand)' ) as $good ) {
			$this->assertSame( $good, CSS::safe_color( $good ), $good );
		}
		// A translucent card has no fixed contrast: the theme's tokens stay.
		$this->assertSame( array(), CSS::card_tokens( '#ffeedd80' ) );
		$this->assertSame( array(), CSS::card_tokens( 'rgba(0, 0, 0, 0.5)' ) );
		$this->assertSame( '', CSS::scheme_class( array( 'cardBackground' => '#00000080' ) ) );
	}
}
