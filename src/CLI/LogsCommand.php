<?php
/**
 * WP-CLI: Logs commands for WB Gamification.
 *
 * @package WB_Gamification
 * @since   0.5.1
 */

namespace WBGam\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the WB Gamification event log from the command line.
 *
 * The event log (wb_gam_events) is the raw audit trail. Pruning it does NOT
 * affect the points ledger (wb_gam_points) or any derived state.
 *
 * @package WB_Gamification
 */
class LogsCommand {

	/**
	 * Prune old entries from the event log.
	 *
	 * Removes rows from wb_gam_events older than the given timespan.
	 * The points ledger, badges, and leaderboard are not affected.
	 *
	 * ## OPTIONS
	 *
	 * --before=<timespan>
	 * : Delete entries older than this. Examples: 6months, 1year, 90days.
	 *
	 * [--dry-run]
	 * : Show how many rows would be deleted without deleting them.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification logs prune --before=6months --dry-run
	 *   wp wb-gamification logs prune --before=1year
	 *   wp wb-gamification logs prune --before=90days
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function prune( array $args, array $assoc_args ): void {
		$before_raw = $assoc_args['before'] ?? '';
		$dry_run    = isset( $assoc_args['dry-run'] );

		if ( '' === $before_raw ) {
			\WP_CLI::error( '--before is required (e.g. 6months, 1year, 90days).' );
		}

		// Convert "6months" → "-6 months" for strtotime compatibility.
		$normalized = preg_replace( '/(\d+)([a-zA-Z]+)/', '-$1 $2', $before_raw );
		$cutoff     = strtotime( $normalized );

		if ( ! $cutoff ) {
			\WP_CLI::error(
				/* translators: %s: raw --before value provided by user */
				sprintf( "Could not parse '--before=%s'. Use formats like 6months, 1year, 90days.", $before_raw )
			);
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', $cutoff );

		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events WHERE created_at < %s",
				$cutoff_date
			)
		);

		if ( 0 === $count ) {
			\WP_CLI::line( "No event log entries found before {$cutoff_date}." );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::line( "[dry-run] Would delete {$count} event log entries older than {$cutoff_date}." );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_events WHERE created_at < %s",
				$cutoff_date
			)
		);

		\WP_CLI::success( "Deleted {$count} event log entries older than {$cutoff_date}." );
	}
}
