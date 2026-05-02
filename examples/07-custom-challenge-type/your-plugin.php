<?php
/**
 * WB Gamification — Custom challenge type
 *
 * Challenges out of the box: "do X N times in Y days". This example
 * registers two custom mechanics that don't fit that pattern:
 *
 *   - "Streak Challenge"     — N consecutive days, not just N total
 *   - "Diversity Challenge"  — do M different actions (not the same one)
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wb_gam_engines_booted', 'yourplugin_register_custom_challenges' );

/**
 * Register custom challenge types.
 */
function yourplugin_register_custom_challenges(): void {
	if ( ! function_exists( 'wb_gamification_register_challenge_type' ) ) {
		return;
	}

	/**
	 * Streak Challenge — log in / contribute on N consecutive days.
	 *
	 * Different from the default "do X N times" challenge in that it
	 * REQUIRES consecutive days. Missing a day resets progress.
	 */
	wb_gamification_register_challenge_type( [
		'id'          => 'streak_challenge',
		'label'       => __( 'Streak Challenge', 'your-plugin' ),
		'description' => __( 'Complete an action on N consecutive days. Missing a day resets progress.', 'your-plugin' ),

		// progress(): called on every event matching the challenge's
		// target_action. Receives the user_id, the challenge config row,
		// and the new event. Returns the updated progress (an int) or
		// 'completed' to mark complete.
		'progress' => function ( int $user_id, object $challenge, array $event ) {
			$today = wp_date( 'Y-m-d' );

			// Read current streak state for this challenge.
			$state = get_user_meta( $user_id, "_streak_chal_{$challenge->id}", true );
			$state = is_array( $state ) ? $state : [ 'last_date' => null, 'count' => 0 ];

			if ( $state['last_date'] === $today ) {
				return $state['count']; // Already counted today
			}

			$yesterday = wp_date( 'Y-m-d', strtotime( '-1 day' ) );

			if ( $state['last_date'] === $yesterday ) {
				// Continuing the streak
				$state['count']++;
			} else {
				// Streak broken — reset to 1 (today is day 1 of new streak)
				$state['count'] = 1;
			}

			$state['last_date'] = $today;
			update_user_meta( $user_id, "_streak_chal_{$challenge->id}", $state );

			return $state['count'] >= (int) $challenge->target_count
				? 'completed'
				: $state['count'];
		},

		// reset(): how to clear progress when the challenge is reset
		// (admin action, or new cycle for recurring challenges).
		'reset' => function ( int $user_id, object $challenge ) {
			delete_user_meta( $user_id, "_streak_chal_{$challenge->id}" );
		},
	] );

	/**
	 * Diversity Challenge — do M different actions.
	 *
	 * Example: "Try 5 different gamification features this week".
	 * Each event counts only if its action_id is unique within the
	 * challenge window.
	 */
	wb_gamification_register_challenge_type( [
		'id'          => 'diversity_challenge',
		'label'       => __( 'Diversity Challenge', 'your-plugin' ),
		'description' => __( 'Perform M different distinct actions during the challenge.', 'your-plugin' ),

		'progress' => function ( int $user_id, object $challenge, array $event ) {
			$action_id = $event['action_id'] ?? '';
			if ( ! $action_id ) {
				return 0;
			}

			$key = "_diversity_chal_{$challenge->id}";
			$seen = get_user_meta( $user_id, $key, true );
			$seen = is_array( $seen ) ? $seen : [];

			if ( in_array( $action_id, $seen, true ) ) {
				return count( $seen ); // Already counted this action
			}

			$seen[] = $action_id;
			update_user_meta( $user_id, $key, $seen );

			return count( $seen ) >= (int) $challenge->target_count
				? 'completed'
				: count( $seen );
		},

		'reset' => function ( int $user_id, object $challenge ) {
			delete_user_meta( $user_id, "_diversity_chal_{$challenge->id}" );
		},
	] );
}

/**
 * Bonus: react to challenge completion.
 *
 * The engine fires wb_gamification_challenge_completed when any
 * challenge (built-in or custom) hits its target. Hook this to grant
 * extra rewards beyond the configured bonus_points.
 */
add_action(
	'wb_gamification_challenge_completed',
	function ( int $user_id, int $challenge_id ) {
		// Send a personal congrats email
		// Or grant a temporary cosmetic via CosmeticEngine
		// Or post to the BuddyPress activity stream
	},
	10,
	2
);
