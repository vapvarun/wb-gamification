<?php
/**
 * WB Gamification: what an import left in our tables, found by its key prefix.
 *
 * Every imported event carries `source_key = KEY_PREFIX . {source id}` and every imported badge
 * `BADGE_PREFIX . {source id}` (see ImportSource). Reconciliation, progress and undo all need to ask
 * "what did source X put here", and this is the one place that asks it, the same way each time, on the
 * indexes that make it a range scan: `uniq_source_key` on events, `idx_badge_id` on user_badges.
 *
 * A prefix is matched with LIKE 'prefix%' (escaped), which MySQL answers as an index range. It must
 * never be matched with a leading wildcard.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix-keyed reads of imported data.
 *
 * @package WB_Gamification
 */
final class ImportLedger {

	/**
	 * A LIKE pattern that matches every key starting with the prefix.
	 *
	 * @param string $prefix Key prefix, e.g. `mycred:log:`.
	 * @return string
	 */
	public static function like( string $prefix ): string {
		global $wpdb;
		return $wpdb->esc_like( $prefix ) . '%';
	}

	/**
	 * How many imported events exist for a source (a range count on uniq_source_key).
	 *
	 * @param string $key_prefix Event key prefix.
	 * @return int
	 */
	public static function count_events( string $key_prefix ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events WHERE source_key LIKE %s",
				self::like( $key_prefix )
			)
		);
	}

	/**
	 * How many imported badge awards exist for a source (a range count on idx_badge_id).
	 *
	 * @param string $badge_prefix Badge id prefix.
	 * @return int
	 */
	public static function count_badges( string $badge_prefix ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id LIKE %s",
				self::like( $badge_prefix )
			)
		);
	}

	/**
	 * The points that ACTUALLY landed in our ledger for one member from a source.
	 *
	 * Reads what was stored, not what was meant to be: a row the ingest dropped cannot hide.
	 *
	 * @param int    $user_id    Member.
	 * @param string $key_prefix Event key prefix.
	 * @return int
	 */
	public static function points( int $user_id, string $key_prefix ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(p.points),0)
				   FROM {$wpdb->prefix}wb_gam_points p
				   JOIN {$wpdb->prefix}wb_gam_events e ON e.id = p.event_id
				  WHERE p.user_id = %d AND e.source_key LIKE %s",
				$user_id,
				self::like( $key_prefix )
			)
		);
	}

	/**
	 * How many imported badges one member holds.
	 *
	 * @param int    $user_id      Member.
	 * @param string $badge_prefix Badge id prefix.
	 * @return int
	 */
	public static function member_badges( int $user_id, string $badge_prefix ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id = %d AND badge_id LIKE %s",
				$user_id,
				self::like( $badge_prefix )
			)
		);
	}

	/**
	 * One keyset page of the members an import touched, in user-id order.
	 *
	 * Driven from wb_gam_user_totals (primary key user_id) with an EXISTS into the events table on
	 * (user_id, ...): bounded by the number of members, never by the number of imported events.
	 *
	 * @param string $key_prefix Event key prefix.
	 * @param int    $after      User id to start strictly after.
	 * @param int    $limit      Maximum members.
	 * @return int[] User ids, ascending.
	 */
	public static function touched_users( string $key_prefix, int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT ut.user_id
				   FROM {$wpdb->prefix}wb_gam_user_totals ut
				  WHERE ut.user_id > %d
				    AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}wb_gam_events e
				                  WHERE e.user_id = ut.user_id AND e.source_key LIKE %s )
				  ORDER BY ut.user_id ASC
				  LIMIT %d",
				$after,
				self::like( $key_prefix ),
				$limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}
}
