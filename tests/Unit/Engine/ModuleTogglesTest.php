<?php
/**
 * Tests for optional-module toggles (1.5.3).
 *
 * Locks the default-on, explicit-'0'-off semantics + the filter override.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ModuleToggles;

/**
 * @coversDefaultClass \WBGam\Engine\ModuleToggles
 */
class ModuleTogglesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::enabled
	 */
	public function modules_are_enabled_by_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertTrue( ModuleToggles::enabled( 'kudos' ) );
		$this->assertTrue( ModuleToggles::enabled( 'redemption' ) );
	}

	/**
	 * @test
	 * @covers ::enabled
	 */
	public function an_explicit_zero_disables_only_that_module(): void {
		Functions\when( 'get_option' )->justReturn( array( 'redemption' => '0' ) );
		$this->assertFalse( ModuleToggles::enabled( 'redemption' ) );
		$this->assertTrue( ModuleToggles::enabled( 'kudos' ) );
	}

	/**
	 * @test
	 * @covers ::enabled
	 */
	public function the_filter_can_force_a_module_off(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $slug ) {
				return ( 'wb_gam_module_enabled' === $hook && 'streaks' === $slug ) ? false : $value;
			}
		);
		$this->assertFalse( ModuleToggles::enabled( 'streaks' ) );
		$this->assertTrue( ModuleToggles::enabled( 'kudos' ) );
	}

	/**
	 * @test
	 * @covers ::modules
	 */
	public function the_module_map_covers_the_optional_modules(): void {
		$slugs = array_keys( ModuleToggles::modules() );
		$this->assertContains( 'kudos', $slugs );
		$this->assertContains( 'redemption', $slugs );
		$this->assertContains( 'cohort_leagues', $slugs );
	}
}
