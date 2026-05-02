<?php
/**
 * WB Gamification — Custom badge condition
 *
 * Use case: you want a badge that doesn't fit the "earn N points" or
 * "do action N times" patterns. This example registers two custom badge
 * triggers with arbitrary PHP conditions.
 *
 * Pattern: register_badge_trigger() ties a WP hook + a condition closure.
 * When the hook fires, your closure runs; if it returns true, the badge
 * is awarded.
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wb_gam_engines_booted', 'yourplugin_register_custom_badges' );

/**
 * Register two custom badge triggers.
 *
 * The badges themselves still need to exist — create them in WP Admin
 * → Gamification → Badges with the IDs used below ('night_owl',
 * 'comment_streak'). The trigger you register here only handles the
 * condition; the engine handles the actual awarding.
 */
function yourplugin_register_custom_badges(): void {
	if ( ! function_exists( 'wb_gamification_register_badge_trigger' ) ) {
		return;
	}

	/**
	 * Badge 1: "Night Owl" — comment between midnight and 4 AM
	 *          in the user's locale.
	 *
	 * Hooks on the same action that already awards points for commenting,
	 * runs an additional time-of-day check, and awards the badge if it
	 * passes.
	 */
	wb_gamification_register_badge_trigger( [
		'id'          => 'night_owl',
		'label'       => __( 'Night Owl', 'your-plugin' ),
		'description' => __( 'Awarded for commenting between midnight and 4 AM.', 'your-plugin' ),
		'hook'        => 'comment_post',
		// Closure receives the same args as the WP hook.
		// Return true to award, false to skip.
		'condition'   => function ( int $comment_id, $approved ) {
			if ( 1 !== (int) $approved ) {
				return false;
			}

			$comment = get_comment( $comment_id );
			if ( ! $comment || ! $comment->user_id ) {
				return false;
			}

			// Use the site's timezone so "midnight" is geographically meaningful.
			$tz   = wp_timezone();
			$hour = (int) ( new DateTimeImmutable( $comment->comment_date_gmt, new DateTimeZone( 'UTC' ) ) )
				->setTimezone( $tz )
				->format( 'G' );

			return $hour >= 0 && $hour < 4;
		},
	] );

	/**
	 * Badge 2: "Comment Streak" — comment on 7 different days in a row.
	 *
	 * Demonstrates state-tracking via user meta. The condition closure
	 * counts distinct dates and awards once on the 7th day.
	 */
	wb_gamification_register_badge_trigger( [
		'id'          => 'comment_streak',
		'label'       => __( 'Comment Streak', 'your-plugin' ),
		'description' => __( 'Awarded for commenting 7 days in a row.', 'your-plugin' ),
		'hook'        => 'comment_post',
		'condition'   => function ( int $comment_id, $approved ) {
			if ( 1 !== (int) $approved ) {
				return false;
			}

			$comment = get_comment( $comment_id );
			if ( ! $comment || ! $comment->user_id ) {
				return false;
			}

			$user_id = (int) $comment->user_id;
			$today   = wp_date( 'Y-m-d' );

			$dates_meta = get_user_meta( $user_id, '_yourplugin_comment_dates', true );
			$dates      = is_array( $dates_meta ) ? $dates_meta : [];

			// Already commented today? Don't double-count.
			if ( in_array( $today, $dates, true ) ) {
				return false;
			}

			$dates[] = $today;

			// Keep last 14 days for the rolling check.
			$dates = array_slice( $dates, -14 );
			update_user_meta( $user_id, '_yourplugin_comment_dates', $dates );

			// Count back from today, looking for 7 consecutive days.
			$streak = 0;
			for ( $i = 0; $i < 7; $i++ ) {
				$check = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
				if ( in_array( $check, $dates, true ) ) {
					$streak++;
				} else {
					break;
				}
			}

			return 7 === $streak;
		},
	] );
}

/**
 * Bonus: a "should award badge" filter for the 'night_owl' badge —
 * use this to add veto logic without rewriting the trigger.
 *
 * For example, "don't award the night_owl badge to admins":
 */
add_filter(
	'wb_gamification_should_award_badge',
	function ( bool $should_award, string $badge_id, int $user_id ) {
		if ( 'night_owl' !== $badge_id ) {
			return $should_award;
		}

		$user = get_userdata( $user_id );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			return false; // No badge for admins
		}

		return $should_award;
	},
	10,
	3
);
