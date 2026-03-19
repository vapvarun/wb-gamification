<?php
/**
 * WP-CLI: Points commands for WB Gamification.
 *
 * @package WB_Gamification
 * @since   0.5.1
 */

namespace WBGam\CLI;

use WBGam\Engine\PointsEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Award and inspect gamification points from the command line.
 *
 * @package WB_Gamification
 */
class PointsCommand {

	/**
	 * Award points to a user.
	 *
	 * Bypasses cooldown and daily-cap checks — this is a direct admin award.
	 *
	 * ## OPTIONS
	 *
	 * --user=<id>
	 * : User ID, login name, or email address.
	 *
	 * --points=<n>
	 * : Number of points to award (positive integer).
	 *
	 * [--action=<id>]
	 * : Action ID to record in the ledger. Defaults to 'manual'.
	 *
	 * [--message=<msg>]
	 * : Optional admin note stored in event metadata.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification points award --user=42 --points=100
	 *   wp wb-gamification points award --user=jane --points=50 --message="Community hero this month"
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function award( array $args, array $assoc_args ): void {
		$user   = $this->resolve_user( $assoc_args['user'] ?? '' );
		$points = (int) ( $assoc_args['points'] ?? 0 );

		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}

		if ( $points <= 0 ) {
			\WP_CLI::error( '--points must be a positive integer.' );
		}

		$action_id = sanitize_key( $assoc_args['action'] ?? 'manual' ) ?: 'manual';
		$message   = sanitize_text_field( $assoc_args['message'] ?? '' );

		$awarded = PointsEngine::award( $user->ID, $action_id, $points );

		if ( ! $awarded ) {
			\WP_CLI::error( 'Award failed — check that the user is valid and points > 0.' );
		}

		$new_total = PointsEngine::get_total( $user->ID );

		$summary = $message
			? sprintf( 'Awarded %d pts to %s (%s). New total: %d.', $points, $user->display_name, $message, $new_total )
			: sprintf( 'Awarded %d pts to %s. New total: %d.', $points, $user->display_name, $new_total );

		\WP_CLI::success( $summary );
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
