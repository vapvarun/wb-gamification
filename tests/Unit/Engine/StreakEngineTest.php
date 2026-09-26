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
	 * Stub the lookups get_streak() makes for a member in UTC with $grace grace days,
	 * and serve $row from the cache.
	 *
	 * @param array $row   Cached streak row.
	 * @param int   $grace Grace days option.
	 */
	private function stub_row( array $row, int $grace = 1 ): void {
		Functions\when( 'wp_cache_get' )->justReturn( $row );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( $grace );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * A streak row whose last active day is $days_ago days before today (UTC).
	 *
	 * @param int  $days_ago   Days since last activity.
	 * @param bool $grace_used Whether grace is spent.
	 * @return array
	 */
	private function row( int $days_ago, bool $grace_used = false ): array {
		return array(
			'current_streak' => 7,
			'longest_streak' => 30,
			'last_active'    => gmdate( 'Y-m-d', strtotime( "-{$days_ago} days" ) ),
			'timezone'       => 'UTC',
			'grace_used'     => $grace_used,
		);
	}

	/**
	 * @test
	 * @covers ::get_streak
	 */
	public function reads_live_streak_active_today_or_yesterday(): void {
		$this->stub_row( $this->row( 0 ) );
		$this->assertSame( 7, StreakEngine::get_streak( 1 )['current_streak'] );

		$this->stub_row( $this->row( 1, true ) );
		$this->assertSame( 7, StreakEngine::get_streak( 1 )['current_streak'] );
	}

	/**
	 * @test
	 * @covers ::get_streak
	 */
	public function reads_streak_inside_unused_grace_window(): void {
		$this->stub_row( $this->row( 2 ) );
		$this->assertSame( 7, StreakEngine::get_streak( 1 )['current_streak'] );
	}

	/**
	 * @test
	 * @covers ::get_streak
	 */
	public function reads_lapsed_streak_as_zero_but_keeps_longest(): void {
		$this->stub_row( $this->row( 3 ) );
		$streak = StreakEngine::get_streak( 1 );
		$this->assertSame( 0, $streak['current_streak'] );
		$this->assertSame( 30, $streak['longest_streak'] );

		// Grace already spent: one missed day ends it.
		$this->stub_row( $this->row( 2, true ) );
		$this->assertSame( 0, StreakEngine::get_streak( 1 )['current_streak'] );
	}
}
