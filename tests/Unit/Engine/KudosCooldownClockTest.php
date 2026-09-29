<?php
/**
 * The kudos clock guards, exercised through KudosEngine itself (UTC storage, 1.6.5).
 *
 * `wb_gam_kudos.created_at` is stored in UTC. The daily limit counts from the SITE's midnight
 * (the member's "today"), converted to its UTC instant; the per-receiver cooldown is "now minus N
 * seconds" in UTC. Before 1.6.5 both columns and bounds were site-local; mixing the two frames made
 * the guards silently never fire across a whole hemisphere, so these tests pin a moment where the
 * site day and the UTC day disagree and assert the SQL bound the engine really binds.
 *
 * current_time() is stubbed with Brain Monkey (never a namespaced shadow function, which hijacks
 * every class in the namespace) and $wpdb is a fake that captures the bound arguments.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\KudosEngine;

/**
 * The kudos clock guards, exercised through KudosEngine itself.
 */
class KudosCooldownClockTest extends TestCase {

	/**
	 * UTC "now" the stubbed current_time() is anchored to.
	 *
	 * @var int
	 */
	public static $fake_utc_now = 0;

	/**
	 * The args the engine bound into its last query.
	 *
	 * @var array<int, mixed>
	 */
	public static $captured = array();

	/**
	 * Anchor the clock in the band where the site day and the UTC day disagree.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// 2026-07-14 06:30 UTC is 2026-07-13 23:30 in Los Angeles: still YESTERDAY for the member.
		self::$fake_utc_now = strtotime( '2026-07-14 06:30:00 UTC' );
		self::$captured     = array();

		Functions\when( 'current_time' )->alias(
			static function ( $type, $gmt = 0 ) {
				$now = $gmt ? self::$fake_utc_now : self::$fake_utc_now - 7 * 3600;
				return 'timestamp' === $type ? $now : gmdate( 'Y-m-d H:i:s', $now );
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Los_Angeles' ) );

		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The daily window starts at the member's midnight, as a UTC instant.
	 *
	 * Mutation-checked: bind UTC midnight ('2026-07-14 00:00:00', the member's future) or the old
	 * site-local string ('2026-07-13 00:00:00', seven hours early against a UTC column) and this fails.
	 */
	public function test_the_daily_limit_boundary_is_the_sites_midnight_in_utc(): void {
		KudosEngine::get_daily_sent_count( 123 );

		$this->assertSame(
			'2026-07-13 07:00:00',
			$this->last_datetime_bound(),
			"The daily window must start at the SITE's midnight (00:00 in Los Angeles), expressed in UTC like the column."
		);
	}

	/**
	 * The cooldown window is "now minus the cooldown", in UTC.
	 */
	public function test_the_cooldown_boundary_is_utc_now_minus_the_cooldown(): void {
		KudosEngine::has_recent_kudos_to_receiver( 123, 456, 3600 );

		$this->assertSame(
			'2026-07-14 05:30:00',
			$this->last_datetime_bound(),
			'The cooldown window must be measured in UTC, the clock created_at is written in.'
		);
	}

	/**
	 * The engine must have bound SOMETHING date-shaped -- otherwise the assertions above are vacuous.
	 */
	public function test_the_engine_actually_binds_a_boundary(): void {
		KudosEngine::get_daily_sent_count( 123 );

		$this->assertNotSame( '', $this->last_datetime_bound(), 'KudosEngine bound no datetime at all.' );
	}

	/**
	 * The last datetime-shaped argument the engine bound.
	 *
	 * @return string
	 */
	private function last_datetime_bound(): string {
		foreach ( array_reverse( self::$captured ) as $arg ) {
			if ( is_string( $arg ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $arg ) ) {
				return $arg;
			}
		}
		return '';
	}
}

/**
 * A $wpdb that records what the engine binds and answers nothing.
 */
class FakeWpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Capture the bound args.
	 *
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Bound values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		KudosCooldownClockTest::$captured = $args;
		return $query;
	}

	/**
	 * Answer nothing; this test is about the bound, not the count.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function get_var( $query ) {
		unset( $query );
		return 0;
	}
}
