<?php
/**
 * WB Gamification — Leaderboard customization (output filter)
 *
 * Use case: add a custom column or annotation to the leaderboard
 * results without forking the leaderboard block.
 *
 * The wb_gamification_leaderboard_results filter runs in
 * LeaderboardController::get_leaderboard right before the response is
 * returned. It receives the rows array and lets you transform it.
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pattern 1: Add a "country" annotation per row.
 *
 * Reads the user's country from a meta field (set by your registration
 * flow or a profile-field plugin) and adds it to each row.
 */
add_filter(
	'wb_gamification_leaderboard_results',
	function ( array $rows, array $args ): array {
		foreach ( $rows as &$row ) {
			$country = get_user_meta( $row['user_id'], 'country', true );
			$row['country'] = $country ?: 'Unknown';
			$row['country_flag_emoji'] = yourplugin_country_to_flag_emoji( $country );
		}
		return $rows;
	},
	10,
	2
);

/**
 * Pattern 2: Filter to top-N per country.
 *
 * If args['scope']['type'] === 'country', filter the rows to only
 * include users from that country. This unlocks per-country leaderboards
 * via REST: GET /leaderboard?scope_type=country&scope_id=US
 */
add_filter(
	'wb_gamification_leaderboard_results',
	function ( array $rows, array $args ): array {
		if ( ( $args['scope']['type'] ?? '' ) !== 'country' ) {
			return $rows;
		}

		$target_country = $args['scope']['id'] ?? '';
		if ( ! $target_country ) {
			return $rows;
		}

		$rows = array_values( array_filter(
			$rows,
			static fn( $row ) => ( $row['country'] ?? '' ) === $target_country
		) );

		// Re-rank within the filtered set
		foreach ( $rows as $i => &$row ) {
			$row['rank'] = $i + 1;
		}

		return $rows;
	},
	20,                                         // After Pattern 1 has set country
	2
);

/**
 * Pattern 3: Add a "trending" indicator.
 *
 * Compare each user's current points to their points 7 days ago,
 * mark anyone gaining >100 in the last week as 'trending'.
 *
 * Performance note: this loops with a DB hit per row. For large
 * leaderboards (>50 rows), batch the query or pre-compute via cron.
 */
add_filter(
	'wb_gamification_leaderboard_results',
	function ( array $rows, array $args ): array {
		global $wpdb;

		foreach ( $rows as &$row ) {
			$week_ago = $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0)
				 FROM {$wpdb->prefix}wb_gam_points
				 WHERE user_id = %d
				   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
				$row['user_id']
			) );
			$row['trending'] = (int) $week_ago > 100;
			$row['weekly_gain'] = (int) $week_ago;
		}
		return $rows;
	},
	30,
	2
);

/**
 * Helper: country code → flag emoji.
 *
 * @param string $code Two-letter country code (e.g. 'US', 'IN').
 */
function yourplugin_country_to_flag_emoji( string $code ): string {
	if ( strlen( $code ) !== 2 ) {
		return '';
	}
	$code = strtoupper( $code );
	$base = ord( '🇦' ) - ord( 'A' );
	return mb_chr( ord( $code[0] ) + $base ) . mb_chr( ord( $code[1] ) + $base );
}

/**
 * Optional: filter cron-cached leaderboard snapshots.
 *
 * If you only filter the on-the-fly response, the 5-min cron snapshot
 * still has the unfiltered version. To make the snapshot match, also
 * filter the snapshot before it's written:
 */
add_filter(
	'wb_gamification_leaderboard_snapshot',
	function ( array $snapshot, string $period ): array {
		// Same shape as wb_gamification_leaderboard_results — apply same
		// transforms to keep cached + live consistent.
		return $snapshot;
	},
	10,
	2
);
