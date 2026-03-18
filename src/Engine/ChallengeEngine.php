<?php
/**
 * WB Gamification Challenge Engine
 *
 * Tracks progress toward time-bound or open-ended challenges.
 * Integrated into the event pipeline — checks challenge progress after
 * every point award without N+1 queries.
 *
 * Challenge types:
 *   individual — one member achieves N completions of an action
 *   team       — a BP group collectively achieves N completions together
 *
 * Progress flow:
 *   1. `wb_gamification_points_awarded` fires after every award.
 *   2. ChallengeEngine::on_points_awarded() queries active challenges
 *      matching the event's action_id (single query, cached for 60s).
 *   3. For each matching challenge, increments the user's progress row.
 *   4. If progress >= target AND not already completed, marks complete
 *      and awards bonus_points.
 *   5. Fires `wb_gamification_challenge_completed`.
 *
 * Team challenges:
 *   Each member in the BP group gets their own wb_gam_challenge_log row.
 *   A team challenge is "complete" when the SUM of all group members'
 *   progress reaches the target. Completion is stored per-group-member
 *   (so all get the bonus) but only awarded once per member.
 *
 * Time-limited challenges:
 *   starts_at / ends_at are checked before incrementing. Challenges with
 *   status = 'active' AND (starts_at IS NULL OR starts_at <= NOW())
 *   AND (ends_at IS NULL OR ends_at >= NOW()) are considered active.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks progress toward time-bound or open-ended challenges.
 *
 * @package WB_Gamification
 */
final class ChallengeEngine {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_TTL   = 60;

	// ── Boot ────────────────────────────────────────────────────────────────────

