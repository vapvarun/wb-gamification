<?php
/**
 * One delivery position per member, shared by every toast reader.
 *
 * Each reader (page seed, heartbeat, REST) used to keep its own position, so a
 * toast showed once per reader: the heartbeat painted it on one page and the
 * page seed painted it again on the next.
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
			public function prepare( $sql, ...$args ) {
				$this->after = (int) ( $args[1] ?? 0 );
				return $sql;
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
	public function what_one_reader_delivered_no_other_reader_delivers_again(): void {
		$this->assertCount( 1, NotificationBridge::read_pending( 7, 'heartbeat' ), 'Ten waiting arrive as one summary.' );
		$this->assertSame( array(), NotificationBridge::read_pending( 7, 'footer' ), 'The page seed must not repeat what the heartbeat showed.' );
		$this->assertSame( array(), NotificationBridge::read_pending( 7 ) );
		$this->assertSame( 10, NotificationBridge::cursor( 7 ) );
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
	public function a_new_members_burst_is_one_welcome_summary(): void {
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

		$this->assertCount( 2, $events, 'The level-up card passes through; the six toasts become one.' );
		$this->assertSame( 'level_up', $events[0]['type'] );
		$summary = $events[1];
		$this->assertSame( 'summary', $summary['type'] );
		$this->assertSame( 'Welcome - you earned 55 Points', $summary['message'] );
		$this->assertSame( 'Badge earned: Welcome Aboard', $summary['detail'] );
		$this->assertSame( '/hub/', $summary['url'], 'The See my progress link survives.' );
		$this->assertSame( 105, $summary['_id'] );
	}

	#[Test]
	public function three_or_fewer_toasts_arrive_one_by_one(): void {
		$this->meta = array( 'wb_gam_notif_cursor_member' => 7 );
		$events     = NotificationBridge::read_pending( 7 );
		$this->assertSame( array( 8, 9, 10 ), array_column( $events, '_id' ) );
	}
}
