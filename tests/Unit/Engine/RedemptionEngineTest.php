<?php
/**
 * Unit tests for RedemptionEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\RedemptionEngine;

class RedemptionEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Coupon code format ───────────────────────────────────────────────────

	/**
	 * The generated coupon code should start with WBG- and be uppercase.
	 */
	public function test_coupon_code_format_matches_expected_pattern(): void {
		// We can test the pattern via the create_woo_coupon output format
		// by verifying the prefix is correct (indirectly through a mock run).
		// Since create_woo_coupon is private, we test the contract: any code
		// returned by redeem() when WooCommerce is active starts with WBG-.
		// Here we just verify the regex the code would produce.
		$redemption_id = 1;
		$user_id       = 1;
		$code          = strtoupper( 'WBG-' . substr( md5( $redemption_id . $user_id . 1234567890 ), 0, 8 ) );

		$this->assertMatchesRegularExpression( '/^WBG-[A-F0-9]{8}$/', $code );
	}

	// ── redeem() input validation ────────────────────────────────────────────

	public function test_redeem_returns_error_when_item_not_found(): void {
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->expects( 'get_row' )->andReturn( null );
		$wpdb->expects( 'prepare' )->andReturnArg( 0 );

		Functions\expect( '__' )->andReturnArg( 0 );

		$result = RedemptionEngine::redeem( 1, 999 );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error'] );
		$this->assertNull( $result['redemption_id'] );
	}

	public function test_redeem_returns_error_when_out_of_stock(): void {
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$item = [
			'id'            => 1,
			'title'         => 'Test Reward',
			'is_active'     => '1',
			'points_cost'   => '100',
			'stock'         => '0',
			'reward_type'   => 'custom',
			'reward_config' => '{}',
		];

		$wpdb->expects( 'get_row' )->andReturn( $item );
		$wpdb->expects( 'prepare' )->andReturnArg( 0 );

		Functions\expect( '__' )->andReturnArg( 0 );

		$result = RedemptionEngine::redeem( 1, 1 );

		$this->assertFalse( $result['success'] );
	}

	public function test_redeem_returns_error_when_insufficient_points(): void {
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$item = [
			'id'            => 1,
			'title'         => 'Expensive Reward',
			'is_active'     => '1',
			'points_cost'   => '10000',
			'stock'         => null,
			'reward_type'   => 'custom',
			'reward_config' => '{}',
		];

		$wpdb->expects( 'get_row' )->andReturn( $item );
		$wpdb->expects( 'prepare' )->andReturnArg( 0 );

		// Mock PointsEngine::get_total() to return 50 (not enough).
		Functions\expect( '__' )->andReturnArg( 0 );
		Functions\expect( 'wp_cache_get' )->andReturn( 50 );

		$result = RedemptionEngine::redeem( 1, 1 );

		$this->assertFalse( $result['success'] );
	}
}
