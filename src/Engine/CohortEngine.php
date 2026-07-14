<?php
/**
 * Cohort League Engine — Weekly promotion/demotion leagues (Duolingo model)
 *
 * Each week, active users are placed in cohorts of ~30 with similar point
 * totals. At end of week, the top 33% promote to the next tier, bottom 33%
 * demote, middle 33% stay. Cohort membership and tier are stored in
 * wb_gam_cohort_members.
 *
 * Tiers (Bronze → Silver → Gold → Diamond → Obsidian):
 *   0 = Bronze, 1 = Silver, 2 = Gold, 3 = Diamond, 4 = Obsidian
 *
 * Cron schedule:
 *   Monday 00:05 UTC — assign new weekly cohorts
 *   Sunday 23:00 UTC — process promotions/demotions + notify users
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - PrefixAllGlobals.NonPrefixedHooknameFound — plugin uses `wb_gam_*` as its
// established hook prefix (documented in CLAUDE.md, declared in .phpcs.xml).
// Plugin Check auto-detects `wb_gamification` from the text-domain header
// and doesn't share the .phpcs.xml prefix list; hooks like
// `wb_gam_points_redeemed` are part of the public 1.0 API and can't rename.
// - PrefixAllGlobals.NonPrefixedFunctionFound — same convention. Helper
// functions exported under `wb_gam_*` are documented in `src/Extensions/`.
// - PluginCheck.Security.DirectDB.UnescapedDBParameter +
// WordPress.DB.PreparedSQL.InterpolatedNotPrepared — this file does custom-
// table work. Table names are interpolated from `{$wpdb->prefix}` plus
// literal constants (no user input); user-supplied values pass through
// `$wpdb->prepare()`. MySQL doesn't allow placeholder table names, so the
// interpolation is unavoidable.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Weekly promotion/demotion league cohort engine (Duolingo model).
 *
 * @package WB_Gamification
 */
final class CohortEngine {

	public const TIERS       = array( 'Bronze', 'Silver', 'Gold', 'Diamond', 'Obsidian' );
	public const COHORT_SIZE = 30;

	/**
	 * Rows per multi-row upsert when persisting cohort membership.
	 *
	 * Was one $wpdb->replace() per member — 100,000 individual write queries in a
	 * single weekly cron request, which times out long before it finishes and
	 * leaves the cohort table half-populated with no resume.
	 *
	 * @since 1.6.4
	 * @var int
	 */
	private const WRITE_BATCH = 500;

	/**
	 * Cohorts processed per promotion tick. At COHORT_SIZE = 30 that is ~1,500 members a page --
	 * enough to be worth the round trip, small enough that no single tick carries the site.
	 */
	private const PROMOTION_PAGE_SIZE = 50;

	/**
	 * Action Scheduler hook for one page of end-of-week promotions.
	 */
	private const AS_PROMOTION_HOOK = 'wb_gam_cohort_promotion_page';
	public const PROMOTE_PCT        = 0.33;
	public const DEMOTE_PCT         = 0.33;
	private const CRON_ASSIGN       = 'wb_gam_cohort_assign';
	private const CRON_PROCESS      = 'wb_gam_cohort_process';

	/**
	 * Option key for admin-customised cohort settings (tier names, %s, etc.).
	 * Mirrors {@see \WBGam\Admin\CohortSettingsPage::OPTION_KEY} +
	 * {@see \WBGam\API\CohortSettingsController::OPTION_KEY}.
	 *
	 * Duplicated here as a const to avoid a circular Admin → Engine dependency.
	 * The three constants MUST stay in lock-step.
	 *
	 * @var string
	 */
	public const SETTINGS_OPTION = 'wb_gam_cohort_settings';

