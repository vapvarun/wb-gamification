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
	 * Registered GamiPress achievement-type slugs (the `achievement-type` CPT
	 * post names) — these are the `user_earnings.post_type` values that mean
	 * "earned an achievement" (as opposed to a step / points-award / rank row).
	 *
	 * @return string[]
	 */
	private static function achievement_type_slugs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				'achievement-type'
			)
		);
	}

	/**
	 * Build achievement-award records from `gamipress_user_earnings`.
	 *
	 * One record per earned achievement: a stable WB badge id
	 * (`gamipress-achievement-{post_id}`), the achievement title + featured
	 * image, and the earned date (for a backdated award).
	 *
	 * @return array<int, array{user_id:int, badge_id:string, name:string, image:string, earned_at:string, post_id:int}>
	 */
	public static function build_achievements(): array {
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_earning_id, title, user_id, post_id, date
				   FROM {$wpdb->prefix}gamipress_user_earnings
				  WHERE user_id > 0 AND post_type IN ($placeholders)
				  ORDER BY user_earning_id ASC",
				...$types
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$post_id = (int) $r['post_id'];
			$out[]   = array(
				'user_id'   => (int) $r['user_id'],
				'badge_id'  => 'gamipress-achievement-' . $post_id,
				'name'      => (string) $r['title'],
				'image'     => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				'earned_at' => (string) $r['date'],
				'post_id'   => $post_id,
			);
		}
		return $out;
	}

	/**
	 * Run the import (or preview it).
	 *
	 * @param bool $dry_run When true, build + reconcile but do not write.
	 * @return array<string, mixed> Ingestion counts plus a per-user reconciliation.
	 */
	public static function run( bool $dry_run = false ): array {
		$rows         = self::build_rows();
		$achievements = self::build_achievements();

		// Write FIRST (real run) so reconciliation can compare what actually
		// landed, not what we hoped would land.
		$ingest       = null;
		$ach_imported = 0;
		if ( ! $dry_run ) {
			$ingest = ImportService::ingest( $rows );
			foreach ( $achievements as $a ) {
				\WBGam\Engine\BadgeEngine::upsert_def(
					array(
						'id'        => $a['badge_id'],
						'name'      => $a['name'],
						'image_url' => $a['image'],
						'category'  => 'imported',
					)
				);
				$earned_at = gmdate( 'Y-m-d H:i:s', strtotime( $a['earned_at'] ) ?: time() );
				if ( \WBGam\Engine\BadgeEngine::award_badge( $a['user_id'], $a['badge_id'], $earned_at ) ) {
					++$ach_imported;
				}
			}
		}

		// POINTS reconciliation. Real run compares the sum that ACTUALLY landed
		// in our ledger (keyed by source_key) against GamiPress's own balance,
		// so a rejected/dropped row surfaces as a mismatch instead of hiding
		// behind an optimistic expected-sum. Dry run previews the expected sum.
		$reconcile = array();
		foreach ( self::user_ids( $rows ) as $uid ) {
			$ours              = $dry_run ? self::expected_points( $rows, $uid ) : self::our_imported_points( $uid );
			$source            = self::gamipress_balance( $uid );
			$reconcile[ $uid ] = array(
				'imported_sum'      => $ours,
				'gamipress_balance' => $source,
				'match'             => $ours === $source,
			);
		}

		// ACHIEVEMENT reconciliation: our imported badge count vs GamiPress's
		// own achievement count.
		$ach_reconcile = array();
		foreach ( self::user_ids( $achievements ) as $uid ) {
			$ours                  = $dry_run
				? count( array_filter( $achievements, static fn ( $a ) => (int) $a['user_id'] === $uid ) )
				: self::our_imported_badge_count( $uid );
			$own                   = function_exists( 'gamipress_get_user_achievements' )
				? count( (array) gamipress_get_user_achievements( array( 'user_id' => $uid ) ) )
				: $ours;
			$ach_reconcile[ $uid ] = array(
				'imported_achievements'  => (int) $ours,
				'gamipress_achievements' => (int) $own,
				'match'                  => (int) $ours === (int) $own,
			);
		}

		$result = array(
			'rows'                       => count( $rows ),
			'achievements'               => count( $achievements ),
			'dry_run'                    => $dry_run,
			'reconciliation'             => $reconcile,
			'achievement_reconciliation' => $ach_reconcile,
		);
		if ( ! $dry_run ) {
			$result['ingest']               = $ingest;
			$result['achievements_awarded'] = $ach_imported;
		}
		return $result;
	}

	/**
	 * Distinct user ids present in a set of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with a user_id key.
	 * @return int[]
	 */
	private static function user_ids( array $rows ): array {
		return array_values( array_unique( array_map( static fn ( $r ) => (int) $r['user_id'], $rows ) ) );
	}

	/**
	 * Expected point sum for a user from the built rows (dry-run preview).
	 *
	 * @param array<int, array<string, mixed>> $rows    Point rows.
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
	 * Sum of points that ACTUALLY landed in our ledger from a GamiPress import.
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
				'gamipress:log:%'
			)
		);
	}

	/**
	 * Count of imported GamiPress achievement badges a user actually holds.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	private static function our_imported_badge_count( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges
				  WHERE user_id = %d AND badge_id LIKE %s",
				$user_id,
				'gamipress-achievement-%'
			)
		);
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
