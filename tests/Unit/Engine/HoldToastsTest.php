<?php
/**
 * A host can hold toasts on a screen without losing them.
 *
 * The welcome-points toast landed on sign-up, verify, onboarding and checkout,
 * over the form the member was filling in. A host now returns true from
 * wb_gam_hold_toasts there; render() then reads nothing, so no cursor moves and
 * the toast shows on the next screen that does not hold it.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\NotificationBridge;

#[CoversMethod( \WBGam\Engine\NotificationBridge::class, 'render' )]
class HoldToastsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function a_held_screen_reads_and_renders_nothing(): void {
		Filters\expectApplied( 'wb_gam_hold_toasts' )->once()->with( false, 5 )->andReturn( true );
		Functions\expect( 'get_user_meta' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_enqueue_script_module' )->never();

		ob_start();
		NotificationBridge::render();
		$this->assertSame( '', ob_get_clean() );
	}
}
