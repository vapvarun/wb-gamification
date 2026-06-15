<?php
/**
 * Unit tests for LevelEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
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

	/**
	 * Healthy default ladder — sort_order and min_points agree. The next level
	 * is simply the threshold-successor, exactly as before the sort_order fix.
	 *
	 * @test
	 * @covers ::get_next_level_for_points
	 */
	public function next_level_follows_the_ladder_on_a_healthy_seed(): void {
		$this->seed_cache( $this->default_levels() );

		$this->assertSame( 'Member',      LevelEngine::get_next_level_for_points( 50 )['name'] );
		$this->assertSame( 'Regular',     LevelEngine::get_next_level_for_points( 781 )['name'] );
		$this->assertNull( LevelEngine::get_next_level_for_points( 6000 ), 'Top of ladder has no next level.' );
	}

	/**
	 * Regression — Basecamp 9995220498. When an admin edits thresholds so a
	 * lower-ranked level (Member, sort_order 2) ends up with a HIGHER min_points
	 * (796) than the level above it (Contributor, sort_order 3, min 500), the
	 * "next level" must still follow sort_order. A Contributor on 781 points is
	 * heading to Regular, not back down to Member.
	 *
	 * @test
	 * @covers ::get_next_level_for_points
	 * @covers ::get_level_for_points
	 */
	public function next_level_respects_sort_order_when_thresholds_cross(): void {
		$this->seed_cache( $this->scrambled_levels() );

		// Current level is still the highest threshold actually reached.
		$this->assertSame( 'Contributor', LevelEngine::get_level_for_points( 781 )['name'] );

		$next = LevelEngine::get_next_level_for_points( 781 );
		$this->assertNotNull( $next );
		$this->assertSame( 'Regular', $next['name'], 'Next level must follow sort_order, not the lowest threshold above the total.' );
		$this->assertNotSame( 'Member', $next['name'], 'The pre-fix bug named Member here (796 - 781 = 15 points away).' );
	}

	/**
	 * With rows ordered by sort_order rather than min_points, get_level_for_points
	 * must NOT break early — the highest reachable threshold can sit anywhere in
	 * the list. At 800 points the user has cleared Member's 796 threshold.
	 *
	 * @test
	 * @covers ::get_level_for_points
	 */
	public function level_for_points_does_not_break_early_under_sort_order(): void {
		$this->seed_cache( $this->scrambled_levels() );

		$this->assertSame( 'Member', LevelEngine::get_level_for_points( 800 )['name'] );
	}

	/**
	 * invalidate_cache() clears BOTH tiers of the single level cache: it deletes
	 * the object-cache entry and resets the per-request static array, so an admin
	 * editing a level threshold sees it reflected immediately rather than after
	 * the 1-hour TTL. There is only one level cache, so this is the only
	 * invalidation needed (regression guard for the no-invalidation gap).
	 *
	 * @test
	 * @covers ::invalidate_cache
	 */
	public function invalidate_cache_clears_static_and_object_tiers(): void {
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'wb_gam_levels_all_v2', 'wb_gamification' )
			->andReturn( true );

		// Seed the per-request (static) tier, then invalidate it.
		$this->seed_cache( $this->default_levels() );
		LevelEngine::invalidate_cache();

		$reflection = new ReflectionClass( LevelEngine::class );
		$cache_prop = $reflection->getProperty( 'levels_cache' );
		$cache_prop->setAccessible( true );
		$this->assertNull( $cache_prop->getValue(), 'Static tier must be reset to null.' );
	}

	/**
	 * Default seed: sort_order and min_points monotonic together.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function default_levels(): array {
		return array(
			array( 'id' => 1, 'name' => 'Newcomer',    'min_points' => 0,    'sort_order' => 1, 'icon_url' => null ),
			array( 'id' => 2, 'name' => 'Member',      'min_points' => 100,  'sort_order' => 2, 'icon_url' => null ),
			array( 'id' => 3, 'name' => 'Contributor', 'min_points' => 500,  'sort_order' => 3, 'icon_url' => null ),
			array( 'id' => 4, 'name' => 'Regular',     'min_points' => 1500, 'sort_order' => 4, 'icon_url' => null ),
			array( 'id' => 5, 'name' => 'Champion',    'min_points' => 5000, 'sort_order' => 5, 'icon_url' => null ),
		);
	}

	/**
	 * Same ladder, but Member's threshold was edited up to 796 — above
	 * Contributor's 500 — while keeping its sort_order (2). Rows are returned in
	 * sort_order ASC, exactly as get_all_levels() would yield them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scrambled_levels(): array {
		return array(
			array( 'id' => 1, 'name' => 'Newcomer',    'min_points' => 0,    'sort_order' => 1, 'icon_url' => null ),
			array( 'id' => 2, 'name' => 'Member',      'min_points' => 796,  'sort_order' => 2, 'icon_url' => null ),
			array( 'id' => 3, 'name' => 'Contributor', 'min_points' => 500,  'sort_order' => 3, 'icon_url' => null ),
			array( 'id' => 4, 'name' => 'Regular',     'min_points' => 1500, 'sort_order' => 4, 'icon_url' => null ),
			array( 'id' => 5, 'name' => 'Champion',    'min_points' => 5000, 'sort_order' => 5, 'icon_url' => null ),
		);
	}
}
