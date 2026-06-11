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

	// ── Write path (1.5.5) — the member-facing surface for wb_gam_profile_public.
	//
	// Before 1.5.5 the meta was read everywhere but written nowhere, so a
	// member could not make their own profile private (Basecamp 9985172423).
	// These lock the write helper + its read companion.

	/**
	 * @test
	 * @covers ::member_opted_private
	 */
	public function member_opted_private_reads_only_explicit_zero(): void {
		Functions\when( 'get_user_meta' )->justReturn( '0' );
		$this->assertTrue( ProfilePage::member_opted_private( 42 ) );
	}

	/**
	 * @test
	 * @covers ::member_opted_private
	 */
	public function member_opted_private_is_false_when_unset_or_public(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$this->assertFalse( ProfilePage::member_opted_private( 42 ) );

		Functions\when( 'get_user_meta' )->justReturn( '1' );
		$this->assertFalse( ProfilePage::member_opted_private( 42 ) );
	}

	/**
	 * @test
	 * @covers ::set_member_visibility
	 */
	public function set_member_visibility_writes_zero_for_private(): void {
		$written = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$written ) {
				$written = array( $uid, $key, $value );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		ProfilePage::set_member_visibility( 42, false );
		$this->assertSame( array( 42, 'wb_gam_profile_public', '0' ), $written );
	}

	/**
	 * @test
	 * @covers ::set_member_visibility
	 */
	public function set_member_visibility_writes_one_for_public(): void {
		$written = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$written ) {
				$written = array( $uid, $key, $value );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		ProfilePage::set_member_visibility( 42, true );
		$this->assertSame( array( 42, 'wb_gam_profile_public', '1' ), $written );
	}

	/**
	 * @test
	 * @covers ::set_member_visibility
	 */
	public function set_member_visibility_ignores_invalid_user(): void {
		Functions\when( 'update_user_meta' )->alias(
			static function () {
				throw new \RuntimeException( 'update_user_meta must not run for an invalid user id' );
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		ProfilePage::set_member_visibility( 0, false );
		$this->assertTrue( true ); // Reached here = no write attempted.
	}
}
