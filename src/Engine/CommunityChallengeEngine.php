<?php
/**
 * Community Challenge Engine — Phase 3
 *
 * Time-limited, site-wide challenges where the entire community works toward
 * a shared target (Pokémon GO Community Day model).
 *
 * How it works:
 *   1. Admin creates a community challenge (title, target_action, target_count,
 *      starts_at, ends_at, bonus_points).
 *   2. Engine hooks `wb_gamification_points_awarded` — every matching event
 *      increments the global counter atomically.
 *   3. When counter reaches target, Engine fires `wb_gamification_community_challenge_completed`
 *      and awards bonus_points to every user who contributed at least one event.
 *   4. A live counter is served via REST at GET /community-challenges/{id}.
 *
 * Data stored in wb_gam_community_challenges (separate table added by DbUpgrader).
 * Contributions stored in wb_gam_community_challenge_contributions.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide community challenges where all members work toward a shared target.
 *
 * @package WB_Gamification
 */
final class CommunityChallengeEngine {

	/**
	 * Register the points-awarded hook for community challenge processing.
	 */
	public static function init(): void {
		add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'on_points_awarded' ), 20, 3 );
	}

	// ── Event hook ──────────────────────────────────────────────────────────

	/**
	 * Called after every point award. Checks active community challenges for the action.
	 *
	 * @param int   $user_id User who earned points.
	 * @param Event $event   The event.
	 * @param int   $points  Points awarded (unused but required by hook signature).
	 */
	public static function on_points_awarded( int $user_id, Event $event, int $points ): void {
		global $wpdb;

		$now = current_time( 'mysql' );

		// Find active community challenges that match this action.
		$challenges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, target_action, target_count, bonus_points
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE status = 'active'
				    AND (target_action = %s OR target_action = '*')
				    AND starts_at <= %s
				    AND ends_at >= %s",
				$event->action_id,
				$now,
				$now
			),
			ARRAY_A
		);

		foreach ( $challenges as $challenge ) {
			self::record_contribution( (int) $challenge['id'], $user_id, $event );
		}
	}

	// ── Contribution recording ───────────────────────────────────────────────

	/**
	 * Record one contribution from a user and increment the global counter.
	 *
	 * @param int   $challenge_id Community challenge identifier.
	 * @param int   $user_id      User making the contribution.
	 * @param Event $event        The triggering event (unused beyond the hook signature).
	 */
	private static function record_contribution( int $challenge_id, int $user_id, Event $event ): void {
		global $wpdb;

		// Upsert contribution count for this user.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}wb_gam_community_challenge_contributions
				    (challenge_id, user_id, contribution_count)
				 VALUES (%d, %d, 1)
				 ON DUPLICATE KEY UPDATE contribution_count = contribution_count + 1",
				$challenge_id,
				$user_id
			)
		);

		// Increment global counter atomically.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wb_gam_community_challenges
				    SET global_progress = global_progress + 1
				  WHERE id = %d AND status = 'active'",
				$challenge_id
			)
		);

		// Check if target is now met.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, global_progress, target_count, bonus_points
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE id = %d",
				$challenge_id
			),
			ARRAY_A
		);

		if ( $row && (int) $row['global_progress'] >= (int) $row['target_count'] ) {
			self::complete_challenge( $challenge_id, (int) $row['bonus_points'] );
		}
	}

	// ── Challenge completion ─────────────────────────────────────────────────

	/**
	 * Mark a community challenge as completed and award bonus points to all contributors.
	 *
	 * @param int $challenge_id  The challenge to complete.
	 * @param int $bonus_points  Points to award to each contributor.
	 */
	private static function complete_challenge( int $challenge_id, int $bonus_points ): void {
		global $wpdb;

		// Mark completed — use atomic update to prevent double-fire.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wb_gam_community_challenges
				    SET status = 'completed', completed_at = %s
				  WHERE id = %d AND status = 'active'",
				current_time( 'mysql' ),
				$challenge_id
			)
		);

		if ( ! $updated ) {
			return; // Already completed by another request.
		}

		// Award bonus to all contributors via async AS jobs.
		$contributors = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}wb_gam_community_challenge_contributions WHERE challenge_id = %d",
				$challenge_id
			)
		);

		foreach ( $contributors as $user_id ) {
			as_enqueue_async_action(
				'wb_gam_community_bonus_award',
				array(
					'user_id'      => (int) $user_id,
					'challenge_id' => $challenge_id,
					'points'       => $bonus_points,
				),
				'wb-gamification'
			);
		}

		/**
		 * Fires when a community challenge is completed.
		 *
		 * @param int $challenge_id  The completed challenge ID.
		 * @param int $bonus_points  Bonus points awarded to contributors.
		 * @param int $contributors  Number of contributing users.
		 */
		do_action( 'wb_gamification_community_challenge_completed', $challenge_id, $bonus_points, count( $contributors ) );
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Get all active community challenges with their current progress.
	 *
	 * @return array<int, array>
	 */
	public static function get_active(): array {
		global $wpdb;

		$now = current_time( 'mysql' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, target_action, target_count, global_progress,
				        bonus_points, starts_at, ends_at, status
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE status = 'active' AND starts_at <= %s AND ends_at >= %s
				  ORDER BY ends_at ASC",
				$now,
				$now
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get a single community challenge.
	 *
	 * @param int $id Community challenge ID.
	 * @return array|null Challenge row, or null if not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, target_action, target_count, global_progress,
				        bonus_points, starts_at, ends_at, status, completed_at
				   FROM {$wpdb->prefix}wb_gam_community_challenges
				  WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get a user's contribution count to a community challenge.
	 *
	 * @param int $challenge_id Community challenge identifier.
	 * @param int $user_id      User to query.
	 * @return int Contribution count (0 if none).
	 */
	public static function get_user_contribution( int $challenge_id, int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT contribution_count FROM {$wpdb->prefix}wb_gam_community_challenge_contributions
				  WHERE challenge_id = %d AND user_id = %d",
				$challenge_id,
				$user_id
			)
		);
	}
}
