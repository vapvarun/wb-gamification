<?php
/**
 * Regression tests for earning exclusion (1.5.3).
 *
 * Locks the site-owner control that stops chosen roles, accounts, or
 * sandboxed users from earning points. Enforced in
 * PointsEngine::user_can_earn(), the single gate both award paths pass
 * through (passes_rate_limits).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\PointsEngine;

/**
 * @coversDefaultClass \WBGam\Engine\PointsEngine
 */
class AccessExclusionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Defaults: empty exclusions, no sandbox meta, filter pass-through.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::user_can_earn
	 */
	public function a_normal_member_can_earn_by_default(): void {
		$this->assertTrue( PointsEngine::user_can_earn( 42 ) );
	}

	/**
	 * @test
	 * @covers ::user_can_earn
	 */
	public function logged_out_users_never_earn(): void {
		$this->assertFalse( PointsEngine::user_can_earn( 0 ) );
	}

	/**
	 * @test
	 * @covers ::user_can_earn
	 */
	public function an_explicitly_excluded_user_id_cannot_earn(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				return 'wb_gam_excluded_users' === $name ? array( 42 ) : array();
			}
		);
		$this->assertFalse( PointsEngine::user_can_earn( 42 ) );
		$this->assertTrue( PointsEngine::user_can_earn( 7 ) );
	}

	/**
	 * @test
	 * @covers ::user_can_earn
	 */
	public function a_sandboxed_user_cannot_earn(): void {
		Functions\when( 'get_user_meta' )->justReturn( '1' );
		$this->assertFalse( PointsEngine::user_can_earn( 42 ) );
	}

	/**
	 * @test
	 * @covers ::user_can_earn
	 */
	public function a_user_in_an_excluded_role_cannot_earn(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) {
				return 'wb_gam_excluded_roles' === $name ? array( 'administrator' ) : array();
			}
		);
		$admin        = (object) array( 'roles' => array( 'administrator' ) );
		$member       = (object) array( 'roles' => array( 'subscriber' ) );
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $admin, $member ) {
				return 1 === $id ? $admin : $member;
			}
		);
		$this->assertFalse( PointsEngine::user_can_earn( 1 ) );
		$this->assertTrue( PointsEngine::user_can_earn( 99 ) );
	}

	/**
	 * @test
	 * @covers ::user_can_earn
	 */
	public function the_filter_can_override_the_decision(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'wb_gam_user_can_earn' === $hook ? false : $value;
			}
		);
		$this->assertFalse( PointsEngine::user_can_earn( 42 ) );
	}
}
