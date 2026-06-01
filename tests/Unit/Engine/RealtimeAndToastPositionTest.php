<?php
/**
 * Regression tests for the 1.5.2 realtime + notification-placement hardening.
 *
 * Locks two scale/UX invariants:
 *   - Realtime defaults to Heartbeat, and SSE only activates behind the
 *     wb_gam_sse_allowed gate (so no PHP worker is pinned by default).
 *   - The toast position is always a validated member of the allowed set,
 *     defaulting to bottom-right (off the theme header).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\API\SSEController;
use WBGam\Engine\NotificationBridge;

/**
 * @coversDefaultClass \WBGam\API\SSEController
 */
class RealtimeAndToastPositionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default: option unset → get_option returns its default (2nd arg),
		// and apply_filters is a pass-through (returns the value, 2nd arg).
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @test
	 * @covers ::get_transport
	 */
	public function transport_defaults_to_heartbeat(): void {
		$this->assertSame( 'heartbeat', SSEController::get_transport() );
	}

	/**
	 * @test
	 * @covers ::get_transport
	 */
	public function transport_returns_stored_valid_value(): void {
		Functions\when( 'get_option' )->justReturn( 'sse' );
		$this->assertSame( 'sse', SSEController::get_transport() );
	}

	/**
	 * @test
	 * @covers ::get_transport
	 */
	public function transport_falls_back_to_heartbeat_for_garbage(): void {
		Functions\when( 'get_option' )->justReturn( 'nonsense' );
		$this->assertSame( 'heartbeat', SSEController::get_transport() );
	}

	/**
	 * @test
	 * @covers ::sse_allowed
	 */
	public function sse_is_not_allowed_by_default(): void {
		$this->assertFalse( SSEController::sse_allowed() );
	}

	/**
	 * @test
	 * @covers ::sse_allowed
	 */
	public function sse_allowed_respects_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'wb_gam_sse_allowed' === $hook ? true : $value
		);
		$this->assertTrue( SSEController::sse_allowed() );
	}

	/**
	 * @test
	 * @covers ::effective_transport
	 */
	public function effective_transport_downgrades_sse_to_heartbeat_when_not_allowed(): void {
		Functions\when( 'get_option' )->justReturn( 'sse' );
		// sse_allowed() stays false (filter pass-through), so the client must
		// be told heartbeat and never open an EventSource.
		$this->assertSame( 'heartbeat', SSEController::effective_transport() );
	}

	/**
	 * @test
	 * @covers ::effective_transport
	 */
	public function effective_transport_keeps_sse_when_allowed(): void {
		Functions\when( 'get_option' )->justReturn( 'sse' );
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'wb_gam_sse_allowed' === $hook ? true : $value
		);
		$this->assertSame( 'sse', SSEController::effective_transport() );
	}

	/**
	 * @test
	 * @covers ::is_enabled
	 */
	public function stream_endpoint_is_disabled_unless_sse_allowed(): void {
		Functions\when( 'get_option' )->justReturn( 'auto' );
		$this->assertFalse( SSEController::is_enabled() );
	}

	/**
	 * @test
	 * @covers \WBGam\Engine\NotificationBridge::get_toast_position
	 */
	public function toast_position_defaults_to_bottom_right(): void {
		$this->assertSame( 'bottom-right', NotificationBridge::get_toast_position() );
	}

	/**
	 * @test
	 * @covers \WBGam\Engine\NotificationBridge::get_toast_position
	 */
	public function toast_position_returns_stored_valid_value(): void {
		Functions\when( 'get_option' )->justReturn( 'top-center' );
		$this->assertSame( 'top-center', NotificationBridge::get_toast_position() );
	}

	/**
	 * @test
	 * @covers \WBGam\Engine\NotificationBridge::get_toast_position
	 */
	public function toast_position_falls_back_for_invalid_value(): void {
		Functions\when( 'get_option' )->justReturn( 'middle-of-nowhere' );
		$this->assertSame( 'bottom-right', NotificationBridge::get_toast_position() );
	}

	/**
	 * @test
	 * @covers \WBGam\Engine\NotificationBridge::get_toast_position
	 */
	public function toast_position_rejects_invalid_filter_value(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'wb_gam_toast_position' === $hook ? 'bogus' : $value
		);
		$this->assertSame( 'bottom-right', NotificationBridge::get_toast_position() );
	}
}