	/**
	 * Resolve the display name for a given tier index.
	 *
	 * Reads {@see SETTINGS_OPTION} so admin tier-name edits flow to every
	 * read-side surface (block render, REST `get_user_standing`, promotion
	 * email subject lines). Falls back to the hard-coded TIERS constant
	 * when the option is missing or the slot is empty — preserves the
	 * pre-1.4.1 behaviour for installs that never visited the admin page.
	 *
	 * @param int $tier 0-indexed tier slot.
	 * @return string The customised name if admins set one, else the default.
	 */
	public static function get_tier_name( int $tier ): string {
		$default  = self::TIERS[ $tier ] ?? 'Bronze';
		$settings = get_option( self::SETTINGS_OPTION );
		if ( ! is_array( $settings ) ) {
			return $default;
		}
		// Admin form labels tiers 1-indexed (tier_1 = Bronze).
		$key    = 'tier_' . ( $tier + 1 );
		$custom = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		return '' !== trim( $custom ) ? $custom : $default;
	}

	/**
	 * Register cron action callbacks.
	 */
	public static function init(): void {
		add_action( self::CRON_ASSIGN, array( __CLASS__, 'assign_cohorts' ) );
		add_action( self::CRON_PROCESS, array( __CLASS__, 'process_promotions' ) );

		// Paged continuation of the above.
		add_action( self::AS_PROMOTION_HOOK, array( __CLASS__, 'process_promotions' ), 10, 1 );
	}

	/**
	 * Schedule the weekly cohort cron events on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_ASSIGN ) ) {
			// Next Monday 00:05 UTC.
			$next_monday = strtotime( 'next monday 00:05:00 UTC' );
			wp_schedule_event( $next_monday, 'weekly', self::CRON_ASSIGN );
		}
		if ( ! wp_next_scheduled( self::CRON_PROCESS ) ) {
			// Next Sunday 23:00 UTC.
			$next_sunday = strtotime( 'next sunday 23:00:00 UTC' );
			wp_schedule_event( $next_sunday, 'weekly', self::CRON_PROCESS );
		}
	}

	/**
	 * Clear the weekly cohort cron events on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_ASSIGN );
		wp_clear_scheduled_hook( self::CRON_PROCESS );
	}

	// ── Cohort assignment ────────────────────────────────────────────────────

	/**
	 * Assign active users into new weekly cohorts.
	 * Groups users by current tier and then by point total within each tier
	 * so cohort competition is fair.
	 */
	public static function assign_cohorts(): void {
		if ( ! FeatureFlags::is_enabled( 'cohort_leagues' ) ) {
			return;
		}

		global $wpdb;

		$week = Clock::site_week();
		// Site clock, not UTC: these bound wb_gam_points.created_at, which is written site-local. And
		// 'monday this week' is worse than an offset -- strtotime() resolves the WEEKDAY against PHP's
		// UTC, so near a Monday boundary (Auckland Mon 03:30 = UTC Sun 15:30) it picks the PREVIOUS
		// Monday and the whole week is off by seven days.
		$week_start = Clock::site_cutoff( 'monday this week' );
		$active_of  = Clock::site_cutoff( '-4 weeks' );

		// ONE query: active members, their tier, and their points this week.
		//
		// Cohort assignment needs a GLOBAL ranking — every active member sorted
		// within their tier — so it cannot be keyset-paginated the way the weekly
		// email can. What it CAN stop doing is the three things that made it fatal:
		//
		// 1. `update_meta_cache( 'user', $ids )` on every active member. That issues
		// SELECT * FROM wp_usermeta WHERE user_id IN (...100k ids...) and loads
		// EVERY meta row for EVERY active member into PHP — ~25 rows each, so
		// ~2.5M rows resident. That alone is the OOM. The tier is ONE meta key;
		// we LEFT JOIN it here instead and never touch the meta cache.
		//
		// 2. A `WHERE user_id IN ($placeholders)` clause built from one %d per
		// member. At 100k members that is a ~700 KB prepared statement with
		// 100,000 bind args — it blows max_allowed_packet before MySQL even
		// plans it. The aggregate below needs no id list at all: the same
		// `created_at` predicate that defines "active" also defines the rows.
		//
		// 3. One `REPLACE` per member (100k individual writes). See the batched
		// multi-row upsert below.
		//
		// What remains resident is one small row per active member (id, pts, tier) —
		// a few MB at 100k, which the global sort genuinely requires.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.user_id,
				        COALESCE( SUM( CASE WHEN p.created_at >= %s THEN p.points ELSE 0 END ), 0 ) AS pts,
				        COALESCE( um.meta_value, 0 ) AS tier
				   FROM {$wpdb->prefix}wb_gam_points p
				   LEFT JOIN {$wpdb->usermeta} um
				          ON um.user_id = p.user_id
				         AND um.meta_key = 'wb_gam_league_tier'
				  WHERE p.created_at >= %s
				  GROUP BY p.user_id, um.meta_value",
				$week_start,
				$active_of
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$week_pts = array();
		$by_tier  = array_fill( 0, count( self::TIERS ), array() );
		foreach ( $rows as $row ) {
			$uid  = (int) $row['user_id'];
			$tier = max( 0, min( count( self::TIERS ) - 1, (int) $row['tier'] ) );

			$week_pts[ $uid ]   = (int) $row['pts'];
			$by_tier[ $tier ][] = $uid;
		}
		unset( $rows );

		// Sort within each tier by weekly points desc.
		foreach ( $by_tier as $tier => &$members ) {
			usort( $members, static fn( $a, $b ) => $week_pts[ $b ] <=> $week_pts[ $a ] );
		}
		unset( $members );

		// Split into cohorts and persist with BATCHED multi-row upserts.
		//
		// Was one $wpdb->replace() per member: 100,000 individual write queries in a
		// single cron request. Now one statement per WRITE_BATCH members, inside a
		// transaction, using the (user_id, week) PRIMARY KEY for the upsert.
		$pending = array();

		foreach ( $by_tier as $tier => $members ) {
			foreach ( array_chunk( $members, self::COHORT_SIZE ) as $cohort_idx => $chunk ) {
				$cohort_id = "{$week}-t{$tier}-{$cohort_idx}";
				foreach ( $chunk as $uid ) {
					$pending[] = array( $uid, $cohort_id, $tier, $week, $week_pts[ $uid ] );
					if ( count( $pending ) >= self::WRITE_BATCH ) {
						self::write_cohort_batch( $pending );
						$pending = array();
					}
				}
			}
		}
		self::write_cohort_batch( $pending );
	}

