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
	 * Does this site's `gamipress_logs` carry the points columns (6.9.4+), or the legacy meta rows?
	 *
	 * Asked of the SCHEMA, not of a version string: a plugin version tells you what the code is, not
	 * what the database survived. Sites get upgraded, downgraded, restored from old dumps and migrated
	 * between hosts, and the table is the only thing that knows the truth.
	 *
	 * @return bool True when `points` exists as a column.
	 */
	private static function logs_have_points_column(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}gamipress_logs" );

		return in_array( 'points', $columns, true );
	}

	/**
	 * Build normalized import rows from the GamiPress point ledger.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_rows(): array {
		global $wpdb;
		$rows = array();

		// GamiPress moved `points` and `points_type` from the log META table into COLUMNS on the logs
		// table in 6.9.4. Both shapes are alive in the wild, and a site still on the old one is exactly
		// the kind of stale install that wants to migrate away.
		//
		// Reading the columns unconditionally did not fail loudly on an older site: MySQL rejected the
		// query, $wpdb swallowed the error, get_results() returned null, and the import reported
		// `rows: 0` with HTTP 200. The owner was told their migration had SUCCEEDED and imported
		// nothing -- their entire points history skipped, with nothing to explain why.
		//
		// So ask the schema which shape this site has, and read that one.
		$modern = self::logs_have_points_column();

		$select = $modern
			? 'l.points AS points, l.points_type AS points_type'
			: "( SELECT m.meta_value FROM {$wpdb->prefix}gamipress_logs_meta m
			      WHERE m.log_id = l.log_id AND m.meta_key = '_gamipress_points' LIMIT 1 ) AS points,
			   ( SELECT m2.meta_value FROM {$wpdb->prefix}gamipress_logs_meta m2
			      WHERE m2.log_id = l.log_id AND m2.meta_key = '_gamipress_points_type' LIMIT 1 ) AS points_type";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = $wpdb->get_results(
			"SELECT l.log_id, l.user_id, l.type, l.trigger_type, {$select}, l.date
			   FROM {$wpdb->prefix}gamipress_logs l
			  WHERE l.type IN ('points_earn','points_award','points_deduct','points_revoke')
			    AND l.user_id > 0
			  ORDER BY l.log_id ASC",
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
	 * Registered GamiPress rank-type slugs (the `rank-type` CPT post names).
	 *
	 * @return string[]
	 */
	private static function rank_type_slugs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				'rank-type'
			)
		);
	}

	/**
	 * Points required to REACH a GamiPress rank.
	 *
	 * GamiPress stores a rank's "reach minimum points" threshold on its
	 * `rank-requirement` child posts (`_gamipress_points_required`); some
	 * setups also stamp it on the rank itself. Read both and take the max so
	 * the WB level threshold matches whatever the source used. The base rank
	 * has no requirement → 0.
	 *
	 * @param int $rank_id Rank post ID.
	 * @return int
	 */
	private static function rank_min_points( int $rank_id ): int {
		$points = (int) get_post_meta( $rank_id, '_gamipress_points_required', true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$req_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_type = 'rank-requirement' AND post_status = 'publish' AND post_parent = %d",
				$rank_id
			)
		);
		foreach ( $req_ids as $rid ) {
			$points = max( $points, (int) get_post_meta( (int) $rid, '_gamipress_points_required', true ) );
		}
		return max( 0, $points );
	}

	/**
	 * Build rank tiers (as WB level definitions) from GamiPress rank posts.
	 *
	 * @return array<int, array{id:int, name:string, min_points:int, order:int, type:string}>
	 */
	public static function build_ranks(): array {
		$types = self::rank_type_slugs();
		if ( empty( $types ) ) {
			return array();
		}
		$ranks = get_posts(
			array(
				'post_type'   => $types,
				'numberposts' => -1,
				'post_status' => 'publish',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);

		$out = array();
		foreach ( $ranks as $i => $rank ) {
			$out[] = array(
				'id'         => (int) $rank->ID,
				'name'       => (string) $rank->post_title,
				'min_points' => self::rank_min_points( (int) $rank->ID ),
				'order'      => (int) $i,
				'type'       => (string) $rank->post_type,
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
		$ranks        = self::build_ranks();

		// Write FIRST (real run) so reconciliation can compare what actually
		// landed, not what we hoped would land.
		$ingest       = null;
		$ach_imported = 0;
		$levels_made  = 0;
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
			// Ranks → WB levels: recreate each tier (name + threshold). Members
			// then land at the matching level from their imported points, since
			// our levels are point-derived on read.
			foreach ( $ranks as $r ) {
				if ( \WBGam\Engine\LevelEngine::upsert_level( $r['name'], $r['min_points'], $r['order'] ) > 0 ) {
					++$levels_made;
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
			$ours = $dry_run
				? count( array_filter( $achievements, static fn ( $a ) => (int) $a['user_id'] === $uid ) )
				: self::our_imported_badge_count( $uid );
			// Filter GamiPress's getter to achievement types ONLY — unfiltered
			// it also counts rank earnings, which we migrate as levels, not
			// badges (that inflated the source count and hid a false match).
			$own                   = function_exists( 'gamipress_get_user_achievements' )
				? count(
					(array) gamipress_get_user_achievements(
						array(
							'user_id'          => $uid,
							'achievement_type' => self::achievement_type_slugs(),
						)
					)
				)
				: $ours;
			$ach_reconcile[ $uid ] = array(
				'imported_achievements'  => (int) $ours,
				'gamipress_achievements' => (int) $own,
				'match'                  => (int) $ours === (int) $own,
			);
		}

		// RANK reconciliation. For each user who holds a GamiPress rank, the WB
		// level derived from their IMPORTED points (isolating any pre-existing
		// WB points on the target) must equal their GamiPress rank name.
		$rank_reconcile = array();
		if ( ! empty( $ranks ) ) {
			foreach ( self::user_ids( $rows ) as $uid ) {
				$gp_rank = self::gamipress_user_rank_name( $uid );
				if ( '' === $gp_rank ) {
					continue;
				}
				$points                 = $dry_run ? self::expected_points( $rows, $uid ) : self::our_imported_points( $uid );
				$our_level              = $dry_run
					? self::tier_name_for_points( $ranks, $points )
					: ( \WBGam\Engine\LevelEngine::get_level_for_points( $points )['name'] ?? '' );
				$rank_reconcile[ $uid ] = array(
					'our_level'      => (string) $our_level,
					'gamipress_rank' => $gp_rank,
					'match'          => (string) $our_level === $gp_rank,
				);
			}
		}

		$result = array(
			'rows'                       => count( $rows ),
			'achievements'               => count( $achievements ),
			'ranks'                      => count( $ranks ),
			'dry_run'                    => $dry_run,
			'reconciliation'             => $reconcile,
			'achievement_reconciliation' => $ach_reconcile,
			'rank_reconciliation'        => $rank_reconcile,
		);
		if ( ! $dry_run ) {
			$result['ingest']               = $ingest;
			$result['achievements_awarded'] = $ach_imported;
			$result['levels_created']       = $levels_made;
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
	 * A user's current GamiPress rank name (highest across rank types).
	 *
	 * @param int $user_id User.
	 * @return string Rank title, or '' if none.
	 */
	private static function gamipress_user_rank_name( int $user_id ): string {
		if ( ! function_exists( 'gamipress_get_user_rank' ) ) {
			return '';
		}
		$name = '';
		foreach ( self::rank_type_slugs() as $type ) {
			$rank = gamipress_get_user_rank( $user_id, $type );
			if ( $rank instanceof \WP_Post && '' !== $rank->post_title ) {
				$name = $rank->post_title;
			}
		}
		return $name;
	}

	/**
	 * The tier name a point total maps to (dry-run preview of the derived level).
	 *
	 * @param array<int, array{name:string, min_points:int}> $ranks  Tiers.
	 * @param int                                            $points Point total.
	 * @return string
	 */
	private static function tier_name_for_points( array $ranks, int $points ): string {
		$name = '';
		$best = -1;
		foreach ( $ranks as $r ) {
			if ( $points >= (int) $r['min_points'] && (int) $r['min_points'] >= $best ) {
				$best = (int) $r['min_points'];
				$name = (string) $r['name'];
			}
		}
		return $name;
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
