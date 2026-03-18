<?php
/**
 * WB Gamification Points Engine
 *
 * Data-access layer for the points ledger.
 * The main award pipeline now lives in Engine::process() — this class
 * provides the rate-limit checks and DB write methods that Engine calls.
 *
 * External callers should use Engine::process(Event) or the helper
 * functions in functions.php. Direct calls to award() are legacy.
 *
 * @package WB_Gamification
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the points ledger — rate-limit checks and DB write methods.
 *
 * @package WB_Gamification
 */
final class PointsEngine {

	// ── Internal methods called by Engine ─────────────────────────────────────

	/**
	 * Check cooldown, repeatable, daily, and weekly caps for a registered action.
	 *
	 * Called by Engine::process() before persisting anything.
	 *
	 * @param int    $user_id   User to check.
	 * @param string $action_id Action to check.
	 * @param array  $action    Action config from Registry.
	 * @return bool             True if the award is allowed to proceed.
	 */
	public static function passes_rate_limits( int $user_id, string $action_id, array $action ): bool {
		// Cooldown check.
		$cooldown = (int) ( $action['cooldown'] ?? 0 );
		if ( $cooldown > 0 && self::is_on_cooldown( $user_id, $action_id, $cooldown ) ) {
			return false;
		}

		// Repeatable check.
		if ( ! ( $action['repeatable'] ?? true ) && self::get_action_count( $user_id, $action_id ) > 0 ) {
			return false;
		}

		// Daily cap check.
		$daily_cap = (int) ( $action['daily_cap'] ?? 0 );
		if ( $daily_cap > 0 && self::get_today_count( $user_id, $action_id ) >= $daily_cap ) {
			return false;
		}

		// Weekly cap check.
		$weekly_cap = (int) ( $action['weekly_cap'] ?? 0 );
		if ( $weekly_cap > 0 && self::get_week_count( $user_id, $action_id ) >= $weekly_cap ) {
			return false;
		}

		return true;
	}

