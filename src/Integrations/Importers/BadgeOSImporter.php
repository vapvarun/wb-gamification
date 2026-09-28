<?php
/**
 * WB Gamification: BadgeOS importer.
 *
 * Reads BadgeOS 3.7's custom tables (verified against BadgeOS 3.7.1.6) one keyset page at a time and
 * hands normalized records to the ImportRunner. READ the source, WRITE only via our ingestion path.
 * Idempotent per source row (`badgeos:points:{id}`) and per achievement (`badgeos-achievement-{ID}`).
 *
 * BadgeOS specifics handled here:
 *   - `badgeos_points` is the credit ledger. `credit` is the ABSOLUTE amount; the `type` enum
 *     (Award / Deduct / Utilized) carries the sign (Deduct + Utilized reduce the balance). `credit_id`
 *     is the point-type post id. Paged by `id`.
 *   - `badgeos_achievements` holds earned achievements, one row per earning, so a re-earnable
 *     achievement has several rows and is deduped by user + ID. It has no single row key for that
 *     grouped record, so it is paged by `user_id`: a page is a set of whole members.
 *   - `badgeos_ranks` holds earned ranks; rank tiers are the rank posts of the types found there.
 *   - dates are site-local wall-clock time and are converted to UTC here.
 *   - BadgeOS is normally DEACTIVATED when a site migrates off it, so nothing here depends on its PHP
 *     API being loaded; every read falls back to its tables.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Integrations\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads BadgeOS data for the ImportRunner.
 *
 * @package WB_Gamification
 */
final class BadgeOSImporter implements ImportSource {

	public const KEY_PREFIX   = 'badgeos:points:';
	public const BADGE_PREFIX = 'badgeos-achievement-';

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

