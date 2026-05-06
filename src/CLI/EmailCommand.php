<?php
/**
 * WP-CLI: Email test command for WB Gamification.
 *
 * Send a sample transactional email to a member to verify template
 * rendering, theme override resolution, and SMTP delivery on the
 * site. Bypasses the per-event admin toggle so admins can test even
 * when the email type is disabled in production settings.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\CLI;

use WBGam\Engine\Email;
use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Services\PointTypeService;

defined( 'ABSPATH' ) || exit;

/**
 * Send sample transactional emails for testing.
 */
class EmailCommand {

	/**
	 * Send a sample email of a given type to a member.
	 *
	 * Renders the same template the site's transactional pipeline uses,
	 * with placeholder data when needed (sample badge / level / challenge).
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login name, or email address. Positional because --user= is
	 *   a reserved WP-CLI global flag.
	 *
	 * --event=<slug>
	 * : Email type. One of: level_up | badge_earned | challenge_completed | weekly_recap | leaderboard_nudge.
	 *
	 * ## EXAMPLES
	 *
	 *   wp wb-gamification email-test 42 --event=level_up
	 *   wp wb-gamification email-test jane --event=badge_earned
	 *
	 * @param array $args       Positional args ([0] = user reference).
	 * @param array $assoc_args Named args.
	 */
	public function test( array $args, array $assoc_args ): void {
		$user = $this->resolve_user( (string) ( $args[0] ?? '' ) );
		$event = sanitize_key( $assoc_args['event'] ?? '' );

		if ( ! $user ) {
			\WP_CLI::error( 'User not found.' );
		}
		if ( ! in_array( $event, array( 'level_up', 'badge_earned', 'challenge_completed', 'weekly_recap', 'leaderboard_nudge' ), true ) ) {
			\WP_CLI::error( 'Unknown --event. Use one of: level_up, badge_earned, challenge_completed, weekly_recap, leaderboard_nudge.' );
		}

		$pt_service   = new PointTypeService();
		$pt_record    = $pt_service->get( $pt_service->default_slug() );
		$points_label = (string) ( $pt_record['label'] ?? __( 'Points', 'wb-gamification' ) );
		$site_name    = (string) get_bloginfo( 'name' );
		$base_vars    = array(
			'user'         => $user,
			'name'         => esc_html( (string) $user->display_name ),
			'site_name'    => $site_name,
			'site_url'     => home_url( '/' ),
			'points_label' => $points_label,
		);

		$template = '';
		$subject  = '';
		$vars     = $base_vars;

		switch ( $event ) {
			case 'level_up':
				$template = 'level-up';
				$current  = LevelEngine::get_level_for_user( $user->ID );
				$vars    += array(
					'old_level_name' => __( 'Newcomer', 'wb-gamification' ),
					'new_level_name' => $current['name'] ?? __( 'Member', 'wb-gamification' ),
					'new_level_min'  => (int) ( $current['min_points'] ?? 100 ),
					'points'         => (int) PointsEngine::get_total( $user->ID ),
				);
				$subject = sprintf(
					/* translators: %s: level name */
					__( '[TEST] You reached %s!', 'wb-gamification' ),
					$vars['new_level_name']
				);
				break;

			case 'badge_earned':
				$template = 'badge-earned';
				$vars    += array(
					'badge_id'          => 'sample',
					'badge_name'        => __( 'Sample Badge', 'wb-gamification' ),
					'badge_description' => __( 'A sample badge for email testing.', 'wb-gamification' ),
					'badge_image_url'   => '',
					'share_url'         => home_url( '/' ),
				);
				$subject = __( '[TEST] You earned the Sample Badge!', 'wb-gamification' );
				break;

			case 'challenge_completed':
				$template = 'challenge-completed';
				$vars    += array(
					'challenge_title'       => __( 'Sample Challenge', 'wb-gamification' ),
					'challenge_description' => __( 'A sample challenge for email testing.', 'wb-gamification' ),
					'reward_label'         => sprintf( '50 %s', $points_label ),
				);
				$subject = __( '[TEST] Challenge completed: Sample Challenge', 'wb-gamification' );
				break;

			case 'weekly_recap':
				$template = 'weekly-recap';
				$vars    += array(
					'unsub_url'           => '#',
					'points_this_week'    => 120,
					'total_points'        => (int) PointsEngine::get_total( $user->ID ),
					'is_best'             => false,
					'best_week'           => 100,
					'badges_this_week'    => array(),
					'challenges_this_week'=> array(),
					'streak'              => array( 'current_streak' => 5, 'longest_streak' => 12 ),
					'rank'                => 7,
				);
				$subject = sprintf(
					/* translators: %s: site name */
					__( '[TEST] Your week in %s', 'wb-gamification' ),
					$site_name
				);
				break;

			case 'leaderboard_nudge':
				$template = 'leaderboard-nudge';
				$vars    += array(
					'message' => __( "You're #3 this week with 240 points.", 'wb-gamification' ),
					'rank'    => 3,
					'points'  => 240,
				);
				$subject = sprintf(
					/* translators: %s: site name */
					__( '[TEST] Your weekly community ranking at %s', 'wb-gamification' ),
					$site_name
				);
				break;
		}

		$body = Email::render( $template, $vars );
		if ( '' === $body ) {
			\WP_CLI::error( "Template '{$template}' did not render — check the file exists at templates/emails/{$template}.php or theme override." );
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Email::from_header(),
		);
		$sent = wp_mail( $user->user_email, $subject, $body, $headers );

		if ( $sent ) {
			\WP_CLI::success( "Sent {$event} sample to {$user->user_email}." );
		} else {
			\WP_CLI::error( "wp_mail returned false. Check SMTP / mail server config." );
		}
	}

	/**
	 * Resolve a user ID, login, or email to a WP_User object.
	 *
	 * @param string $ref User ID, login, or email.
	 */
	private function resolve_user( string $ref ): ?\WP_User {
		if ( '' === $ref ) {
			return null;
		}
		$user = is_numeric( $ref )
			? get_user_by( 'id', (int) $ref )
			: ( get_user_by( 'login', $ref ) ?: get_user_by( 'email', $ref ) );
		return $user ?: null;
	}
}