	/**
	 * Insert a row into the points ledger, linked to the event.
	 *
	 * Called by Engine::process() after all checks have passed.
	 *
	 * @param Event $event  The source event (provides event_id and context).
	 * @param int   $points Points to record.
	 * @return bool         True on success.
	 */
	public static function insert_point_row( Event $event, int $points ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_points',
			array(
				'event_id'   => $event->event_id,
				'user_id'    => $event->user_id,
				'action_id'  => $event->action_id,
				'points'     => $points,
				'object_id'  => $event->object_id ?: null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * Debit points from a user's balance.
	 *
	 * Inserts a negative row in the ledger. The caller is responsible for
	 * verifying the user has sufficient balance before calling this.
	 *
	 * @param int    $user_id   User to debit.
	 * @param int    $amount    Positive integer; stored as negative in the ledger.
	 * @param string $action_id Action context label (e.g. 'redemption').
	 * @param string $event_id  Optional UUID reference to a source event.
	 * @return bool             True if the row was inserted.
	 */
	public static function debit( int $user_id, int $amount, string $action_id, string $event_id = '' ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_points',
			array(
				'event_id'   => $event_id ?: null,
				'user_id'    => $user_id,
				'action_id'  => $action_id,
				'points'     => -abs( $amount ),
				'object_id'  => null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s' )
		);

		if ( $inserted ) {
			wp_cache_delete( "wb_gam_total_{$user_id}", 'wb_gamification' );
		}

		return (bool) $inserted;
	}

	// ── Legacy / public API ────────────────────────────────────────────────────

	/**
	 * Process a registered action — legacy entry point.
	 *
	 * Creates an Event and routes through Engine::process() so all award
	 * paths share the same pipeline.
	 *
	 * @param string $action_id Action ID.
	 * @param int    $user_id   User to award.
	 * @param int    $object_id Optional context object (post_id, comment_id, etc.).
	 * @return bool             True if points were awarded.
	 */
	public static function process_action( string $action_id, int $user_id, int $object_id = 0 ): bool {
		return Engine::process(
			new Event(
				array(
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'object_id' => $object_id,
				)
			)
		);
	}

	/**
	 * Manually award points to a user.
	 *
	 * Bypasses cooldown/cap checks (it's a manual, admin-controlled award).
	 * Carries the points value in metadata so Engine can read it when the
	 * action_id is not in the Registry.
	 *
	 * @param int    $user_id   User to award.
	 * @param string $action_id Action context (use 'manual' for admin awards).
	 * @param int    $points    Points to award.
	 * @param int    $object_id Optional context object.
	 * @return bool
	 */
	public static function award( int $user_id, string $action_id, int $points, int $object_id = 0 ): bool {
		if ( $points <= 0 || $user_id <= 0 ) {
			return false;
		}

		return Engine::process(
			new Event(
				array(
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'object_id' => $object_id,
					'metadata'  => array(
						'points' => $points,
						'manual' => true,
					),
				)
			)
		);
	}

	// ── Read methods ──────────────────────────────────────────────────────────

	/**
	 * Get total points for a user.
	 *
	 * @param int $user_id User ID to look up.
	 * @return int Total points balance.
	 */
	public static function get_total( int $user_id ): int {
		$cache_key = "wb_gam_total_{$user_id}";
		$cached    = wp_cache_get( $cache_key, 'wb_gamification' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d",
				$user_id
			)
		);

		wp_cache_set( $cache_key, $total, 'wb_gamification', 300 );

		return $total;
	}

	/**
	 * Get how many times a user has performed a specific action.
	 *
	 * @param int    $user_id   User ID to check.
	 * @param string $action_id Action ID to count.
	 * @return int Number of times the action has been performed.
	 */
	public static function get_action_count( int $user_id, string $action_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d AND action_id = %s",
				$user_id,
				$action_id
			)
		);
	}

	/**
	 * Get recent point transactions for a user, newest first.
	 *
	 * @param int $user_id User ID to look up.
	 * @param int $limit   Maximum rows to return (1–100).
	 * @return array<int, array{action_id: string, points: int, created_at: string}>
	 */
	public static function get_history( int $user_id, int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, points, created_at
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d
				  ORDER BY created_at DESC
				  LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	// ── Private rate-limit helpers ────────────────────────────────────────────

	/**
	 * Check whether a user is within the cooldown window for an action.
	 *
	 * @param int    $user_id          User to check.
	 * @param string $action_id        Action to check.
	 * @param int    $cooldown_seconds Cooldown duration in seconds.
	 * @return bool True if the user is still within the cooldown period.
	 */
	private static function is_on_cooldown( int $user_id, string $action_id, int $cooldown_seconds ): bool {
		global $wpdb;

		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s
				ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$action_id
			)
		);

		if ( ! $last ) {
			return false;
		}

		return ( time() - strtotime( $last ) ) < $cooldown_seconds;
	}

	/**
	 * Count how many times a user has performed an action today (site timezone).
	 *
	 * @param int    $user_id   User to check.
	 * @param string $action_id Action to count.
	 * @return int Number of times the action was performed today.
	 */
	private static function get_today_count( int $user_id, string $action_id ): int {
		global $wpdb;
		// Use range comparison so MySQL can use the idx_user_action_created index.
		// wp_date() returns times in the site timezone, matching current_time('mysql').
		$day_start = wp_date( 'Y-m-d 00:00:00' );
		$day_end   = wp_date( 'Y-m-d 00:00:00', strtotime( '+1 day' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s
				  AND created_at >= %s AND created_at < %s",
				$user_id,
				$action_id,
				$day_start,
				$day_end
			)
		);
	}

	/**
	 * Count how many times a user has performed an action this ISO week.
	 *
	 * @param int    $user_id   User to check.
	 * @param string $action_id Action to count.
	 * @return int Number of times the action was performed this week.
	 */
	private static function get_week_count( int $user_id, string $action_id ): int {
		global $wpdb;
		// ISO week start: Monday 00:00:00 in site timezone.
		// Range comparison allows index seek on idx_user_action_created.
		$week_start = wp_date( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s AND created_at >= %s",
				$user_id,
				$action_id,
				$week_start
			)
		);
	}
}
