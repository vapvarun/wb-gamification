<?php
/**
 * WP-CLI: Export commands for WB Gamification.
 *
 * @package WB_Gamification
 * @since   0.5.1
 */

namespace WBGam\CLI;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\LevelEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Export gamification data for GDPR portability requests.
 *
 * @package WB_Gamification
 */
class ExportCommand {

	/**
	 * Export all gamification data for a user (GDPR portability).
	 *
	 * Outputs points history, earned badges, current level, and streak data.
	 * Pipe the output to a file for delivery to the member.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address. Positional because --user= is
	 *   a reserved WP-CLI global flag.
	 *
	 * [--format=<fmt>]
	 * : Output format. Only 'json' is supported. Default: json.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification export user 42
	 *   wp wb-gamification export user jane@example.com > export.json
	 *
	 * @param array $args       Positional args ([0] = user reference).
	 * @param array $assoc_args Named args.
	 */
	public function user( array $args, array $assoc_args ): void {
		$user   = $this->resolve_user( (string) ( $args[0] ?? '' ) );
		$format = $assoc_args['format'] ?? 'json';

		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}

		if ( 'json' !== $format ) {
			\WP_CLI::error( "Format '{$format}' is not supported. Use: json" );
		}

		$uid = $user->ID;

		$data = array(
			'user_id'           => $uid,
			'display_name'      => $user->display_name,
			'email'             => $user->user_email,
			'exported_at'       => gmdate( 'c' ),
			'points_total'      => PointsEngine::get_total( $uid ),
			// Multi-currency breakdown — empty on single-currency sites.
			'points_by_type'    => PointsEngine::get_totals_by_type( $uid ),
			'points_history'    => PointsEngine::get_history( $uid, 100 ),
			'badges'            => BadgeEngine::get_user_badges( $uid ),
			'level'             => LevelEngine::get_level_for_user( $uid ),
		);

		\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
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
