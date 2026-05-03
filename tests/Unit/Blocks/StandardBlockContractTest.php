<?php
/**
 * Acceptance contract test for every Phase D.1 standardised block.
 *
 * Each block's `render.php` must satisfy the Wbcom Block Quality
 * Standard contract:
 *
 *   1. Calls `WBGam\Blocks\CSS::add( $unique_id, $attrs )` so per-instance
 *      scoped CSS is collected.
 *   2. Emits a wrapper class `wb-gam-block-{uniqueId}` so the per-instance
 *      CSS rule actually targets it.
 *   3. Drops inline `<style>` and `<script>` tags from the markup.
 *   4. Honours the user's standard attribute schema (padding ends up in the
 *      generated CSS bucket).
 *
 * Running each block through the same data provider gives Phase D + Phase E
 * a single regression suite.
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
class StandardBlockContractTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const BASE = __DIR__ . '/../../../src/Blocks/';

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
				'esc_attr_e'          => static function ( $text ) {
					echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				'esc_html__'          => static fn ( $text ) => (string) $text,
				'esc_html_e'          => static function ( $text ) {
					echo (string) $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				'__'                  => static fn ( $text ) => (string) $text,
				'_n'                  => static fn ( $single, $plural, $n ) => $n === 1 ? $single : $plural,
				'wp_json_encode'      => static fn ( $value ) => json_encode( $value ),
				'number_format_i18n'  => static fn ( $value, $decimals = 0 ) => number_format( (float) $value, (int) $decimals ),
				'wp_enqueue_style'    => static fn () => null,
				'add_action'          => static fn () => true,
				'apply_filters'       => static fn ( $hook, $value ) => $value,
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
				'get_userdata'        => static fn ( $id ) => (object) array( 'ID' => $id, 'display_name' => 'Test User' ),
				'get_option'          => static fn ( $key, $default = false ) => $default,
				'current_time'        => static fn ( $type ) => $type === 'timestamp' ? time() : gmdate( 'Y-m-d H:i:s' ),
				'date_i18n'           => static fn ( $fmt, $ts ) => gmdate( $fmt, $ts ),
			)
		);

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		require_once __DIR__ . '/../Engine/Stubs/BlockHooksStub.php';

		// Provide the missing engine static methods through a global $wpdb mock.
		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public function get_results( string $sql, $output_type = null ): array {
				return array();
			}
			public function get_var( string $sql ): int {
				return 200;
			}
			public function get_row( string $sql, $output_type = null ): ?array {
				return null;
			}
			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}
			public function query( string $sql ): int {
				return 0;
			}
			public function insert( string $table, array $data, $format = null ): int {
				return 0;
			}
			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				return 0;
			}
		};

		// Stubbed engine helpers: many static methods on these classes the
		// templates touch directly. Using Brain\Monkey's runtime Functions
		// system isn't enough for static class methods, so we shim them via
		// a "method missing" approach by replacing the autoloader paths is
		// not necessary — the renders always work against $wpdb above and
		// never throw because LevelEngine / StreakEngine / Registry methods
		// gracefully handle empty results. Concretely:
		//   - LevelEngine::get_level_for_user → null when no levels defined
		//   - LevelEngine::get_next_level     → null
		//   - LevelEngine::get_progress_percent → 0
		//   - StreakEngine::get_streak        → array of zeros
		//   - StreakEngine::get_contribution_data → empty array
		//   - Registry::get_actions           → empty array
		// The contract assertions in this test are markup-shape based, so
		// the renders' "no-data" paths are sufficient for exercising the
		// standard contract.
	}

	protected function tearDown(): void {
		CSS::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function blockProvider(): array {
		return array(
			'member-points' => array( 'member-points' ),
			'streak'        => array( 'streak' ),
			'level-progress' => array( 'level-progress' ),
			'earning-guide' => array( 'earning-guide' ),
		);
	}

	/**
	 * @dataProvider blockProvider
	 */
	public function test_render_emits_unique_id_scoped_wrapper_and_drops_inline_assets( string $slug ): void {
		$output = $this->render( $slug, array( 'uniqueId' => 'd1pilot' ) );

		$this->assertStringContainsString( 'wb-gam-block-d1pilot', $output, "{$slug} wrapper missing per-instance class" );
		$this->assertStringNotContainsString( '<script', $output, "{$slug} render still emits an inline <script>" );
		$this->assertStringNotContainsString( '<style', $output, "{$slug} render still emits an inline <style>" );
	}

	/**
	 * @dataProvider blockProvider
	 */
	public function test_render_registers_per_instance_css_via_block_css_helper( string $slug ): void {
		$attrs = array(
			'uniqueId' => 'd1' . substr( $slug, 0, 4 ),
			'padding'  => array( 'top' => 24, 'right' => 16, 'bottom' => 24, 'left' => 16 ),
		);

		$this->render( $slug, $attrs );

		$styles = $this->reflect_styles();
		$this->assertArrayHasKey( $attrs['uniqueId'], $styles, "{$slug} did not register CSS::add() for the unique id" );
		$this->assertStringContainsString( 'padding: 24px 16px 24px 16px;', $styles[ $attrs['uniqueId'] ], "{$slug} did not surface the padding attribute in scoped CSS" );
	}

	private function render( string $slug, array $attributes ): string {
		$attributes = array_merge(
			array(
				'uniqueId'      => 'unit',
				'padding'       => array( 'top' => 16, 'right' => 16, 'bottom' => 16, 'left' => 16 ),
				'paddingUnit'   => 'px',
				'borderRadius'  => array( 'top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12 ),
				'hideOnDesktop' => false,
				'hideOnTablet'  => false,
				'hideOnMobile'  => false,
				'columns'       => 3,
			),
			$attributes
		);

		ob_start();
		try {
			require self::BASE . $slug . '/render.php';
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
		return (string) ob_get_clean();
	}

	private function reflect_styles(): array {
		$reflection = new \ReflectionClass( CSS::class );
		$prop       = $reflection->getProperty( 'styles' );
		$prop->setAccessible( true );
		return (array) $prop->getValue();
	}
}
