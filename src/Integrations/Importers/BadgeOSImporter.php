<?php
/**
 * WB Gamification — BadgeOS importer.
 *
 * Reads BadgeOS 3.7's custom tables (verified against BadgeOS 3.7.1.6):
 *   - `badgeos_points`       — the credit ledger. `credit` is the ABSOLUTE
 *      amount; the `type` enum (Award / Deduct / Utilized) carries the sign
 *      (Deduct + Utilized reduce the balance). `credit_id` is the point-type
 *      post id.
 *   - `badgeos_achievements` — earned achievements (one row per earning; a
 *      re-earnable achievement has multiple rows, so we dedupe by user+ID).
 *   - `badgeos_ranks`        — earned ranks.
 *
 * READ the source, WRITE only through ImportService / BadgeEngine, never a
 * direct wb_gam_* insert. Idempotent per source row (`badgeos:points:{id}`).
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Integrations\Importers;

use WBGam\Engine\ImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Migrates BadgeOS data into WB Gamification.
 *
 * @package WB_Gamification
 */
final class BadgeOSImporter {

	/**
	 * Is BadgeOS data present?
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		// "Is there BadgeOS data to import?" -- either table on its own is a yes. Checking only
		// badgeos_points told a site with achievements but no points ledger that there was nothing to
		// import, and (worse) told a site with points but no achievements table that everything was
		// fine, right before the run died on the missing table. A partial uninstall leaves exactly that
		// state and is perfectly normal.
		return self::has_table( 'badgeos_points' ) || self::has_table( 'badgeos_achievements' );
	}

	/**
	 * Does one of BadgeOS's tables exist?
	 *
	 * @param string $suffix Table name without the WP prefix.
	 * @return bool
	 */
	private static function has_table( string $suffix ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Map a BadgeOS point-type (credit) post id to a WB point-type slug.
	 *
	 * @param int $credit_id BadgeOS point_type post id.
	 * @return string
	 */
	private static function map_point_type( int $credit_id ): string {
		$slug    = $credit_id > 0 ? (string) get_post_field( 'post_name', $credit_id ) : '';
		$service = new \WBGam\Services\PointTypeService();
		$known   = wp_list_pluck( $service->list(), 'slug' );
		$default = ( '' !== $slug && in_array( $slug, $known, true ) ) ? $slug : $service->default_slug();

		/** This filter is documented in GamiPressImporter::map_point_type(). */
		return (string) apply_filters( 'wb_gam_import_point_type_map', $default, $slug, $known );
	}

	/**
	 * Achievement-type slugs (excludes the structural `step` type), read from
	 * BadgeOS's own `badgeos_achievements` table rather than
	 * `badgeos_get_achievement_types_slugs()`.
	 *
	 * The real migration scenario is the owner DEACTIVATING BadgeOS before
	 * running the import, so the PHP API being unavailable is the NORMAL case,
	 * not an edge case. The old code returned `[]` whenever the function was
	 * missing, `build_achievements()` bailed on an empty list, and every
	 * earned achievement was dropped SILENTLY while the import still reported
	 * success. Proven: 2 seeded points rows + 2 seeded earned achievements,
	 * BadgeOS not installed -> import returned success, points landed,
	 * achievements vanished with nothing to explain why.
	 *
	 * `DISTINCT post_type` on the achievements table needs no plugin code at
	 * all, so it works identically whether BadgeOS is active, deactivated, or
	 * fully removed (as long as its tables are still there — see the loud
	 * failure below for when they are not).
	 *
	 * @return string[]
	 * @throws \RuntimeException When the achievements table itself is missing,
	 *                            so the caller cannot mistake "genuinely no
	 *                            achievement types" for "data unreadable".
	 */
	private static function achievement_type_slugs(): array {
		global $wpdb;

		// A missing table used to throw. The instinct was right -- we cannot tell "no achievements were
		// ever earned" from "the data is unreachable", and quietly returning [] is how achievements
		// disappeared silently in the first place. But an uncaught RuntimeException is not "loud", it is
		// a white screen: nothing catches it -- not run(), not ImportController, not ImportMode::run()
		// (its finally re-raises) -- so the owner got a 500 and, because this runs BEFORE ingest(), not
		// even the points imported. A partial BadgeOS uninstall (points table kept, achievements table
		// dropped) is a normal state, and it lost the whole migration.
		//
		// Loud now means a warning the owner can read, carried out in the result payload by run(). The
		// caller decides; this function just answers the question it was asked.
		if ( ! self::has_table( 'badgeos_achievements' ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'badgeos_achievements';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$types = (array) $wpdb->get_col( "SELECT DISTINCT post_type FROM {$table} WHERE post_type <> '' AND post_type <> 'step'" );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	/**
	 * Point-type post ids (BadgeOS `point_type` CPT).
	 *
	 * @return int[]
	 */
	private static function point_type_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'point_type',
					'numberposts' => -1,
					'post_status' => 'publish',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Build normalized point rows from `badgeos_points`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = $wpdb->get_results(
			"SELECT id, user_id, credit_id, type, credit, this_trigger, actual_date_earned
			   FROM {$wpdb->prefix}badgeos_points
			  WHERE user_id > 0 AND credit <> 0
			  ORDER BY id ASC",
			ARRAY_A
		);

		$rows = array();
		foreach ( (array) $logs as $log ) {
			// `credit` is absolute; the enum type carries the sign. Mirror BadgeOS's own arithmetic
			// exactly (Award adds, Deduct/Utilized subtract, ANYTHING ELSE is ignored) -- treating an
			// unrecognised type as a deduction would make us disagree with the balance we reconcile
			// against, and report a mismatch on an import that was fine.
			$amount = abs( (int) $log['credit'] );
			$type   = (string) $log['type'];
			if ( 'Award' === $type ) {
				$delta = $amount;
			} elseif ( 'Deduct' === $type || 'Utilized' === $type ) {
				$delta = -$amount;
			} else {
				continue;
			}
			if ( 0 === $delta ) {
				continue;
			}
			$rows[] = array(
				'action_id'   => 'badgeos_' . sanitize_key( (string) $log['this_trigger'] ),
				'user_id'     => (int) $log['user_id'],
				'points'      => $delta,
				'point_type'  => self::map_point_type( (int) $log['credit_id'] ),
				'occurred_at' => (string) $log['actual_date_earned'],
				'source_key'  => 'badgeos:points:' . (int) $log['id'],
				'metadata'    => array(
					'_source' => 'badgeos',
					'bo_type' => (string) $log['type'],
				),
			);
		}
		return $rows;
	}

	/**
	 * Build achievement-award records (deduped by user + achievement id).
	 *
	 * @return array<int, array{user_id:int, badge_id:string, name:string, image:string, earned_at:string, post_id:int}>
	 */
	public static function build_achievements(): array {
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return array();
		}
		global $wpdb;
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// Earliest earning per (user, achievement) — MIN(date) for a stable backdate.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_id, MAX(achievement_title) AS achievement_title, MIN(date_earned) AS date_earned
				   FROM {$wpdb->prefix}badgeos_achievements
				  WHERE user_id > 0 AND post_type IN ($ph)
			   GROUP BY user_id, ID",
				...$types
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$post_id = (int) $r['ID'];
			$out[]   = array(
				'user_id'   => (int) $r['user_id'],
				'badge_id'  => 'badgeos-achievement-' . $post_id,
				'name'      => (string) $r['achievement_title'],
				'image'     => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				'earned_at' => (string) $r['date_earned'],
				'post_id'   => $post_id,
			);
		}
		return $out;
	}

	/**
	 * BadgeOS rank-type slugs — read from the `badgeos_ranks.rank_type` column
	 * (BadgeOS's authoritative record) rather than the generic `rank-type` CPT,
	 * which on a multi-plugin site also holds another plugin's rank types.
	 *
	 * @return string[]
	 */
	private static function rank_type_slugs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$types = (array) $wpdb->get_col( "SELECT DISTINCT rank_type FROM {$wpdb->prefix}badgeos_ranks WHERE rank_type <> ''" );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	/**
	 * Build rank tiers (as WB level defs) from BadgeOS rank posts.
	 *
	 * A rank's points threshold is post meta `_ranks_points`; rank order is
	 * `menu_order`.
	 *
	 * @return array<int, array{id:int, name:string, min_points:int, order:int}>
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
		$out   = array();
		foreach ( $ranks as $i => $rank ) {
			$out[] = array(
				'id'         => (int) $rank->ID,
				'name'       => (string) $rank->post_title,
				'min_points' => (int) get_post_meta( $rank->ID, '_ranks_points', true ),
				'order'      => (int) $i,
			);
		}
		return $out;
	}

	/**
	 * A user's current BadgeOS rank name — the highest-priority earned row in
	 * `badgeos_ranks` (badgeos_get_user_rank is unreliable on this install).
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function badgeos_user_rank_name( int $user_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rank_title FROM {$wpdb->prefix}badgeos_ranks
				  WHERE user_id = %d ORDER BY priority DESC, id DESC LIMIT 1",
				$user_id
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
	 * Run (or preview) the import with reconciliation against BadgeOS.
	 *
	 * @param bool $dry_run Preview only.
	 * @return array<string, mixed>
	 */
	public static function run( bool $dry_run = false ): array {
		$rows         = self::build_rows();
		$achievements = self::build_achievements();
		$ranks        = self::build_ranks();

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

		// POINTS reconciliation — actual ledger sum vs BadgeOS's own balance
		// (summed across point types via badgeos_get_points_by_type).
		$reconcile = array();
		foreach ( self::user_ids( $rows ) as $uid ) {
			$ours              = $dry_run ? self::expected_points( $rows, $uid ) : self::our_imported_points( $uid );
			$reconcile[ $uid ] = array(
				'imported_sum'    => $ours,
				'badgeos_balance' => self::badgeos_balance( $uid ),
				'match'           => $ours === self::badgeos_balance( $uid ),
			);
		}

		// ACHIEVEMENT reconciliation — our unique imported badges vs BadgeOS
		// DISTINCT earned achievement ids (the getter counts re-earn rows).
		$ach_reconcile = array();
		foreach ( self::user_ids( $achievements ) as $uid ) {
			$ours                  = $dry_run
				? count( array_filter( $achievements, static fn ( $a ) => (int) $a['user_id'] === $uid ) )
				: self::our_imported_badge_count( $uid );
			$ach_reconcile[ $uid ] = array(
				'imported_achievements' => (int) $ours,
				'badgeos_achievements'  => self::badgeos_distinct_achievements( $uid ),
				'match'                 => (int) $ours === self::badgeos_distinct_achievements( $uid ),
			);
		}

		// RANK reconciliation — level derived from imported points vs the
		// user's current BadgeOS rank (highest-priority earned row).
		$rank_reconcile = array();
		if ( ! empty( $ranks ) ) {
			foreach ( self::user_ids( $rows ) as $uid ) {
				$bo = self::badgeos_user_rank_name( $uid );
				if ( '' === $bo ) {
					continue;
				}
				$points                 = $dry_run ? self::expected_points( $rows, $uid ) : self::our_imported_points( $uid );
				$our_level              = $dry_run
					? self::tier_name_for_points( $ranks, $points )
					: ( \WBGam\Engine\LevelEngine::get_level_for_points( $points )['name'] ?? '' );
				$rank_reconcile[ $uid ] = array(
					'our_level'    => (string) $our_level,
					'badgeos_rank' => $bo,
					'match'        => (string) $our_level === $bo,
				);
			}
		}

		// Loud, but survivable. If the achievements table is gone we still import the points -- losing
		// the whole migration because half the source is missing helps nobody -- and we say plainly what
		// did not come across, so the owner is never left believing they got everything.
		$warnings = array();
		if ( ! self::has_table( 'badgeos_achievements' ) ) {
			// Tense matters here: the same run() answers a Preview, where nothing has been written yet.
			// "no badges were imported" is a false statement on a dry run, and a warning the owner can
			// catch lying to them is a warning they stop reading.
			$warnings[] = __( 'The BadgeOS achievements table was not found, so no badges can be imported — only points. This usually means BadgeOS was uninstalled and dropped its tables. If you still have a database backup, restore that table and import again.', 'wb-gamification' );
		}

		$result = array(
			'rows'                       => count( $rows ),
			'achievements'               => count( $achievements ),
			'ranks'                      => count( $ranks ),
			'dry_run'                    => $dry_run,
			'warnings'                   => $warnings,
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
	 * A user's BadgeOS balance, summed across every point type.
	 *
	 * Uses badgeos_get_points_by_type (the credit-system authority);
	 * badgeos_get_users_points reads a legacy meta and is unreliable on 3.7.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	private static function badgeos_balance( int $user_id ): int {
		global $wpdb;

		// This returned 0 whenever BadgeOS was not loaded, so a perfectly correct import (+100 / -30 =
		// 70) reconciled against 0 and was reported to the owner as a MISMATCH. A false alarm on a good
		// migration is worse than no check: it teaches the owner to ignore the one number that would
		// have told them a real import went wrong. And BadgeOS is normally deactivated when you migrate
		// off it, so 0 was the case that mattered.
		//
		// It failed twice over. function_exists() was only half of it -- point_type_ids() calls
		// get_posts( 'point_type' ), and with BadgeOS inactive that post type is not registered, so the
		// loop had nothing to iterate and could not have summed anything even if the getter existed.
		//
		// The sibling importers fall back to their plugin's balance META. BadgeOS has none: its own
		// badgeos_get_points_by_type() (verified in 3.7.1.6, includes/points/point-rules-engine.php)
		// reads the badgeos_points LEDGER, summing `credit` with the sign carried by `type` -- Award
		// adds, Deduct and Utilized subtract, anything else is ignored. So the honest fallback is that
		// same aggregation in SQL, which needs neither the plugin nor its post types. It stays a real
		// check: it is the source's own arithmetic, and it still catches us mis-signing the conversion.
		if ( function_exists( 'badgeos_get_points_by_type' ) ) {
			$total = 0;
			foreach ( self::point_type_ids() as $pt ) {
				$total += (int) badgeos_get_points_by_type( $pt, $user_id );
			}
			return $total;
		}

		if ( ! self::has_table( 'badgeos_points' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( CASE WHEN type = 'Award' THEN ABS( credit ) ELSE -ABS( credit ) END ), 0 )
				   FROM {$wpdb->prefix}badgeos_points
				  WHERE user_id = %d
				    AND type IN ( 'Award', 'Deduct', 'Utilized' )",
				$user_id
			)
		);

		return (int) $total;
	}

	/**
	 * BadgeOS distinct earned-achievement count for a user (excludes re-earns).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	private static function badgeos_distinct_achievements( int $user_id ): int {
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return 0;
		}
		global $wpdb;
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT ID) FROM {$wpdb->prefix}badgeos_achievements
				  WHERE user_id = %d AND post_type IN ($ph)",
				$user_id,
				...$types
			)
		);
	}

	/**
	 * Distinct user ids in a row set.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return int[]
	 */
	private static function user_ids( array $rows ): array {
		return array_values( array_unique( array_map( static fn ( $r ) => (int) $r['user_id'], $rows ) ) );
	}

	/**
	 * Expected point sum for a user (dry-run preview).
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
	 * Sum of points that ACTUALLY landed in our ledger from a BadgeOS import.
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
				'badgeos:points:%'
			)
		);
	}

	/**
	 * Count of imported BadgeOS achievement badges a user holds in WB.
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
				'badgeos-achievement-%'
			)
		);
	}
}
