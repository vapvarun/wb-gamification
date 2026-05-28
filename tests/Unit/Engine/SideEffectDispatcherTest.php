<?php
/**
 * Unit tests for SideEffectDispatcher.
 *
 * Covers:
 *  - dispatch() fans out to every registered handler
 *  - a throwing handler is captured + queued, doesn't stop siblings
 *  - reconcile() retries pending failures and deletes on success
 *  - reconcile() flips status to 'exhausted' after MAX_RETRIES
 *  - reconcile() skips rows whose handler is no longer registered
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Event;
use WBGam\Engine\SideEffectDispatcher;

/**
 * @coversDefaultClass \WBGam\Engine\SideEffectDispatcher
 */
class SideEffectDispatcherTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private $wpdb_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		SideEffectDispatcher::reset_handlers();

		// Mock $wpdb so the table writes return what we want.
		$this->wpdb_mock = Mockery::mock();
		$this->wpdb_mock->prefix = 'wp_';

		global $wpdb;
		$wpdb = $this->wpdb_mock; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// wp_json_encode is a WP function (alias of json_encode); the
		// rest of the WP boundary needs stubs because we don't load
		// WordPress. gmdate is a PHP internal — used unmocked.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		SideEffectDispatcher::reset_handlers();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_event( string $id = 'evt-1' ): Event {
		return new Event(
			array(
				'action_id'  => 'test_action',
				'user_id'    => 42,
				'object_id'  => 0,
				'metadata'   => array(),
				'created_at' => '2026-05-28 00:00:00',
				'event_id'   => $id,
			)
		);
	}

	public function test_dispatch_fires_every_registered_handler(): void {
		$called = array();
		SideEffectDispatcher::register( 'a', function ( $event, $points ) use ( &$called ) {
			$called[] = 'a';
		} );
		SideEffectDispatcher::register( 'b', function ( $event, $points ) use ( &$called ) {
			$called[] = 'b';
		} );

		// No failures expected — $wpdb->insert must NOT be called.
		$this->wpdb_mock->shouldNotReceive( 'insert' );

		SideEffectDispatcher::dispatch( $this->make_event(), 10 );

		$this->assertSame( array( 'a', 'b' ), $called );
	}

	public function test_dispatch_throwing_handler_records_failure_and_continues(): void {
		$called = array();
		SideEffectDispatcher::register( 'good_before', function () use ( &$called ) {
			$called[] = 'good_before';
		} );
		SideEffectDispatcher::register( 'bad', function () {
			throw new \RuntimeException( 'oops' );
		} );
		SideEffectDispatcher::register( 'good_after', function () use ( &$called ) {
			$called[] = 'good_after';
		} );

		$captured = null;
		$this->wpdb_mock->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_wb_gam_side_effect_failures',
				Mockery::on( function ( $row ) use ( &$captured ) {
					$captured = $row;
					return true;
				} ),
				Mockery::any()
			)
			->andReturn( 1 );

		// Log::warning is called too — stub the static.
		Functions\when( 'wb_gam_log' )->justReturn( null );

		SideEffectDispatcher::dispatch( $this->make_event(), 25 );

		// Sibling handlers ran.
		$this->assertSame( array( 'good_before', 'good_after' ), $called );

		// Failure row is shaped right.
		$this->assertIsArray( $captured );
		$this->assertSame( 'bad', $captured['side_effect'] );
		$this->assertSame( 'evt-1', $captured['event_id'] );
		$this->assertSame( 42, $captured['user_id'] );
		$this->assertSame( 25, $captured['points'] );
		$this->assertSame( 0, $captured['retry_count'] );
		$this->assertSame( 'pending', $captured['status'] );
		$this->assertStringContainsString( 'oops', $captured['error_message'] );
	}

	public function test_reconcile_retries_pending_failure_and_deletes_on_success(): void {
		$call_count = 0;
		SideEffectDispatcher::register( 'flaky', function ( $event, $points ) use ( &$call_count ) {
			$call_count++;
			// Succeeds — no exception.
		} );

		$payload = wp_json_encode(
			array(
				'action_id'  => 'test_action',
				'user_id'    => 42,
				'object_id'  => 0,
				'metadata'   => array(),
				'created_at' => '2026-05-28 00:00:00',
				'event_id'   => 'evt-retry',
			)
		);

		$this->wpdb_mock->shouldReceive( 'prepare' )
			->andReturnUsing( function ( $sql, ...$args ) {
				return $sql; // crude but sufficient for the assertion below
			} );

		$this->wpdb_mock->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'id'            => 7,
						'event_id'      => 'evt-retry',
						'user_id'       => 42,
						'side_effect'   => 'flaky',
						'points'        => 10,
						'event_payload' => $payload,
						'retry_count'   => 1,
					),
				)
			);

		// Success → delete the row.
		$this->wpdb_mock->shouldReceive( 'delete' )
			->once()
			->with(
				'wp_wb_gam_side_effect_failures',
				array( 'id' => 7 ),
				array( '%d' )
			)
			->andReturn( 1 );

		SideEffectDispatcher::reconcile();

		$this->assertSame( 1, $call_count );
	}

	public function test_reconcile_flips_to_exhausted_after_max_retries(): void {
		SideEffectDispatcher::register( 'always_fails', function () {
			throw new \RuntimeException( 'still broken' );
		} );

		$payload = wp_json_encode(
			array(
				'action_id'  => 'test_action',
				'user_id'    => 42,
				'object_id'  => 0,
				'metadata'   => array(),
				'created_at' => '2026-05-28 00:00:00',
				'event_id'   => 'evt-doomed',
			)
		);

		$this->wpdb_mock->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql, ...$_ ) => $sql );

		// retry_count=2 means this attempt makes it 3 = MAX_RETRIES → 'exhausted'.
		$this->wpdb_mock->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'            => 9,
					'event_id'      => 'evt-doomed',
					'user_id'       => 42,
					'side_effect'   => 'always_fails',
					'points'        => 5,
					'event_payload' => $payload,
					'retry_count'   => 2,
				),
			)
		);

		$update_captured = null;
		$this->wpdb_mock->shouldReceive( 'update' )
			->once()
			->with(
				'wp_wb_gam_side_effect_failures',
				Mockery::on( function ( $row ) use ( &$update_captured ) {
					$update_captured = $row;
					return true;
				} ),
				array( 'id' => 9 ),
				Mockery::any(),
				array( '%d' )
			)
			->andReturn( 1 );

		Functions\when( 'wb_gam_log' )->justReturn( null );

		SideEffectDispatcher::reconcile();

		$this->assertIsArray( $update_captured );
		$this->assertSame( 3, $update_captured['retry_count'] );
		$this->assertSame( 'exhausted', $update_captured['status'] );
	}

	public function test_reconcile_marks_exhausted_when_handler_unregistered(): void {
		// No handlers registered. The row references one — should be marked
		// exhausted immediately rather than looping forever.
		$this->wpdb_mock->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql, ...$_ ) => $sql );

		$this->wpdb_mock->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'            => 11,
					'event_id'      => 'evt-orphan',
					'user_id'       => 42,
					'side_effect'   => 'removed_engine',
					'points'        => 1,
					'event_payload' => '{}',
					'retry_count'   => 0,
				),
			)
		);

		$this->wpdb_mock->shouldReceive( 'update' )
			->once()
			->with(
				'wp_wb_gam_side_effect_failures',
				Mockery::on( function ( $row ) {
					return 'exhausted' === ( $row['status'] ?? '' )
						&& 'handler_unregistered' === ( $row['error_message'] ?? '' );
				} ),
				array( 'id' => 11 ),
				Mockery::any(),
				array( '%d' )
			)
			->andReturn( 1 );

		SideEffectDispatcher::reconcile();
	}
}
