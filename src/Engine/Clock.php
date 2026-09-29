<?php
/**
 * The plugin clock: every stored moment is UTC, every window is read in the site calendar.
 *
 * The WordPress model (post_date_gmt): a datetime column holds UTC, written from PHP with
 * `current_time( 'mysql', true )`, never SQL NOW() (the database server's zone varies by host).
 * What a person means by "today", "this week" or "the last 7 days" is the SITE's calendar
 * (Settings > General), so a window is worked out in the site zone and converted to the UTC instant
 * the column can be compared with. Pure calendar keys (a streak's last active DATE, a cohort week)
 * stay site-calendar strings.
 *
 *     $since = Clock::site_cutoff( '-7 days' );       // UTC bound: 7 days ago
 *     $today = Clock::site_day_start( 'today' );      // UTC instant of the site's midnight
 *     $day   = Clock::site_date( '-1 days' );         // site-calendar Y-m-d key
 *
 * Before 1.6.5 the plugin stored site-local wall-clock strings instead; DbUpgrader converts those
 * rows once (card 10344291999). bin/check-clock-contract.sh enforces this contract.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * UTC bounds for moment columns, resolved in the site calendar.
 *
 * @package WB_Gamification
 */
final class Clock {

	/**
	 * A UTC bound, offset by a relative modifier resolved in the SITE calendar.
	 *
	 * "-1 month" or "monday this week" mean the site's month and week, so they are applied to the
	 * site-zone "now" and the result is converted to UTC for comparison with a UTC column.
	 *
	 * @param string $modifier A strtotime() relative modifier, e.g. '-7 days', 'monday this week'.
	 * @return string MySQL datetime (Y-m-d H:i:s), UTC.
	 */
	public static function site_cutoff( string $modifier ): string {
		return self::utc( self::modify( $modifier ) );
	}

	/**
	 * The UTC instant of the site's midnight, offset by a relative modifier.
	 *
	 * "Since midnight 7 days ago" in the site calendar. Correct across DST: the day a clock change
	 * lands on is 23 or 25 hours long, and the next midnight is computed, not added.
	 *
	 * @param string $modifier A strtotime() relative modifier, e.g. 'today', '-7 days', 'tomorrow'.
	 * @return string MySQL datetime (Y-m-d H:i:s), UTC.
	 */
	public static function site_day_start( string $modifier = 'today' ): string {
		return self::utc( self::modify( $modifier )->setTime( 0, 0 ) );
	}

	/**
	 * A site-calendar date key (Y-m-d). Not a moment: compare it only with DATE columns and keys.
	 *
	 * @param string $modifier A strtotime() relative modifier, e.g. 'now', '-1 days'.
	 * @return string
	 */
	public static function site_date( string $modifier = 'now' ): string {
		return self::modify( $modifier )->format( 'Y-m-d' );
	}

	/**
	 * The current site-calendar week key (Y-W) the cohort tables file members under.
	 *
	 * @return string
	 */
	public static function site_week(): string {
		return self::site_now()->format( 'Y-W' );
	}

	/**
	 * SQL that shifts a UTC datetime column into site wall-clock time, for grouping by site day.
	 *
	 * Exact across DST: the offset is a CASE over the zone's transitions inside [$from, $to], so a
	 * point earned at 23:30 site time on a DST day lands on that day, not the next.
	 *
	 * @param string $column Column reference, e.g. 'created_at' or 'p.created_at'.
	 * @param int    $from   Earliest UTC timestamp the query covers.
	 * @param int    $to     Latest UTC timestamp the query covers.
	 * @return string SQL expression.
	 */
	public static function sql_utc_to_local( string $column, int $from, int $to ): string {
		return 'DATE_ADD(' . self::column( $column ) . ', INTERVAL ' . self::offset_case( $column, $from, $to, false ) . ' SECOND)';
	}

	/**
	 * SQL that converts a site wall-clock datetime column to UTC. Used once, by the storage migration.
	 *
	 * An ambiguous fall-back hour resolves to its first occurrence, as PHP does.
	 *
	 * @param string $column Column reference.
	 * @param int    $from   Earliest UTC timestamp the rows can hold.
	 * @param int    $to     Latest UTC timestamp the rows can hold.
	 * @return string SQL expression.
	 */
	public static function sql_local_to_utc( string $column, int $from, int $to ): string {
		return 'DATE_SUB(' . self::column( $column ) . ', INTERVAL ' . self::offset_case( $column, $from, $to, true ) . ' SECOND)';
	}

