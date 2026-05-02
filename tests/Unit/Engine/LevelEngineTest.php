<?php
/**
 * Unit tests for LevelEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WBGam\Engine\LevelEngine;

/**
 * @coversDefaultClass \WBGam\Engine\LevelEngine
 */
class LevelEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		// Reset the static cache between tests.
		$reflection = new ReflectionClass( LevelEngine::class );
		$cache_prop = $reflection->getProperty( 'levels_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, null );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: seed the private $levels_cache so get_level_for_points doesn't
	 * hit wpdb.
	 *
	 * @param array<int, array<string, mixed>> $levels Sorted ASC by min_points.
	 */
	private function seed_cache( array $levels ): void {
		$reflection = new ReflectionClass( LevelEngine::class );
		$cache_prop = $reflection->getProperty( 'levels_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue( null, $levels );
	}

	/**
	 * @test
	 * @covers ::get_level_for_points
	 */
	public function returns_null_when_no_levels_defined(): void {
		$this->seed_cache( array() );

		$this->assertNull( LevelEngine::get_level_for_points( 100 ) );
	}

	/**
	 * @test
	 * @covers ::get_level_for_points
	 */
	public function returns_the_starting_level_for_zero_points(): void {
		$this->seed_cache( array(
			array( 'id' => 1, 'name' => 'Newcomer',  'min_points' => 0,    'icon_url' => null ),
			array( 'id' => 2, 'name' => 'Member',    'min_points' => 100,  'icon_url' => null ),
			array( 'id' => 3, 'name' => 'Champion',  'min_points' => 1000, 'icon_url' => null ),
		) );

		$level = LevelEngine::get_level_for_points( 0 );
		$this->assertNotNull( $level );
		$this->assertSame( 'Newcomer', $level['name'] );
	}

	/**
	 * @test
	 * @covers ::get_level_for_points
	 */
	public function returns_highest_threshold_user_has_reached(): void {
		$this->seed_cache( array(
			array( 'id' => 1, 'name' => 'Newcomer',  'min_points' => 0,    'icon_url' => null ),
			array( 'id' => 2, 'name' => 'Member',    'min_points' => 100,  'icon_url' => null ),
			array( 'id' => 3, 'name' => 'Champion',  'min_points' => 1000, 'icon_url' => null ),
		) );

		$this->assertSame( 'Newcomer', LevelEngine::get_level_for_points( 99 )['name'] );
		$this->assertSame( 'Member',   LevelEngine::get_level_for_points( 100 )['name'] );
		$this->assertSame( 'Member',   LevelEngine::get_level_for_points( 999 )['name'] );
		$this->assertSame( 'Champion', LevelEngine::get_level_for_points( 1000 )['name'] );
		$this->assertSame( 'Champion', LevelEngine::get_level_for_points( 99999 )['name'] );
	}

	/**
	 * @test
	 * @covers ::get_level_for_points
	 */
	public function thresholds_must_be_inclusive(): void {
		// Edge-case the "exactly at threshold" boundary explicitly.
		$this->seed_cache( array(
			array( 'id' => 1, 'name' => 'Bronze',   'min_points' => 0,   'icon_url' => null ),
			array( 'id' => 2, 'name' => 'Silver',   'min_points' => 500, 'icon_url' => null ),
		) );

		$this->assertSame( 'Bronze', LevelEngine::get_level_for_points( 499 )['name'] );
		$this->assertSame( 'Silver', LevelEngine::get_level_for_points( 500 )['name'] );
	}
}
