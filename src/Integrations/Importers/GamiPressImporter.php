<?php
/**
 * WB Gamification — GamiPress importer.
 *
 * Reads GamiPress's own point ledger (`wp_gamipress_logs`, verified against
 * GamiPress 7.9.5) and re-plays it into WB Gamification through the shared
 * ImportService — READ from the source, WRITE only via our ingestion path,
 * never a direct wb_gam_* insert. Each source log row carries a stable
 * source_key (`gamipress:log:{log_id}`) so a re-run is idempotent.
 *
 * Point-type mapping: each GamiPress points-type slug maps to a WB point-type
 * (default: the same slug if it exists here, else the site default). Balances
 * are reconciled after import: the sum of imported deltas per user must equal
 * GamiPress's own stored balance (`_gamipress_{type}_points`).
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Integrations\Importers;

use WBGam\Engine\ImportService;

defined( 'ABSPATH' ) || exit;

/**
 * GamiPress → WB Gamification migration.
 *
 * @package WB_Gamification
 */
final class GamiPressImporter {

	/**
	 * Is GamiPress data present to import?
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'gamipress_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Map a GamiPress points-type slug to a WB point-type slug.
	 *
	 * Uses the same slug when WB already defines it; otherwise the site
	 * default. Override per-site with the `wb_gam_import_point_type_map` filter.
	 *
	 * @param string $gp_slug GamiPress points-type slug.
	 * @return string WB point-type slug.
	 */
	private static function map_point_type( string $gp_slug ): string {
		$service = new \WBGam\Services\PointTypeService();
		$known   = wp_list_pluck( $service->list(), 'slug' );
		$default = in_array( $gp_slug, $known, true ) ? $gp_slug : $service->default_slug();

		/**
		 * Filter the GamiPress → WB point-type slug mapping.
		 *
		 * @since 1.6.2
		 * @param string   $default Resolved WB point-type slug.
		 * @param string   $gp_slug Source GamiPress slug.
		 * @param string[] $known   WB point-type slugs.
		 */
		return (string) apply_filters( 'wb_gam_import_point_type_map', $default, $gp_slug, $known );
	}

	/**
	 * Build normalized import rows from the GamiPress point ledger.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_rows(): array {
		global $wpdb;
		$rows = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = $wpdb->get_results(
			"SELECT log_id, user_id, type, trigger_type, points, points_type, date
			   FROM {$wpdb->prefix}gamipress_logs
			  WHERE type IN ('points_earn','points_award','points_deduct','points_revoke')
			    AND user_id > 0
			  ORDER BY log_id ASC",
			ARRAY_A
		);

		foreach ( (array) $logs as $log ) {
			$type  = (string) $log['type'];
			$delta = (int) $log['points'];
			// Deduct / revoke rows lower the balance.
			if ( in_array( $type, array( 'points_deduct', 'points_revoke' ), true ) ) {
				$delta = -abs( $delta );
			}
			if ( 0 === $delta ) {
				continue;
			}

			$rows[] = array(
				'action_id'   => 'gamipress_' . sanitize_key( (string) $log['trigger_type'] ),
				'user_id'     => (int) $log['user_id'],
				'points'      => $delta,
				'point_type'  => self::map_point_type( (string) $log['points_type'] ),
				'occurred_at' => (string) $log['date'],
				'source_key'  => 'gamipress:log:' . (int) $log['log_id'],
				'metadata'    => array(
					'_source'    => 'gamipress',
					'gp_type'    => $type,
					'gp_trigger' => (string) $log['trigger_type'],
				),
			);
		}

		return $rows;
	}

	/**
	 * Run the import (or preview it).
	 *
	 * @param bool $dry_run When true, build + reconcile but do not write.
	 * @return array<string, mixed> Ingestion counts plus a per-user reconciliation.
	 */
	public static function run( bool $dry_run = false ): array {
		$rows = self::build_rows();

		// Expected per-user imported delta, and GamiPress's own stored balance.
		$expected = array();
		foreach ( $rows as $row ) {
			$expected[ $row['user_id'] ] = ( $expected[ $row['user_id'] ] ?? 0 ) + (int) $row['points'];
		}

		$reconcile = array();
		foreach ( $expected as $uid => $sum ) {
			$gp_balance              = self::gamipress_balance( (int) $uid );
			$reconcile[ (int) $uid ] = array(
				'imported_sum'      => (int) $sum,
				'gamipress_balance' => $gp_balance,
				'match'             => (int) $sum === $gp_balance,
			);
		}

		$result = array(
			'rows'           => count( $rows ),
			'dry_run'        => $dry_run,
			'reconciliation' => $reconcile,
		);

		if ( ! $dry_run ) {
			$result['ingest'] = ImportService::ingest( $rows );
		}

		return $result;
	}

	/**
	 * A user's GamiPress balance, summed across every points type.
	 *
	 * Uses GamiPress's OWN getter (`gamipress_get_user_points`) as the
	 * authority so reconciliation is independent of how we read the source —
	 * exactly the cross-check that caught an earlier raw-meta miscount. Falls
	 * back to the exact per-slug balance meta only if the getter is absent.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function gamipress_balance( int $user_id ): int {
		$total = 0;
		foreach ( self::points_type_slugs() as $slug ) {
			if ( function_exists( 'gamipress_get_user_points' ) ) {
				$total += (int) gamipress_get_user_points( $user_id, $slug );
			} else {
				$total += (int) get_user_meta( $user_id, '_gamipress_' . $slug . '_points', true );
			}
		}
		return $total;
	}

	/**
	 * All registered GamiPress points-type slugs (the `points-type` CPT names).
	 *
	 * @return string[]
	 */
	private static function points_type_slugs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				'points-type'
			)
		);
	}
}
