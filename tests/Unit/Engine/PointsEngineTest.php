<?php
/**
 * Unit tests for PointsEngine.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\Transaction;

class PointsEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stubResolveType();

		// passes_rate_limits()/award() now run user_can_earn(); stub its WP reads
		// so the default is "user can earn" (no exclusions configured).
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) {
				return in_array( $name, array( 'wb_gam_excluded_users', 'wb_gam_excluded_roles' ), true )
					? array()
					: $default_value;
			}
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Transaction::reset_for_tests();
		parent::tearDown();
	}

	// ── debit() ──────────────────────────────────────────────────────────────
	//
	// Post-1.4.1 debit() goes through Transaction::run with a FOR UPDATE
	// balance lock, then writes both wb_gam_events (audit) and wb_gam_points
	// (ledger). The mock has to cover all three: a get_var for the balance,
	// two `insert` calls (events + points), and the transactional query()
	// envelope ('START TRANSACTION' / 'COMMIT' / 'ROLLBACK'). Returns
	// array{success: bool, reason?: string, event_id?: string, new_balance?: int}.

	public function test_debit_writes_audit_row_and_ledger_row_and_busts_cache(): void {
		global $wpdb;

		$wpdb         = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		// FOR UPDATE balance lock — return 200 so a debit of 50 has enough.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 200 );

		// Two inserts: wb_gam_events (audit) + wb_gam_points (ledger).
		$inserts = [];
		$wpdb->shouldReceive( 'insert' )
			->andReturnUsing(
				static function ( $table, $data ) use ( &$inserts ): int {
					$inserts[] = $data;
					return 1;
				}
			);

		// Transactional envelope + bump_user_total upsert.
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		Functions\expect( 'current_time' )->atLeast()->once()->andReturn( '2026-01-01 00:00:00' );
		Functions\stubs( array( 'wp_json_encode' => static fn( $v ) => json_encode( $v ) ) );
		Functions\expect( 'wp_cache_delete' )->atLeast()->once();

		$result = PointsEngine::debit( 7, 50, 'redemption' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 150, $result['new_balance'] ); // 200 - 50

		// Verify both rows were written and the ledger row has the negative amount.
		$this->assertCount( 2, $inserts, 'Both events and points rows must be written for audit invariant.' );
		$ledger_row = end( $inserts );
		$this->assertSame( -50, $ledger_row['points'] );
		$this->assertSame( 7, $ledger_row['user_id'] );
	}

	public function test_debit_rolls_back_when_balance_insufficient(): void {
		global $wpdb;

		$wpdb         = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		// Balance < requested amount → debit refuses.
		$wpdb->shouldReceive( 'get_var' )->andReturn( 10 );
		// Should NOT call insert (transaction rolls back before write).
		$wpdb->shouldReceive( 'insert' )->never();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		Functions\stubs( array( 'wp_json_encode' => static fn( $v ) => json_encode( $v ) ) );

		$result = PointsEngine::debit( 7, 50, 'redemption' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'insufficient_balance', $result['reason'] );
	}

	public function test_debit_always_stores_negative_amount(): void {
		global $wpdb;

		$wpdb         = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_var' )->andReturn( 500 );

		$ledger_amount = null;
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			static function ( $table, $data ) use ( &$ledger_amount ): int {
				// The wb_gam_points insert has a `points` key; the events
				// insert doesn't. Capture the points value when present.
				if ( array_key_exists( 'points', $data ) ) {
					$ledger_amount = $data['points'];
				}
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		Functions\expect( 'current_time' )->atLeast()->once()->andReturn( '2026-01-01 00:00:00' );
		Functions\stubs( array( 'wp_json_encode' => static fn( $v ) => json_encode( $v ) ) );
		Functions\expect( 'wp_cache_delete' )->atLeast()->once();

		// Even if a positive amount is passed, the ledger stores it negative.
		$result = PointsEngine::debit( 1, 100, 'test' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( -100, $ledger_amount );
	}

	// ── passes_rate_limits() ─────────────────────────────────────────────────

	public function test_passes_rate_limits_true_when_no_caps(): void {
		global $wpdb;
		$wpdb = $this->mockWpdb();
		$wpdb->prefix = 'wp_';

		// No DB calls expected for repeatable action with no cooldown.
		$wpdb->allows( 'get_var' )->andReturn( 0 );

		$action = [ 'repeatable' => true, 'cooldown' => 0 ];
		$result = PointsEngine::passes_rate_limits( 1, 'test_action', $action );

		$this->assertTrue( $result );
	}

	public function test_passes_rate_limits_false_when_not_repeatable_and_already_performed(): void {
		global $wpdb;
		$wpdb = $this->mockWpdb();
		$wpdb->prefix = 'wp_';
		$wpdb->expects( 'prepare' )->once()->andReturn( 'SELECT...' );
		$wpdb->expects( 'get_var' )->once()->with( 'SELECT...' )->andReturn( 1 ); // Already done once.

		$action = [ 'repeatable' => false, 'cooldown' => 0 ];
		$result = PointsEngine::passes_rate_limits( 1, 'test_action', $action );

		$this->assertFalse( $result );
	}

	// ── get_total() ──────────────────────────────────────────────────────────

	public function test_get_total_returns_cached_value(): void {
		// Cache returns the type-default lookup ('points') AND the user's total.
		// Cache key shape: wb_gam_total_<user>_<type> (post-multi-currency rename).
		Functions\when( 'wp_cache_get' )->alias(
			static function ( string $key, string $group ): mixed {
				if ( 'wb_gamification' !== $group ) {
					return false;
				}
				if ( 'point_types_default' === $key ) {
					return 'points';
				}
				if ( 'wb_gam_total_42_points' === $key ) {
					return 500;
				}
				return false;
			}
		);

		$total = PointsEngine::get_total( 42 );

		$this->assertSame( 500, $total );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private function mockWpdb(): \Mockery\MockInterface {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		return $wpdb;
	}

	/**
	 * Stub the resolve_type() chain so PointTypeService doesn't hit the DB.
	 *
	 * resolve_type(null) → PointTypeService::resolve(null) → repo->default_slug()
	 * → wp_cache_get('point_types_default', 'wb_gamification'). When that hits,
	 * the rest of the chain skips DB. Returning 'points' (DEFAULT_SLUG) lets
	 * unit tests run without a real wpdb behind PointTypeRepository.
	 */
	private function stubResolveType(): void {
		Functions\when( 'wp_cache_get' )->alias(
			static function ( string $key, string $group ): mixed {
				if ( 'wb_gamification' === $group && 'point_types_default' === $key ) {
					return 'points';
				}
				return false;
			}
		);
	}
}
