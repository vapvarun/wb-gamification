<?php
/**
 * Render contract for the Badge Showcase block against the public
 * `wb_gam_block_badge_showcase_data` filter.
 *
 * The filter is public API, so a callback can return records with keys missing, the same badge twice,
 * or entries that are not arrays. Every count, tab and card must be derived from ONE normalised list.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Blocks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WBGam\Blocks\CSS;

#[CoversNothing]
class BadgeShowcaseRenderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const RENDER_TEMPLATE = __DIR__ . '/../../../src/Blocks/badge-showcase/render.php';

	/** @var array<int, mixed> Records the fixture filter hands back. */
	private array $fixture = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CSS::reset();

		foreach ( array( 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $const => $secs ) {
			if ( ! defined( $const ) ) {
				define( $const, $secs );
			}
		}

		Functions\stubs(
			array(
				'sanitize_html_class' => static fn ( $c ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ),
				'sanitize_key'        => static fn ( $c ) => strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ) ),
				'esc_url'             => static fn ( $v ) => (string) $v,
				'esc_html'            => static fn ( $v ) => (string) $v,
				'esc_attr'            => static fn ( $v ) => (string) $v,
				'esc_html__'          => static fn ( $t ) => (string) $t,
				'esc_attr__'          => static fn ( $t ) => (string) $t,
				'esc_html_e'          => static function ( $t ) {
					echo (string) $t; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				'esc_attr_e'          => static function ( $t ) {
					echo (string) $t; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				'__'                  => static fn ( $t ) => (string) $t,
				'_x'                  => static fn ( $t ) => (string) $t,
				'wp_json_encode'      => static fn ( $v ) => json_encode( $v ),
				'wp_create_nonce'     => static fn () => 'nonce',
				'home_url'            => static fn ( $p = '' ) => 'https://example.test/' . ltrim( (string) $p, '/' ),
				'rest_url'            => static fn ( $p = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ),
				'number_format_i18n'  => static fn ( $v ) => number_format( (float) $v ),
				'wp_date'             => static fn () => 'Jan 1, 2026',
				'human_time_diff'     => static fn () => '1 day',
				'wp_enqueue_style'    => static fn () => null,
				'wp_cache_get'        => static fn () => false,
				'wp_cache_set'        => static fn () => true,
				'add_action'          => static fn () => true,
				'do_action'           => static fn () => null,
				'get_current_user_id' => static fn () => 7,
				'get_block_wrapper_attributes' => static fn ( $a = array() ) => 'class="' . ( $a['class'] ?? '' ) . '"',
			)
		);

		Functions\when( 'apply_filters' )->alias(
			function ( string $hook, $value ) {
				return 'wb_gam_block_badge_showcase_data' === $hook ? $this->fixture : $value;
			}
		);

		// The engine reads no definitions: the fixture filter supplies the whole collection.
		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public function get_results( $sql = '', $type = null ): array {
				return array();
			}
			public function get_var( $sql = '' ) {
				return null;
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

	/**
	 * Render the block and fail on any PHP notice or warning.
	 *
	 * @param array<int, mixed> $fixture Records the filter returns.
	 * @return string
	 */
	private function render( array $fixture ): string {
		$this->fixture = $fixture;
		$attributes    = array(
			'uniqueId'    => 'unit',
			'user_id'     => 7,
			'show_locked' => true,
		);

		set_error_handler(
			static function ( int $no, string $msg, string $file, int $line ): bool {
				throw new \ErrorException( $msg, 0, $no, $file, $line );
			}
		);
		$level = ob_get_level();
		ob_start();
		try {
			require self::RENDER_TEMPLATE;
			return (string) ob_get_contents();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			restore_error_handler();
		}
	}

	/**
	 * @param string $id     Badge id.
	 * @param bool   $earned Earned flag.
	 * @return array<string, mixed>
	 */
	private function badge( string $id, bool $earned ): array {
		return array(
			'id'            => $id,
			'name'          => 'Badge ' . $id,
			'description'   => '',
			'image_url'     => null,
			'is_credential' => false,
			'category'      => 'general',
			'earned'        => $earned,
			'earned_at'     => $earned ? '2026-01-01 00:00:00' : null,
		);
	}

	public function test_a_record_without_an_id_renders_without_notices_and_without_a_share_control(): void {
		$out = $this->render(
			array(
				$this->badge( 'a', true ),
				array( 'earned' => true, 'earned_at' => '2026-01-01 00:00:00' ), // No id, name or image.
			)
		);

		$this->assertSame( 1, substr_count( $out, 'data-wb-gam-share="' ), 'Only the badge that has an id gets a share control.' );
		$this->assertStringNotContainsString( 'badges//share', $out );
		$this->assertStringNotContainsString( 'data-wb-gam-share=""', $out );
	}

	public function test_a_record_missing_name_earned_and_image_renders_as_a_locked_card(): void {
		$out = $this->render( array( array( 'id' => 'x' ), $this->badge( 'y', true ) ) );

		$this->assertSame( 2, substr_count( $out, 'class="wb-gam-badge-showcase__badge ' ) );
		$this->assertStringContainsString( 'data-state="locked"', $out );
	}

	public function test_non_array_entries_are_dropped(): void {
		$out = $this->render( array( 'junk', null, 5, $this->badge( 'a', true ) ) );

		$this->assertSame( 1, substr_count( $out, 'class="wb-gam-badge-showcase__badge ' ) );
		$this->assertStringContainsString( '1 / 1', $out );
	}

	public function test_duplicate_ids_count_and_render_once_and_the_earned_copy_wins(): void {
		$out = $this->render(
			array(
				$this->badge( 'a', false ),
				$this->badge( 'b', true ),
				$this->badge( 'a', true ), // Same badge again, this time earned.
				$this->badge( 'b', true ),
			)
		);

		$this->assertSame( 2, substr_count( $out, 'class="wb-gam-badge-showcase__badge ' ), 'One card per distinct badge.' );
		$this->assertStringContainsString( '2 / 2', $out, 'Header counts distinct badges, earned copy kept.' );
		$this->assertStringContainsString( 'aria-valuemax="2"', $out );
		$this->assertStringContainsString( 'aria-valuenow="2"', $out );
		$this->assertStringNotContainsString( 'data-state="locked"', $out );
	}

	public function test_tabs_render_when_the_member_has_only_locked_badges(): void {
		$out = $this->render( array( $this->badge( 'a', false ), $this->badge( 'b', false ) ) );

		$this->assertStringContainsString( 'role="tablist"', $out );
		$this->assertStringContainsString( 'data-wb-gam-filter="all"', $out );
		$this->assertStringContainsString( 'data-wb-gam-filter="locked"', $out );
		$this->assertMatchesRegularExpression( '/data-wb-gam-filter="earned"\s+disabled/', $out, 'The empty Earned tab is disabled, not removed.' );
		$this->assertDoesNotMatchRegularExpression( '/data-wb-gam-filter="locked"[^>]*disabled/', $out );
	}

	public function test_a_full_collection_has_no_disabled_tab(): void {
		$out = $this->render( array( $this->badge( 'a', true ), $this->badge( 'b', false ) ) );

		$this->assertStringContainsString( 'role="tablist"', $out );
		$this->assertDoesNotMatchRegularExpression( '/data-wb-gam-filter="[a-z]+"[^>]*disabled/', $out );
	}
}