	/**
	 * Whether the site zone is UTC for the whole span (the storage migration then has nothing to do).
	 *
	 * @param int $from Span start, UTC timestamp.
	 * @param int $to   Span end, UTC timestamp.
	 * @return bool
	 */
	public static function is_utc_site( int $from, int $to ): bool {
		foreach ( self::offsets( $from, $to ) as $offset ) {
			if ( 0 !== $offset['offset'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The site-zone "now".
	 *
	 * @return \DateTimeImmutable
	 */
	private static function site_now(): \DateTimeImmutable {
		// A true UTC epoch (gmt = true), read through current_time() so tests can pin "now".
		return ( new \DateTimeImmutable( '@' . (int) current_time( 'timestamp', true ) ) )->setTimezone( wp_timezone() ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.RequestedUTC -- gmt epoch, stubbable in tests.
	}

	/**
	 * The site-zone "now" moved by a relative modifier; an unparseable modifier falls back to now.
	 *
	 * @param string $modifier Relative modifier.
	 * @return \DateTimeImmutable
	 */
	private static function modify( string $modifier ): \DateTimeImmutable {
		$now = self::site_now();
		try {
			$moved = $now->modify( $modifier );
		} catch ( \Exception $e ) { // PHP 8.3+ throws DateMalformedStringException.
			return $now;
		}
		return false === $moved ? $now : $moved;
	}

	/**
	 * Format a moment as a UTC MySQL datetime.
	 *
	 * @param \DateTimeImmutable $at Moment.
	 * @return string
	 */
	private static function utc( \DateTimeImmutable $at ): string {
		return $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Guard a column reference before it is concatenated into SQL.
	 *
	 * @param string $column Column reference.
	 * @return string
	 * @throws \InvalidArgumentException When the reference is not a plain column name.
	 */
	private static function column( string $column ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_.]+$/i', $column ) ) {
			throw new \InvalidArgumentException( 'Clock: invalid column reference.' );
		}
		return $column;
	}

	/**
	 * The zone's offsets over a span: [ [ 'ts' => int, 'offset' => int ], ... ], first entry at $from.
	 *
	 * @param int $from Span start, UTC timestamp.
	 * @param int $to   Span end, UTC timestamp.
	 * @return array<int, array{ts:int, offset:int}>
	 */
	private static function offsets( int $from, int $to ): array {
		$zone        = wp_timezone();
		$transitions = $zone->getTransitions( $from, max( $from, $to ) );
		if ( ! $transitions ) { // A fixed-offset zone such as "+05:30".
			return array(
				array(
					'ts'     => $from,
					'offset' => $zone->getOffset( new \DateTimeImmutable( '@' . $from ) ),
				),
			);
		}
		return array_map(
			static fn( array $t ): array => array(
				'ts'     => (int) $t['ts'],
				'offset' => (int) $t['offset'],
			),
			$transitions
		);
	}

	/**
	 * The offset (seconds) as SQL: a constant, or a CASE over the span's transition boundaries.
	 *
	 * @param string $column   Column reference (already guarded).
	 * @param int    $from     Span start.
	 * @param int    $to       Span end.
	 * @param bool   $is_local Whether the column holds site wall-clock values (boundaries shift).
	 * @return string
	 */
	private static function offset_case( string $column, int $from, int $to, bool $is_local ): string {
		$offsets = self::offsets( $from, $to );
		if ( 1 === count( $offsets ) ) {
			return (string) $offsets[0]['offset'];
		}
		$sql = 'CASE';
		for ( $i = 1, $n = count( $offsets ); $i < $n; $i++ ) {
			// A local column crosses a transition at the wall-clock time the change happens.
			$edge = $offsets[ $i ]['ts'] + ( $is_local ? $offsets[ $i - 1 ]['offset'] : 0 );
			$sql .= " WHEN {$column} < '" . gmdate( 'Y-m-d H:i:s', $edge ) . "' THEN " . $offsets[ $i - 1 ]['offset'];
		}
		return $sql . ' ELSE ' . $offsets[ $n - 1 ]['offset'] . ' END';
	}
}