	/**
	 * Upsert one batch of cohort memberships in a single multi-row statement.
	 *
	 * Assignment was one `$wpdb->replace()` per member: 100,000 individual write
	 * queries in a single cron request. This collapses a batch into one statement,
	 * upserting on the (user_id, week) PRIMARY KEY.
	 *
	 * @param array<int,array{0:int,1:string,2:int,3:string,4:int}> $rows Batch of
	 *        [user_id, cohort_id, tier, week, pts_start] tuples. Empty is a no-op,
	 *        so the caller can flush unconditionally at the end of the loop.
	 * @return void
	 */
	private static function write_cohort_batch( array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		global $wpdb;

		$table  = $wpdb->prefix . 'wb_gam_cohort_members';
		$values = array();
		$args   = array();
		foreach ( $rows as $row ) {
			$values[] = '(%d, %s, %d, %s, %d)';
			array_push( $args, $row[0], $row[1], $row[2], $row[3], $row[4] );
		}

		// $values holds only literal '(%d, %s, %d, %s, %d)' placeholder GROUPS built in the loop above;
		// every real value is bound through $args. The ignore has to sit on the line the sniff flags
		// (this one) -- above the $wpdb->query() call it suppressed nothing, and the ERROR shipped.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, cohort_id, tier, week, pts_start) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE cohort_id = VALUES(cohort_id), tier = VALUES(tier), pts_start = VALUES(pts_start)',
				$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Promotion processing ─────────────────────────────────────────────────

	/**
	 * Process end-of-week promotions and demotions.
	 */
	public static function process_promotions( string $cursor = '' ): void {
		if ( ! FeatureFlags::is_enabled( 'cohort_leagues' ) ) {
			return;
		}

		global $wpdb;

		$week       = Clock::site_week();
		$week_start = Clock::site_cutoff( 'monday this week' );
		$table      = $wpdb->prefix . 'wb_gam_cohort_members';

		// ONE page of cohorts, keyset-walked. The old version processed EVERY cohort in a single
		// cron tick and, inside that, issued a query per cohort and TWO writes per member:
		//
		// 3,334 cohorts x 2 SELECTs               =   6,668 selects
		// 100,000 members x ($wpdb->update + meta) = 200,000 writes
		//
		// ~207,000 queries in one request, at 100k members. assign_cohorts() was converted to
		// batched writes in 1.6.4 after it was caught doing one replace() per member;
		// process_promotions() sat right underneath it and was missed.
		$cohort_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT cohort_id FROM {$table}
				  WHERE week = %s AND cohort_id > %s
				  ORDER BY cohort_id ASC
				  LIMIT %d",
				$week,
				$cursor,
				self::PROMOTION_PAGE_SIZE
			)
		);

