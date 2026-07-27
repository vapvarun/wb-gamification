<?php
/**
 * WP-CLI: badge sharing.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */

namespace WBGam\CLI;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI, custom table.

/**
 * Manage which badges members have published.
 *
 * @package WB_Gamification
 */
final class ShareCommand {

	/**
	 * Publish every badge every member currently holds.
	 *
	 * THE ESCAPE HATCH, not the default, and worth understanding before you run it.
	 *
	 * Until 1.6.4 the badge share card, the OpenBadges credential and the public share page all served
	 * any member's badge to anyone who could guess a badge id and a user id -- the member was never
	 * asked. Sharing is now a member's own decision, recorded when they press Share, and every badge
	 * earned before that decision existed starts private.
	 *
	 * The cost of that is real: a member who had already posted a credential link to LinkedIn will find
	 * it 404s until they publish it again. If you knowingly ran an open community on the old behaviour
	 * and would rather not break those links, this command restores them in one pass.
	 *
	 * It publishes EVERY badge of EVERY member, which is exactly the state the plugin used to be in.
	 * It is not a fix for the privacy hole; it is a decision to keep living with it, taken with your
	 * eyes open. Members can still unpublish any badge individually afterwards.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report how many badges would be published, and change nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification share grandfather --dry-run
	 *     wp wb-gamification share grandfather
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function grandfather( array $args, array $assoc_args ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wb_gam_user_badges';

		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE shared_at IS NULL" );

		if ( 0 === $pending ) {
			\WP_CLI::success( 'Every earned badge is already published. Nothing to do.' );
			return;
		}

		$members = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE shared_at IS NULL" );

		\WP_CLI::log(
			sprintf(
				'%s badge(s) across %s member(s) are currently private.',
				number_format_i18n( $pending ),
				number_format_i18n( $members )
			)
		);

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}

		\WP_CLI::warning( 'This publishes every one of them, on behalf of members who have not asked for it.' );
		\WP_CLI::confirm( 'Publish all of them?', $assoc_args );

		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"UPDATE {$table} SET shared_at = %s WHERE shared_at IS NULL",
				current_time( 'mysql' )
			)
		);

		wp_cache_flush();

		\WP_CLI::success( sprintf( 'Published %s badge(s). Members can unpublish any of them individually.', number_format_i18n( $updated ) ) );
	}

	/**
	 * Make every badge private again.
	 *
	 * The inverse of `grandfather`. Withdraws every member's publication in one pass -- useful if a
	 * `grandfather` run was a mistake, or an owner wants to reset to consent-only.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification share reset
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function reset( array $args, array $assoc_args ): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'wb_gam_user_badges';
		$shared = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE shared_at IS NOT NULL" );

		if ( 0 === $shared ) {
			\WP_CLI::success( 'No published badges. Nothing to do.' );
			return;
		}

		\WP_CLI::log( sprintf( '%s badge(s) are currently published.', number_format_i18n( $shared ) ) );
		\WP_CLI::confirm( 'Unpublish all of them? Any share links members have posted will stop resolving.', $assoc_args );

		$updated = (int) $wpdb->query( "UPDATE {$table} SET shared_at = NULL WHERE shared_at IS NOT NULL" );

		wp_cache_flush();

		\WP_CLI::success( sprintf( 'Unpublished %s badge(s).', number_format_i18n( $updated ) ) );
	}
}
