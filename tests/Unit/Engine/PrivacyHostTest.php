<?php
/**
 * Privacy::can_view_public_profile() - gamification's own rule, and a host community deciding (1.6.5).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Privacy;

#[CoversClass( \WBGam\Engine\Privacy::class )]
#[CoversMethod( \WBGam\Engine\Privacy::class, 'can_view_public_profile' )]
class PrivacyHostTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function member_toggle_and_site_switch_decide_without_a_host(): void {
		$this->assertTrue( Privacy::can_view_public_profile( 7, 3 ) );

		Functions\when( 'get_user_meta' )->justReturn( '0' );
		$this->assertFalse( Privacy::can_view_public_profile( 7, 3 ), 'Member hid their profile.' );
		$this->assertTrue( Privacy::can_view_public_profile( 7, 7 ), 'Members always see their own.' );

		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertFalse( Privacy::can_view_public_profile( 7, 3 ), 'Site switch off.' );
	}

	#[Test]
	public function a_host_community_answer_wins(): void {
		Filters\expectApplied( 'wb_gam_can_view_public_profile' )->andReturn( false );
		$this->assertFalse( Privacy::can_view_public_profile( 7, 3 ), 'Host says private although our own rule says public.' );
	}

	#[Test]
	public function self_is_decided_before_the_host(): void {
		Filters\expectApplied( 'wb_gam_can_view_public_profile' )->never();
		$this->assertTrue( Privacy::can_view_public_profile( 7, 7 ) );
	}
}
