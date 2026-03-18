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

/**
 * Weekly promotion/demotion league cohort engine (Duolingo model).
 *
 * @package WB_Gamification
 */
final class CohortEngine {

	public const TIERS         = array( 'Bronze', 'Silver', 'Gold', 'Diamond', 'Obsidian' );
	public const COHORT_SIZE   = 30;
	public const PROMOTE_PCT   = 0.33;
	public const DEMOTE_PCT    = 0.33;
	private const CRON_ASSIGN  = 'wb_gam_cohort_assign';
	private const CRON_PROCESS = 'wb_gam_cohort_process';

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
		global $wpdb;

		$week = gmdate( 'Y-W' );

		// Get all active users (earned points in last 4 weeks).
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->prefix}wb_gam_points
				  WHERE created_at >= %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-4 weeks' ) )
			)
		);

		if ( empty( $user_ids ) ) {
			return;
		}

		$ids_ints = array_map( 'intval', $user_ids );

		// Prime the object cache for all users' tier meta in one round-trip.
		update_meta_cache( 'user', $ids_ints );

		// Get current tiers — all meta cache hits after the bulk prime above.
		$tier_map = array();
		foreach ( $ids_ints as $uid ) {
			$tier_map[ $uid ] = self::get_user_tier( $uid );
		}

		// Batch-fetch weekly points for all users in one query.
		$week_start   = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
		$placeholders = implode( ',', array_fill( 0, count( $ids_ints ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is implode(',', array_fill(..., '%d')), safe.
		$pts_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COALESCE(SUM(points), 0) AS pts
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id IN ($placeholders) AND created_at >= %s
				 GROUP BY user_id",
				array_merge( $ids_ints, array( $week_start ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$week_pts = array_fill_keys( $ids_ints, 0 );
		foreach ( $pts_rows as $row ) {
			$week_pts[ (int) $row['user_id'] ] = (int) $row['pts'];
		}

		// Group users by tier.
		$by_tier = array_fill( 0, count( self::TIERS ), array() );
		foreach ( $ids_ints as $uid ) {
			$by_tier[ $tier_map[ $uid ] ][] = $uid;
		}

		// Sort within each tier by weekly points desc.
		foreach ( $by_tier as $tier => &$members ) {
			usort( $members, fn( $a, $b ) => $week_pts[ $b ] <=> $week_pts[ $a ] );
		}
		unset( $members );

		// Split into cohorts of COHORT_SIZE and persist.
		foreach ( $by_tier as $tier => $members ) {
			$chunks = array_chunk( $members, self::COHORT_SIZE );
			foreach ( $chunks as $cohort_idx => $chunk ) {
				$cohort_id = "{$week}-t{$tier}-{$cohort_idx}";
				foreach ( $chunk as $uid ) {
					$wpdb->replace(
						$wpdb->prefix . 'wb_gam_cohort_members',
						array(
							'user_id'   => $uid,
							'cohort_id' => $cohort_id,
							'tier'      => $tier,
							'week'      => $week,
							'pts_start' => $week_pts[ $uid ],
						),
						array( '%d', '%s', '%d', '%s', '%d' )
					);
				}
			}
		}
	}

	// ── Promotion processing ─────────────────────────────────────────────────

	/**
	 * Process end-of-week promotions and demotions.
	 */
	public static function process_promotions(): void {
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
				do_action( 'wb_gamification_cohort_outcome', $uid, $cur_tier, $new_tier, $outcome, $entry['pts'] );
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
			if ( $m['user_id'] === $user_id ) {
				$m['is_me'] = true;
			}
		}
		unset( $m );

		return array(
			'cohort_id' => $row['cohort_id'],
			'tier'      => (int) $row['tier'],
			'tier_name' => self::TIERS[ (int) $row['tier'] ] ?? 'Bronze',
			'week'      => $week,
			'standings' => array_values( $members ),
		);
	}
}
