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
	 * Build myCred badge-award records from user meta.
	 *
	 * Each earned badge is stored by myCred as user_meta `mycred_badge{post_id}`
	 * = level (verified against mycred_get_users_badges), with the earned time
	 * in `mycred_badge{post_id}_issued_on`. We match the exact key shape and
	 * skip the `_ids` / `_issued_on` / `_requirement_` siblings.
	 *
	 * @return array<int, array{user_id:int, badge_id:string, name:string, image:string, earned_at:string, post_id:int}>
	 */
	public static function build_badges(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_key FROM {$wpdb->usermeta}
			  WHERE meta_key REGEXP '^mycred_badge[0-9]+$'",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$post_id = (int) str_replace( 'mycred_badge', '', $r['meta_key'] );
			if ( $post_id <= 0 || 'mycred_badge' !== get_post_type( $post_id ) ) {
				continue;
			}
			$issued = (int) get_user_meta( (int) $r['user_id'], 'mycred_badge' . $post_id . '_issued_on', true );
			$out[]  = array(
				'user_id'   => (int) $r['user_id'],
				'badge_id'  => 'mycred-badge-' . $post_id,
				'name'      => (string) get_the_title( $post_id ),
				'image'     => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				// earned_at is a SITE-LOCAL column (BadgeEngine::award_badge writes it with
				// current_time('mysql')), and $issued is a real Unix epoch -- so it has to be formatted in
				// the site's timezone, not UTC. gmdate() here wrote a UTC wall clock into a local column:
				// one column, two clocks, and badge-showcase then reported an imported badge as earned
				// hours off -- in the future, on any site ahead of UTC.
				'earned_at' => $issued > 0 ? wp_date( 'Y-m-d H:i:s', $issued ) : current_time( 'mysql' ),
				'post_id'   => $post_id,
			);
		}
		return $out;
	}

	/**
	 * Build rank tiers (as WB level defs) from myCred `mycred_rank` posts.
	 *
	 * Ranks in myCred are point-based (`mycred_rank_min`), which maps directly
	 * to our point-threshold levels.
	 *
	 * @return array<int, array{id:int, name:string, min_points:int, order:int}>
	 */
	public static function build_ranks(): array {
		$ranks = get_posts(
			array(
				'post_type'   => 'mycred_rank',
				'numberposts' => -1,
				'post_status' => 'publish',
				'meta_key'    => 'mycred_rank_min',
				'orderby'     => 'meta_value_num',
				'order'       => 'ASC',
			)
		);
		$out   = array();
		foreach ( $ranks as $i => $rank ) {
			$out[] = array(
				'id'         => (int) $rank->ID,
				'name'       => (string) $rank->post_title,
				'min_points' => (int) get_post_meta( $rank->ID, 'mycred_rank_min', true ),
				'order'      => (int) $i,
			);
		}
		return $out;
	}

	/**
	 * A user's current myCred rank name (from the `mycred_rank` meta), read
	 * from myCred's authoritative store since its getter isn't loadable here.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function mycred_user_rank_name( int $user_id ): string {
		$rank_id = (int) get_user_meta( $user_id, 'mycred_rank', true );
		return $rank_id > 0 ? (string) get_the_title( $rank_id ) : '';
	}

	/**
	 * Count of a user's earned myCred badges (its authoritative meta store).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	private static function mycred_badge_count( int $user_id ): int {
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
	 * Count of imported myCred badges a user actually holds in WB.
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
				'mycred-badge-%'
			)
		);
	}

	/**
	 * The tier name a point total maps to (dry-run preview).
	 *
	 * @param array<int, array{name:string, min_points:int}> $ranks  Tiers.
	 * @param int                                            $points Total.
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
	 * Run (or preview) the import with per-user reconciliation.
	 *
	 * @param bool $dry_run Preview only.
	 * @return array<string, mixed>
	 */
	public static function run( bool $dry_run = false ): array {
		$rows   = self::build_rows();
		$badges = self::build_badges();
		$ranks  = self::build_ranks();

		// Write FIRST so reconciliation compares what ACTUALLY landed.
		$ingest        = null;
		$badge_awarded = 0;
		$levels_made   = 0;
		if ( ! $dry_run ) {
			$ingest = ImportService::ingest( $rows );
			foreach ( $badges as $b ) {
				\WBGam\Engine\BadgeEngine::upsert_def(
					array(
						'id'        => $b['badge_id'],
						'name'      => $b['name'],
						'image_url' => $b['image'],
						'category'  => 'imported',
					)
				);
				// The strtotime()/gmdate() round trip PRESERVES the source's wall clock (parse-as-UTC then
				// format-as-UTC is an identity), which is what we want: the source stored a local time and
				// we keep it. But the FALLBACK was time() -- a real epoch -- which gmdate() then renders as
				// a UTC wall clock into a site-local column. current_time('mysql') is the site's now.
				$earned_at = $b['earned_at'] ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $b['earned_at'] ) ) : current_time( 'mysql' );
				if ( \WBGam\Engine\BadgeEngine::award_badge( $b['user_id'], $b['badge_id'], $earned_at ) ) {
					++$badge_awarded;
				}
			}
			foreach ( $ranks as $r ) {
				// Count what was CREATED, not what was found. upsert_level() returns an id either way, so
				// counting `> 0` reported levels the import had not built -- on a re-run it claimed
				// `levels_created: 1` while the database gained nothing.
				$level_created = false;
				\WBGam\Engine\LevelEngine::upsert_level( $r['name'], $r['min_points'], $r['order'], '', $level_created );
				if ( $level_created ) {
					++$levels_made;
				}
			}
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

		// BADGE reconciliation: our imported count vs myCred's earned-badge meta.
		$badge_reconcile = array();
		foreach ( array_values( array_unique( array_map( static fn ( $b ) => (int) $b['user_id'], $badges ) ) ) as $uid ) {
			$ours                    = $dry_run
				? count( array_filter( $badges, static fn ( $b ) => (int) $b['user_id'] === $uid ) )
				: self::our_imported_badge_count( $uid );
			$badge_reconcile[ $uid ] = array(
				'imported_badges' => (int) $ours,
				'mycred_badges'   => self::mycred_badge_count( $uid ),
				'match'           => (int) $ours === self::mycred_badge_count( $uid ),
			);
		}

		// RANK reconciliation: derived level (from imported points) vs myCred rank.
		$rank_reconcile = array();
		if ( ! empty( $ranks ) ) {
			foreach ( $user_ids as $uid ) {
				$gp = self::mycred_user_rank_name( $uid );
				if ( '' === $gp ) {
					continue;
				}
				$points                 = $dry_run ? self::expected_points( $rows, $uid ) : self::our_imported_points( $uid );
				$our_level              = $dry_run
					? self::tier_name_for_points( $ranks, $points )
					: ( \WBGam\Engine\LevelEngine::get_level_for_points( $points )['name'] ?? '' );
				$rank_reconcile[ $uid ] = array(
					'our_level'   => (string) $our_level,
					'mycred_rank' => $gp,
					'match'       => (string) $our_level === $gp,
				);
			}
		}

		$result = array(
			'rows'                 => count( $rows ),
			'badges'               => count( $badges ),
			'ranks'                => count( $ranks ),
			'dry_run'              => $dry_run,
			'reconciliation'       => $reconcile,
			'badge_reconciliation' => $badge_reconcile,
			'rank_reconciliation'  => $rank_reconcile,
		);
		if ( ! $dry_run ) {
			$result['ingest']         = $ingest;
			$result['badges_awarded'] = $badge_awarded;
			$result['levels_created'] = $levels_made;
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
