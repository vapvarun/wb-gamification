<?php
/**
 * The kudos clock guards must run against KudosEngine, not against a copy of its arithmetic.
 *
 * `wb_gam_kudos.created_at` is written with current_time( 'mysql' ) -- the SITE's local time. The
 * daily-limit boundary and the per-receiver cooldown were both computed with gmdate() -- UTC. On any
 * site behind UTC a kudos sent one second ago is stamped hours BEFORE the UTC boundary, the COUNT(*)
 * comes back 0, and neither guard fires at all: the spam protection was simply absent across a whole
 * hemisphere.
 *
 * The first version of this test re-implemented that boundary arithmetic as private helpers and
 * asserted against its own copy. It never mentioned KudosEngine -- `grep -c KudosEngine` returned
 * zero -- so both production bugs could be reintroduced and it stayed green. It could not fail. A
 * test that restates the rule instead of exercising the code is a comment with a green tick on it.
 *
 * This version calls the real method. `current_time()` is stubbed with Brain Monkey (the idiom the
 * rest of the suite uses) and $wpdb is a fake that captures what the engine actually binds -- so the
 * assertion is about the SQL the plugin would really run.
 *
 * It is stubbed with Brain Monkey and NOT by declaring a WBGam\Engine\current_time() function, which
 * was the first thing I tried: a namespaced function shadows the global for EVERY class in that
 * namespace, so it silently hijacked current_time() in two unrelated PointsEngine tests and broke
 * them. A test helper that reaches outside its own test is not a helper.
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
	 * UTC "now" that the stubbed current_time() is anchored to.
	 *
	 * @var int
	 */
	public static $fake_utc_now = 0;

	/**
	 * Site offset in seconds (negative = behind UTC).
	 *
	 * @var int
	 */
	public static $fake_offset = 0;

	/**
	 * The args the engine bound into its last query.
	 *
	 * @var array<int, mixed>
	 */
	public static $captured = array();

	/**
	 * Anchor the clock in the band where the two boundaries disagree.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// 2026-07-14 06:30 UTC. In Los Angeles (-7) that is 2026-07-13 23:30 -- still YESTERDAY
		// locally. This is exactly the band where a UTC day boundary sits in the member's future, and
		// where the shipped bug let every kudos through.
		self::$fake_utc_now = strtotime( '2026-07-14 06:30:00 UTC' );
		self::$fake_offset  = -7 * 3600;
		self::$captured     = array();

		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				$now = self::$fake_utc_now + self::$fake_offset;
				return 'timestamp' === $type ? $now : gmdate( 'Y-m-d H:i:s', $now );
			}
		);

		$GLOBALS['wpdb'] = new FakeWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The daily-limit window must start at the SITE's midnight, not UTC's.
	 *
	 * Mutation-checked: put `gmdate( 'Y-m-d' )` back into KudosEngine::get_daily_sent_count() and this
	 * fails -- the bound becomes 2026-07-14 00:00:00 (UTC's midnight, which is in the member's future)
	 * instead of 2026-07-13 00:00:00.
	 */
	public function test_the_daily_limit_boundary_is_the_sites_midnight(): void {
		KudosEngine::get_daily_sent_count( 123 );

		$this->assertSame(
			'2026-07-13 00:00:00',
			$this->last_datetime_bound(),
			"The daily-limit window must start at the SITE's midnight. A UTC boundary is in the "
				. 'members future on any site behind UTC, so every kudos sent today falls before it, '
				. 'COUNT(*) returns 0, and the limit never fires at all.'
		);
	}

	/**
	 * The bound must be a value the column could actually contain.
	 *
	 * created_at is written with current_time( 'mysql' ), so the boundary has to be in that frame.
	 * Comparing it against the real UTC clock IS the bug, stated as an assertion.
	 */
	public function test_the_boundary_is_in_the_same_clock_the_column_is_written_in(): void {
		KudosEngine::get_daily_sent_count( 123 );

		$bound    = $this->last_datetime_bound();
		$site_now = current_time( 'mysql' );
		$utc_now  = gmdate( 'Y-m-d H:i:s', self::$fake_utc_now );

		$this->assertLessThanOrEqual( $site_now, $bound, 'The window cannot start in the future.' );
		$this->assertNotSame(
			substr( $utc_now, 0, 10 ) . ' 00:00:00',
			$bound,
			'The bound is UTC midnight -- the exact defect this guard exists to catch.'
		);
	}

	/**
	 * The COOLDOWN boundary must also be the site's clock -- and it is not the daily limit.
	 *
	 * The class is named KudosCooldownClockTest, and until now it contained no cooldown test at all:
	 * it asserted the daily-limit boundary and stopped. Reintroduce the original cooldown bug alone
	 * and the suite stayed green, which is the same "a check that cannot fail" the daily-limit half
	 * was already caught for. Two bugs were fixed; only one was guarded.
	 *
	 * has_recent_kudos_to_receiver() bounds `created_at >= now - cooldown`. created_at is site-local,
	 * so the boundary must be too. With gmdate() it sat hours in the member's future on any site
	 * behind UTC: the COUNT came back 0, the guard concluded no recent kudos existed, and the
	 * per-receiver cooldown never fired across an entire hemisphere.
	 */
	public function test_the_cooldown_boundary_is_also_the_sites_clock(): void {
		$cooldown = 3600;

		KudosEngine::has_recent_kudos_to_receiver( 123, 456, $cooldown );

		$bound = $this->last_datetime_bound();

		// Site-local now, minus the cooldown. Site is UTC-7, so 06:30 UTC is 23:30 the previous day.
		$expected = gmdate( 'Y-m-d H:i:s', ( self::$fake_utc_now + self::$fake_offset ) - $cooldown );

		$this->assertSame(
			$expected,
			$bound,
			'The cooldown window must be measured in the clock created_at is WRITTEN in. A UTC boundary '
				. 'is in the future on any site behind UTC, so a kudos sent one second ago falls before '
				. 'it, COUNT(*) returns 0, and the cooldown never fires at all.'
		);

		// And it must not be the UTC boundary -- the bug, stated directly.
		$this->assertNotSame(
			gmdate( 'Y-m-d H:i:s', self::$fake_utc_now - $cooldown ),
			$bound,
			'The bound is UTC-now minus the cooldown: the exact defect this guard exists to catch.'
		);
	}

	/**
	 * The engine must have bound SOMETHING date-shaped -- otherwise the assertions above are vacuous.
	 */
	public function test_the_engine_actually_binds_a_boundary(): void {
		KudosEngine::get_daily_sent_count( 123 );

		$this->assertNotSame(
			'',
			$this->last_datetime_bound(),
			'KudosEngine bound no datetime at all, so this test is asserting against nothing.'
		);
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
