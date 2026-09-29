<?php
/**
 * WB Gamification: GamiPress importer.
 *
 * Reads GamiPress's own point ledger (`wp_gamipress_logs`, verified against GamiPress 7.9.5) one
 * keyset page at a time and hands normalized rows to the ImportRunner. READ the source, WRITE only
 * via our ingestion path. Idempotent per source row (`gamipress:log:{log_id}`).
 *
 * GamiPress specifics handled here:
 *   - `points` / `points_type` are COLUMNS on the logs table since GamiPress 6.9.4 and log META rows
 *     before it. Both shapes are alive in the wild; the schema is asked which one this site has.
 *   - deduct / revoke rows store a positive amount; the sign is inferred from the log `type`.
 *   - `date` on logs and earnings is site-local time; it is converted to UTC here.
 *   - each points-type slug maps to a WB point type (the same slug when it exists here, else the
 *     site default). Balances reconcile against GamiPress's own `_gamipress_{type}_points`.
 *   - achievements are `gamipress_user_earnings` rows whose `post_type` is a registered
 *     achievement type, paged by `user_earning_id`. Rank earnings live in the same table and are
 *     migrated as levels (read_ranks), never as badges.
 *
 * @package WB_Gamification
 * @since   1.6.2
 */

namespace WBGam\Integrations\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads GamiPress ledger, achievement and rank data for the ImportRunner.
 *
 * @package WB_Gamification
 */
final class GamiPressImporter implements ImportSource {

	public const KEY_PREFIX   = 'gamipress:log:';
	public const BADGE_PREFIX = 'gamipress-achievement-';

	/**
	 * Log types that move a balance. Deduct / revoke lower it.
	 */
	private const POINT_LOG_TYPES = "'points_earn','points_award','points_deduct','points_revoke'";

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
		 * Filter the GamiPress to WB point-type slug mapping.
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
	 * Reading the columns unconditionally did not fail loudly on an older site: MySQL rejected the
	 * query, $wpdb swallowed the error, get_results() returned null, and the import reported zero rows
	 * as a success. So the reader asks which shape this site has and reads that one.
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
	 * How many ledger rows the reader scans (an upper bound: zero-point rows are skipped on read).
	 *
	 * @return int
	 */
	public static function count_points(): int {
		global $wpdb;
		$types = self::POINT_LOG_TYPES;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gamipress_logs WHERE type IN ({$types}) AND user_id > 0" );
	}

