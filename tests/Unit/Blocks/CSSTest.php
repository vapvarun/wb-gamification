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
use PHPUnit\Framework\TestCase;
use WBGam\Blocks\CSS;

/**
 * @coversDefaultClass \WBGam\Blocks\CSS
 */
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
}
