<?php
/**
 * Unit tests for ShortcodeHandler.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\CLI\QAPages;
use WBGam\Engine\ShortcodeHandler;

class ShortcodeHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'shortcode_atts' )->alias( function ( array $pairs, array $atts, string $shortcode = '' ): array {
			$out = [];
			foreach ( $pairs as $key => $default ) {
				$out[ $key ] = array_key_exists( $key, $atts ) ? $atts[ $key ] : $default;
			}
			return $out;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_registers_all_shortcodes(): void {
		$registered = [];

		Functions\expect( 'add_shortcode' )
			->times( 15 )
			->andReturnUsing( static function ( string $tag ) use ( &$registered ): void {
				$registered[] = $tag;
			} );

		ShortcodeHandler::init();

		$expected = [
			'wb_gam_leaderboard',
			'wb_gam_member_points',
			'wb_gam_badge_showcase',
			'wb_gam_level_progress',
			'wb_gam_challenges',
			'wb_gam_streak',
			'wb_gam_top_members',
			'wb_gam_kudos_feed',
			'wb_gam_year_recap',
			'wb_gam_points_history',
			'wb_gam_earning_guide',
			'wb_gam_hub',
			'wb_gam_redemption_store',
			'wb_gam_community_challenges',
			'wb_gam_cohort_rank',
		];

		sort( $expected );
		sort( $registered );

		$this->assertSame( $expected, $registered );
	}

	public function test_leaderboard_atts_sanitized(): void {
		// limit must be clamped to 1–100.
		$atts = ShortcodeHandler::normalize_leaderboard_atts( [ 'limit' => '999', 'period' => 'week' ] );

		$this->assertSame( 100, $atts['limit'] );
		$this->assertSame( 'week', $atts['period'] );
	}

	// ── Per-shortcode dispatch contract ──────────────────────────────────────
	//
	// The earning-guide shortcode used to call a non-existent helper and
	// PHP-fataled when rendered. These tests assert every shortcode
	// handler dispatches to render_block() with the right block name —
	// catching missing-method fatals + slug typos at unit-test time.
	//
	// `QAPages::MAP` is the single source of truth (consumed here, by
	// the WP-CLI seeder, and by the QA journey walker), so adding a new
	// block automatically grows this suite.

	/**
	 * @dataProvider shortcodeDispatchProvider
	 */
	public function test_shortcode_dispatches_to_correct_block(
		string $handler_method,
		string $expected_block_slug
	): void {
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\stubs(
			array(
				'sanitize_key'        => static fn ( $v ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $v ) ),
				'sanitize_text_field' => static fn ( $v ) => trim( (string) $v ),
				'sanitize_hex_color'  => static fn ( $v ) => preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $v ) ? $v : '',
			)
		);

		Functions\expect( 'render_block' )
			->once()
			->with(
				\Mockery::on(
					static fn ( $block ) => is_array( $block )
						&& "wb-gamification/{$expected_block_slug}" === ( $block['blockName'] ?? '' )
				)
			)
			->andReturn( '<div data-stub>ok</div>' );

		$out = call_user_func( array( ShortcodeHandler::class, "render_{$handler_method}" ), array() );

		$this->assertSame( '<div data-stub>ok</div>', $out );
	}

	public static function shortcodeDispatchProvider(): array {
		$cases = array();
		foreach ( QAPages::MAP as $block_slug => $unit ) {
			// Convert `wb_gam_member_points` → `member_points` (handler suffix).
			$handler_method = preg_replace( '/^wb_gam_/', '', $unit['shortcode'] );
			$cases[ $unit['shortcode'] ] = array( $handler_method, $block_slug );
		}
		return $cases;
	}

	public function test_qa_pages_map_covers_every_block_in_src_blocks(): void {
		$declared_blocks = array_map(
			static fn ( $dir ) => basename( $dir ),
			glob( __DIR__ . '/../../../src/Blocks/*', GLOB_ONLYDIR )
		);
		$declared_blocks = array_filter(
			$declared_blocks,
			static fn ( $name ) => file_exists(
				__DIR__ . '/../../../src/Blocks/' . $name . '/block.json'
			)
		);

		sort( $declared_blocks );
		$mapped = array_keys( QAPages::MAP );
		sort( $mapped );

		$this->assertSame(
			$declared_blocks,
			$mapped,
			'Every src/Blocks/<slug>/block.json must have a QAPages::MAP entry (and vice versa).'
		);
	}
}
