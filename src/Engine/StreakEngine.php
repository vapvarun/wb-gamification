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
 *       1. `wb_gam_streak_milestone` action (for UI overlay)
 *       2. Bonus points via Engine::process() if option > 0
 *
 * Streak data stored in wb_gam_streaks (one row per user).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

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
			'wb_gam_streak_grace_days',
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
				do_action( 'wb_gam_streak_broken', $user_id, $old_streak, $gap );
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

	// ── Admin read API (roster) ──────────────────────────────────────────────────

	/**
	 * Columns the admin roster may sort by (whitelist — guards the ORDER BY).
	 *
	 * @var array<int, string>
	 */
	private const SORTABLE = array( 'current_streak', 'longest_streak', 'last_active', 'updated_at' );

	/**
	 * Total number of members with a streak row (for pagination).
	 *
	 * @return int
	 */
	public static function admin_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_streaks" );
	}

	/**
	 * Fetch a page of streak rows for the admin roster, sorted at the DB.
	 *
	 * Uses LIMIT/OFFSET + an indexed ORDER BY (idx_current_streak /
	 * idx_longest_streak, added in 1.6.2) so it stays fast at 100k members.
	 * Callers batch-fetch display names to avoid N+1.
	 *
	 * @param int    $per_page Rows per page (1–200).
	 * @param int    $offset   Row offset.
	 * @param string $orderby  One of SORTABLE (falls back to current_streak).
	 * @param string $order    'asc' or 'desc' (falls back to desc).
	 * @return array<int, array{ user_id: int, current_streak: int, longest_streak: int, last_active: string|null, grace_used: bool }>
	 */
	public static function admin_list( int $per_page = 20, int $offset = 0, string $orderby = 'current_streak', string $order = 'desc' ): array {
		global $wpdb;

		$orderby  = in_array( $orderby, self::SORTABLE, true ) ? $orderby : 'current_streak';
		$order    = strtolower( $order ) === 'asc' ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, $offset );

		// $orderby is whitelisted above; $order is a literal ASC|DESC — neither is
		// user input by the time it reaches the query. LIMIT/OFFSET are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, current_streak, longest_streak, last_active, grace_used
				   FROM {$wpdb->prefix}wb_gam_streaks
				  ORDER BY {$orderby} {$order}
				  LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			static function ( array $r ): array {
				return array(
					'user_id'        => (int) $r['user_id'],
					'current_streak' => (int) $r['current_streak'],
					'longest_streak' => (int) $r['longest_streak'],
					'last_active'    => $r['last_active'] ?: null,
					'grace_used'     => (bool) $r['grace_used'],
				);
			},
			$rows
		);
	}

	// ── Admin write API ──────────────────────────────────────────────────────────

	/**
	 * Administratively set a member's streak values (support/moderation).
	 *
	 * Unlike record_activity(), this bypasses the day-progression logic and
	 * writes the given values verbatim — for fixing a member's broken streak
	 * from wp-admin or the REST API. NEVER a silent mutation: it persists a
	 * `streak_adjusted` row to the immutable wb_gam_events log (surfaced by
	 * GET /members/{id}/events) carrying the before/after values, the reason,
	 * and the acting admin, then fires `wb_gam_streak_adjusted` so listeners
	 * (BuddyPress bridge, Compete bridge) react exactly as they do to an
	 * organic change.
	 *
	 * @param int      $user_id  Member whose streak to set.
	 * @param int|null $current  New current streak (null = leave unchanged).
	 * @param int|null $longest  New longest streak (null = derive as max(current, existing)).
	 * @param string   $reason   Free-text audit reason.
	 * @param int      $admin_id Acting admin user ID (0 = system/CLI).
	 * @return array{ current_streak: int, longest_streak: int, last_active: string|null, timezone: string, grace_used: bool } The row after the write.
	 */
	public static function admin_set( int $user_id, ?int $current, ?int $longest, string $reason, int $admin_id = 0 ): array {
		$before = self::get_row( $user_id );

		$new_current = null !== $current ? max( 0, $current ) : $before['current_streak'];
		$new_longest = null !== $longest ? max( 0, $longest ) : max( $before['longest_streak'], $new_current );
		// Longest can never be below the current streak — keep the invariant.
		$new_longest = max( $new_longest, $new_current );

		self::upsert_row(
			$user_id,
			$new_current,
			$new_longest,
			(string) $before['last_active'],
			$before['timezone'],
			$before['grace_used'] ? 1 : 0
		);
		wp_cache_delete( "wb_gam_streak_{$user_id}", self::CACHE_GROUP );

		$after = self::get_row( $user_id );
		self::record_admin_event( $user_id, 'streak_adjusted', $before, $after, $reason, $admin_id );

		/**
		 * Fires after an admin sets a member's streak values.
		 *
		 * @since 1.6.2
		 * @param int    $user_id  Member whose streak changed.
		 * @param array  $after    The streak row after the change.
		 * @param array  $before   The streak row before the change.
		 * @param string $reason   Audit reason supplied by the admin.
		 * @param int    $admin_id Acting admin user ID.
		 */
		do_action( 'wb_gam_streak_adjusted', $user_id, $after, $before, $reason, $admin_id );

		return $after;
	}

	/**
	 * Administratively reset a member's current streak to zero.
	 *
	 * Clears current_streak, last_active and the grace flag so the next
	 * activity starts a fresh streak at 1. longest_streak is PRESERVED — it is
	 * an all-time record, not part of the active run. Audited identically to
	 * admin_set() via a `streak_reset` event + `wb_gam_streak_reset` action.
	 *
	 * @param int    $user_id  Member whose streak to reset.
	 * @param string $reason   Free-text audit reason.
	 * @param int    $admin_id Acting admin user ID (0 = system/CLI).
	 * @return array{ current_streak: int, longest_streak: int, last_active: string|null, timezone: string, grace_used: bool } The row after the reset.
	 */
	public static function admin_reset( int $user_id, string $reason, int $admin_id = 0 ): array {
		$before = self::get_row( $user_id );

		self::upsert_row( $user_id, 0, $before['longest_streak'], '', $before['timezone'], 0 );
		wp_cache_delete( "wb_gam_streak_{$user_id}", self::CACHE_GROUP );

		$after = self::get_row( $user_id );
		self::record_admin_event( $user_id, 'streak_reset', $before, $after, $reason, $admin_id );

		/**
		 * Fires after an admin resets a member's current streak.
		 *
		 * @since 1.6.2
		 * @param int    $user_id  Member whose streak was reset.
		 * @param array  $after    The streak row after the reset.
		 * @param array  $before   The streak row before the reset.
		 * @param string $reason   Audit reason supplied by the admin.
		 * @param int    $admin_id Acting admin user ID.
		 */
		do_action( 'wb_gam_streak_reset', $user_id, $after, $before, $reason, $admin_id );

		return $after;
	}

	/**
	 * Persist an admin streak mutation to the immutable event log.
	 *
	 * Writes a points-free row to wb_gam_events (no wb_gam_points row) so the
	 * change is auditable via GET /members/{id}/events without touching the
	 * points ledger. `upsert_row` already handles last_active as an empty
	 * string; here we only record what changed.
	 *
	 * @param int    $user_id   Member the mutation applies to.
	 * @param string $action_id Event action id (`streak_adjusted` | `streak_reset`).
	 * @param array  $before    Streak row before the change.
	 * @param array  $after     Streak row after the change.
	 * @param string $reason    Audit reason.
	 * @param int    $admin_id  Acting admin user ID.
	 */
	private static function record_admin_event( int $user_id, string $action_id, array $before, array $after, string $reason, int $admin_id ): void {
		Engine::persist_event(
			new Event(
				array(
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'object_id' => (int) $after['current_streak'],
					'metadata'  => array(
						'reason'   => sanitize_text_field( $reason ),
						'admin_id' => $admin_id,
						'before'   => array(
							'current_streak' => (int) $before['current_streak'],
							'longest_streak' => (int) $before['longest_streak'],
						),
						'after'    => array(
							'current_streak' => (int) $after['current_streak'],
							'longest_streak' => (int) $after['longest_streak'],
						),
						'_site_id' => (string) get_current_blog_id(),
					),
				)
			)
		);
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
		 * NOTE: this hook used to fire TWICE (a second `do_action` followed
		 * with the same args) — milestones produced duplicate toasts +
		 * duplicate activity-stream posts. The duplicate was caught by the
		 * 2026-05-27 data-flow audit and removed; the merged docblock here
		 * preserves both `@since` tags from the original twin declarations.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id     User who hit the milestone.
		 * @param int $streak_days Number of consecutive days.
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
			Log::error(
				'StreakEngine — invalid timezone, falling back to UTC',
				array(
					'tz'    => $tz,
					'error' => $e->getMessage(),
				)
			);
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
			Log::error(
				'StreakEngine — date construction failed, falling back to UTC today',
				array(
					'tz'    => $tz,
					'error' => $e->getMessage(),
				)
			);
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
			Log::error(
				'StreakEngine — day-diff failed, returning 999 to force streak reset (corrupt date stored?)',
				array(
					'from'  => $from,
					'to'    => $to,
					'tz'    => $tz,
					'error' => $e->getMessage(),
				)
			);
			return 999; // Triggers a reset on error.
		}
	}
}
