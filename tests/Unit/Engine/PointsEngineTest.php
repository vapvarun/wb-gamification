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

class PointsEngineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stubResolveType();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── debit() ──────────────────────────────────────────────────────────────

	public function test_debit_inserts_negative_row_and_busts_cache(): void {
		global $wpdb;

		$wpdb = $this->mockWpdb();
		$wpdb->expects( 'insert' )
			->once()
			->with(
				\Mockery::type( 'string' ), // table
				\Mockery::on( fn( $data ) => $data['points'] === -50 && $data['user_id'] === 7 ),
				\Mockery::type( 'array' )
			)
			->andReturn( 1 );

		// bump_user_total — atomic UPSERT via prepare + query.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$wpdb->prefix = 'wp_';

		Functions\expect( 'current_time' )->once()->andReturn( '2026-01-01 00:00:00' );
		Functions\expect( 'wp_cache_delete' )->once()->with( 'wb_gam_total_7_points', 'wb_gamification' );

		$result = PointsEngine::debit( 7, 50, 'redemption' );

		$this->assertTrue( $result );
	}

	public function test_debit_returns_false_on_db_error(): void {
		global $wpdb;

		$wpdb = $this->mockWpdb();
		$wpdb->expects( 'insert' )->once()->andReturn( false );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );
		$wpdb->last_error = 'mocked error';
		$wpdb->prefix = 'wp_';

		Functions\expect( 'current_time' )->once()->andReturn( '2026-01-01 00:00:00' );
		Functions\expect( 'wp_cache_delete' )->never();

		$result = PointsEngine::debit( 7, 50, 'redemption' );

		$this->assertFalse( $result );
	}

	public function test_debit_always_stores_negative_amount(): void {
		global $wpdb;

		$wpdb = $this->mockWpdb();
		$wpdb->prefix = 'wp_';
		$wpdb->expects( 'insert' )
			->once()
			->with(
				\Mockery::any(),
				\Mockery::on( fn( $data ) => $data['points'] < 0 ),
				\Mockery::any()
			)
			->andReturn( 1 );

		// bump_user_total — atomic UPSERT via prepare + query.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		Functions\expect( 'current_time' )->once()->andReturn( '2026-01-01 00:00:00' );
		Functions\expect( 'wp_cache_delete' )->once();

		// Even if a positive amount is passed in error, stored as negative.
		PointsEngine::debit( 1, 100, 'test' );
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
