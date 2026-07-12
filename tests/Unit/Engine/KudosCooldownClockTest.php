<?php
/**
 * The kudos cooldown must be measured in ONE clock.
 *
 * `wb_gam_kudos.created_at` is written with current_time( 'mysql' ) — the site's LOCAL
 * time. The cooldown window was computed with gmdate() — UTC. Two clocks, compared to
 * each other, and nobody noticed because the developer's site ran UTC.
 *
 * On any site BEHIND UTC — which is every site in the Americas — a kudos sent one second
 * ago is stamped hours *before* the UTC boundary. The COUNT(*) comes back 0, the guard
 * concludes no recent kudos exists, and the per-receiver cooldown never fires at all. No
 * concurrency needed: the spam protection was simply absent across a whole hemisphere.
 *
 * This is the same two-clock defect that silently emptied the leaderboard snapshot
 * (write stamped with current_time(), retention pruned with NOW()). Different subsystem,
 * identical root cause — which is why it gets a test that states the rule rather than a
 * one-line patch.
 *
 * @package WB_Gamification
 */

namespace WBGam\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Boundary arithmetic for the per-receiver kudos cooldown.
 */
class KudosCooldownClockTest extends TestCase {

	private const COOLDOWN = 3600; // One hour, the shipped default.

	/**
	 * The BROKEN boundary: window computed in UTC, compared against a local-time column.
	 *
	 * @param int $utc_now      Current UTC timestamp.
	 * @param int $site_offset  Site's UTC offset in seconds (negative = behind UTC).
	 * @return string
	 */
	private function boundary_utc( int $utc_now, int $site_offset ): string {
		unset( $site_offset ); // The bug: the site's offset is never considered.

		return gmdate( 'Y-m-d H:i:s', $utc_now - self::COOLDOWN );
	}

	/**
	 * The FIXED boundary: window computed in the same local clock the column is stored in.
	 *
	 * @param int $utc_now     Current UTC timestamp.
	 * @param int $site_offset Site's UTC offset in seconds.
	 * @return string
	 */
	private function boundary_local( int $utc_now, int $site_offset ): string {
		$local_now = $utc_now + $site_offset; // What current_time( 'mysql' ) yields.

		return gmdate( 'Y-m-d H:i:s', $local_now - self::COOLDOWN );
	}

	/**
	 * How a row is actually stamped: current_time( 'mysql' ), i.e. local.
	 *
	 * @param int $utc_now     UTC timestamp of the write.
	 * @param int $site_offset Site's UTC offset in seconds.
	 * @return string
	 */
	private function created_at( int $utc_now, int $site_offset ): string {
		return gmdate( 'Y-m-d H:i:s', $utc_now + $site_offset );
	}

	/**
	 * New York (UTC-5): the cooldown did not fire AT ALL.
	 *
	 * A kudos sent 60 seconds ago sits 5 hours "before" a UTC boundary, so the query that
	 * asks "any kudos since the boundary?" answers no, and the member can spam freely.
	 *
	 * @return void
	 */
	public function test_site_behind_utc_lost_the_cooldown_entirely(): void {
		$utc_now = 1752307200;   // Fixed instant; no wall-clock dependency.
		$offset  = -5 * 3600;    // America/New_York.

		$just_sent = $this->created_at( $utc_now - 60, $offset );

		$this->assertLessThan(
			$this->boundary_utc( $utc_now, $offset ),
			$just_sent,
			'THE BUG: a kudos sent a minute ago falls outside a UTC window, so the cooldown never fired on any US site.'
		);

		$this->assertGreaterThanOrEqual(
			$this->boundary_local( $utc_now, $offset ),
			$just_sent,
			'FIXED: measured in the clock the column is written in, the kudos is correctly inside the cooldown window.'
		);
	}

	/**
	 * Kolkata (UTC+5:30): the mirror-image failure — the cooldown over-fired.
	 *
	 * A kudos sent 90 minutes ago (past a 60-minute cooldown, so it should be allowed
	 * again) still looked "recent" against a UTC boundary, and the member was blocked.
	 *
	 * @return void
	 */
	public function test_site_ahead_of_utc_kept_blocking_after_the_cooldown_expired(): void {
		$utc_now = 1752307200;
		$offset  = 5 * 3600 + 1800; // Asia/Kolkata.

		$expired = $this->created_at( $utc_now - 5400, $offset ); // 90 min ago > 60 min cooldown.

		$this->assertGreaterThanOrEqual(
			$this->boundary_utc( $utc_now, $offset ),
			$expired,
			'THE BUG: an expired cooldown still looked recent against a UTC window, so the member stayed blocked.'
		);

		$this->assertLessThan(
			$this->boundary_local( $utc_now, $offset ),
			$expired,
			'FIXED: in one clock, a 90-minute-old kudos is correctly outside a 60-minute cooldown.'
		);
	}

	/**
	 * UTC itself: both forms agree. This is why the bug survived — the box it was written
	 * on could never reproduce it.
	 *
	 * @return void
	 */
	public function test_on_a_utc_site_both_clocks_agree(): void {
		$utc_now = 1752307200;

		$this->assertSame(
			$this->boundary_utc( $utc_now, 0 ),
			$this->boundary_local( $utc_now, 0 ),
			'At offset 0 the broken and fixed boundaries are identical — which is exactly why nobody caught this locally.'
		);
	}

	/**
	 * The rule, stated once: a fresh kudos is inside the window at EVERY offset.
	 *
	 * @return void
	 */
	public function test_fresh_kudos_is_inside_the_window_at_every_offset(): void {
		$utc_now = 1752307200;

		foreach ( array( -12 * 3600, -5 * 3600, 0, 5 * 3600 + 1800, 14 * 3600 ) as $offset ) {
			$this->assertGreaterThanOrEqual(
				$this->boundary_local( $utc_now, $offset ),
				$this->created_at( $utc_now - 60, $offset ),
				sprintf( 'A kudos sent a minute ago must be inside the cooldown window at UTC offset %d.', $offset )
			);
		}
	}
}
