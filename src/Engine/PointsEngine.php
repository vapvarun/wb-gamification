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

use WBGam\Services\PointTypeService;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the points ledger — rate-limit checks and DB write methods.
 *
 * Multi-currency support (since 1.0.0): every read/write method accepts an
 * optional `point_type` parameter that scopes the operation to one currency.
 * Defaults preserve single-currency behaviour: callers that don't pass a type
 * read/write the primary type (typically slug `points`).
 *
 * @package WB_Gamification
 */
final class PointsEngine {

	/**
	 * Resolve a point-type input to a known slug. Centralised so every method
	 * shares the same back-compat fallback (= primary type).
	 *
	 * @param string|null $type Raw input.
	 */
	private static function resolve_type( ?string $type ): string {
		static $service = null;
		if ( null === $service ) {
			$service = new PointTypeService();
		}
		return $service->resolve( $type );
	}

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
		// Resolve the currency this action awards — rate-limit caps scope per-type so
		// e.g. a daily 3× cap on points doesn't block earning XP.
		$type = self::resolve_type( isset( $action['point_type'] ) ? (string) $action['point_type'] : null );

		// Cooldown check.
		$cooldown = (int) ( $action['cooldown'] ?? 0 );
		if ( $cooldown > 0 && self::is_on_cooldown( $user_id, $action_id, $cooldown, $type ) ) {
			return false;
		}

		// Repeatable check.
		if ( ! ( $action['repeatable'] ?? true ) && self::get_action_count( $user_id, $action_id, $type ) > 0 ) {
			return false;
		}

		// Daily cap check.
		$daily_cap = (int) ( $action['daily_cap'] ?? 0 );
		if ( $daily_cap > 0 && self::get_today_count( $user_id, $action_id, $type ) >= $daily_cap ) {
			return false;
		}

		// Weekly cap check.
		$weekly_cap = (int) ( $action['weekly_cap'] ?? 0 );
		if ( $weekly_cap > 0 && self::get_week_count( $user_id, $action_id, $type ) >= $weekly_cap ) {
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

		$type = self::resolve_type( $event->metadata['point_type'] ?? null );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_points',
			array(
				'event_id'   => $event->event_id,
				'user_id'    => $event->user_id,
				'action_id'  => $event->action_id,
				'points'     => $points,
				'point_type' => $type,
				'object_id'  => $event->object_id ?: null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			Log::error(
				'PointsEngine::insert_point_row — ledger insert failed',
				array(
					'user_id'    => $event->user_id,
					'action_id'  => $event->action_id,
					'points'     => $points,
					'point_type' => $type,
					'event_id'   => $event->event_id,
					'db_error'   => $wpdb->last_error,
				)
			);
			return false;
		}

		wp_cache_delete( self::cache_key_total( $event->user_id, $type ), 'wb_gamification' );

		return true;
	}

	/**
	 * Debit points from a user's balance.
	 *
	 * Inserts a negative row in the ledger. The caller is responsible for
	 * verifying the user has sufficient balance before calling this.
	 *
	 * @param int         $user_id   User to debit.
	 * @param int         $amount    Positive integer; stored as negative in the ledger.
	 * @param string      $action_id Action context label (e.g. 'redemption').
	 * @param string      $event_id  Optional UUID reference to a source event.
	 * @param string|null $type      Optional point-type slug. Defaults to primary type.
	 * @return bool                  True if the row was inserted.
	 */
	public static function debit( int $user_id, int $amount, string $action_id, string $event_id = '', ?string $type = null ): bool {
		global $wpdb;

		$type = self::resolve_type( $type );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_points',
			array(
				'event_id'   => $event_id ?: null,
				'user_id'    => $user_id,
				'action_id'  => $action_id,
				'points'     => -abs( $amount ),
				'point_type' => $type,
				'object_id'  => null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			Log::error(
				'PointsEngine::debit — ledger debit failed',
				array(
					'user_id'    => $user_id,
					'action_id'  => $action_id,
					'amount'     => $amount,
					'point_type' => $type,
					'event_id'   => $event_id,
					'db_error'   => $wpdb->last_error,
				)
			);
			return false;
		}

		wp_cache_delete( self::cache_key_total( $user_id, $type ), 'wb_gamification' );

		return true;
	}

	/**
	 * Cache key for a user/type balance lookup. Single source of truth so
	 * every read/write site uses the same key shape.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Resolved point-type slug.
	 */
	private static function cache_key_total( int $user_id, string $type ): string {
		return "wb_gam_total_{$user_id}_{$type}";
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
	public static function award( int $user_id, string $action_id, int $points, int $object_id = 0, ?string $type = null ): bool {
		if ( $points <= 0 || $user_id <= 0 ) {
			return false;
		}

		$metadata = array(
			'points' => $points,
			'manual' => true,
		);
		if ( null !== $type && '' !== $type ) {
			$metadata['point_type'] = self::resolve_type( $type );
		}

		return Engine::process(
			new Event(
				array(
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'object_id' => $object_id,
					'metadata'  => $metadata,
				)
			)
		);
	}

	// ── Read methods ──────────────────────────────────────────────────────────

	/**
	 * Get total points for a user, optionally scoped to a single point type.
	 *
	 * @param int         $user_id User ID to look up.
	 * @param string|null $type    Optional point-type slug. Defaults to primary type.
	 * @return int Total points balance.
	 */
	public static function get_total( int $user_id, ?string $type = null ): int {
		$type      = self::resolve_type( $type );
		$cache_key = self::cache_key_total( $user_id, $type );
		$cached    = wp_cache_get( $cache_key, 'wb_gamification' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0)
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND point_type = %s",
				$user_id,
				$type
			)
		);

