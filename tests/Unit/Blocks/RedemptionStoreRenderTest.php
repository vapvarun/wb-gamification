<?php
/**
 * Acceptance test for the Phase C standardised redemption-store block.
 *
 * Exercises `src/Blocks/redemption-store/render.php` in a sandboxed scope
 * to verify the Wbcom Block Quality Standard contract:
 *
 * - Per-instance scoped CSS is registered via `WBGam\Blocks\CSS::add()`.
 * - The wrapper carries the Interactivity API namespace + per-card
 *   contexts (no inline `<script>`).
 * - Inline `<style>` is gone — visual rules come from style.css and
 *   the per-instance generator.
 * - REST endpoint, nonce, and i18n strings are exposed via data
 *   attributes for the IA store.
 * - The styled confirm dialog markup is present and toggles via
 *   `data-wp-bind--hidden`, replacing `window.confirm`.
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
 * @coversNothing
 */
class RedemptionStoreRenderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const RENDER_TEMPLATE = __DIR__ . '/../../../src/Blocks/redemption-store/render.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CSS::reset();

		Functions\stubs(
			array(
				'sanitize_html_class' => static fn ( $cls ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $cls ),
				'sanitize_text_field' => static fn ( $value ) => trim( (string) $value ),
				'absint'              => static fn ( $value ) => abs( (int) $value ),
				'esc_url_raw'         => static fn ( $value ) => (string) $value,
				'esc_url'             => static fn ( $value ) => (string) $value,
				'esc_html'            => static fn ( $value ) => (string) $value,
				'esc_attr'            => static fn ( $value ) => (string) $value,
				'esc_html__'          => static fn ( $text ) => (string) $text,
				'esc_html_e'          => static function ( $text ) {
					echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				'__'                  => static fn ( $text ) => (string) $text,
				'wp_json_encode'      => static fn ( $value ) => json_encode( $value ),
				'wp_login_url'        => static fn ( $value = '' ) => 'https://example.test/login',
				'wp_create_nonce'     => static fn () => 'nonce-12345',
				'rest_url'            => static fn ( $path = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ),
				'get_permalink'       => static fn () => 'https://example.test/redeem/',
				'number_format_i18n'  => static fn ( $value ) => number_format( (float) $value ),
				'wp_enqueue_style'    => static fn () => null,
				'add_action'          => static fn () => true,
				'apply_filters'       => static function ( $hook, $value ) {
					return $value;
				},
				'get_block_wrapper_attributes' => static function ( $attrs = array() ) {
					$out = '';
					foreach ( (array) $attrs as $key => $value ) {
						if ( null === $value ) {
							continue;
						}
						$out .= sprintf( ' %s="%s"', $key, htmlspecialchars( (string) $value, ENT_QUOTES ) );
					}
					return trim( $out );
				},
			)
		);

		// Bridge classes the render template touches.
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		require_once __DIR__ . '/../Engine/Stubs/BlockHooksStub.php';

		// Stub the global $wpdb so RedemptionEngine::get_items() and
		// PointsEngine::get_total() resolve without hitting a database.
		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public function get_results( string $sql, $output_type = null ): array {
				return array(
					array(
						'id'            => 1,
						'title'         => 'Sample Reward',
						'description'   => 'A test redemption item.',
						'points_cost'   => 100,
						'reward_type'   => 'custom',
						'reward_config' => '{}',
						'stock'         => 5,
						'is_active'     => 1,
					),
					array(
						'id'            => 2,
						'title'         => 'Out of stock reward',
						'description'   => 'Stock = 0 case.',
						'points_cost'   => 50,
						'reward_type'   => 'custom',
						'reward_config' => '{}',
						'stock'         => 0,
						'is_active'     => 1,
					),
				);
			}
			public function get_var( string $sql ): int {
				return 200; // Member balance.
			}
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
		};
	}

	protected function tearDown(): void {
		CSS::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_emits_interactivity_namespace_and_drops_inline_script_tags(): void {
		$output = $this->render(
			array(
				'uniqueId'    => 'pilot01',
				'columns'     => 3,
				'showBalance' => true,
				'showStock'   => true,
			)
		);

		$this->assertStringContainsString( 'data-wp-interactive="wb-gamification/redemption"', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( '<style', $output );
	}

	public function test_render_exposes_endpoint_nonce_and_i18n_via_data_attributes(): void {
		$output = $this->render( array( 'uniqueId' => 'pilot02' ) );

		$this->assertStringContainsString(
			'data-redemption-endpoint="https://example.test/wp-json/wb-gamification/v1/redemptions"',
			$output
		);
		$this->assertStringContainsString( 'data-rest-nonce="nonce-12345"', $output );
		$this->assertStringContainsString( 'data-i18n-failed=', $output );
		$this->assertStringContainsString( 'data-i18n-network=', $output );
	}

	public function test_render_emits_styled_confirm_panel_replacing_window_confirm(): void {
		$output = $this->render( array( 'uniqueId' => 'pilot03' ) );

		$this->assertStringContainsString( 'wb-gam-redemption__confirm', $output );
		$this->assertStringContainsString( 'data-wp-bind--hidden="!context.confirming"', $output );
		$this->assertStringContainsString( 'data-wp-on--click="actions.confirmRedeem"', $output );
		$this->assertStringContainsString( 'data-wp-on--click="actions.cancelRedeem"', $output );
	}

	public function test_render_registers_per_instance_css_via_block_css_helper(): void {
		$attrs = array(
			'uniqueId' => 'pilot04',
			'padding'  => array( 'top' => 32, 'right' => 24, 'bottom' => 32, 'left' => 24 ),
		);

		$this->render( $attrs );

		// The contract: CSS::add() is called and emit() prints the rule on
		// wp_footer. We assert via reflection on the static $styles store.
		$styles = $this->reflect_styles();
		$this->assertArrayHasKey( 'pilot04', $styles );
		$this->assertStringContainsString( '.wb-gam-block-pilot04', $styles['pilot04'] );
		$this->assertStringContainsString( 'padding: 32px 24px 32px 24px;', $styles['pilot04'] );
	}

	public function test_logged_out_visitor_sees_login_cta_not_redeem_button(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$output = $this->render( array( 'uniqueId' => 'pilot05' ) );

		$this->assertStringContainsString( 'wb-gam-redemption__login', $output );
		$this->assertStringNotContainsString( 'data-wp-on--click="actions.requestRedeem"', $output );
	}

	public function test_render_passes_block_data_through_block_hooks_actions(): void {
		$output = $this->render( array( 'uniqueId' => 'pilot06' ) );

		$this->assertStringContainsString( 'wb-gam-redemption__grid', $output );
		$this->assertStringContainsString( 'wb-gam-redemption__card', $output );
	}

	private function render( array $attributes ): string {
		$attributes = $this->fill_defaults( $attributes );

		ob_start();
		// Render template uses `$attributes` as a global var.
		require self::RENDER_TEMPLATE;
		return (string) ob_get_clean();
	}

	private function fill_defaults( array $attrs ): array {
		return array_merge(
			array(
				'uniqueId'      => 'unit',
				'limit'         => 0,
				'columns'       => 3,
				'showBalance'   => true,
				'showStock'     => true,
				'buttonLabel'   => '',
				'emptyMessage'  => '',
				'padding'       => array( 'top' => 16, 'right' => 16, 'bottom' => 16, 'left' => 16 ),
				'paddingUnit'   => 'px',
				'borderRadius'  => array( 'top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12 ),
				'hideOnDesktop' => false,
				'hideOnTablet'  => false,
				'hideOnMobile'  => false,
			),
			$attrs
		);
	}

	private function reflect_styles(): array {
		$reflection = new \ReflectionClass( CSS::class );
		$prop       = $reflection->getProperty( 'styles' );
		$prop->setAccessible( true );
		return (array) $prop->getValue();
	}
}
