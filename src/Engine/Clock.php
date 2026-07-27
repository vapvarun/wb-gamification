<?php
/**
 * The site clock.
 *
 * Nearly every datetime column in this plugin is written with `current_time( 'mysql' )` -- the
 * SITE's wall clock, not UTC. wb_gam_points.created_at, wb_gam_kudos.created_at,
 * wb_gam_user_badges.earned_at, wb_gam_streaks: all site-local.
 *
 * And nearly every "last N days" window in the plugin bounded those columns with
 * `gmdate( 'Y-m-d H:i:s', strtotime( "-7 days" ) )` -- which is UTC. Twenty-odd call sites, the same
 * mistake in each: a UTC bound against a site-local column, so every window was wrong by the site's
 * offset. Seven hours short in Los Angeles. On the analytics dashboard that meant a day holding 777
 * points rendered as an empty column, and the chart and the stat tiles disagreed with each other --
 * on any site not running UTC, which is most of them, and never on the developer's box, which is why
 * it survived so long.
 *
 * It is not a hard fix. It is a fix that has to be made in one place, or it will be made in nineteen
 * places and missed in the twentieth. So: one function, one contract.
 *
 *     $since = Clock::site_cutoff( '-7 days' );   // bound a site-local column
 *     $since = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );   // bound a UTC column
 *
 * If you find yourself writing the second form, be sure the column really is UTC (expires_at,
 * last_attempt_at, and the notifications queue are; almost nothing else is) and annotate it
 * `@clock-ok` with the reason. bin/check-clock-contract.sh enforces exactly this.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Site-clock helpers for bounding site-local datetime columns.
 *
 * @package WB_Gamification
 */
final class Clock {

	/**
	 * A MySQL datetime, in the SITE's clock, offset by a relative modifier.
	 *
	 * Use this for any bound compared against a column written with `current_time( 'mysql' )`.
	 *
	 * How it works, since the combination looks odd at first glance: `current_time( 'timestamp' )`
	 * returns the site's wall-clock time expressed as a Unix timestamp -- the "local read as UTC"
	 * frame. `gmdate()` formats a timestamp without applying any further offset. So formatting the
	 * former with the latter yields the site's wall clock as a string, which is precisely what
	 * `current_time( 'mysql' )` writes into the column. Both sides of the comparison then agree.
	 *
	 * Using `date()` here instead would apply PHP's timezone (UTC under WordPress) a second time and
	 * put the offset back in, which is the bug wearing a different hat.
	 *
	 * @param string $modifier A strtotime() relative modifier, e.g. '-7 days', '-1 month', 'monday this week'.
	 * @return string MySQL datetime (Y-m-d H:i:s) in the site's timezone.
	 */
	public static function site_cutoff( string $modifier ): string {
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- deliberate: this IS the local-as-UTC frame the columns are written in.
		$now = (int) current_time( 'timestamp' );

		$ts = strtotime( $modifier, $now );
		if ( false === $ts ) {
			// An unparseable modifier is a programming error, not a runtime condition. Falling back to
			// "now" keeps the query valid and bounded rather than handing MySQL an empty string.
			$ts = $now;
		}

		// @clock-ok: gmdate() applied to current_time('timestamp') is the SITE wall clock, by design.
		// This is the one place in the plugin allowed to build a bound this way; see the class docblock.
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * The START OF THE DAY, in the site's clock, offset by a relative modifier.
	 *
	 * For windows that mean "since midnight N days ago" rather than "since this time N days ago" --
	 * `gmdate( 'Y-m-d', strtotime( '-7 days' ) ) . ' 00:00:00'`, of which there were several.
	 *
	 * @param string $modifier A strtotime() relative modifier, e.g. '-7 days'.
	 * @return string MySQL datetime at 00:00:00 site-local.
	 */
	public static function site_day_start( string $modifier ): string {
		return substr( self::site_cutoff( $modifier ), 0, 10 ) . ' 00:00:00';
	}

	/**
	 * The current ISO week key (Y-W) in the SITE's clock.
	 *
	 * The cohort tables key their rows by week, and the week a member is filed under has to be the
	 * same week the query windows for. Building the KEY with gmdate( 'Y-W' ) while the WINDOW starts
	 * at the site's Monday means that near a week boundary a member is written into one week and read
	 * out of another.
	 *
	 * @return string
	 */
	public static function site_week(): string {
		// @clock-ok: same construction as site_cutoff(); this is the site wall clock by design.
		return gmdate( 'Y-W', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- deliberate.
	}

	/**
	 * Today's date in the SITE's clock (Y-m-d).
	 *
	 * @return string
	 */
	public static function site_date(): string {
		// @clock-ok: same construction, same reason as site_cutoff().
		return gmdate( 'Y-m-d', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- deliberate.
	}
}