	/**
	 * Register the points-awarded hook for challenge processing.
	 */
	public static function init(): void {
		add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'on_points_awarded' ), 15, 3 );
	}

	// ── Event hook ───────────────────────────────────────────────────────────────

	/**
	 * Called after every point award. Checks active challenges for the action.
	 *
	 * @param int   $user_id User who earned points.
	 * @param Event $event   The event.
	 * @param int   $points  Points awarded.
	 */
	public static function on_points_awarded( int $user_id, Event $event, int $points ): void {
		$challenges = self::get_active_challenges_for_action( $event->action_id );

		if ( empty( $challenges ) ) {
			return;
		}

		foreach ( $challenges as $challenge ) {
			if ( 'team' === $challenge['type'] ) {
				self::process_team( $user_id, $challenge );
			} else {
				self::process_individual( $user_id, $challenge );
			}
		}
	}

	// ── Public read API ─────────────────────────────────────────────────────────

	/**
	 * Get all active challenges with the current user's progress.
	 *
	 * @param int $user_id User to fetch progress for.
	 * @return array<int, array{ id: int, title: string, type: string, action_id: string, target: int, bonus_points: int, period: string, starts_at: string|null, ends_at: string|null, progress: int, completed: bool }>
	 */
	public static function get_active_challenges( int $user_id ): array {
		global $wpdb;

		$challenges = $wpdb->get_results(
			"SELECT id, title, type, team_group_id, action_id, target, bonus_points,
			        period, starts_at, ends_at
			   FROM {$wpdb->prefix}wb_gam_challenges
			  WHERE status = 'active'
			    AND (starts_at IS NULL OR starts_at <= NOW())
			    AND (ends_at IS NULL OR ends_at >= NOW())
			  ORDER BY id ASC",
			ARRAY_A
		);

		if ( empty( $challenges ) ) {
			return array();
		}

		$challenge_ids = array_column( $challenges, 'id' );
		$progress_map  = self::get_progress_map( $user_id, $challenge_ids );

		return array_map(
			static function ( array $ch ) use ( $progress_map ): array {
				$progress  = (int) ( $progress_map[ $ch['id'] ]['progress'] ?? 0 );
				$completed = ! empty( $progress_map[ $ch['id'] ]['completed_at'] );
				return array(
					'id'           => (int) $ch['id'],
					'title'        => $ch['title'],
					'type'         => $ch['type'],
					'action_id'    => $ch['action_id'],
					'target'       => (int) $ch['target'],
					'bonus_points' => (int) $ch['bonus_points'],
					'period'       => $ch['period'],
					'starts_at'    => $ch['starts_at'],
					'ends_at'      => $ch['ends_at'],
					'progress'     => $progress,
					'completed'    => $completed,
					'progress_pct' => $ch['target'] > 0
						? min( 100, round( ( $progress / (int) $ch['target'] ) * 100, 1 ) )
						: 0,
				);
			},
			$challenges
		);
	}

	// ── Individual challenge processing ─────────────────────────────────────────

	/**
	 * Increment and evaluate progress for an individual challenge.
	 *
	 * @param int   $user_id   User being processed.
	 * @param array $challenge Challenge row from the database.
	 */
	private static function process_individual( int $user_id, array $challenge ): void {
		global $wpdb;

		$existing = self::get_log_row( $user_id, (int) $challenge['id'] );

		// Already completed.
		if ( null !== $existing && null !== $existing['completed_at'] ) {
			return;
		}

		$new_progress = ( $existing ? (int) $existing['progress'] : 0 ) + 1;

		self::upsert_log( $user_id, (int) $challenge['id'], $new_progress );

		if ( $new_progress >= (int) $challenge['target'] ) {
			self::complete_challenge( $user_id, $challenge );
		}
	}

	// ── Team challenge processing ────────────────────────────────────────────────

	/**
	 * Increment this user's contribution and evaluate the team's combined progress.
	 *
	 * @param int   $user_id   User being processed.
	 * @param array $challenge Challenge row from the database.
	 */
	private static function process_team( int $user_id, array $challenge ): void {
		if ( ! function_exists( 'groups_is_user_member' ) ) {
			return;
		}

		$group_id = (int) $challenge['team_group_id'];
		if ( $group_id <= 0 ) {
			return;
		}

		if ( ! groups_is_user_member( $user_id, $group_id ) ) {
			return;
		}

		global $wpdb;

		// Increment this member's personal contribution.
		$existing     = self::get_log_row( $user_id, (int) $challenge['id'] );
		$new_progress = ( $existing ? (int) $existing['progress'] : 0 ) + 1;
		self::upsert_log( $user_id, (int) $challenge['id'], $new_progress );

		// Already marked complete for this user — skip.
		if ( $existing && null !== $existing['completed_at'] ) {
			return;
		}

		// Sum all group members' progress.
		$total_progress = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(cl.progress)
				   FROM {$wpdb->prefix}wb_gam_challenge_log cl
				   JOIN {$wpdb->users} u ON u.ID = cl.user_id
				  WHERE cl.challenge_id = %d",
				$challenge['id']
			)
		);

		if ( $total_progress >= (int) $challenge['target'] ) {
			self::complete_challenge( $user_id, $challenge );
		}
	}

	// ── Completion ───────────────────────────────────────────────────────────────

	/**
	 * Mark the challenge complete, award bonus points, and fire the completion hook.
	 *
	 * @param int   $user_id   User who completed the challenge.
	 * @param array $challenge Challenge row from the database.
	 */
	private static function complete_challenge( int $user_id, array $challenge ): void {
		global $wpdb;

		// Mark complete.
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_challenge_log',
			array( 'completed_at' => current_time( 'mysql' ) ),
			array(
				'user_id'      => $user_id,
				'challenge_id' => $challenge['id'],
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		// Award bonus points.
		$bonus = (int) $challenge['bonus_points'];
		if ( $bonus > 0 ) {
			Engine::process(
				new Event(
					array(
						'action_id' => 'challenge_completed',
						'user_id'   => $user_id,
						'object_id' => (int) $challenge['id'],
						'metadata'  => array(
							'points'       => $bonus,
							'challenge_id' => (int) $challenge['id'],
							'title'        => $challenge['title'],
						),
					)
				)
			);
		}

		/**
		 * Fires when a member completes a challenge.
		 *
		 * @param int   $user_id   User who completed the challenge.
		 * @param array $challenge Full challenge row.
		 */
		do_action( 'wb_gamification_challenge_completed', $user_id, $challenge );
	}

	// ── DB helpers ───────────────────────────────────────────────────────────────

	/**
	 * Get active challenges matching a specific action_id (cached).
	 *
	 * @param string $action_id The action identifier to match.
	 * @return array[]
	 */
	private static function get_active_challenges_for_action( string $action_id ): array {
		$cache_key = 'wb_gam_challenges_' . md5( $action_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, type, team_group_id, action_id, target, bonus_points, period
				   FROM {$wpdb->prefix}wb_gam_challenges
				  WHERE status = 'active'
				    AND action_id = %s
				    AND (starts_at IS NULL OR starts_at <= NOW())
				    AND (ends_at IS NULL OR ends_at >= NOW())",
				$action_id
			),
			ARRAY_A
		);

		$data = $rows ?: array();
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Fetch a single challenge-log row for a user.
	 *
	 * @param int $user_id      User to look up.
	 * @param int $challenge_id Challenge identifier.
	 * @return array{progress: int, completed_at: string|null}|null Null when no row exists.
	 */
	private static function get_log_row( int $user_id, int $challenge_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT progress, completed_at FROM {$wpdb->prefix}wb_gam_challenge_log
				  WHERE user_id = %d AND challenge_id = %d",
				$user_id,
				$challenge_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Insert or update the challenge-log row for a user.
	 *
	 * @param int $user_id      User to update.
	 * @param int $challenge_id Challenge identifier.
	 * @param int $progress     New progress value to store.
	 */
	private static function upsert_log( int $user_id, int $challenge_id, int $progress ): void {
		global $wpdb;

		$wpdb->replace(
			$wpdb->prefix . 'wb_gam_challenge_log',
			array(
				'user_id'      => $user_id,
				'challenge_id' => $challenge_id,
				'progress'     => $progress,
			),
			array( '%d', '%d', '%d' )
		);
	}

	/**
	 * Get a map of challenge_id to progress and completed_at for a user.
	 *
	 * @param int   $user_id       User to look up.
	 * @param int[] $challenge_ids Challenge IDs to query.
	 * @return array<int, array{ progress: int, completed_at: string|null }>
	 */
	private static function get_progress_map( int $user_id, array $challenge_ids ): array {
		if ( empty( $challenge_ids ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $challenge_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is implode(',', array_fill(..., '%d')), safe.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT challenge_id, progress, completed_at
				   FROM {$wpdb->prefix}wb_gam_challenge_log
				  WHERE user_id = %d AND challenge_id IN ($placeholders)",
				array_merge( array( $user_id ), $challenge_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map = array();
		foreach ( $rows ?: array() as $row ) {
			$map[ (int) $row['challenge_id'] ] = array(
				'progress'     => (int) $row['progress'],
				'completed_at' => $row['completed_at'],
			);
		}

		return $map;
	}
}