		wp_cache_set( $cache_key, $total, 'wb_gamification', 300 );

		return $total;
	}

	/**
	 * Get every per-type balance for a user as a slug => total map.
	 *
	 * Single SQL aggregation across all types — used by Hub block, member
	 * profile, and the GET /members/{id}/points multi-currency response.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,int> Map of type-slug => integer balance.
	 */
	public static function get_totals_by_type( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate across types; result is small (one row per active type).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT point_type, COALESCE(SUM(points), 0) AS total
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d
				  GROUP BY point_type",
				$user_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['point_type'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Get how many times a user has performed a specific action.
	 *
	 * Pass `$type = null` (default) for all-type count; pass a slug to scope
	 * to one currency. Per-type scoping is what makes the non-repeatable
	 * check work correctly under multi-currency.
	 *
	 * @param int         $user_id   User ID to check.
	 * @param string      $action_id Action ID to count.
	 * @param string|null $type      Optional point-type filter. Null = all types.
	 * @return int Number of times the action has been performed.
	 */
	public static function get_action_count( int $user_id, string $action_id, ?string $type = null ): int {
		global $wpdb;
		if ( null !== $type && '' !== $type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
					 WHERE user_id = %d AND action_id = %s AND point_type = %s",
					$user_id,
					$action_id,
					$type
				)
			);
		}
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
	 * Pass `$type = null` (default) to return entries across every point type.
	 * Pass a specific slug to scope to one currency.
	 *
	 * @param int         $user_id User ID to look up.
	 * @param int         $limit   Maximum rows to return (1–100).
	 * @param string|null $type    Optional point-type filter. Null = all types.
	 * @return array<int, array{action_id: string, points: int, point_type: string, created_at: string}>
	 */
	public static function get_history( int $user_id, int $limit = 20, ?string $type = null ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		if ( null !== $type && '' !== $type ) {
			$resolved = self::resolve_type( $type );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, points, point_type, created_at
					   FROM {$wpdb->prefix}wb_gam_points
					  WHERE user_id = %d AND point_type = %s
					  ORDER BY created_at DESC
					  LIMIT %d",
					$user_id,
					$resolved,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT action_id, points, point_type, created_at
					   FROM {$wpdb->prefix}wb_gam_points
					  WHERE user_id = %d
					  ORDER BY created_at DESC
					  LIMIT %d",
					$user_id,
					$limit
				),
				ARRAY_A
			);
		}

		return $rows ?: array();
	}

	// ── Private rate-limit helpers ────────────────────────────────────────────

	/**
	 * Check whether a user is within the cooldown window for an action.
	 *
	 * Scoped by point_type so two currencies with the same action don't
	 * inherit each other's cooldown.
	 *
	 * @param int    $user_id          User to check.
	 * @param string $action_id        Action to check.
	 * @param int    $cooldown_seconds Cooldown duration in seconds.
	 * @param string $type             Resolved point-type slug.
	 * @return bool True if the user is still within the cooldown period.
	 */
	private static function is_on_cooldown( int $user_id, string $action_id, int $cooldown_seconds, string $type ): bool {
		global $wpdb;

		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s AND point_type = %s
				ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$action_id,
				$type
			)
		);

		if ( ! $last ) {
			return false;
		}

		// created_at is stored in site timezone via current_time('mysql'),
		// so compare using current_time('timestamp') for consistency.
		return ( current_time( 'timestamp' ) - strtotime( $last ) ) < $cooldown_seconds;
	}

	/**
	 * Count how many times a user has performed an action today (site timezone).
	 *
	 * Scoped by point_type — daily caps don't cross currencies.
	 *
	 * @param int    $user_id   User to check.
	 * @param string $action_id Action to count.
	 * @param string $type      Resolved point-type slug.
	 * @return int Number of times the action was performed today.
	 */
	private static function get_today_count( int $user_id, string $action_id, string $type ): int {
		global $wpdb;
		// Use range comparison so MySQL can use the idx_user_type_created index.
		// wp_date() returns times in the site timezone, matching current_time('mysql').
		$day_start = wp_date( 'Y-m-d 00:00:00' );
		$day_end   = wp_date( 'Y-m-d 00:00:00', strtotime( '+1 day' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s AND point_type = %s
				  AND created_at >= %s AND created_at < %s",
				$user_id,
				$action_id,
				$type,
				$day_start,
				$day_end
			)
		);
	}

	/**
	 * Count how many times a user has performed an action this ISO week.
	 *
	 * Scoped by point_type — weekly caps don't cross currencies.
	 *
	 * @param int    $user_id   User to check.
	 * @param string $action_id Action to count.
	 * @param string $type      Resolved point-type slug.
	 * @return int Number of times the action was performed this week.
	 */
	private static function get_week_count( int $user_id, string $action_id, string $type ): int {
		global $wpdb;
		// ISO week start: Monday 00:00:00 in site timezone.
		$week_start = wp_date( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points
				WHERE user_id = %d AND action_id = %s AND point_type = %s AND created_at >= %s",
				$user_id,
				$action_id,
				$type,
				$week_start
			)
		);
	}
}
