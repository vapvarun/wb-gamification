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
}
