<?php
/**
 * WB Gamification Nudge Engine
 *
 * Returns a single contextual "what to do next" nudge for a user based on
 * 7 priority rules (first match wins). Result is cached in a user-specific
 * transient for 5 minutes to avoid running the full evaluation on every
 * page load.
 *
 * Priority rules:
 *   1. Unclaimed challenge reward (completed but not claimed)
 *   2. Close to level-up (within 20% of next threshold)
 *   3. Streak at risk (streak > 3, no activity today)
 *   4. New badges earned (earned in last 7 days)
 *   5. Active challenge with progress > 50%
 *   6. No challenges joined at all
 *   7. Fallback — points earned this week (or total)
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Returns a single contextual nudge for a user based on 7 priority rules.
 *
 * @since 1.0.0
 */
final class NudgeEngine {

	/**
	 * Transient TTL in seconds (5 minutes).
	 *
	 * @since 1.0.0
	 */
	private const CACHE_TTL = 300;

	/**
	 * Get the highest-priority nudge for a user.
	 *
	 * Returns a cached result when available. Otherwise evaluates all 7
	 * priority rules and caches the winning nudge for 5 minutes.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID to evaluate.
	 * @return array{ message: string, panel: string, icon: string }
	 */
	public static function get_nudge( int $user_id ): array {
		$transient_key = 'wb_gam_nudge_' . $user_id;
		$cached        = get_transient( $transient_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$nudge = self::calculate( $user_id );

		set_transient( $transient_key, $nudge, self::CACHE_TTL );

		return $nudge;
	}

	/**
	 * Evaluate all 7 priority rules and return the first matching nudge.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID to evaluate.
	 * @return array{ message: string, panel: string, icon: string }
	 */
	private static function calculate( int $user_id ): array {
		// Fetch shared data up-front to avoid redundant calls across rules.
		$challenges = ChallengeEngine::get_active_challenges( $user_id );

		// Priority 1: Unclaimed challenge reward.
		$nudge = self::check_unclaimed_reward( $challenges );
		if ( $nudge ) {
			return $nudge;
		}

		// Priority 2: Close to level-up.
		$nudge = self::check_close_to_level_up( $user_id );
		if ( $nudge ) {
			return $nudge;
		}

		// Priority 3: Streak at risk.
		$nudge = self::check_streak_at_risk( $user_id );
		if ( $nudge ) {
			return $nudge;
		}

		// Priority 4: New badges earned in last 7 days.
		$nudge = self::check_new_badges( $user_id );
		if ( $nudge ) {
			return $nudge;
		}

		// Priority 5: Active challenge with progress > 50%.
		$nudge = self::check_challenge_in_progress( $challenges );
		if ( $nudge ) {
			return $nudge;
		}

		// Priority 6: No challenges joined.
		$nudge = self::check_no_challenges( $challenges );
		if ( $nudge ) {
			return $nudge;
		}

		// Priority 7: Fallback.
		return self::fallback_nudge( $user_id );
	}

	/**
	 * Priority 1: Unclaimed challenge reward (completed but not claimed).
	 *
	 * @since 1.0.0
	 *
	 * @param array $challenges Active challenges with progress data.
	 * @return array{ message: string, panel: string, icon: string }|null
	 */
	private static function check_unclaimed_reward( array $challenges ): ?array {
		foreach ( $challenges as $ch ) {
			// A challenge is "unclaimed" if completed === true and claimed === false.
			if ( ! empty( $ch['completed'] ) && isset( $ch['claimed'] ) && ! $ch['claimed'] ) {
				$title  = $ch['title'] ?? 'a challenge';
				$bonus  = (int) ( $ch['bonus_points'] ?? 0 );
				return array(
					'message' => sprintf(
						'You completed %s! Claim your +%d bonus points',
						$title,
						$bonus
					),
					'panel' => 'challenges',
					'icon'  => 'trophy',
				);
			}
		}

		return null;
	}

	/**
	 * Priority 2: Close to level-up (within 20% of next threshold).
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID to evaluate.
	 * @return array{ message: string, panel: string, icon: string }|null
	 */
	private static function check_close_to_level_up( int $user_id ): ?array {
		$next_level = LevelEngine::get_next_level( $user_id );

		if ( ! $next_level ) {
			return null;
		}

		$total     = PointsEngine::get_total( $user_id );
		$threshold = (int) $next_level['min_points'];
		$remaining = $threshold - $total;

		// "Within 20%" means the user needs <= 20% of the threshold to level up.
		if ( $remaining > 0 && $remaining <= ( $threshold * 0.2 ) ) {
			return array(
				'message' => sprintf(
					"You're %d points from %s — keep going!",
					$remaining,
					$next_level['name']
				),
				'panel' => 'earning',
				'icon'  => 'trending-up',
			);
		}

		return null;
	}

	/**
	 * Priority 3: Streak at risk (streak > 3, no activity today).
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID to evaluate.
	 * @return array{ message: string, panel: string, icon: string }|null
	 */
	private static function check_streak_at_risk( int $user_id ): ?array {
		$streak = StreakEngine::get_streak( $user_id );

		if ( (int) $streak['current_streak'] <= 3 ) {
			return null;
		}

		// Check if last_active is today — if so, streak is safe.
		$last_active = $streak['last_active'] ?? null;
		if ( null === $last_active ) {
			return null;
		}

		$today = current_time( 'Y-m-d' );

		if ( $last_active === $today ) {
			return null; // Already active today — streak is safe.
		}

		return array(
			'message' => sprintf(
				"Don't break your %d-day streak! Do any activity to keep it",
				(int) $streak['current_streak']
			),
			'panel' => 'earning',
			'icon'  => 'flame',
		);
	}

	/**
	 * Priority 4: New badges earned in last 7 days.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID to evaluate.
	 * @return array{ message: string, panel: string, icon: string }|null
	 */
	private static function check_new_badges( int $user_id ): ?array {
		$badges = BadgeEngine::get_user_badges( $user_id );

		if ( empty( $badges ) ) {
			return null;
		}

		$seven_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$recent_count   = 0;

		foreach ( $badges as $badge ) {
			if ( ! empty( $badge['earned_at'] ) && $badge['earned_at'] >= $seven_days_ago ) {
				++$recent_count;
			}
		}

		if ( $recent_count > 0 ) {
			return array(
				'message' => sprintf(
					'You earned %d new badge%s! Check them out',
					$recent_count,
					1 === $recent_count ? '' : 's'
				),
				'panel' => 'badges',
				'icon'  => 'award',
			);
		}

		return null;
	}

	/**
	 * Priority 5: Active challenge with progress > 50%.
	 *
	 * @since 1.0.0
	 *
	 * @param array $challenges Active challenges with progress data.
	 * @return array{ message: string, panel: string, icon: string }|null
	 */
	private static function check_challenge_in_progress( array $challenges ): ?array {
		foreach ( $challenges as $ch ) {
			if ( ! empty( $ch['completed'] ) ) {
				continue; // Already done.
			}

			$target   = (int) ( $ch['target'] ?? 0 );
			$progress = (int) ( $ch['progress'] ?? 0 );

			if ( $target <= 0 || $progress <= 0 ) {
				continue;
			}

			$pct       = (int) round( ( $progress / $target ) * 100 );
			$remaining = $target - $progress;

			if ( $pct > 50 ) {
				return array(
					'message' => sprintf(
						'%s is %d%% done — %d more to complete it',
						$ch['title'] ?? 'Challenge',
						$pct,
						$remaining
					),
					'panel' => 'challenges',
					'icon'  => 'target',
				);
			}
		}

		return null;
	}

	/**
	 * Priority 6: No challenges joined at all.
	 *
	 * Fires when the challenges list is empty, suggesting the user join one.
	 *
	 * @since 1.0.0
	 *
	 * @param array $challenges Active challenges with progress data.
	 * @return array{ message: string, panel: string, icon: string }|null
	 */
	private static function check_no_challenges( array $challenges ): ?array {
		if ( empty( $challenges ) ) {
			return array(
				'message' => 'Try a challenge to earn bonus points',
				'panel'   => 'challenges',
				'icon'    => 'target',
			);
		}

		return null;
	}

	/**
	 * Priority 7: Fallback nudge — encouragement with total points.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID to evaluate.
	 * @return array{ message: string, panel: string, icon: string }
	 */
	private static function fallback_nudge( int $user_id ): array {
		$total = PointsEngine::get_total( $user_id );

		return array(
			'message' => sprintf(
				"Keep going! You've earned %d points this week",
				$total
			),
			'panel' => 'earning',
			'icon'  => 'zap',
		);
	}
}
