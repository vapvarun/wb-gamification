<?php
/**
 * Unit tests for RateLimiter.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\RateLimiter;

class RateLimiterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_consume_allows_first_request(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$result = RateLimiter::consume( 1 );

		$this->assertTrue( $result );
	}

	public function test_consume_denies_when_bucket_empty(): void {
		$bucket = [ 'tokens' => 0.0, 'last_refill' => time() ];
		Functions\expect( 'get_transient' )->once()->andReturn( $bucket );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$result = RateLimiter::consume( 1 );

		$this->assertFalse( $result );
	}

	public function test_consume_returns_false_for_invalid_user(): void {
		$result = RateLimiter::consume( 0 );

		$this->assertFalse( $result );
	}

	public function test_consume_refills_tokens_over_time(): void {
		// Bucket with 0 tokens but set 100 seconds ago — should be refilled.
		$bucket = [
			'tokens'      => 0.0,
			'last_refill' => time() - 100, // 100 seconds ago → +20 tokens at 0.2/s
		];

		Functions\expect( 'get_transient' )->once()->andReturn( $bucket );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$result = RateLimiter::consume( 1 );

		$this->assertTrue( $result ); // Should have tokens now.
	}

	public function test_remaining_returns_capacity_when_no_bucket(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$remaining = RateLimiter::remaining( 1 );

		$this->assertSame( 60, $remaining );
	}

	public function test_reset_deletes_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'wb_gam_rl_5' );

		RateLimiter::reset( 5 );
	}
}
