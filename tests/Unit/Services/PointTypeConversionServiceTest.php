<?php
/**
 * Regression tests for PointTypeConversionService::convert().
 *
 * Guards the B1 ledger-corruption bug (fixed 2026-05-30): convert() used a
 * raw `START TRANSACTION` instead of the re-entrant Transaction::run, passed
 * the event_id STRING (not the Event object) to PointsEngine::debit, checked
 * the array return as a boolean, and inserted a SECOND, duplicate-PK events
 * row. The net effect was that every conversion debited the source currency
 * but never credited the destination — the user lost points and gained
 * nothing. These tests exercise the REAL convert + debit + Transaction stack
 * against a mocked $wpdb and lock in the corrected behaviour.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Transaction;
use WBGam\Repository\PointTypeRepository;
use WBGam\Services\PointTypeConversionService;

class PointTypeConversionServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->resetRepoStatics();
		$this->stubWpFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Transaction::reset_for_tests();
		$this->resetRepoStatics();
		parent::tearDown();
	}

	/**
	 * A 250-point conversion at a 100:1 rate must debit 200 from the source
	 * and credit 2 to the destination, atomically, sharing one event row.
	 */
	public function test_convert_debits_source_and_credits_destination(): void {
		$writes = $this->mockWpdbForConversion( 1000 );

		$service = new PointTypeConversionService();
		$result  = $service->convert( 7, 'points', 'coins', 250 );

		$this->assertTrue( $result['ok'], 'Conversion should succeed.' );
		$this->assertSame( 200, $result['debit'] );
		$this->assertSame( 2, $result['credit'] );
		$this->assertSame( 2, $result['units'] );

		// The core B1 guard: exactly ONE events row. The buggy code inserted a
		// second row with the same primary key (debit's persist_event + a
		// manual mirror insert), which fails on a real DB and aborted the
		// credit.
		$this->assertCount( 1, $writes->events, 'Exactly one wb_gam_events row must be written.' );

		// Both a debit and a credit ledger row, sharing the events row's id.
		$this->assertCount( 2, $writes->points, 'A debit AND a credit ledger row must be written.' );
		$event_id = $writes->events[0]['id'];
		foreach ( $writes->points as $row ) {
			$this->assertSame( $event_id, $row['event_id'], 'Both ledger rows share the audit event_id.' );
		}

		$by_type = array();
		foreach ( $writes->points as $row ) {
			$by_type[ $row['point_type'] ] = (int) $row['points'];
		}
		$this->assertSame( -200, $by_type['points'], 'Source currency debited by 200.' );
		$this->assertSame( 2, $by_type['coins'], 'Destination currency credited by 2.' );

		// Single atomic frame: the buggy raw START TRANSACTION + the nested
		// debit's Transaction::run opened TWO transactions (an implicit commit
		// between them was the corruption). The fix runs exactly one.
		$starts = array_filter(
			$writes->queries,
			static fn( $sql ) => false !== strpos( (string) $sql, 'START TRANSACTION' )
		);
		$this->assertCount( 1, $starts, 'Conversion must run in exactly one transaction.' );
	}

	/**
	 * Insufficient balance must roll back with no ledger writes at all.
	 */
	public function test_convert_insufficient_balance_writes_nothing(): void {
		$writes = $this->mockWpdbForConversion( 50 ); // 50 < 200 needed.

		$service = new PointTypeConversionService();
		$result  = $service->convert( 7, 'points', 'coins', 250 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'insufficient', $result['error'] );
		$this->assertCount( 0, $writes->events, 'No events row on rollback.' );
		$this->assertCount( 0, $writes->points, 'No ledger rows on rollback.' );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Mock $wpdb so the only DB that matters is the ledger. Point types and
	 * the conversion rule are served from the object cache (see stubWpFunctions),
	 * so $wpdb sees only the balance locks (get_var), ledger inserts, and the
	 * transaction/UPSERT queries.
	 *
	 * @param int $balance Balance returned by every FOR UPDATE lock.
	 * @return object A shared container with ->events, ->points, ->queries arrays.
	 */
	private function mockWpdbForConversion( int $balance ): object {
		global $wpdb;

		// A shared object (not an array) so the mock closures and the test see
		// the SAME captured writes — returning an array would hand the test a
		// copy the closures never touch.
		$writes          = new \stdClass();
		$writes->events  = array();
		$writes->points  = array();
		$writes->queries = array();

		$wpdb             = \Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_var' )->andReturn( $balance );

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			static function ( $table, $data ) use ( $writes ): int {
				if ( false !== strpos( (string) $table, 'wb_gam_events' ) ) {
					$writes->events[] = $data;
				} elseif ( false !== strpos( (string) $table, 'wb_gam_points' ) ) {
					$writes->points[] = $data;
				}
				return 1;
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( $writes ): int {
				$writes->queries[] = (string) $sql;
				return 1;
			}
		);

		return $writes;
	}

	/**
	 * Feed point types + the conversion rule via the object cache so type
	 * resolution and rule lookup never touch the mocked $wpdb.
	 */
	private function stubWpFunctions(): void {
		Functions\when( 'wp_cache_get' )->alias(
			static function ( string $key, string $group = '' ) {
				if ( 'point_types_all' === $key ) {
					return array(
						array( 'slug' => 'points', 'label' => 'Points', 'is_default' => 1, 'position' => 0 ),
						array( 'slug' => 'coins', 'label' => 'Coins', 'is_default' => 0, 'position' => 1 ),
					);
				}
				if ( 'point_types_default' === $key ) {
					return 'points';
				}
				if ( 'point_type_conversions_all' === $key ) {
					return array(
						array(
							'id'               => 1,
							'from_type'        => 'points',
							'to_type'          => 'coins',
							'from_amount'      => 100,
							'to_amount'        => 1,
							'min_convert'      => 1,
							'cooldown_seconds' => 0,
							'max_per_day'      => 0,
							'is_active'        => 1,
						),
					);
				}
				return false;
			}
		);
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\stubs( array( 'wp_json_encode' => static fn( $v ) => json_encode( $v ) ) );
	}

	/**
	 * The point-type repository memoises in static properties; clear them so
	 * the cache stub above is consulted on every test.
	 */
	private function resetRepoStatics(): void {
		foreach ( array( 'request_cache_all', 'request_cache_default' ) as $prop ) {
			$ref = new \ReflectionProperty( PointTypeRepository::class, $prop );
			$ref->setAccessible( true );
			$ref->setValue( null, null );
		}
	}
}
