<?php
/**
 * WB Gamification: myCred importer.
 *
 * Reads myCred's ledger (`wp_myCRED_log`, verified against myCred 3.1.2) one keyset page at a time and
 * hands normalized rows to the ImportRunner. READ the source, WRITE only via our ingestion path.
 * Idempotent per source row (`mycred:log:{id}`).
 *
 * myCred specifics handled here:
 *   - `time` is a site-local wall-clock timestamp (bigint), not a real epoch.
 *   - `creds` already carries the signed delta (deductions are negative), so no sign inference.
 *   - a user's balance lives in user_meta under the point-type key (`ctype`); reconciliation sums
 *     those across every myCred point type.
 *   - decimal-configured myCred sites store fractional creds; WB points are integers, so fractional
 *     values are rounded and flagged as a mismatch by reconciliation rather than silently dropped.
 *   - badges are user_meta `mycred_badge{post_id}` and are paged by `umeta_id`.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Integrations\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads myCred ledger data for the ImportRunner.
 *
 * @package WB_Gamification
 */
final class MyCredImporter implements ImportSource {

	public const KEY_PREFIX   = 'mycred:log:';
	public const BADGE_PREFIX = 'mycred-badge-';

	/**
	 * Is myCred data present?
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'myCRED_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Point-type keys (ctypes) from myCred's `mycred_types` option.
	 *
	 * @return string[]
	 */
	private static function ctypes(): array {
		$types = get_option( 'mycred_types', array( 'mycred_default' => 'Points' ) );
		return is_array( $types ) ? array_keys( $types ) : array( 'mycred_default' );
	}

	/**
	 * Map a myCred ctype to a WB point-type slug (filterable).
	 *
	 * @param string $ctype myCred point-type key.
	 * @return string
	 */
	private static function map_point_type( string $ctype ): string {
		$service = new \WBGam\Services\PointTypeService();
		$known   = wp_list_pluck( $service->list(), 'slug' );
		$default = in_array( $ctype, $known, true ) ? $ctype : $service->default_slug();

		/**
		 * Filter the myCred ctype → WB point-type slug mapping.
		 *
		 * @since 1.6.2
		 * @param string   $default Resolved WB slug.
		 * @param string   $ctype   Source myCred ctype.
		 * @param string[] $known   WB point-type slugs.
		 */
		return (string) apply_filters( 'wb_gam_import_point_type_map', $default, $ctype, $known );
	}