	/**
	 * One keyset page of the GamiPress ledger as normalized rows, paged by `log_id`.
	 *
	 * On the legacy (pre-6.9.4) shape the amount and type come from correlated subqueries on
	 * `gamipress_logs_meta`. They stay subqueries on purpose: a JOIN would duplicate a log row when
	 * the meta table holds the key twice, where `LIMIT 1` reads exactly one value. The page bounds
	 * them to $limit rows, each a lookup on the meta table's `log_id` key.
	 *
	 * @param int $after log_id to start strictly after.
	 * @param int $limit Maximum log rows scanned.
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read_points( int $after, int $limit ): array {
		global $wpdb;

		$select = self::logs_have_points_column()
			? 'l.points AS points, l.points_type AS points_type'
			: "( SELECT m.meta_value FROM {$wpdb->prefix}gamipress_logs_meta m
			      WHERE m.log_id = l.log_id AND m.meta_key = '_gamipress_points' LIMIT 1 ) AS points,
			   ( SELECT m2.meta_value FROM {$wpdb->prefix}gamipress_logs_meta m2
			      WHERE m2.log_id = l.log_id AND m2.meta_key = '_gamipress_points_type' LIMIT 1 ) AS points_type";
		$types  = self::POINT_LOG_TYPES;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.log_id, l.user_id, l.type, l.trigger_type, {$select}, l.date
				   FROM {$wpdb->prefix}gamipress_logs l
				  WHERE l.log_id > %d AND l.type IN ({$types}) AND l.user_id > 0
				  ORDER BY l.log_id ASC
				  LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( $logs as $log ) {
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
				'object_id'   => 0,
				// GamiPress writes `date` in site-local time; convert to UTC.
				'occurred_at' => get_gmt_from_date( (string) $log['date'], 'Y-m-d\TH:i:s\Z' ),
				'source_key'  => self::KEY_PREFIX . (int) $log['log_id'],
				'metadata'    => array(
					'_source'    => 'gamipress',
					'gp_type'    => $type,
					'gp_trigger' => (string) $log['trigger_type'],
				),
			);
		}

		return array(
			'rows' => $rows,
			// Skipped zero-point rows do not shorten the page: the cursor follows what was SCANNED.
			'next' => count( $logs ) < $limit ? 0 : (int) end( $logs )['log_id'],
		);
	}

	/**
	 * Registered GamiPress achievement-type slugs (the `achievement-type` CPT
	 * post names): these are the `user_earnings.post_type` values that mean
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
	 * How many achievement awards GamiPress holds.
	 *
	 * @return int
	 */
	public static function count_awards(): int {
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return 0;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}gamipress_user_earnings
				  WHERE user_id > 0 AND post_type IN ($placeholders)",
				...$types
			)
		);
	}

	/**
	 * One keyset page of earned achievements, paged by `user_earning_id`.
	 *
	 * One record per earned achievement: a stable WB badge id (`gamipress-achievement-{post_id}`),
	 * the achievement title and featured image, and the earned date (for a backdated award).
	 *
	 * @param int $after user_earning_id to start strictly after.
	 * @param int $limit Maximum earning rows scanned.
	 * @return array{rows: array<int, array<string, mixed>>, next: int}
	 */
	public static function read_awards( int $after, int $limit ): array {
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return array(
				'rows' => array(),
				'next' => 0,
			);
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$earnings = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_earning_id, title, user_id, post_id, date
				   FROM {$wpdb->prefix}gamipress_user_earnings
				  WHERE user_earning_id > %d AND user_id > 0 AND post_type IN ($placeholders)
				  ORDER BY user_earning_id ASC
				  LIMIT %d",
				$after,
				...array_merge( $types, array( $limit ) )
			),
			ARRAY_A
		);

		$rows = array();
		foreach ( $earnings as $earning ) {
			$post_id = (int) $earning['post_id'];
			$date    = (string) $earning['date'];
			$rows[]  = array(
				'user_id'   => (int) $earning['user_id'],
				'badge_id'  => self::BADGE_PREFIX . $post_id,
				'name'      => (string) $earning['title'],
				'image'     => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				// GamiPress stores earning dates in site-local time; earned_at is UTC.
				'earned_at' => '' !== $date ? get_gmt_from_date( $date ) : current_time( 'mysql', true ),
			);
		}

		return array(
			'rows' => $rows,
			'next' => count( $earnings ) < $limit ? 0 : (int) end( $earnings )['user_earning_id'],
		);
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
	 * has no requirement, so 0.
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
	 * Rank tiers from GamiPress rank posts, as level definitions.
	 *
	 * A site has a handful, so this is not paged. Members land at the matching level from their
	 * imported points, since our levels are point-derived on read.
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

		$out = array();
		foreach ( $ranks as $i => $rank ) {
			$out[] = array(
				'name'       => (string) $rank->post_title,
				'min_points' => self::rank_min_points( (int) $rank->ID ),
				'order'      => (int) $i,
			);
		}
		return $out;
	}

	/**
	 * A member's GamiPress balance, summed across every points type.
	 *
	 * Uses GamiPress's OWN getter (`gamipress_get_user_points`) as the
	 * authority so reconciliation is independent of how we read the source:
	 * exactly the cross-check that caught an earlier raw-meta miscount. Falls
	 * back to the exact per-slug balance meta only if the getter is absent.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_balance( int $user_id ): int {
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
	 * A member's GamiPress achievement count, read directly from GamiPress's own
	 * `gamipress_user_earnings` table rather than its PHP API.
	 *
	 * `gamipress_get_user_achievements()` is only callable while GamiPress is
	 * active, but the whole point of this importer is migrating AWAY from
	 * GamiPress: the normal sequence is deactivate-then-import. The table
	 * survives deactivation, so read that instead.
	 *
	 * When the count cannot be read (table gone, or no achievement types
	 * registered to filter by) this returns 0, never our own count: any badge
	 * we did import then surfaces as a mismatch instead of a fabricated match.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public static function source_badge_count( int $user_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'gamipress_user_earnings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return 0;
		}

		// Filter to achievement types ONLY: unfiltered this table also holds
		// rank earnings, which we migrate as levels, not badges (that would
		// inflate the source count and hide a real mismatch).
		$types = self::achievement_type_slugs();
		if ( empty( $types ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND post_type IN ($placeholders)",
				$user_id,
				...$types
			)
		);
	}

	/**
	 * A member's current GamiPress rank name (highest across rank types).
	 *
	 * @param int $user_id Member.
	 * @return string Rank title, or '' if none.
	 */
	public static function source_rank_name( int $user_id ): string {
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
