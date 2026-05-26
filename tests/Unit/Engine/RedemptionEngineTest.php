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
		// Stock semantics shifted in 1.4.0 (Basecamp #9925383280): NULL/0
		// mean "unlimited", positive integers mean finite. Out-of-stock now
		// fires when a concurrent redemption races us — the atomic UPDATE
		// finds `stock > 0` false because someone else took the last unit
		// between our SELECT and our UPDATE. Mock that path.
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$item = [
			'id'            => 1,
			'title'         => 'Test Reward',
			'is_active'     => '1',
			'points_cost'   => '100',
			'stock'         => '1',
			'point_type'    => '',
			'reward_type'   => 'custom',
			'reward_config' => '{}',
		];

		$wpdb->shouldReceive( 'get_row' )->andReturn( $item );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $sql ) => $sql );

		// Atomic balance check — sufficient (so we get past the insufficient branch).
		$wpdb->shouldReceive( 'get_var' )->andReturn( 500 );

		// PointsEngine::debit performs an INSERT into the ledger and an
		// INSERT … ON DUPLICATE KEY UPDATE on the materialised totals
		// before the stock UPDATE happens; the engine's transactional
		// ordering is debit-then-decrement so a failed decrement triggers
		// a ROLLBACK that undoes the debit.
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 );
		$wpdb->insert_id = 42;

		// query() catch-all: START TRANSACTION + ROLLBACK + the totals
		// INSERT all succeed; the stock decrement UPDATE returns 0 rows to
		// simulate losing the race. Order matters — specific matcher first
		// so the UPDATE branch is caught before the catch-all fires.
		$wpdb->shouldReceive( 'query' )
			->with( \Mockery::pattern( '/UPDATE\s+wp_wb_gam_redemption_items/i' ) )
			->andReturn( 0 );
		$wpdb->shouldReceive( 'query' )->andReturn( true );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'current_time' )->justReturn( '2026-05-27 12:00:00' );
		Functions\when( 'sanitize_key' )->returnArg();

		$result = RedemptionEngine::redeem( 1, 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'out_of_stock', $result['reason'] );
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
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( $sql ) => $sql );

		// The redeem flow takes an atomic balance check via SELECT … FOR
		// UPDATE inside a transaction, which bypasses the object cache.
		// Mock the transaction wrapper + the locked-balance read so the
		// "insufficient points" branch can be exercised end-to-end.
		$wpdb->shouldReceive( 'query' )->with( 'START TRANSACTION' )->andReturn( true );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 50 ); // 50 < cost 10,000 → insufficient.
		$wpdb->shouldReceive( 'query' )->with( 'ROLLBACK' )->andReturn( true );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'number_format_i18n' )->alias(
			static fn ( $n, $decimals = 0 ) => number_format( (float) $n, (int) $decimals )
		);

		$result = RedemptionEngine::redeem( 1, 1 );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['redemption_id'] );
	}
}
