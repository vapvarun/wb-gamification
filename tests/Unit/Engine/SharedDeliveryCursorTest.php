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
		$this->assertCount( 5, NotificationBridge::read_pending( 7, 'heartbeat' ) );
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
}
