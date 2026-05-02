<?php
/**
 * Unit tests for BadgeEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\BadgeEngine;

/**
 * @coversDefaultClass \WBGam\Engine\BadgeEngine
 */
class BadgeEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::has_badge
	 * @covers ::get_user_earned_badge_ids
	 */
	public function reports_badge_held_when_present_in_cached_list(): void {
		Functions\when( 'wp_cache_get' )->alias(
			fn( $key ) => 'wb_gam_earned_badges_42' === $key
				? array( 'first_post', 'champion' )
				: false
		);

		$this->assertTrue( BadgeEngine::has_badge( 42, 'first_post' ) );
		$this->assertTrue( BadgeEngine::has_badge( 42, 'champion' ) );
		$this->assertFalse( BadgeEngine::has_badge( 42, 'never_earned' ) );
	}

	/**
	 * @test
	 * @covers ::has_badge
	 * @covers ::get_user_earned_badge_ids
	 */
	public function user_with_no_badges_returns_empty_set(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			fn( $q, ...$args ) => vsprintf( str_replace( '%d', '%s', $q ), array_map( 'strval', $args ) )
		);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );

		$this->assertFalse( BadgeEngine::has_badge( 99, 'any_badge' ) );
	}
}
