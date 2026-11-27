<?php
/**
 * One delivery position per member, shared by every toast reader, moved only
 * when a page acknowledges it displayed the toasts.
 *
 * Each reader (page seed, heartbeat, REST) used to keep its own position, so a
 * toast showed once per reader. Then the shared position moved on READ, and a
 * reload right after an award (BuddyNext reloads after a post) lost the toasts:
 * the old page read them and was destroyed before painting. Reading no longer
 * delivers; acknowledging does.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\NotificationBridge;

#[CoversMethod( NotificationBridge::class, 'read_pending' )]
#[CoversMethod( NotificationBridge::class, 'cursor' )]
#[CoversMethod( NotificationBridge::class, 'advance_cursor' )]
#[CoversMethod( NotificationBridge::class, 'acknowledge' )]
class SharedDeliveryCursorTest extends TestCase {

	/**
	 * Simulated user meta for member 7.
	 *
	 * @var array<string, mixed>
	 */
	public array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$test = $this;
		Functions\when( 'get_option' )->justReturn( '1' );
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => 1 === (int) $n ? $s : $p );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_sprintf' )->alias( static fn( $f, $list ) => implode( ' and ', (array) $list ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/hub/' );
		Functions\when( 'wp_make_link_relative' )->alias( static fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_user_meta' )->alias( static fn( $uid, $key, $single = false ) => $test->meta[ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( $test ) {
				$test->meta[ $key ] = $value;
				return true;
			}
		);
		// A queue of ids 1..10; fetch_unseen returns the newest 5 after the cursor, oldest first.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			private int $after   = 0;
			private int $row     = 0;
			public function prepare( $sql, ...$args ) {
				if ( str_contains( $sql, 'AND user_id' ) ) {
					$this->row = (int) ( $args[0] ?? 0 ); // Primary-key lookup of one row of this member.
				} else {
					$this->after = (int) ( $args[1] ?? 0 );
				}
				return $sql;
			}
			public function get_var( $sql ) {
				if ( str_contains( $sql, 'MAX(id)' ) ) {
					return 10; // The member's newest queue row.
				}
				// Member 7 owns rows 1..10 except 5, which is another member's (queue ids are global).
				return ( $this->row >= 1 && $this->row <= 10 && 5 !== $this->row ) ? $this->row : null;
			}
			public function get_results( $sql, $output = null ) {
				$rows = array();
				for ( $id = 10; $id > $this->after && count( $rows ) < 5; $id-- ) {
					$rows[] = array( 'id' => $id, 'event_type' => 'points', 'payload_json' => '{"type":"points"}' );
				}
				return $rows;
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function reading_never_moves_the_position_so_a_vanished_page_loses_nothing(): void {
		$first  = NotificationBridge::read_pending( 7, 'heartbeat' );
		$second = NotificationBridge::read_pending( 7, 'footer' );

		$this->assertNotEmpty( $first );
		$this->assertSame( array_column( $first, '_id' ), array_column( $second, '_id' ), 'Two reads before an acknowledgement return the same rows (a reload re-delivers; two tabs each show them once).' );
		$this->assertSame( 0, NotificationBridge::cursor( 7 ), 'No reader moves the cursor.' );
		$this->assertArrayNotHasKey( 'wb_gam_notif_cursor_member', $this->meta );
	}

	#[Test]
	public function every_payload_carries_its_row_id_and_the_window_head_to_acknowledge(): void {
		$events = NotificationBridge::read_pending( 7 );
		$this->assertSame( array( 6, 7, 8, 9, 10 ), array_column( $events, '_id' ) );
		$this->assertSame( array( 10, 10, 10, 10, 10 ), array_column( $events, '_ack' ) );
	}

	#[Test]
	public function an_acknowledgement_moves_the_position_and_drops_what_it_covered(): void {
		$this->assertTrue( NotificationBridge::acknowledge( 7, 10 ) );
		$this->assertSame( 10, NotificationBridge::cursor( 7 ) );
		$this->assertSame( array(), NotificationBridge::read_pending( 7 ), 'Delivered once: nothing left for the next page.' );
	}

	#[Test]
	public function an_acknowledgement_only_moves_forward(): void {
		NotificationBridge::acknowledge( 7, 9 );
		$this->assertTrue( NotificationBridge::acknowledge( 7, 4 ), 'A late, lower acknowledgement is harmless.' );
		$this->assertSame( 9, NotificationBridge::cursor( 7 ) );
	}

	#[Test]
	public function an_acknowledgement_past_the_members_newest_row_is_refused(): void {
		$this->assertFalse( NotificationBridge::acknowledge( 7, 11 ), 'A future (or another member\'s) id cannot skip toasts the page never received.' );
		$this->assertFalse( NotificationBridge::acknowledge( 7, 0 ) );
		$this->assertFalse( NotificationBridge::acknowledge( 0, 5 ) );
		$this->assertSame( 0, NotificationBridge::cursor( 7 ) );
	}

	#[Test]
	public function an_acknowledgement_of_another_members_row_is_refused_and_the_position_stays(): void {
		$this->assertFalse( NotificationBridge::acknowledge( 7, 5 ), 'Row 5 is below the caller\'s newest row (10) but belongs to someone else: it must not move the caller\'s position past rows 1-4 and 6-10.' );
		$this->assertSame( 0, NotificationBridge::cursor( 7 ) );
		$this->assertSame( array( 6, 7, 8, 9, 10 ), array_column( NotificationBridge::read_pending( 7 ), '_id' ), 'The caller\'s unseen rows are still delivered.' );
	}

	#[Test]
	public function a_truncated_backlog_is_dropped_only_once_acknowledged(): void {
		// 10 rows, window 5: the page shows the newest 5 as a catch-up and the older 5 are never shown.
		$events = NotificationBridge::read_pending( 7 );
		$this->assertSame( 0, NotificationBridge::cursor( 7 ), 'Unacknowledged: the backlog is still there to re-deliver.' );
		NotificationBridge::acknowledge( 7, (int) $events[0]['_ack'] );
		$this->assertSame( 10, NotificationBridge::cursor( 7 ) );
		$this->assertSame( array(), NotificationBridge::read_pending( 7 ) );
	}

	#[Test]
	public function the_shared_position_starts_where_the_furthest_old_reader_stood(): void {
		$this->meta = array(
			'wb_gam_notif_cursor_footer'    => 6,
			'wb_gam_notif_cursor_heartbeat' => 8,
		);
		$this->assertSame( 8, NotificationBridge::cursor( 7 ), 'Updating replays nothing.' );
		$this->assertCount( 2, NotificationBridge::read_pending( 7 ) );
	}

	#[Test]
	public function the_position_never_moves_back(): void {
		NotificationBridge::advance_cursor( 7, 9 );
		NotificationBridge::advance_cursor( 7, 4 );
		$this->assertSame( 9, NotificationBridge::cursor( 7 ) );
	}

	#[Test]
	public function a_new_members_burst_arrives_whole_for_the_page_to_fold(): void {
		$payloads = array(
			array( 'type' => 'badge', 'message' => 'Badge earned: Welcome Aboard' ),
			array( 'type' => 'welcome', 'message' => 'Welcome - you just earned your first points!' ),
			array( 'type' => 'points', 'points' => 15, 'unit_many' => 'Points' ),
			array( 'type' => 'points', 'points' => 10, 'unit_many' => 'Points' ),
			array( 'type' => 'points', 'points' => 10, 'unit_many' => 'Points' ),
			array( 'type' => 'points', 'points' => 20, 'unit_many' => 'Points' ),
			array( 'type' => 'level_up', 'message' => 'Level up!' ),
		);
		$GLOBALS['wpdb'] = new class( $payloads ) {
			public string $prefix = 'wp_';
			public function __construct( private array $payloads ) {}
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
			public function get_results( $sql, $output = null ) {
				$rows = array();
				foreach ( array_reverse( $this->payloads, true ) as $i => $p ) {
					$rows[] = array( 'id' => 100 + $i, 'event_type' => $p['type'], 'payload_json' => json_encode( $p ) );
				}
				return $rows;
			}
		};

		$events = NotificationBridge::read_pending( 7 );

		// Since 1.7.0 the server folds nothing under the read window: assets/js/toast.js turns the six
		// rewards into one summary toast after the level-up (tests/js/toast-summary.test.js). Folding
		// here too made a second summary toast.
		$this->assertCount( 7, $events );
		$this->assertContains( 'level_up', array_column( $events, 'type' ), 'The level-up card still arrives.' );
		$this->assertNotContains( 'summary', array_column( $events, 'type' ) );
		$this->assertSame( 55, array_sum( array_column( $events, 'points' ) ), 'Every award arrives, so the page can add them all up.' );
	}

	#[Test]
	public function three_or_fewer_toasts_arrive_one_by_one(): void {
		$this->meta = array( 'wb_gam_notif_cursor_member' => 7 );
		$events     = NotificationBridge::read_pending( 7 );
		$this->assertSame( array( 8, 9, 10 ), array_column( $events, '_id' ) );
	}
}
