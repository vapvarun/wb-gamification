<?php
/**
 * WP-CLI: Member status commands for WB Gamification.
 *
 * @package WB_Gamification
 * @since   0.5.1
 */

namespace WBGam\CLI;

use WBGam\Engine\MemberData;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\BadgeEngine;
use WBGam\Services\PointTypeService;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect a member's gamification profile from the command line.
 *
 * @package WB_Gamification
 */
class MemberCommand {

	/**
	 * Show a member's gamification status.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address. Positional because --user= is
	 *   a reserved WP-CLI global flag.
	 *
	 * [--type=<slug>]
	 * : Show the balance for a single point-type only (e.g. 'points',
	 * 'coins'). When omitted, every active currency is listed on its own line.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification member status 42
	 *   wp wb-gamification member status jane@example.com
	 *   wp wb-gamification member status 42 --type=coins
	 *
	 * @param array $args       Positional args ([0] = user reference).
	 * @param array $assoc_args Named args.
	 */
	public function status( array $args, array $assoc_args ): void {
		$user = $this->resolve_user( (string) ( $args[0] ?? '' ) );

		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}

		$uid      = $user->ID;
		$level    = LevelEngine::get_level_for_user( $uid );
		$next     = LevelEngine::get_next_level( $uid );
		$progress = LevelEngine::get_progress_percent( $uid );
		$badges   = BadgeEngine::get_user_earned_badge_ids( $uid );

		\WP_CLI::line( "User:    {$user->display_name} (ID: {$uid})" );

		$service       = new PointTypeService();
		$type_arg      = (string) ( $assoc_args['type'] ?? '' );
		$catalog       = $service->list();
		$balances      = PointsEngine::get_totals_by_type( $uid );
		$default_label = __( 'Points', 'wb-gamification' );

		if ( '' !== $type_arg ) {
			$slug    = $service->resolve( $type_arg );
			$record  = $service->get( $slug );
			$label   = $record ? (string) $record['label'] : $default_label;
			$balance = (int) ( $balances[ $slug ] ?? 0 );
			\WP_CLI::line( sprintf( '%-8s %d', $label . ':', $balance ) );
		} elseif ( count( $catalog ) > 1 ) {
			foreach ( $catalog as $row ) {
				$slug    = (string) $row['slug'];
				$balance = (int) ( $balances[ $slug ] ?? 0 );
				\WP_CLI::line( sprintf( '%-8s %d', (string) $row['label'] . ':', $balance ) );
			}
		} else {
			$primary      = $catalog[0] ?? null;
			$label        = $primary ? (string) $primary['label'] : $default_label;
			$primary_slug = $primary ? (string) $primary['slug'] : null;
			$balance      = (int) ( $primary_slug ? ( $balances[ $primary_slug ] ?? 0 ) : 0 );
			\WP_CLI::line( sprintf( '%-8s %d', $label . ':', $balance ) );
		}

		\WP_CLI::line( 'Level:   ' . ( $level ? $level['name'] : '—' ) );

		if ( $next ) {
			\WP_CLI::line( "Next:    {$next['name']} ({$next['min_points']} pts) — {$progress}% there" );
		} else {
			\WP_CLI::line( 'Next:    Max level reached' );
		}

		$badge_count = count( $badges );
		\WP_CLI::line( "Badges:  {$badge_count}" );

		if ( $badges ) {
			\WP_CLI::line( '         ' . implode( ', ', $badges ) );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Resolve a user ID, login, or email to a WP_User object.
	 *
	 * @param string $ref User ID, login, or email.
	 * @return \WP_User|null
	 */
	private function resolve_user( string $ref ): ?\WP_User {
		if ( '' === $ref ) {
			return null;
		}

		if ( is_numeric( $ref ) ) {
			$user = get_user_by( 'id', (int) $ref );
		} else {
			$user = get_user_by( 'login', $ref ) ?: get_user_by( 'email', $ref );
		}

		return $user ?: null;
	}

	/**
	 * Remove gamification rows belonging to members who no longer exist.
	 *
	 * Nothing listened for `deleted_user` before 1.6.4, so every site that has ever deleted a member
	 * is carrying their points, streaks, badges, cohort rows and queued notifications for ever. Those
	 * rows also corrupt the Analytics dashboard: the percentages divide ghost rows by live members,
	 * which is how "6822.5% streak health" happens.
	 *
	 * This is a COMMAND, not an upgrade migration. Silently deleting a site owner's data because they
	 * installed a patch is not something a plugin gets to do. Run --dry-run first; it shows exactly
	 * what would go.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be removed without removing it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification member purge-orphans --dry-run
	 *     wp wb-gamification member purge-orphans
	 *
	 * @subcommand purge-orphans
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 * @return void
	 */
	public function purge_orphans( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		$orphans = MemberData::count_orphans();

		if ( ! $orphans ) {
			\WP_CLI::success( 'No orphaned rows — every gamification row belongs to a member who exists.' );
			return;
		}

		$total = array_sum( $orphans );

		\WP_CLI::log( 'Rows belonging to members who no longer exist:' );
		foreach ( $orphans as $table => $count ) {
			\WP_CLI::log( sprintf( '  %-46s %d', $table, $count ) );
		}
		\WP_CLI::log( sprintf( '  %-46s %d', 'TOTAL', $total ) );

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run — %d row(s) would be removed. Re-run without --dry-run to remove them.', $total ) );
			return;
		}

		$removed = MemberData::purge_orphans();

		\WP_CLI::success( sprintf( '%d orphaned row(s) removed.', array_sum( $removed ) ) );
	}
}