	/**
	 * How many ledger rows will be imported (the rows the reader would return).
	 *
	 * @return int
	 */
	public static function count_points(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}myCRED_log WHERE user_id > 0 AND creds <> 0" );
	}

	/**
	 * One keyset page of the myCred ledger as normalized rows.
	 *
	 * @param int $after Ledger id to start strictly after.
	 * @param int $limit Maximum rows.
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read_points( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ref, ref_id, user_id, creds, ctype, time
				   FROM {$wpdb->prefix}myCRED_log
				  WHERE id > %d AND user_id > 0 AND creds <> 0
				  ORDER BY id ASC
				  LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( $logs as $log ) {
			$rows[] = array(
				'action_id'   => 'mycred_' . sanitize_key( (string) $log['ref'] ),
				'user_id'     => (int) $log['user_id'],
				// creds is the signed delta already; WB points are integers.
				'points'      => (int) round( (float) $log['creds'] ),
				'point_type'  => self::map_point_type( (string) $log['ctype'] ),
				'object_id'   => (int) $log['ref_id'],
				// myCred logs `time` as a site-local wall-clock timestamp, not a real epoch.
				'occurred_at' => get_gmt_from_date( gmdate( 'Y-m-d H:i:s', (int) $log['time'] ), 'Y-m-d\TH:i:s\Z' ),
				'source_key'  => self::KEY_PREFIX . (int) $log['id'],
				'metadata'    => array(
					'_source'      => 'mycred',
					'mycred_ref'   => (string) $log['ref'],
					'mycred_ctype' => (string) $log['ctype'],
				),
			);
		}

		return array(
			'rows' => $rows,
			'next' => count( $logs ) < $limit ? 0 : (int) end( $logs )['id'],
		);
	}

	/**
	 * How many badge awards myCred holds (an upper bound: rows that are not real badges are skipped on read).
	 *
	 * @return int
	 */
	public static function count_awards(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key REGEXP '^mycred_badge[0-9]+$'" );
	}

	/**
	 * One keyset page of earned badges, paged by `umeta_id`.
	 *
	 * Each earned badge is user_meta `mycred_badge{post_id}` = level (verified against
	 * mycred_get_users_badges), with the earned time in `mycred_badge{post_id}_issued_on`. Only the
	 * exact key shape matches: the `_ids` / `_issued_on` / `_requirement_` siblings are skipped.
	 *
	 * @param int $after umeta_id to start strictly after.
	 * @param int $limit Maximum usermeta rows scanned.
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read_awards( int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$metas = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, user_id, meta_key
				   FROM {$wpdb->usermeta}
				  WHERE umeta_id > %d AND meta_key REGEXP '^mycred_badge[0-9]+$'
				  ORDER BY umeta_id ASC
				  LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		// One query for the page's members' meta instead of one per badge below.
		update_meta_cache( 'user', array_values( array_unique( array_map( 'intval', wp_list_pluck( $metas, 'user_id' ) ) ) ) );

		$rows = array();
		foreach ( $metas as $meta ) {
			$post_id = (int) str_replace( 'mycred_badge', '', $meta['meta_key'] );
			if ( $post_id <= 0 || 'mycred_badge' !== get_post_type( $post_id ) ) {
				continue;
			}
			$issued = (int) get_user_meta( (int) $meta['user_id'], 'mycred_badge' . $post_id . '_issued_on', true );
			$rows[] = array(
				'user_id'   => (int) $meta['user_id'],
				'badge_id'  => self::BADGE_PREFIX . $post_id,
				'name'      => (string) get_the_title( $post_id ),
				'image'     => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				// myCred stamps _issued_on with time(), a real epoch; earned_at is UTC.
				'earned_at' => $issued > 0 ? gmdate( 'Y-m-d H:i:s', $issued ) : current_time( 'mysql', true ),
			);
		}

		return array(
			'rows' => $rows,
			// Filtered rows do not shorten the page: the cursor follows what was SCANNED.
			'next' => count( $metas ) < $limit ? 0 : (int) end( $metas )['umeta_id'],
		);
	}

	/**
	 * Rank tiers from `mycred_rank` posts, as level definitions.
	 *
	 * Ranks in myCred are point-based (`mycred_rank_min`), which maps directly to our
	 * point-threshold levels. A site has a handful, so this is not paged.
	 *
	 * @return array<int, array{name: string, min_points: int, order: int}>
	 */
	public static function read_ranks(): array {
		$ranks = get_posts(
			array(
				'post_type'   => 'mycred_rank',
				'numberposts' => -1,
				'post_status' => 'publish',
				'meta_key'    => 'mycred_rank_min', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'     => 'meta_value_num',
				'order'       => 'ASC',
			)
		);

		$out = array();
		foreach ( $ranks as $i => $rank ) {
			$out[] = array(
				'name'       => (string) $rank->post_title,
				'min_points' => (int) get_post_meta( $rank->ID, 'mycred_rank_min', true ),
				'order'      => (int) $i,
			);
		}
		return $out;
	}

	/**
	 * A member's balance summed across all myCred point types (rounded to int).
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_balance( int $user_id ): int {
		$total = 0.0;
		foreach ( self::ctypes() as $ctype ) {
			// myCred's OWN balance getter is the reconciliation authority; fall back to the raw meta
			// key only if the function is missing.
			if ( function_exists( 'mycred_get_users_balance' ) ) {
				$total += (float) mycred_get_users_balance( $user_id, $ctype );
			} else {
				$total += (float) get_user_meta( $user_id, $ctype, true );
			}
		}
		return (int) round( $total );
	}

	/**
	 * Count of a member's earned myCred badges (its authoritative meta store).
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_badge_count( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta}
				  WHERE user_id = %d AND meta_key REGEXP '^mycred_badge[0-9]+$'",
				$user_id
			)
		);
	}

	/**
	 * A member's current myCred rank name (from the `mycred_rank` meta).
	 *
	 * Read from myCred's authoritative store since its getter is not loadable here.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public static function source_rank_name( int $user_id ): string {
		$rank_id = (int) get_user_meta( $user_id, 'mycred_rank', true );
		return $rank_id > 0 ? (string) get_the_title( $rank_id ) : '';
	}
}
