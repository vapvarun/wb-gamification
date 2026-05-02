<?php
/**
 * Unit tests for StreakEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\StreakEngine;

/**
 * @coversDefaultClass \WBGam\Engine\StreakEngine
 */
class StreakEngineTest extends TestCase {

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
	 * @covers ::get_streak
	 */
	public function returns_zero_streak_for_user_with_no_record(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			fn( $q, ...$args ) => vsprintf(
				str_replace( '%d', '%s', $q ),
				array_map( 'strval', $args )
			)
		);
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );

		$result = StreakEngine::get_streak( 99 );

		$this->assertSame( 0, $result['current_streak'] );
		$this->assertSame( 0, $result['longest_streak'] );
		$this->assertNull( $result['last_active'] );
		$this->assertSame( 'UTC', $result['timezone'] );
		$this->assertFalse( $result['grace_used'] );
	}

	/**
	 * @test
	 * @covers ::get_streak
	 */
	public function returns_cached_streak_when_present(): void {
		$cached = array(
			'current_streak' => 7,
			'longest_streak' => 30,
			'last_active'    => '2026-05-02',
			'timezone'       => 'America/New_York',
			'grace_used'     => true,
		);

		Functions\when( 'wp_cache_get' )->justReturn( $cached );

		$this->assertSame( $cached, StreakEngine::get_streak( 1 ) );
	}
}
