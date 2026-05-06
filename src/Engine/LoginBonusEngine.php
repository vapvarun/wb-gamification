<?php
/**
 * WB Gamification — Daily Login Bonus
 *
 * Tracks consecutive-day logins and awards a per-tier bonus on each
 * eligible day. Independent from the activity-based StreakEngine —
 * two distinct concepts:
 *
 *   - StreakEngine   = consecutive days with at least one points-earning event.
 *   - LoginBonusEngine = consecutive days the user actually signed in,
 *                       rewarded on a tier ladder (day 1 = 10pt, day 3 = 20pt,
 *                       day 7 = 50pt, day 14 = 100pt, day 30 = 250pt).
 *
 * State lives in user_meta to avoid a schema change:
 *   - `wb_gam_login_streak`        int   consecutive days (resets on miss)
 *   - `wb_gam_login_streak_max`    int   personal best
 *   - `wb_gam_login_last_award`    Y-m-d last day this user got a bonus
 *
 * Award path: `wp_login` → check whether last_award is today → if not,
 * compute streak (gap-tolerant: 0 = same day, 1 = consecutive, >1 = reset)
 * → look up tier reward → PointsEngine::award() → fire hook for toast.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Awards a per-tier bonus on each consecutive-day login.
 */
final class LoginBonusEngine {

	private const META_STREAK     = 'wb_gam_login_streak';
	private const META_STREAK_MAX = 'wb_gam_login_streak_max';
	private const META_LAST       = 'wb_gam_login_last_award';
	private const ACTION_ID       = 'login_bonus';
	private const OPT_ENABLED     = 'wb_gam_login_bonus_enabled';
	private const OPT_TIERS       = 'wb_gam_login_bonus_tiers';

	/**
	 * Default tier ladder: day → bonus points.
	 *
	 * Day 1 fires on the very first login of any session that breaks a
	 * 24-hour gap. Days 3 / 7 / 14 / 30 are checkpoints; non-checkpoint
	 * days get the most-recent checkpoint's value (so day 4-6 = 20pt).
	 */
	public const DEFAULT_TIERS = array(
		1  => 10,
		3  => 20,
		7  => 50,
		14 => 100,
		30 => 250,
	);

	/**
	 * Boot — listen for login events.
	 */
	public static function init(): void {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/**
	 * On login: check eligibility, advance the streak, award the bonus.
	 *
	 * @param string   $user_login Username (unused).
	 * @param \WP_User $user       Logged-in user.
	 */
	public static function on_login( string $user_login, \WP_User $user ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		$user_id = (int) $user->ID;
		if ( $user_id <= 0 ) {
			return;
		}

		$today = current_time( 'Y-m-d' );
		$last  = (string) get_user_meta( $user_id, self::META_LAST, true );

		// Already awarded today — nothing more to do.
		if ( $last === $today ) {
			return;
		}

		// Compute new streak count based on the gap from last award.
		if ( '' === $last ) {
			$streak = 1;
		} else {
			$gap = self::days_between( $last, $today );
			if ( 1 === $gap ) {
				$streak = (int) get_user_meta( $user_id, self::META_STREAK, true ) + 1;
			} else {
				$streak = 1; // missed a day → reset
			}
		}

		// Look up the bonus for this streak day.
		$tiers = self::get_tiers();
		$bonus = self::tier_for_day( $streak, $tiers );
		if ( $bonus <= 0 ) {
			// Save streak state but skip the award.
			self::persist_streak( $user_id, $streak, $today );
			return;
		}

		// Award via standard pipeline so all hooks fire (badges, levels,
		// notifications, materialised totals all stay consistent).
		PointsEngine::award( $user_id, self::ACTION_ID, $bonus );

		self::persist_streak( $user_id, $streak, $today );

		/**
		 * Fires after a daily-login bonus is awarded.
		 *
		 * @param int $user_id User who got the bonus.
		 * @param int $streak  New consecutive-day streak count (1, 2, 3, ...).
		 * @param int $bonus   Points awarded for this tier.
		 */
		do_action( 'wb_gam_login_bonus_claimed', $user_id, $streak, $bonus );
	}

	/**
	 * Look up the bonus value for a given streak day.
	 *
	 * Picks the highest tier ≤ $day so non-checkpoint days inherit the
	 * most recent checkpoint (day 4–6 → tier 3's 20pt, etc.).
	 *
	 * @param int                $day   Streak day (1-based).
	 * @param array<int,int> $tiers Tier ladder map.
	 */
	public static function tier_for_day( int $day, array $tiers ): int {
		ksort( $tiers );
		$out = 0;
		foreach ( $tiers as $threshold => $bonus ) {
			if ( $day >= (int) $threshold ) {
				$out = (int) $bonus;
			}
		}
		return $out;
	}

	/**
	 * Read the user's current login-streak state.
	 *
	 * @param int $user_id User ID.
	 * @return array{streak:int, max:int, last:string, next_bonus:int, today_bonus:int}
	 */
	public static function get_state( int $user_id ): array {
		$streak = (int) get_user_meta( $user_id, self::META_STREAK, true );
		$max    = (int) get_user_meta( $user_id, self::META_STREAK_MAX, true );
		$last   = (string) get_user_meta( $user_id, self::META_LAST, true );
		$tiers  = self::get_tiers();
		return array(
			'streak'      => $streak,
			'max'         => $max,
			'last'        => $last,
			'today_bonus' => self::tier_for_day( max( 1, $streak ), $tiers ),
			'next_bonus'  => self::tier_for_day( max( 1, $streak ) + 1, $tiers ),
			'tiers'       => $tiers,
		);
	}

	/**
	 * Get the configured tier ladder (admin-overrideable JSON option).
	 *
	 * @return array<int,int>
	 */
	public static function get_tiers(): array {
		$raw = get_option( self::OPT_TIERS, '' );
		if ( '' === $raw ) {
			return self::DEFAULT_TIERS;
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return self::DEFAULT_TIERS;
		}
		// Normalise keys to int.
		$out = array();
		foreach ( $decoded as $k => $v ) {
			$out[ (int) $k ] = (int) $v;
		}
		return $out ?: self::DEFAULT_TIERS;
	}

	/**
	 * Whether the daily-login bonus feature is enabled.
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::OPT_ENABLED, true );
	}

	/**
	 * Persist the new streak state.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $streak  New streak count.
	 * @param string $today   Today's date (Y-m-d).
	 */
	private static function persist_streak( int $user_id, int $streak, string $today ): void {
		$max = max( (int) get_user_meta( $user_id, self::META_STREAK_MAX, true ), $streak );
		update_user_meta( $user_id, self::META_STREAK, $streak );
		update_user_meta( $user_id, self::META_STREAK_MAX, $max );
		update_user_meta( $user_id, self::META_LAST, $today );
	}

	/**
	 * Days between two Y-m-d dates (positive integer).
	 *
	 * @param string $from Earlier date.
	 * @param string $to   Later date.
	 */
	private static function days_between( string $from, string $to ): int {
		$d1 = \DateTimeImmutable::createFromFormat( 'Y-m-d', $from );
		$d2 = \DateTimeImmutable::createFromFormat( 'Y-m-d', $to );
		if ( ! $d1 || ! $d2 ) {
			return 0;
		}
		$diff = $d2->diff( $d1 );
		return (int) $diff->days;
	}
}
