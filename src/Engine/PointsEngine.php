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
			[
				'event_id'   => $event->event_id,
				'user_id'    => $event->user_id,
				'action_id'  => $event->action_id,
				'points'     => $points,
				'object_id'  => $event->object_id ?: null,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%s', '%d', '%d', '%s' ]
		);

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
				[
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'object_id' => $object_id,
				]
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
				[
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'object_id' => $object_id,
					'metadata'  => [ 'points' => $points, 'manual' => true ],
				]
			)
		);
	}

	// ── Read methods ──────────────────────────────────────────────────────────

	/**
	 * Get total points for a user.
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

	// ── Private rate-limit helpers ────────────────────────────────────────────

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

	private static function get_today_count( int $user_id, string $action_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s AND DATE(created_at) = CURDATE()",
				$user_id,
				$action_id
			)
		);
	}

	private static function get_week_count( int $user_id, string $action_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s
				  AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)",
				$user_id,
				$action_id
			)
		);
	}
}
