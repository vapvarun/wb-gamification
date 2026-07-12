<?php
/**
 * Unit tests for the durable notification queue's read bounds.
 *
 * Locks the 1.6.4 invariant: a toast is an ephemeral, realtime surface, so a
 * member who returns to a large backlog sees the NEWEST few events once and is
 * immediately caught up - the backlog is never replayed to them.
 *
 * Before 1.6.4, read_pending_from_table() took the OLDEST 50 events past the
 * cursor and advanced the cursor only as far as what it showed. A member with
 * 30,197 pending events was served 50 stale "you've hit your daily limit"
 * toasts on every page load for their next ~600 page views (Basecamp
 * #10086171887). The fix reads newest-first, caps the burst, and parks the
 * cursor at the head of the backlog so the remainder is dropped, not queued.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\NotificationBridge;

/**
 * @coversDefaultClass \WBGam\Engine\NotificationBridge
 */
class NotificationQueueBoundsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The SQL the bridge sent, so the test can assert on its shape.
	 *
	 * @var string
	 */
	private string $captured_sql = '';

	/**
	 * Cursor value the bridge wrote back to user_meta.
	 *
	 * @var int
	 */
	private int $written_cursor = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Route reads to the durable table (the primary path since v2.2b).
		Functions\when( 'get_option' )->justReturn( '1' );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'get_user_meta' )->justReturn( 0 ); // Cursor starts at 0.

		$test = $this;
		Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value ) use ( $test ) {
				$test->set_written_cursor( (int) $value );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function set_written_cursor( int $value ): void {
		$this->written_cursor = $value;
	}

	public function set_captured_sql( string $sql ): void {
		$this->captured_sql = $sql;
	}

	/**
	 * Stand in for $wpdb, returning the newest-N slice of a simulated backlog.
	 *
	 * Mirrors MySQL semantics for the bridge's query: ORDER BY id DESC + LIMIT
	 * yields the highest ids first, so row 0 is the head of the WHOLE backlog.
	 *
	 * @param int $backlog_size Total pending rows for the member.
	 */
	private function stub_wpdb( int $backlog_size ): void {
		$test = $this;

		$wpdb         = new class( $test, $backlog_size ) {
			public $prefix = 'wp_';
			private $test;
			private $backlog_size;

			public function __construct( $test, $backlog_size ) {
				$this->test         = $test;
				$this->backlog_size = $backlog_size;
			}

			public function prepare( $sql, ...$args ) {
				$this->test->set_captured_sql( $sql );
				// Resolve the LIMIT placeholder so get_results can honor it.
				$this->limit = (int) end( $args );
				return $sql;
			}

			public $limit = 0;

			public function get_results( $sql, $output = null ) {
				// Newest-first, exactly as ORDER BY id DESC LIMIT n returns.
				$rows = array();
				for ( $i = 0; $i < min( $this->limit, $this->backlog_size ); $i++ ) {
					$id     = $this->backlog_size - $i;
					$rows[] = array(
						'id'           => (string) $id,
						'event_type'   => 'points',
						'payload_json' => wp_json_encode( array( 'type' => 'points', 'message' => "event {$id}" ) ),
					);
				}
				return $rows;
			}
		};

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * A 30,197-row backlog yields at most a 5-toast burst - not 50.
	 *
	 * @test
	 * @covers ::read_pending
	 */
	public function large_backlog_is_capped_to_a_small_burst(): void {
		$this->stub_wpdb( 30197 );

		$events = NotificationBridge::read_pending( 7, 'footer' );

		$this->assertLessThanOrEqual( 5, count( $events ), 'A backlog must never render more than a 5-toast burst.' );
		$this->assertCount( 5, $events );
	}

	/**
	 * The events shown are the NEWEST in the backlog, and are ordered
	 * oldest-first so the stack reads chronologically.
	 *
	 * @test
	 * @covers ::read_pending
	 */
	public function burst_contains_the_newest_events_in_chronological_order(): void {
		$this->stub_wpdb( 30197 );

		$events = NotificationBridge::read_pending( 7, 'footer' );
		$ids    = array_map( static fn( $e ) => (int) $e['_id'], $events );

		$this->assertSame( array( 30193, 30194, 30195, 30196, 30197 ), $ids, 'Must show the newest 5, ascending.' );
	}

	/**
	 * The cursor jumps to the HEAD of the backlog, not merely to the newest
	 * event shown. This is what drops the un-shown remainder instead of
	 * queueing it for the next page load.
	 *
	 * @test
	 * @covers ::read_pending
	 */
	public function cursor_fast_forwards_past_the_entire_backlog(): void {
		$this->stub_wpdb( 30197 );

		NotificationBridge::read_pending( 7, 'footer' );

		$this->assertSame( 30197, $this->written_cursor, 'Cursor must park at the head so the backlog is skipped, not replayed.' );
	}

	/**
	 * The read must be newest-first. An ASC read is the exact shape of the
	 * pre-1.6.4 bug (serve the oldest, replay forever), so assert against it.
	 *
	 * @test
	 * @covers ::read_pending
	 */
	public function query_reads_newest_first(): void {
		$this->stub_wpdb( 30197 );

		NotificationBridge::read_pending( 7, 'footer' );

		$this->assertStringContainsString( 'ORDER BY id DESC', $this->captured_sql );
		$this->assertStringNotContainsString( 'ORDER BY id ASC', $this->captured_sql );
	}

	/**
	 * A small queue needs no special case: the newest-5-of-3 is all 3, and the
	 * cursor lands exactly where it would have anyway.
	 *
	 * @test
	 * @covers ::read_pending
	 */
	public function small_queue_is_delivered_whole(): void {
		$this->stub_wpdb( 3 );

		$events = NotificationBridge::read_pending( 7, 'footer' );
		$ids    = array_map( static fn( $e ) => (int) $e['_id'], $events );

		$this->assertSame( array( 1, 2, 3 ), $ids, 'Every event in an under-cap queue is still delivered.' );
		$this->assertSame( 3, $this->written_cursor );
	}
}
