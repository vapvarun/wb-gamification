<?php
/**
 * WB Gamification Badge Engine
 *
 * Evaluates badge conditions after every point award and auto-awards
 * badges when conditions are met.
 *
 * Condition types (stored as JSON in wb_gam_rules.rule_config):
 *
 *   point_milestone  — user's cumulative points >= threshold
 *     { "condition_type": "point_milestone", "points": 100 }
 *
 *   action_count     — user has performed action >= N times
 *     { "condition_type": "action_count", "action_id": "wp_publish_post", "count": 10 }
 *
 *   admin_awarded    — manual only; never auto-evaluates
 *     { "condition_type": "admin_awarded" }
 *
 * Custom condition types can be registered via the
 * `wb_gamification_badge_condition` filter.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class BadgeEngine {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_TTL   = 60; // seconds

	/**
	 * Boot — hook into the points-awarded action to evaluate conditions.
	 */
	public static function init(): void {
		add_action( 'wb_gamification_points_awarded', [ __CLASS__, 'evaluate_on_award' ], 10, 3 );
	}

	// ── Award pipeline ─────────────────────────────────────────────────────────

	/**
	 * Evaluate all badge conditions after a point award.
	 *
	 * Optimised to load all conditions in one query and all earned badge IDs in
	 * one query, so the inner loop runs in-memory without N+1 DB round-trips.
	 *
	 * @param int   $user_id User who just earned points.
	 * @param Event $event   The event that triggered the award.
	 * @param int   $points  Points awarded.
	 */
	public static function evaluate_on_award( int $user_id, Event $event, int $points ): void {
		global $wpdb;

		// Load all active badge conditions — typically ~30 rows.
		$rules = $wpdb->get_results(
			"SELECT target_id AS badge_id, rule_config
			   FROM {$wpdb->prefix}wb_gam_rules
			  WHERE rule_type = 'badge_condition' AND is_active = 1",
			ARRAY_A
		);

		if ( empty( $rules ) ) {
			return;
		}

		// Load earned badge IDs in one query for in-memory filtering.
		$earned = self::get_user_earned_badge_ids( $user_id );
		$total  = PointsEngine::get_total( $user_id );

		foreach ( $rules as $rule ) {
			if ( in_array( $rule['badge_id'], $earned, true ) ) {
				continue; // Already earned — skip.
			}

			$config = json_decode( $rule['rule_config'], true );
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( self::evaluate_condition( $config, $user_id, $event, $total ) ) {
				if ( self::award_badge( $user_id, $rule['badge_id'] ) ) {
					// Add to in-memory list to prevent re-awarding if the same
					// badge_id appears in more than one rule row.
					$earned[] = $rule['badge_id'];
				}
			}
		}
	}

	/**
	 * Evaluate a single badge condition.
	 *
	 * @param array  $config  Decoded condition config.
	 * @param int    $user_id User being evaluated.
	 * @param Event  $event   Current event.
	 * @param int    $total   User's current point total (pre-fetched to avoid N+1).
	 * @return bool           True if the condition is met.
	 */
	private static function evaluate_condition( array $config, int $user_id, Event $event, int $total ): bool {
		$type = $config['condition_type'] ?? '';

		switch ( $type ) {

			case 'point_milestone':
				return $total >= (int) ( $config['points'] ?? 0 );

			case 'action_count':
				$action_id = $config['action_id'] ?? '';
				$required  = max( 1, (int) ( $config['count'] ?? 1 ) );
				// Fast path: first-time badges only trigger on the matching action.
				if ( 1 === $required && $event->action_id !== $action_id ) {
					return false;
				}
				return PointsEngine::get_action_count( $user_id, $action_id ) >= $required;

			case 'admin_awarded':
				return false; // Manual grants only; never auto-evaluates.

			default:
				/**
				 * Allow extensions to handle custom badge condition types.
				 *
				 * @param bool   $result  Whether the condition is met. Default false.
				 * @param string $type    Condition type string.
				 * @param array  $config  Full condition config.
				 * @param int    $user_id User being evaluated.
				 * @param Event  $event   Current event.
				 * @param int    $total   Current point total.
				 */
				return (bool) apply_filters(
					'wb_gamification_badge_condition',
					false,
					$type,
					$config,
					$user_id,
					$event,
					$total
				);
		}
	}

	// ── Public award / read API ────────────────────────────────────────────────

	/**
	 * Award a badge to a user.
	 *
	 * Idempotent — returns false if the user already holds the badge.
	 *
	 * @param int    $user_id  User to award.
	 * @param string $badge_id Badge ID (matches wb_gam_badge_defs.id).
	 * @return bool            True if the badge was newly awarded.
	 */
	public static function award_badge( int $user_id, string $badge_id ): bool {
		if ( self::has_badge( $user_id, $badge_id ) ) {
			return false;
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_user_badges',
			[
				'user_id'   => $user_id,
				'badge_id'  => $badge_id,
				'earned_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return false;
		}

		// Bust earned-badges cache.
		wp_cache_delete( "wb_gam_earned_badges_{$user_id}", self::CACHE_GROUP );

		$def = self::get_badge_def( $badge_id );

		/**
		 * Fires when a member earns a badge.
		 *
		 * @param int        $user_id  User who earned the badge.
		 * @param string     $badge_id Badge identifier.
		 * @param array|null $def      Badge definition row, or null if not found.
		 */
		do_action( 'wb_gamification_badge_awarded', $user_id, $badge_id, $def );

		// Dispatch outbound webhook.
		WebhookDispatcher::dispatch(
			'badge_awarded',
			$user_id,
			null,
			0,
			[
				'badge_id'   => $badge_id,
				'badge_name' => $def ? $def['name'] : $badge_id,
			]
		);

		return true;
	}

	/**
	 * Check whether a user currently holds a badge.
	 *
	 * @param int    $user_id  User to check.
	 * @param string $badge_id Badge identifier.
	 * @return bool
	 */
	public static function has_badge( int $user_id, string $badge_id ): bool {
		return in_array( $badge_id, self::get_user_earned_badge_ids( $user_id ), true );
	}

	/**
	 * Get all earned badge IDs for a user (single query, object-cache backed).
	 *
	 * @param int $user_id User to look up.
	 * @return string[]   Array of badge_id strings.
	 */
	public static function get_user_earned_badge_ids( int $user_id ): array {
		$cache_key = "wb_gam_earned_badges_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT badge_id FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id = %d",
				$user_id
			)
		);

		$ids = array_values( $ids ?: [] );
		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::CACHE_TTL );

		return $ids;
	}

	/**
	 * Get earned badges with full definition data for a user.
	 *
	 * @param int $user_id User to look up.
	 * @return array<int, array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, category: string, earned_at: string}>
	 */
	public static function get_user_badges( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.name, b.description, b.image_url,
				        b.is_credential, b.category, ub.earned_at
				   FROM {$wpdb->prefix}wb_gam_user_badges ub
				   JOIN {$wpdb->prefix}wb_gam_badge_defs b ON b.id = ub.badge_id
				  WHERE ub.user_id = %d
				  ORDER BY ub.earned_at DESC",
				$user_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return [
					'id'            => $row['id'],
					'name'          => $row['name'],
					'description'   => $row['description'],
					'image_url'     => $row['image_url'] ?: null,
					'is_credential' => (bool) $row['is_credential'],
					'category'      => $row['category'],
					'earned_at'     => $row['earned_at'],
				];
			},
			$rows ?: []
		);
	}

	/**
	 * Get a single badge definition.
	 *
	 * @param string $badge_id Badge identifier.
	 * @return array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, category: string}|null
	 */
	public static function get_badge_def( string $badge_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, image_url, is_credential, category
				   FROM {$wpdb->prefix}wb_gam_badge_defs
				  WHERE id = %s",
				$badge_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'id'            => $row['id'],
			'name'          => $row['name'],
			'description'   => $row['description'],
			'image_url'     => $row['image_url'] ?: null,
			'is_credential' => (bool) $row['is_credential'],
			'category'      => $row['category'],
		];
	}

	/**
	 * Get all badge definitions with earned status for a user.
	 *
	 * Unearned badges are included (greyed-out in UI) so members can see
	 * what to work toward — the "locked but visible" forward motivation model.
	 *
	 * @param int $user_id User whose earned status to check. 0 = skip earned check.
	 * @return array<int, array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, category: string, earned: bool, earned_at: string|null}>
	 */
	public static function get_all_badges_for_user( int $user_id = 0 ): array {
		global $wpdb;

		$defs = $wpdb->get_results(
			"SELECT id, name, description, image_url, is_credential, category
			   FROM {$wpdb->prefix}wb_gam_badge_defs
			  ORDER BY category, name",
			ARRAY_A
		);

		if ( empty( $defs ) ) {
			return [];
		}

		// Build earned-at map in one query.
		$earned_at_map = [];
		if ( $user_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT badge_id, earned_at FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id = %d",
					$user_id
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$earned_at_map[ $row['badge_id'] ] = $row['earned_at'];
			}
		}

		return array_map(
			static function ( array $def ) use ( $earned_at_map ): array {
				$earned_at = $earned_at_map[ $def['id'] ] ?? null;
				return [
					'id'            => $def['id'],
					'name'          => $def['name'],
					'description'   => $def['description'],
					'image_url'     => $def['image_url'] ?: null,
					'is_credential' => (bool) $def['is_credential'],
					'category'      => $def['category'],
					'earned'        => null !== $earned_at,
					'earned_at'     => $earned_at,
				];
			},
			$defs
		);
	}
}
