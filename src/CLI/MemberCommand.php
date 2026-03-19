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
	 * --user=<id>
	 * : User ID, login name, or email address.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification member status --user=42
	 *   wp wb-gamification member status --user=jane@example.com
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function status( array $args, array $assoc_args ): void {
		$user = $this->resolve_user( $assoc_args['user'] ?? '' );

		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}

		$uid      = $user->ID;
		$points   = PointsEngine::get_total( $uid );
		$level    = LevelEngine::get_level_for_user( $uid );
		$next     = LevelEngine::get_next_level( $uid );
		$progress = LevelEngine::get_progress_percent( $uid );
		$badges   = BadgeEngine::get_user_earned_badge_ids( $uid );

		\WP_CLI::line( "User:    {$user->display_name} (ID: {$uid})" );
		\WP_CLI::line( "Points:  {$points}" );
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
