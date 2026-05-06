<?php
/**
 * PointTypeConversionRepository
 *
 * Custom-table SQL ONLY for `wb_gam_point_type_conversions`. Admin defines
 * conversion pairs (e.g. 100 points → 1 coin); members convert via REST.
 *
 * Per the canonical Wbcom 7-layer architecture (`plan/ARCHITECTURE.md`),
 * Repository classes own DB queries — no business logic, no HTTP, no
 * rendering.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the conversion-rates catalogue.
 */
final class PointTypeConversionRepository {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_KEY   = 'point_type_conversions_all';
	private const CACHE_TTL   = 300;

	/**
	 * Return every active conversion rule.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function all_active(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bootstrapped table; cached above.
		$rows = $wpdb->get_results(
			"SELECT id, from_type, to_type, from_amount, to_amount, min_convert,
			        cooldown_seconds, max_per_day, is_active, created_at
			   FROM {$wpdb->prefix}wb_gam_point_type_conversions
			  WHERE is_active = 1
			  ORDER BY from_type ASC, to_type ASC",
			ARRAY_A
		) ?: array();

		wp_cache_set( self::CACHE_KEY, $rows, self::CACHE_GROUP, self::CACHE_TTL );

		return $rows;
	}

	/**
	 * Find a single rule by from + to slug pair.
	 *
	 * @param string $from From-type slug.
	 * @param string $to   To-type slug.
	 * @return array<string,mixed>|null
	 */
	public function find( string $from, string $to ): ?array {
		foreach ( $this->all_active() as $row ) {
			if ( $row['from_type'] === $from && $row['to_type'] === $to ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Find a rule by its ID (active or not).
	 *
	 * @param int $id Rule ID.
	 * @return array<string,mixed>|null
	 */
	public function find_by_id( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ID lookup; admin context, infrequent.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, from_type, to_type, from_amount, to_amount, min_convert,
				        cooldown_seconds, max_per_day, is_active, created_at
				   FROM {$wpdb->prefix}wb_gam_point_type_conversions
				  WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Insert a new conversion rule.
	 *
	 * @param array{from_type:string,to_type:string,from_amount:int,to_amount:int,min_convert?:int,cooldown_seconds?:int,max_per_day?:int} $data Sanitised input.
	 * @return int Inserted ID, or 0 on failure (e.g. duplicate pair).
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_point_type_conversions',
			array(
				'from_type'        => $data['from_type'],
				'to_type'          => $data['to_type'],
				'from_amount'      => max( 1, (int) $data['from_amount'] ),
				'to_amount'        => max( 1, (int) $data['to_amount'] ),
				'min_convert'      => max( 1, (int) ( $data['min_convert'] ?? 1 ) ),
				'cooldown_seconds' => max( 0, (int) ( $data['cooldown_seconds'] ?? 0 ) ),
				'max_per_day'      => max( 0, (int) ( $data['max_per_day'] ?? 0 ) ),
				'is_active'        => 1,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d' )
		);

		if ( $inserted ) {
			$this->flush_cache();
			return (int) $wpdb->insert_id;
		}
		return 0;
	}

	/**
	 * Update mutable fields on an existing rule. From/to slugs are
	 * pair-unique, immutable — admin must delete + recreate to change them.
	 *
	 * @param int                                                                                          $id   Rule ID.
	 * @param array{from_amount?:int,to_amount?:int,min_convert?:int,cooldown_seconds?:int,max_per_day?:int,is_active?:bool} $data Fields to update.
	 */
	public function update( int $id, array $data ): bool {
		$payload = array();
		$format  = array();

		$int_fields = array( 'from_amount', 'to_amount', 'min_convert', 'cooldown_seconds', 'max_per_day' );
		foreach ( $int_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$payload[ $field ] = max( 'min_convert' === $field || 'from_amount' === $field || 'to_amount' === $field ? 1 : 0, (int) $data[ $field ] );
				$format[]          = '%d';
			}
		}
		if ( array_key_exists( 'is_active', $data ) ) {
			$payload['is_active'] = $data['is_active'] ? 1 : 0;
			$format[]             = '%d';
		}
		if ( empty( $payload ) ) {
			return true;
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . 'wb_gam_point_type_conversions',
			$payload,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false !== $updated ) {
			$this->flush_cache();
			return true;
		}
		return false;
	}

	/**
	 * Delete a conversion rule by ID.
	 *
	 * @param int $id Rule ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'wb_gam_point_type_conversions',
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( $deleted ) {
			$this->flush_cache();
			return true;
		}
		return false;
	}

	/**
	 * Count how many conversions a user has performed today (site timezone)
	 * for a specific from→to pair. Used for max_per_day cap enforcement.
	 *
	 * Backed by `wb_gam_events` rows tagged with the synthetic action_id
	 * `convert_<from>_to_<to>` written by PointTypeConversionService.
	 *
	 * @param int    $user_id User to check.
	 * @param string $from    From-type slug.
	 * @param string $to      To-type slug.
	 * @return int
	 */
	public function count_today( int $user_id, string $from, string $to ): int {
		global $wpdb;

		$action_id = sprintf( 'convert_%s_to_%s', $from, $to );
		$day_start = wp_date( 'Y-m-d 00:00:00' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single small SUM; rate-limit check.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events
				WHERE user_id = %d AND action_id = %s AND created_at >= %s",
				$user_id,
				$action_id,
				$day_start
			)
		);
	}

	/**
	 * Latest conversion timestamp for a user/pair — used for cooldown checks.
	 *
	 * @param int    $user_id User to check.
	 * @param string $from    From-type slug.
	 * @param string $to      To-type slug.
	 * @return string|null MySQL datetime of last conversion, or null if none.
	 */
	public function last_conversion_at( int $user_id, string $from, string $to ): ?string {
		global $wpdb;

		$action_id = sprintf( 'convert_%s_to_%s', $from, $to );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single timestamp lookup.
		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$wpdb->prefix}wb_gam_events
				WHERE user_id = %d AND action_id = %s
				ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$action_id
			)
		);

		return $last ? (string) $last : null;
	}

	private function flush_cache(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