		if ( ! $cohort_ids ) {
			return;
		}

		// TWO queries for the whole page, not two per cohort.
		$ph = implode( ',', array_fill( 0, count( $cohort_ids ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ph is implode of '%s' placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cohort_id, user_id, tier FROM {$table}
				  WHERE cohort_id IN ({$ph})
				  ORDER BY cohort_id, user_id",
				$cohort_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $rows ) {
			self::schedule_promotion_page( (string) end( $cohort_ids ) );
			return;
		}

		$member_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r['user_id'], $rows ) ) );

		$pts_ph = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $pts_ph is implode of '%d' placeholders.
		$pts_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COALESCE(SUM(points), 0) AS pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id IN ({$pts_ph}) AND created_at >= %s
				 GROUP BY user_id",
				array_merge( $member_ids, array( $week_start ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pts_map = array_fill_keys( $member_ids, 0 );
		foreach ( (array) $pts_rows as $row ) {
			$pts_map[ (int) $row['user_id'] ] = (int) $row['pts'];
		}

		// Group the page's rows back into their cohorts.
		$by_cohort = array();
		foreach ( $rows as $row ) {
			$by_cohort[ (string) $row['cohort_id'] ][] = array(
				'user_id' => (int) $row['user_id'],
				'tier'    => (int) $row['tier'],
				'pts'     => $pts_map[ (int) $row['user_id'] ] ?? 0,
			);
		}

		$writes   = array();
		$outcomes = array();
		$max_tier = count( self::TIERS ) - 1;

		foreach ( $by_cohort as $cohort_id => $ranked ) {
			usort( $ranked, static fn( $a, $b ) => $b['pts'] <=> $a['pts'] );

			$count     = count( $ranked );
			$promote_n = (int) floor( $count * self::PROMOTE_PCT );
			$demote_n  = (int) floor( $count * self::DEMOTE_PCT );

			foreach ( $ranked as $i => $entry ) {
				$uid      = $entry['user_id'];
				$cur_tier = $entry['tier'];

				if ( $i < $promote_n && $cur_tier < $max_tier ) {
					$new_tier = $cur_tier + 1;
					$outcome  = 'promoted';
				} elseif ( $i >= $count - $demote_n && $cur_tier > 0 ) {
					$new_tier = $cur_tier - 1;
					$outcome  = 'demoted';
				} else {
					$new_tier = $cur_tier;
					$outcome  = 'stayed';
				}

				$writes[]   = array( (string) $cohort_id, $uid, $new_tier, $outcome );
				$outcomes[] = array( $uid, $cur_tier, $new_tier, $outcome, $entry['pts'] );
			}
		}

		self::write_outcomes( $writes );
		self::write_tiers( array_map( static fn( $w ) => array( $w[1], $w[2] ), $writes ) );

		// The per-member hook is part of the public API and still fires for every member. It is
		// the WRITES that were the problem, not the notification.
		foreach ( $outcomes as $o ) {
			/**
			 * Fires for each cohort member at end-of-week processing.
			 *
			 * @param int    $user_id  Member.
			 * @param int    $old_tier Tier before processing.
			 * @param int    $new_tier Tier after processing.
			 * @param string $outcome  promoted | demoted | stayed.
			 * @param int    $pts      Points earned in the cohort week.
			 */
			do_action( 'wb_gam_cohort_outcome', $o[0], $o[1], $o[2], $o[3], $o[4] );
		}

		self::schedule_promotion_page( (string) end( $cohort_ids ) );
	}

	/**
	 * Queue the next page of cohort promotions.
	 *
	 * @param string $cursor Last cohort_id processed.
	 * @return void
	 */
	private static function schedule_promotion_page( string $cursor ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::process_promotions( $cursor );
			return;
		}

		$args = array( 'cursor' => $cursor );

		// The handler schedules its own successor, so without this an overlapping run would
		// queue the same cursor twice and promote that page's members twice.
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::AS_PROMOTION_HOOK, $args, 'wb-gamification' ) ) {
			return;
		}

		as_enqueue_async_action( self::AS_PROMOTION_HOOK, $args, 'wb-gamification' );
	}

	/**
	 * Persist tier_end / outcome for a page of members in batched multi-row upserts.
	 *
	 * @param array<int,array{0:string,1:int,2:int,3:string}> $writes [cohort_id, user_id, tier_end, outcome].
	 * @return void
	 */
	private static function write_outcomes( array $writes ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_cohort_members';

		foreach ( array_chunk( $writes, self::WRITE_BATCH ) as $chunk ) {
			$cases_tier = '';
			$cases_out  = '';
			$where      = array();
			$args       = array();

			foreach ( $chunk as $w ) {
				$cases_tier .= ' WHEN cohort_id = %s AND user_id = %d THEN %d';
				array_push( $args, $w[0], $w[1], $w[2] );
			}
			foreach ( $chunk as $w ) {
				$cases_out .= ' WHEN cohort_id = %s AND user_id = %d THEN %s';
				array_push( $args, $w[0], $w[1], $w[3] );
			}
			foreach ( $chunk as $w ) {
				$where[] = '(cohort_id = %s AND user_id = %d)';
				array_push( $args, $w[0], $w[1] );
			}

			// One statement per WRITE_BATCH members instead of one UPDATE each.
			//
			// $cases_* and $where are built from literal placeholder fragments in the loop above;
			// every value is bound through $args. disable/enable, not ignore: the sniff reports on the
			// interpolated line INSIDE the call, which an ignore above the call never covered.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					    SET tier_end = CASE{$cases_tier} END,
					        outcome  = CASE{$cases_out} END
					  WHERE " . implode( ' OR ', $where ),
					$args
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Persist each member's tier for next week, in batched writes.
	 *
	 * Per-member update_user_meta() is ~2 queries each. Measured on a real page of 1,500 members
	 * that was the dominant cost of the whole run -- paging alone did NOT fix it, which is why
	 * the first attempt at this method was wrong.
	 *
	 * The obvious batch is INSERT ... ON DUPLICATE KEY UPDATE against wp_usermeta. **Do not.**
	 * WordPress's usermeta has no unique index on (user_id, meta_key) -- only PRIMARY(umeta_id)
	 * and non-unique keys on each column -- so ON DUPLICATE KEY would never fire and every run
	 * would append a fresh duplicate row for every member, forever.
	 *
	 * So: look up the existing umeta_id for the page (one query), UPDATE those by primary key in
	 * one statement, INSERT the members who have no row yet in another, and invalidate the meta
	 * cache for exactly the members touched. Three queries per 500 members instead of a thousand.
	 *
	 * @param array<int,array{0:int,1:int}> $tiers [user_id, tier].
	 * @return void
	 */
	private static function write_tiers( array $tiers ): void {
		global $wpdb;

		if ( ! $tiers ) {
			return;
		}

		foreach ( array_chunk( $tiers, self::WRITE_BATCH ) as $chunk ) {
			$ids = array_map( static fn( $t ) => (int) $t[0], $chunk );
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$existing = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, umeta_id FROM {$wpdb->usermeta}
					  WHERE meta_key = %s AND user_id IN ({$ph})",
					array_merge( array( 'wb_gam_league_tier' ), $ids )
				),
				ARRAY_A
			);

			$have = array();
			foreach ( (array) $existing as $row ) {
				$have[ (int) $row['user_id'] ] = (int) $row['umeta_id'];
			}

			$cases     = '';
			$case_args = array();
			$umeta_ids = array();
			$ins_vals  = array();
			$ins_args  = array();

			foreach ( $chunk as $t ) {
				$uid  = (int) $t[0];
				$tier = (string) (int) $t[1];

				if ( isset( $have[ $uid ] ) ) {
					$cases      .= ' WHEN umeta_id = %d THEN %s';
					$umeta_ids[] = $have[ $uid ];
					array_push( $case_args, $have[ $uid ], $tier );
				} else {
					$ins_vals[] = '(%d, %s, %s)';
					array_push( $ins_args, $uid, 'wb_gam_league_tier', $tier );
				}
			}

			if ( $umeta_ids ) {
				$uph = implode( ',', array_fill( 0, count( $umeta_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->usermeta} SET meta_value = CASE{$cases} END WHERE umeta_id IN ({$uph})",
						array_merge( $case_args, $umeta_ids )
					)
				);
			}

			if ( $ins_vals ) {
				// $ins_vals holds literal placeholder groups only; values bind via $ins_args.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES " . implode( ',', $ins_vals ),
						$ins_args
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			}

			// A raw write does not invalidate WordPress's meta cache; without this the rest of
			// the request would keep serving the OLD tier.
			foreach ( $ids as $uid ) {
				wp_cache_delete( $uid, 'user_meta' );
			}
		}
	}

	// ── Public helpers ───────────────────────────────────────────────────────

	/**
	 * Get a user's current league tier (0 = Bronze, 4 = Obsidian).
	 *
	 * @param int $user_id User to look up.
	 * @return int Tier index 0–4.
	 */
	public static function get_user_tier( int $user_id ): int {
		$tier = get_user_meta( $user_id, 'wb_gam_league_tier', true );
		return max( 0, min( 4, (int) $tier ) );
	}

	/**
	 * Get a user's current cohort and standings.
	 *
	 * @param int $user_id User to look up.
	 * @return array{cohort_id: string, tier: int, tier_name: string, week: string, standings: array}|null
	 */
	public static function get_user_standing( int $user_id ): ?array {
		global $wpdb;

		$week = Clock::site_week();
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cohort_id, tier FROM {$wpdb->prefix}wb_gam_cohort_members
				  WHERE user_id = %d AND week = %s",
				$user_id,
				$week
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$week_start = Clock::site_cutoff( 'monday this week' );

		// Get all cohort members with their week pts.
		$members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cm.user_id, u.display_name,
				        COALESCE((SELECT SUM(p.points) FROM {$wpdb->prefix}wb_gam_points p WHERE p.user_id = cm.user_id AND p.created_at >= %s), 0) AS week_pts
				   FROM {$wpdb->prefix}wb_gam_cohort_members cm
				   JOIN {$wpdb->users} u ON u.ID = cm.user_id
				  WHERE cm.cohort_id = %s
				  ORDER BY week_pts DESC",
				$week_start,
				$row['cohort_id']
			),
			ARRAY_A
		) ?: array();

		$rank = 1;
		foreach ( $members as &$m ) {
			$m['user_id']  = (int) $m['user_id'];
			$m['week_pts'] = (int) $m['week_pts'];
			$m['rank']     = $rank++;
			if ( $m['user_id'] === $user_id ) {
				$m['is_me'] = true;
			}
		}
		unset( $m );

		return array(
			'cohort_id' => $row['cohort_id'],
			'tier'      => (int) $row['tier'],
			'tier_name' => self::get_tier_name( (int) $row['tier'] ),
			'week'      => $week,
			'standings' => array_values( $members ),
		);
	}
}
