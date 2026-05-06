<?php
/**
 * WP-CLI: Member status commands for WB Gamification.
 *
 * @package WB_Gamification
 * @since   0.5.1
 */

namespace WBGam\CLI;

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
			$primary  = $catalog[0] ?? null;
			$label    = $primary ? (string) $primary['label'] : $default_label;
			$primary_slug = $primary ? (string) $primary['slug'] : null;
			$balance  = (int) ( $primary_slug ? ( $balances[ $primary_slug ] ?? 0 ) : 0 );
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
}
