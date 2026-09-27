<?php
/**
 * Clock: UTC bounds resolved in the site calendar (1.6.5 UTC storage, card 10344291999).
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WBGam\Engine\Clock;

/**
 * @coversDefaultClass \WBGam\Engine\Clock
 */
class ClockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'current_time' )->alias( static fn( $type, $gmt = 0 ) => 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function zone( string $tz ): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( $tz ) );
	}

	/**
	 * @test
	 * @covers ::site_day_start
	 */
	public function site_midnight_is_returned_as_its_utc_instant(): void {
		$this->zone( 'America/Los_Angeles' );
		$this->assertSame( '2026-07-14 07:00:00', Clock::site_day_start( '2026-07-14' ), 'PDT midnight is 07:00 UTC.' );

		$this->zone( 'Asia/Kolkata' );
		$this->assertSame( '2026-07-13 18:30:00', Clock::site_day_start( '2026-07-14' ), 'IST midnight is 18:30 UTC the day before.' );

		$this->zone( 'UTC' );
		$this->assertSame( '2026-07-14 00:00:00', Clock::site_day_start( '2026-07-14' ) );
	}

	/**
	 * @test
	 * @covers ::site_day_start
	 */
	public function a_dst_day_is_23_hours_long(): void {
		$this->zone( 'America/Los_Angeles' );
		$start = strtotime( Clock::site_day_start( '2026-03-08' ) . ' UTC' );
		$end   = strtotime( Clock::site_day_start( '2026-03-09' ) . ' UTC' );
		$this->assertSame( 23 * 3600, $end - $start, 'Spring-forward day: the next midnight is computed, not added.' );
	}

	/**
	 * @test
	 * @covers ::site_date
	 * @covers ::site_week
	 */
	public function calendar_keys_stay_in_the_site_calendar(): void {
		$this->zone( 'Pacific/Auckland' );
		$this->assertSame( '2026-07-13', Clock::site_date( '2026-07-14 -1 days' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}$/', Clock::site_week() );
	}

	/**
	 * @test
	 * @covers ::site_cutoff
	 */
	public function an_unreadable_modifier_falls_back_to_now(): void {
		$this->zone( 'UTC' );
		$this->assertEqualsWithDelta( time(), strtotime( Clock::site_cutoff( 'not a date at all' ) . ' UTC' ), 5 );
	}

	/**
	 * @test
	 * @covers ::sql_utc_to_local
	 * @covers ::sql_local_to_utc
	 */
	public function sql_offsets_follow_dst_transitions(): void {
		$this->zone( 'America/Los_Angeles' );
		$from = strtotime( '2026-03-01 00:00:00 UTC' );
		$to   = strtotime( '2026-03-20 00:00:00 UTC' );

		$this->assertSame(
			"DATE_ADD(p.created_at, INTERVAL CASE WHEN p.created_at < '2026-03-08 10:00:00' THEN -28800 ELSE -25200 END SECOND)",
			Clock::sql_utc_to_local( 'p.created_at', $from, $to )
		);
		// A local column crosses the change at 02:00 wall clock: 10:00 UTC minus the old 8 hours.
		$this->assertSame(
			"DATE_SUB(created_at, INTERVAL CASE WHEN created_at < '2026-03-08 02:00:00' THEN -28800 ELSE -25200 END SECOND)",
			Clock::sql_local_to_utc( 'created_at', $from, $to )
		);

		$this->zone( '+05:30' );
		$this->assertSame( 'DATE_ADD(created_at, INTERVAL 19800 SECOND)', Clock::sql_utc_to_local( 'created_at', $from, $to ), 'A fixed offset needs no CASE.' );
	}

	/**
	 * @test
	 * @covers ::sql_utc_to_local
	 */
	public function a_column_reference_is_guarded(): void {
		$this->zone( 'UTC' );
		$this->expectException( \InvalidArgumentException::class );
		Clock::sql_utc_to_local( 'created_at); DROP TABLE x; --', 0, 1 );
	}

	/**
	 * @test
	 * @covers ::is_utc_site
	 */
	public function a_utc_site_is_recognised(): void {
		$this->zone( 'UTC' );
		$this->assertTrue( Clock::is_utc_site( time() - 86400 * 400, time() ) );
		$this->zone( 'Europe/London' );
		$this->assertFalse( Clock::is_utc_site( time() - 86400 * 400, time() ), 'London is UTC in winter but not in summer.' );
	}
}
