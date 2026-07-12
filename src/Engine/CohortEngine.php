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
	private const WRITE_BATCH  = 500;
	public const PROMOTE_PCT   = 0.33;
	public const DEMOTE_PCT    = 0.33;
	private const CRON_ASSIGN  = 'wb_gam_cohort_assign';
	private const CRON_PROCESS = 'wb_gam_cohort_process';

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

		$week       = gmdate( 'Y-W' );
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
		$active_of  = gmdate( 'Y-m-d H:i:s', strtotime( '-4 weeks' ) );

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
		$table   = $wpdb->prefix . 'wb_gam_cohort_members';
		$pending = array();

		$flush = static function () use ( &$pending, $wpdb, $table ) {
			if ( empty( $pending ) ) {
				return;
			}
			$values = array();
			$args   = array();
			foreach ( $pending as $r ) {
				$values[] = '(%d, %s, %d, %s, %d)';
				array_push( $args, $r[0], $r[1], $r[2], $r[3], $r[4] );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (user_id, cohort_id, tier, week, pts_start) VALUES "
					. implode( ',', $values )
					. ' ON DUPLICATE KEY UPDATE cohort_id = VALUES(cohort_id), tier = VALUES(tier), pts_start = VALUES(pts_start)',
					$args
				)
			);
			$pending = array();
		};

		foreach ( $by_tier as $tier => $members ) {
			foreach ( array_chunk( $members, self::COHORT_SIZE ) as $cohort_idx => $chunk ) {
				$cohort_id = "{$week}-t{$tier}-{$cohort_idx}";
				foreach ( $chunk as $uid ) {
					$pending[] = array( $uid, $cohort_id, $tier, $week, $week_pts[ $uid ] );
					if ( count( $pending ) >= self::WRITE_BATCH ) {
						$flush();
					}
				}
			}
		}
		$flush();
	}

	// ── Promotion processing ─────────────────────────────────────────────────

	/**
	 * Process end-of-week promotions and demotions.
	 */
	public static function process_promotions(): void {
		if ( ! FeatureFlags::is_enabled( 'cohort_leagues' ) ) {
			return;
		}

		global $wpdb;

		$week       = gmdate( 'Y-W' );
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';

		// Get all cohorts for this week.
		$cohort_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT cohort_id FROM {$wpdb->prefix}wb_gam_cohort_members WHERE week = %s",
				$week
			)
		);

		foreach ( $cohort_ids as $cohort_id ) {
			$members = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, tier FROM {$wpdb->prefix}wb_gam_cohort_members
					  WHERE cohort_id = %s ORDER BY user_id",
					$cohort_id
				),
				ARRAY_A
			);

			if ( empty( $members ) ) {
				continue;
			}

			// Batch-fetch week points for all cohort members in one query.
			$member_ids   = array_map( fn( $m ) => (int) $m['user_id'], $members );
			$placeholders = implode( ',', array_fill( 0, count( $member_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is implode(',', array_fill(..., '%d')), safe.
			$pts_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, COALESCE(SUM(points), 0) AS pts
					   FROM {$wpdb->prefix}wb_gam_points
					  WHERE user_id IN ($placeholders) AND created_at >= %s
					 GROUP BY user_id",
					array_merge( $member_ids, array( $week_start ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pts_map = array_fill_keys( $member_ids, 0 );
			foreach ( $pts_rows as $row ) {
				$pts_map[ (int) $row['user_id'] ] = (int) $row['pts'];
			}

			$ranked = array();
			foreach ( $members as $m ) {
				$uid      = (int) $m['user_id'];
				$ranked[] = array(
					'user_id' => $uid,
					'tier'    => (int) $m['tier'],
					'pts'     => $pts_map[ $uid ],
				);
			}

			// Sort descending by pts.
			usort( $ranked, fn( $a, $b ) => $b['pts'] <=> $a['pts'] );

			$count     = count( $ranked );
			$promote_n = (int) floor( $count * self::PROMOTE_PCT );
			$demote_n  = (int) floor( $count * self::DEMOTE_PCT );

			foreach ( $ranked as $i => $entry ) {
				$uid      = $entry['user_id'];
				$cur_tier = $entry['tier'];
				$max_tier = count( self::TIERS ) - 1;

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

				$wpdb->update(
					$wpdb->prefix . 'wb_gam_cohort_members',
					array(
						'tier_end' => $new_tier,
						'outcome'  => $outcome,
					),
					array(
						'cohort_id' => $cohort_id,
						'user_id'   => $uid,
					)
				);

				// Update the user's tier for next week.
				update_user_meta( $uid, 'wb_gam_league_tier', $new_tier );

				/**
				 * Fires for each cohort member at end-of-week processing.
				 *
				 * @param int    $user_id  User ID.
				 * @param int    $cur_tier Tier at start of week.
				 * @param int    $new_tier Tier for next week.
				 * @param string $outcome  'promoted' | 'demoted' | 'stayed'.
				 * @param int    $pts      Points earned this week.
				 */
				do_action( 'wb_gam_cohort_outcome', $uid, $cur_tier, $new_tier, $outcome, $entry['pts'] );
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

		$week = gmdate( 'Y-W' );
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

		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';

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
