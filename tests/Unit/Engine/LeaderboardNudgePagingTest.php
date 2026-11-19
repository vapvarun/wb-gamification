<?php
/**
 * The weekly nudge reaches every eligible member, a page at a time.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\LeaderboardNudge;

/**
 * Walks the page chain the way Action Scheduler would.
 */
#[CoversClass( LeaderboardNudge::class )]
class LeaderboardNudgePagingTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fake the ledger query: ids above the cursor, up to the limit, ascending.
	 *
	 * @param int $members Eligible members.
	 */
	private function fake_ledger( int $members ): void {
		$GLOBALS['wpdb'] = new class( $members ) {
			public string $prefix = 'wp_';
			public array $seen    = array();
			private array $args   = array();

			public function __construct( private int $members ) {}

			public function prepare( $sql, ...$args ) {
				$this->args = $args;
				return $sql;
			}

			public function get_col( $sql ) {
				list( $week, $cursor, $limit ) = $this->args;
				$this->seen[]                  = $week;
				$next                          = $cursor + 1;
				return $next > $this->members ? array() : array_map( 'strval', range( $next, min( $this->members, $cursor + $limit ) ) );
			}
		};
	}

	/**
	 * Run the chain to the end and return the members queued and the pages used.
	 *
	 * @return array{0: int[], 1: int}
	 */
	private function run_chain(): array {
		$queued = array();
		$next   = null;

		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args ) use ( &$queued ) {
				$queued[] = $args[0];
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $when, $hook, $args ) use ( &$next ) {
				$next = $args;
			}
		);

		$pages = 1;
		LeaderboardNudge::dispatch_page( 0, '2026-10-26 00:00:00' );
		while ( null !== $next ) {
			$args = $next;
			$next = null;
			++$pages;
			LeaderboardNudge::dispatch_page( $args['cursor'], $args['week_start'] );
		}

		return array( $queued, $pages );
	}

	public function test_every_member_is_queued_once_past_the_old_5000_cap(): void {
		$this->fake_ledger( 12000 );

		list( $queued, $pages ) = $this->run_chain();

		$this->assertCount( 12000, $queued );
		$this->assertCount( 12000, array_unique( $queued ) );
		$this->assertSame( 24 + 1, $pages, 'An exact multiple of the page size needs one more page to find the end.' );
	}

	public function test_a_short_page_ends_the_chain(): void {
		$this->fake_ledger( 300 );

		list( $queued, $pages ) = $this->run_chain();

		$this->assertCount( 300, $queued );
		$this->assertSame( 1, $pages );
	}

	public function test_every_page_covers_the_week_the_run_started_in(): void {
		$this->fake_ledger( 1200 );

		$this->run_chain();

		$this->assertSame( array( '2026-10-26 00:00:00' ), array_values( array_unique( $GLOBALS['wpdb']->seen ) ) );
	}
}
