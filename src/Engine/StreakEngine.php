<?php
/**
 * WB Gamification Streak Engine
 *
 * Tracks activity streaks per member — timezone-aware, with a configurable
 * grace period so life disruptions don't destroy weeks of progress.
 *
 * Rules:
 *   - "Activity streak" not "login streak": a point award from any action
 *     counts. Engine::process() calls record_activity() after every award.
 *   - Timezone-aware: midnight is the member's local midnight, read from
 *     user meta `timezone_string` (same key WP uses for author timezone in
 *     the user profile). Falls back to site timezone then UTC.
 *   - Grace period (default 1 day, configurable 0–3):
 *       • last_active = yesterday  → extend streak, clear grace flag
 *       • last_active = N days ago (N ≤ grace_days) AND grace_used = 0
 *                                  → extend streak, set grace_used = 1
 *       • otherwise               → reset streak to 1, clear grace flag
 *   - Milestones: 7, 14, 30, 60, 100, 180, 365 days trigger:
 *       1. `wb_gamification_streak_milestone` action (for UI overlay)
 *       2. Bonus points via Engine::process() if option > 0
 *
 * Streak data stored in wb_gam_streaks (one row per user).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks daily activity streaks per member with timezone awareness and a configurable grace period.
 *
 * @package WB_Gamification
 */
final class StreakEngine {

	private const MILESTONES     = array( 7, 14, 30, 60, 100, 180, 365 );
	private const OPT_GRACE_DAYS = 'wb_gam_streak_grace_days';
	private const OPT_BONUS_PTS  = 'wb_gam_streak_milestone_bonus';
	private const CACHE_GROUP    = 'wb_gamification';
	private const CACHE_TTL      = 300; // 5 minutes

	// ── Engine hook ─────────────────────────────────────────────────────────────

	/**
	 * Record activity for a user and update their streak.
	 *
	 * Called by Engine::process() after every successful point award.
	 *
	 * @param int $user_id User whose activity to record.
	 */
	public static function record_activity( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$data  = self::get_row( $user_id );
		$tz    = self::get_timezone( $user_id, $data['timezone'] );
		$today = self::today( $tz );

		// Already recorded activity today — nothing to do.
		if ( $data['last_active'] === $today ) {
			return;
		}

		/**
		 * Filter the number of grace days before a streak breaks.
		 *
		 * @since 1.0.0
		 * @param int $grace_days Default grace days (from option, default 1).
		 * @param int $user_id    The user whose streak is being evaluated.
		 */
		$grace_days = (int) apply_filters(
			'wb_gamification_streak_grace_days',
			(int) get_option( self::OPT_GRACE_DAYS, 1 ),
			$user_id
		);
		$gap        = $data['last_active']
			? (int) self::date_diff_days( $data['last_active'], $today, $tz )
			: null;

		if ( null === $gap ) {
			// First ever activity — start streak at 1.
			$new_streak = 1;
			$grace_used = 0;
		} elseif ( 1 === $gap ) {
			// Consecutive day — extend streak, reset grace availability.
			$new_streak = $data['current_streak'] + 1;
			$grace_used = 0;
		} elseif ( $gap <= $grace_days && ! $data['grace_used'] ) {
			// Within grace window and grace not yet used — extend streak, burn grace.
			$new_streak = $data['current_streak'] + 1;
			$grace_used = 1;
		} else {
			// Gap too large or grace exhausted — reset.
			$old_streak = (int) $data['current_streak'];
			$new_streak = 1;
			$grace_used = 0;

			if ( $old_streak > 1 ) {
				/**
				 * Fires when a member's streak is broken (reset to 1).
				 *
				 * @since 1.0.0
				 * @param int $user_id    User whose streak broke.
				 * @param int $old_streak The streak count before it was reset.
				 * @param int $gap        Number of inactive days that caused the break.
				 */
				do_action( 'wb_gamification_streak_broken', $user_id, $old_streak, $gap );
			}
		}

		$longest = max( $data['longest_streak'], $new_streak );

		self::upsert_row( $user_id, $new_streak, $longest, $today, $tz, $grace_used );

		// Bust cache.
		wp_cache_delete( "wb_gam_streak_{$user_id}", self::CACHE_GROUP );

		// Check for milestone.
		if ( in_array( $new_streak, self::MILESTONES, true ) ) {
			self::fire_milestone( $user_id, $new_streak );
		}
	}

	// ── Public read API ─────────────────────────────────────────────────────────

	/**
	 * Get streak data for a user.
	 *
	 * @param int $user_id User ID to retrieve streak data for.
	 * @return array{ current_streak: int, longest_streak: int, last_active: string|null, timezone: string, grace_used: bool }
	 */
	public static function get_streak( int $user_id ): array {
		return self::get_row( $user_id );
	}

