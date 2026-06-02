<?php
/**
 * Tests for point expiry / inactivity decay (1.5.3).
 *
 * Locks the opt-in contract: decay is OFF unless explicitly enabled, and a
 * disabled run is a no-op (never debits anyone).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\PointsExpiry;

/**
 * @coversDefaultClass \WBGam\Engine\PointsExpiry
 */
class PointsExpiryTest extends TestCase {

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
	 * @covers ::enabled
	 */
	public function decay_is_off_by_default(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		$this->assertFalse( PointsExpiry::enabled() );
	}

	/**
	 * @test
	 * @covers ::enabled
	 */
	public function decay_is_on_when_the_option_is_set(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		$this->assertTrue( PointsExpiry::enabled() );
	}

	/**
	 * @test
	 * @covers ::run
	 */
	public function a_disabled_run_is_a_noop(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		// run() returns before touching the DB when disabled; no $wpdb needed.
		$this->assertSame( 0, PointsExpiry::run() );
	}
}
