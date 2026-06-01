<?php
/**
 * Regression tests for public-profile visibility (1.5.2).
 *
 * Locks the opt-OUT model: /u/{username} profiles are public by default so
 * member progress showcases out of the box. Before 1.5.2 visibility required
 * an opt-IN flag no member-facing UI ever wrote, so every profile 404'd.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\ProfilePage;

/**
 * @coversDefaultClass \WBGam\Engine\ProfilePage
 */
class ProfileVisibilityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Defaults: option returns its default (2nd arg), meta empty, filter
		// pass-through.
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::is_publicly_visible
	 */
	public function profile_is_public_by_default(): void {
		// Site kill-switch defaults '1' (returnArg), per-user meta empty.
		$this->assertTrue( ProfilePage::is_publicly_visible( 42 ) );
	}

	/**
	 * @test
	 * @covers ::is_publicly_visible
	 */
	public function explicit_zero_opts_the_member_out(): void {
		Functions\when( 'get_user_meta' )->justReturn( '0' );
		$this->assertFalse( ProfilePage::is_publicly_visible( 42 ) );
	}

	/**
	 * @test
	 * @covers ::is_publicly_visible
	 */
	public function site_kill_switch_forces_private(): void {
		// Option stored as '' (feature disabled site-wide).
		Functions\when( 'get_option' )->justReturn( '' );
		$this->assertFalse( ProfilePage::is_publicly_visible( 42 ) );
	}

	/**
	 * @test
	 * @covers ::is_publicly_visible
	 */
	public function filter_can_force_private(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'wb_gam_profile_publicly_visible' === $hook ? false : $value
		);
		$this->assertFalse( ProfilePage::is_publicly_visible( 42 ) );
	}
}