	/**
	 * Get a daily activity map for the contribution heatmap block.
	 *
	 * Returns an array of date => points, sorted ascending, for the past $days days.
	 *
	 * @param int $user_id User ID to retrieve contribution data for.
	 * @param int $days    Lookback window (default 365 = one year heatmap).
	 * @return array<string, int>
	 */
	public static function get_contribution_data( int $user_id, int $days = 365 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' 00:00:00';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS activity_date, SUM(points) AS total
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND created_at >= %s
				  GROUP BY DATE(created_at)
				  ORDER BY activity_date ASC",
				$user_id,
				$since
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['activity_date'] ] = (int) $row['total'];
		}

		return $map;
	}

	// ── Milestone ───────────────────────────────────────────────────────────────

	/**
	 * Fire the streak milestone action and award any configured bonus points.
	 *
	 * @param int $user_id     User who hit the milestone.
	 * @param int $streak_days Number of consecutive days in the streak.
	 */
	private static function fire_milestone( int $user_id, int $streak_days ): void {
		/**
		 * Fires when a member hits a streak milestone.
		 *
		 * Front-end hooks in here to show the fire/celebration overlay.
		 * BP integration posts to activity stream.
		 *
		 * @param int $user_id     User who hit the milestone.
		 * @param int $streak_days Number of consecutive days.
		 */
		do_action( 'wb_gamification_streak_milestone', $user_id, $streak_days );

		/**
		 * Fires when a user reaches a streak milestone.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id       User who reached the milestone.
		 * @param int $streak_days   Current streak day count.
		 */
		do_action( 'wb_gam_streak_milestone', $user_id, $streak_days );

		// Award bonus points if configured.
		$bonus = (int) get_option( self::OPT_BONUS_PTS, 10 );
		if ( $bonus > 0 ) {
			Engine::process(
				new Event(
					array(
						'action_id' => 'streak_milestone',
						'user_id'   => $user_id,
						'object_id' => $streak_days,
						'metadata'  => array(
							'points'      => $bonus,
							'streak_days' => $streak_days,
						),
					)
				)
			);
		}
	}

	// ── DB helpers ───────────────────────────────────────────────────────────────

	/**
	 * Fetch the streak row for a user (object-cache backed).
	 *
	 * @param int $user_id User ID to fetch streak data for.
	 * @return array{ current_streak: int, longest_streak: int, last_active: string|null, timezone: string, grace_used: bool }
	 */
	private static function get_row( int $user_id ): array {
		$cache_key = "wb_gam_streak_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT current_streak, longest_streak, last_active, timezone, grace_used
				   FROM {$wpdb->prefix}wb_gam_streaks
				  WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$data = $row
			? array(
				'current_streak' => (int) $row['current_streak'],
				'longest_streak' => (int) $row['longest_streak'],
				'last_active'    => $row['last_active'] ?: null,
				'timezone'       => $row['timezone'] ?: 'UTC',
				'grace_used'     => (bool) $row['grace_used'],
			)
			: array(
				'current_streak' => 0,
				'longest_streak' => 0,
				'last_active'    => null,
				'timezone'       => 'UTC',
				'grace_used'     => false,
			);

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Insert or replace the streak row for a user.
	 *
	 * @param int    $user_id        User ID.
	 * @param int    $current_streak Current consecutive day streak.
	 * @param int    $longest_streak All-time longest streak.
	 * @param string $last_active    Date of last activity (Y-m-d).
	 * @param string $timezone       Timezone string used for date calculations.
	 * @param int    $grace_used     Whether grace period has been used (0 or 1).
	 */
	private static function upsert_row(
		int $user_id,
		int $current_streak,
		int $longest_streak,
		string $last_active,
		string $timezone,
		int $grace_used
	): void {
		global $wpdb;

		$wpdb->replace(
			$wpdb->prefix . 'wb_gam_streaks',
			array(
				'user_id'        => $user_id,
				'current_streak' => $current_streak,
				'longest_streak' => $longest_streak,
				'last_active'    => $last_active,
				'timezone'       => $timezone,
				'grace_used'     => $grace_used,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	// ── Timezone helpers ────────────────────────────────────────────────────────

	/**
	 * Return the member's timezone string.
	 *
	 * Priority: user meta > stored streak row > site timezone > UTC.
	 *
	 * @param int    $user_id   User ID to look up timezone for.
	 * @param string $stored_tz Timezone string stored in the streak row.
	 * @return string Valid timezone string.
	 */
	private static function get_timezone( int $user_id, string $stored_tz ): string {
		$tz = (string) get_user_meta( $user_id, 'timezone_string', true );

		if ( '' === $tz ) {
			$tz = $stored_tz;
		}

		if ( '' === $tz ) {
			$tz = get_option( 'timezone_string', 'UTC' );
		}

		// Validate — DateTimeZone constructor throws for invalid strings.
		try {
			new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			$tz = 'UTC';
		}

		return $tz;
	}

	/**
	 * Get "today" as a Y-m-d string in the given timezone.
	 *
	 * @param string $tz Timezone string (e.g. 'America/New_York').
	 * @return string Today's date in Y-m-d format.
	 */
	private static function today( string $tz ): string {
		try {
			$dt = new \DateTime( 'now', new \DateTimeZone( $tz ) );
			return $dt->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d' );
		}
	}

	/**
	 * Calculate the difference in days between two Y-m-d date strings in the given timezone.
	 *
	 * Returns a positive integer.
	 *
	 * @param string $from Start date in Y-m-d format.
	 * @param string $to   End date in Y-m-d format.
	 * @param string $tz   Timezone string for date calculations.
	 * @return int Absolute day difference, or 999 on error.
	 */
	private static function date_diff_days( string $from, string $to, string $tz ): int {
		try {
			$dtz  = new \DateTimeZone( $tz );
			$d1   = new \DateTime( $from, $dtz );
			$d2   = new \DateTime( $to, $dtz );
			$diff = $d1->diff( $d2 );
			return abs( $diff->days );
		} catch ( \Exception $e ) {
			return 999; // Triggers a reset on error.
		}
	}
}
