<?php
/**
 * WB Gamification — Recap Engine (Phase 3)
 *
 * Computes a "Year in Community" summary for a given user and year.
 * Inspired by Spotify Wrapped — surfaces moments of pride and
 * encourages sharing to drive organic growth.
 *
 * Data returned:
 *   - Total points earned in year
 *   - Points rank vs all members (percentile)
 *   - Top 3 actions by event count
 *   - Badges earned in year (count + list)
 *   - Peak activity week (ISO week, points earned)
 *   - Longest streak achieved in year (from points log density)
 *   - Challenges completed in year
 *   - Kudos given and received in year
 *   - Total events (actions) fired in year
 *   - Headline stat ("You were in the top N% of contributors")
 *
 * All queries are index-range scans on created_at (already indexed).
 * Results are object-cache backed (1 hour TTL).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

final class RecapEngine {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_TTL   = HOUR_IN_SECONDS;

	/**
	 * Get the full year recap for a user.
	 *
	 * @param int $user_id
	 * @param int $year  Four-digit year (e.g. 2024). 0 = previous calendar year.
	 * @return array
	 */
	public static function get_recap( int $user_id, int $year = 0 ): array {
		if ( $year <= 0 ) {
			$year = (int) gmdate( 'Y' ) - 1;
		}

		$cache_key = "wb_gam_recap_{$user_id}_{$year}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$start = "{$year}-01-01 00:00:00";
		$end   = "{$year}-12-31 23:59:59";

		global $wpdb;

		// ── Points earned this year ──────────────────────────────────────────
		$points_this_year = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0)
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND created_at BETWEEN %s AND %s",
				$user_id, $start, $end
			)
		);

		// ── Total events fired ──────────────────────────────────────────────
		$total_events = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events
				  WHERE user_id = %d AND created_at BETWEEN %s AND %s",
				$user_id, $start, $end
			)
		);

		// ── Top 3 actions by frequency ──────────────────────────────────────
		$top_actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, COUNT(*) AS event_count
				   FROM {$wpdb->prefix}wb_gam_events
				  WHERE user_id = %d AND created_at BETWEEN %s AND %s
				  GROUP BY action_id
				  ORDER BY event_count DESC
				  LIMIT 3",
				$user_id, $start, $end
			),
			ARRAY_A
		) ?: [];

		// ── Badges earned this year ─────────────────────────────────────────
		$badges_this_year = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ub.badge_id, b.name, ub.earned_at
				   FROM {$wpdb->prefix}wb_gam_user_badges ub
				   JOIN {$wpdb->prefix}wb_gam_badge_defs b ON b.id = ub.badge_id
				  WHERE ub.user_id = %d AND ub.earned_at BETWEEN %s AND %s
				  ORDER BY ub.earned_at ASC",
				$user_id, $start, $end
			),
			ARRAY_A
		) ?: [];

		// ── Peak activity week ──────────────────────────────────────────────
		$peak_week = self::get_peak_week( $user_id, $year );

		// ── Challenges completed this year ──────────────────────────────────
		$challenges_completed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_challenge_log
				  WHERE user_id = %d
				    AND completed_at IS NOT NULL
				    AND completed_at BETWEEN %s AND %s",
				$user_id, $start, $end
			)
		);

		// ── Kudos given / received ──────────────────────────────────────────
		$kudos_given = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos
				  WHERE giver_id = %d AND created_at BETWEEN %s AND %s",
				$user_id, $start, $end
			)
		);

		$kudos_received = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_kudos
				  WHERE receiver_id = %d AND created_at BETWEEN %s AND %s",
				$user_id, $start, $end
			)
		);

		// ── Rank / percentile among all members in year ─────────────────────
		$percentile = self::get_points_percentile( $user_id, $year, $points_this_year );

		// ── Headline ─────────────────────────────────────────────────────────
		$headline = self::compute_headline( $points_this_year, $percentile, $badges_this_year, $challenges_completed );

		$recap = [
			'user_id'             => $user_id,
			'year'                => $year,
			'points_this_year'    => $points_this_year,
			'total_events'        => $total_events,
			'top_actions'         => array_map(
				static fn( array $r ) => [
					'action_id'   => $r['action_id'],
					'event_count' => (int) $r['event_count'],
				],
				$top_actions
			),
			'badges_earned'       => [
				'count'  => count( $badges_this_year ),
				'badges' => array_map(
					static fn( array $r ) => [
						'badge_id'  => $r['badge_id'],
						'name'      => $r['name'],
						'earned_at' => $r['earned_at'],
					],
					$badges_this_year
				),
			],
			'peak_week'           => $peak_week,
			'challenges_completed' => $challenges_completed,
			'kudos'               => [
				'given'    => $kudos_given,
				'received' => $kudos_received,
			],
			'percentile'          => $percentile,
			'headline'            => $headline,
		];

		/**
		 * Filter the recap data before caching and returning.
		 *
		 * @param array $recap   Computed recap data.
		 * @param int   $user_id User ID.
		 * @param int   $year    Recap year.
		 */
		$recap = (array) apply_filters( 'wb_gamification_recap_data', $recap, $user_id, $year );

		wp_cache_set( $cache_key, $recap, self::CACHE_GROUP, self::CACHE_TTL );

		return $recap;
	}

	// ── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Find the ISO week in which the user earned the most points.
	 *
	 * @return array{ week: string, points: int }|null
	 */
	private static function get_peak_week( int $user_id, int $year ): ?array {
		global $wpdb;

		$start = "{$year}-01-01 00:00:00";
		$end   = "{$year}-12-31 23:59:59";

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-W%%V') AS iso_week,
				        SUM(points) AS week_points
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d AND created_at BETWEEN %s AND %s
				  GROUP BY iso_week
				  ORDER BY week_points DESC
				  LIMIT 1",
				$user_id, $start, $end
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'week'   => $row['iso_week'],
			'points' => (int) $row['week_points'],
		];
	}

	/**
	 * Compute the user's points percentile among all members who earned points
	 * in the given year. Returns a rounded integer 1–100 (100 = top earner).
	 */
	private static function get_points_percentile( int $user_id, int $year, int $user_points ): int {
		if ( $user_points <= 0 ) {
			return 0;
		}

		global $wpdb;

		$start = "{$year}-01-01 00:00:00";
		$end   = "{$year}-12-31 23:59:59";

		$total_members = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id)
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE created_at BETWEEN %s AND %s",
				$start, $end
			)
		);

		if ( $total_members <= 1 ) {
			return 100;
		}

		// Members who earned strictly fewer points than this user.
		$below = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
				    SELECT user_id, SUM(points) AS total
				      FROM {$wpdb->prefix}wb_gam_points
				     WHERE created_at BETWEEN %s AND %s
				     GROUP BY user_id
				    HAVING total < %d
				 ) AS sub",
				$start, $end, $user_points
			)
		);

		return (int) round( ( $below / $total_members ) * 100 );
	}

	/**
	 * Generate a short, personalised headline for the recap card.
	 */
	private static function compute_headline(
		int   $points,
		int   $percentile,
		array $badges,
		int   $challenges
	): string {
		if ( $points <= 0 ) {
			return __( 'Your journey starts here — see you next year!', 'wb-gamification' );
		}

		if ( $percentile >= 99 ) {
			return __( 'Top 1% contributor. You defined this community this year.', 'wb-gamification' );
		}

		if ( $percentile >= 90 ) {
			return sprintf(
				/* translators: %d = percentile rank */
				__( 'Top %d%% contributor — an outstanding year.', 'wb-gamification' ),
				100 - $percentile
			);
		}

		if ( $percentile >= 75 ) {
			return sprintf(
				/* translators: %d = percentile rank */
				__( 'You were in the top %d%% of contributors this year.', 'wb-gamification' ),
				100 - $percentile
			);
		}

		if ( count( $badges ) >= 5 ) {
			return sprintf(
				/* translators: %d = badge count */
				__( 'A badge collector — you earned %d badges this year.', 'wb-gamification' ),
				count( $badges )
			);
		}

		if ( $challenges >= 3 ) {
			return sprintf(
				/* translators: %d = challenges */
				__( 'Challenge champion — you completed %d challenges this year.', 'wb-gamification' ),
				$challenges
			);
		}

		return sprintf(
			/* translators: %d = points earned */
			__( 'You earned %s points this year — keep the momentum going!', 'wb-gamification' ),
			number_format_i18n( $points )
		);
	}
}
