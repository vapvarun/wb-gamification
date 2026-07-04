<?php
/**
 * WB Gamification — myCred importer.
 *
 * Reads myCred's ledger (`wp_myCRED_log`, verified against myCred 3.1.2) and
 * re-plays it through the shared ImportService. READ the source, WRITE only
 * via our ingestion path. Idempotent per source row (`mycred:log:{id}`).
 *
 * myCred specifics handled here:
 *   - `time` is a Unix timestamp (bigint), not a datetime.
 *   - `creds` already carries the signed delta (deductions are negative), so
 *     no sign inference is needed.
 *   - a user's balance lives in user_meta under the point-type key (`ctype`);
 *     reconciliation sums those across every myCred point type.
 *   - decimal-configured myCred sites store fractional creds; WB points are
 *     integers, so fractional values are rounded and flagged as a mismatch by
 *     reconciliation rather than silently dropped.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Integrations\Importers;

use WBGam\Engine\ImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates myCred ledger data into WB Gamification.
 *
 * @package WB_Gamification
 */
final class MyCredImporter {

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
	 * Build normalized rows from the myCred ledger.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_rows(): array {
		global $wpdb;
		$rows = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = $wpdb->get_results(
			"SELECT id, ref, ref_id, user_id, creds, ctype, time
			   FROM {$wpdb->prefix}myCRED_log
			  WHERE user_id > 0 AND creds <> 0
			  ORDER BY id ASC",
			ARRAY_A
		);

		foreach ( (array) $logs as $log ) {
			$rows[] = array(
				'action_id'   => 'mycred_' . sanitize_key( (string) $log['ref'] ),
				'user_id'     => (int) $log['user_id'],
				// creds is the signed delta already; WB points are integers.
				'points'      => (int) round( (float) $log['creds'] ),
				'point_type'  => self::map_point_type( (string) $log['ctype'] ),
				'object_id'   => (int) $log['ref_id'],
				'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $log['time'] ),
				'source_key'  => 'mycred:log:' . (int) $log['id'],
				'metadata'    => array(
					'_source'      => 'mycred',
					'mycred_ref'   => (string) $log['ref'],
					'mycred_ctype' => (string) $log['ctype'],
				),
			);
		}

		return $rows;
	}

	/**
	 * Run (or preview) the import with per-user reconciliation.
	 *
	 * @param bool $dry_run Preview only.
	 * @return array<string, mixed>
	 */
	public static function run( bool $dry_run = false ): array {
		$rows = self::build_rows();

		// Write FIRST so reconciliation compares what ACTUALLY landed.
		$ingest = null;
		if ( ! $dry_run ) {
			$ingest = ImportService::ingest( $rows );
		}

		$user_ids  = array_values( array_unique( array_map( static fn ( $r ) => (int) $r['user_id'], $rows ) ) );
		$reconcile = array();
		foreach ( $user_ids as $uid ) {
			// Real run: the sum that actually landed in our ledger (a dropped
			// row can't hide). Dry run: the expected sum.
			$ours              = $dry_run ? self::expected_points( $rows, $uid ) : self::our_imported_points( $uid );
			$balance           = self::mycred_balance( $uid );
			$reconcile[ $uid ] = array(
				'imported_sum'   => $ours,
				'mycred_balance' => $balance,
				'match'          => $ours === $balance,
			);
		}

		$result = array(
			'rows'           => count( $rows ),
			'dry_run'        => $dry_run,
			'reconciliation' => $reconcile,
		);
		if ( ! $dry_run ) {
			$result['ingest'] = $ingest;
		}
		return $result;
	}

	/**
	 * Expected point sum for a user from the built rows (dry-run preview).
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param int                              $user_id User.
	 * @return int
	 */
	private static function expected_points( array $rows, int $user_id ): int {
		$sum = 0;
		foreach ( $rows as $r ) {
			if ( (int) $r['user_id'] === $user_id ) {
				$sum += (int) $r['points'];
			}
		}
		return $sum;
	}

	/**
	 * Sum of points that ACTUALLY landed in our ledger from a myCred import.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	private static function our_imported_points( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(p.points),0)
				   FROM {$wpdb->prefix}wb_gam_points p
				   JOIN {$wpdb->prefix}wb_gam_events e ON e.id = p.event_id
				  WHERE p.user_id = %d AND e.source_key LIKE %s",
				$user_id,
				'mycred:log:%'
			)
		);
	}

	/**
	 * A user's myCred balance summed across all point types (rounded to int).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function mycred_balance( int $user_id ): int {
		$total = 0.0;
		foreach ( self::ctypes() as $ctype ) {
			// Use myCred's OWN balance getter as the reconciliation authority;
			// fall back to the raw meta key only if the function is missing.
			if ( function_exists( 'mycred_get_users_balance' ) ) {
				$total += (float) mycred_get_users_balance( $user_id, $ctype );
			} else {
				$total += (float) get_user_meta( $user_id, $ctype, true );
			}
		}
		return (int) round( $total );
	}
}