		/** This filter is documented in MyCredImporter::map_point_type(). */
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
	 * missing, the achievement reader bailed on an empty list, and every
	 * earned achievement was dropped SILENTLY while the import still reported
	 * success. Proven: 2 seeded points rows + 2 seeded earned achievements,
	 * BadgeOS not installed -> import returned success, points landed,
	 * achievements vanished with nothing to explain why.
	 *
	 * `DISTINCT post_type` on the achievements table needs no plugin code at
	 * all, so it works identically whether BadgeOS is active, deactivated, or
	 * fully removed (as long as its tables are still there; see below for
	 * when they are not).
	 *
	 * Memoized per request: the awards reader asks once per page and reconciliation once per member,
	 * and the answer is a scan of the whole table.
	 *
	 * @return string[]
	 */
	private static function achievement_type_slugs(): array {
		static $types = null;
		if ( null !== $types ) {
			return $types;
		}

		global $wpdb;

		// A missing table used to throw. The instinct was right -- we cannot tell "no achievements were
		// ever earned" from "the data is unreachable", and quietly returning [] is how achievements
		// disappeared silently in the first place. But an uncaught RuntimeException is not "loud", it is
		// a white screen: nothing up the stack caught it, so the owner got a 500 and not even the points
		// imported. A partial BadgeOS uninstall (points table kept, achievements table dropped) is a
		// normal state, and it lost the whole migration.
		//
		// So a missing table reads as "no awards" and the points still import. This function just
		// answers the question it was asked; telling the owner the badges could not come across is the
		// caller's job.
		if ( ! self::has_table( 'badgeos_achievements' ) ) {
			$types = array();
			return $types;
		}

		$table = $wpdb->prefix . 'badgeos_achievements';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = (array) $wpdb->get_col( "SELECT DISTINCT post_type FROM {$table} WHERE post_type <> '' AND post_type <> 'step'" );
		$types = array_values( array_filter( array_map( 'strval', $found ) ) );
		return $types;
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
	 * How many ledger rows will be imported (the rows the reader would return).
	 *
	 * @return int
	 */
	public static function count_points(): int {
		if ( ! self::has_table( 'badgeos_points' ) ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}badgeos_points WHERE user_id > 0 AND credit <> 0 AND type IN ( 'Award', 'Deduct', 'Utilized' )" );
	}

	/**
	 * One keyset page of `badgeos_points` as normalized rows.
	 *
	 * @param int $after Ledger id to start strictly after.
	 * @param int $limit Maximum rows scanned.
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read_points( int $after, int $limit ): array {
		if ( ! self::has_table( 'badgeos_points' ) ) {
			return array(
				'rows' => array(),
				'next' => 0,
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, credit_id, type, credit, this_trigger, actual_date_earned
				   FROM {$wpdb->prefix}badgeos_points
				  WHERE id > %d AND user_id > 0 AND credit <> 0
				  ORDER BY id ASC
				  LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( $logs as $log ) {
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
				// BadgeOS writes its dates in site-local time; convert to UTC.
				'occurred_at' => get_gmt_from_date( (string) $log['actual_date_earned'], 'Y-m-d\TH:i:s\Z' ),
				'source_key'  => self::KEY_PREFIX . (int) $log['id'],
				'metadata'    => array(
					'_source' => 'badgeos',
					'bo_type' => (string) $log['type'],
				),
			);
		}

		return array(
			'rows' => $rows,
			// Skipped rows do not shorten the page: the cursor follows what was SCANNED.
			'next' => count( $logs ) < $limit ? 0 : (int) end( $logs )['id'],
		);
	}

	/**
	 * How many distinct (member, achievement) awards BadgeOS holds (re-earns counted once).
	 *
	 * @return int
	 */
	public static function count_awards(): int {
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return 0;
		}
		global $wpdb;
		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id, ID) FROM {$wpdb->prefix}badgeos_achievements
				  WHERE user_id > 0 AND post_type IN ($ph)",
				...$types
			)
		);
	}

	/**
	 * One keyset page of earned achievements (deduped by user + achievement id), paged by `user_id`.
	 *
	 * A grouped (user, achievement) record has no single row key, so the cursor is the member: step one
	 * takes the next $limit distinct user ids after the cursor, step two reads every award those members
	 * hold. A page therefore always holds whole members, and may hold more than $limit records.
	 *
	 * @param int $after User id to start strictly after.
	 * @param int $limit Maximum members per page.
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read_awards( int $after, int $limit ): array {
		$empty = array(
			'rows' => array(),
			'next' => 0,
		);
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return $empty;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'badgeos_achievements';
		$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$table}
					  WHERE user_id > %d AND post_type IN ($ph)
					  ORDER BY user_id ASC
					  LIMIT %d",
					$after,
					...array_merge( $types, array( $limit ) )
				)
			)
		);
		if ( empty( $user_ids ) ) {
			return $empty;
		}

		$uph = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// Earliest earning per (user, achievement): MIN(date) for a stable backdate.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$grouped = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_id, MAX(achievement_title) AS achievement_title, MIN(date_earned) AS date_earned
				   FROM {$table}
				  WHERE user_id IN ($uph) AND post_type IN ($ph)
			   GROUP BY user_id, ID
			   ORDER BY user_id ASC, ID ASC",
				...array_merge( $user_ids, $types )
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( $grouped as $r ) {
			$post_id = (int) $r['ID'];
			$rows[]  = array(
				'user_id'   => (int) $r['user_id'],
				'badge_id'  => self::BADGE_PREFIX . $post_id,
				'name'      => (string) $r['achievement_title'],
				'image'     => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				// BadgeOS stores date_earned in site-local time; earned_at is UTC. Empty lets the runner stamp now.
				'earned_at' => $r['date_earned'] ? get_gmt_from_date( (string) $r['date_earned'] ) : '',
			);
		}

		return array(
			'rows' => $rows,
			'next' => count( $user_ids ) < $limit ? 0 : (int) end( $user_ids ),
		);
	}

	/**
	 * BadgeOS rank-type slugs, read from the `badgeos_ranks.rank_type` column
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
	 * Rank tiers (as WB level defs) from BadgeOS rank posts.
	 *
	 * A rank's points threshold is post meta `_ranks_points`; rank order is `menu_order`. A site has a
	 * handful, so this is not paged.
	 *
	 * @return array<int, array{name: string, min_points: int, order: int}>
	 */
	public static function read_ranks(): array {
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
				'name'       => (string) $rank->post_title,
				'min_points' => (int) get_post_meta( $rank->ID, '_ranks_points', true ),
				'order'      => (int) $i,
			);
		}
		return $out;
	}

	/**
	 * A member's BadgeOS balance, summed across every point type.
	 *
	 * Uses badgeos_get_points_by_type (the credit-system authority);
	 * badgeos_get_users_points reads a legacy meta and is unreliable on 3.7.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_balance( int $user_id ): int {
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
	 * BadgeOS distinct earned-achievement count for a member (excludes re-earns, as the import does;
	 * BadgeOS's own getter counts every re-earn row).
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_badge_count( int $user_id ): int {
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
	 * A member's current BadgeOS rank name: the highest-priority earned row in
	 * `badgeos_ranks` (badgeos_get_user_rank is unreliable on this install).
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public static function source_rank_name( int $user_id ): string {
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
}
