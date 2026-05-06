<?php
/**
 * WB Gamification — Modify points per action (filter usage)
 *
 * Use case: tier-based or context-aware point multipliers. Without
 * touching the engine, you can transform the points awarded for any
 * action just before they're written to the ledger.
 *
 * The wb_gam_points_for_action filter runs INSIDE PointsEngine
 * just before the points row is inserted, after rate-limiting, after
 * before-evaluate, before the points_awarded action fires.
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pattern 1: VIP multiplier (membership-tier based).
 *
 * VIP members get 2× points on every action. Free members get 1×.
 * Premium members get 1.5×.
 */
add_filter(
	'wb_gam_points_for_action',
	function ( int $points, string $action_id, int $user_id ): int {
		$tier = get_user_meta( $user_id, 'yourplugin_membership_tier', true );

		$multiplier = match ( $tier ) {
			'vip'     => 2.0,
			'premium' => 1.5,
			default   => 1.0,
		};

		return (int) round( $points * $multiplier );
	},
	10,
	3
);

/**
 * Pattern 2: Per-action context multiplier.
 *
 * "Publish a blog post" gets 25 points by default. We want to award:
 *   - 50 points if the post is in the "Tutorials" category
 *   - 25 points otherwise (default)
 *
 * The filter receives the action_id, so we can check before mutating.
 */
add_filter(
	'wb_gam_points_for_action',
	function ( int $points, string $action_id, int $user_id ): int {
		if ( 'wp_publish_post' !== $action_id ) {
			return $points;
		}

		// Find the most recent post by this user (the one that triggered this).
		$recent_posts = get_posts( [
			'author'      => $user_id,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		if ( empty( $recent_posts ) ) {
			return $points;
		}

		$tutorial_cat = get_term_by( 'slug', 'tutorials', 'category' );
		if ( ! $tutorial_cat ) {
			return $points;
		}

		if ( has_term( $tutorial_cat->term_id, 'category', $recent_posts[0] ) ) {
			return $points + 25; // Bonus 25 → total 50
		}

		return $points;
	},
	20,                                         // Lower priority than the VIP multiplier
	3
);

/**
 * Pattern 3: Time-windowed bonus (event campaigns).
 *
 * Double points week — every action awards 2× points between
 * 2026-06-01 and 2026-06-08 inclusive.
 */
add_filter(
	'wb_gam_points_for_action',
	function ( int $points, string $action_id, int $user_id ): int {
		$campaign_start = strtotime( '2026-06-01 00:00:00 UTC' );
		$campaign_end   = strtotime( '2026-06-08 23:59:59 UTC' );
		$now            = time();

		if ( $now < $campaign_start || $now > $campaign_end ) {
			return $points;
		}

		return $points * 2;
	},
	30,                                         // Lowest priority — runs LAST so it stacks
	3
);

/**
 * Pattern 4: Veto via 0 points.
 *
 * Some actions should be penalized rather than awarded — return 0 to
 * skip the award entirely. (Negative points are also valid for "loss"
 * mechanics, but the rate-limiter / floor logic may reject them; test
 * before relying.)
 */
add_filter(
	'wb_gam_points_for_action',
	function ( int $points, string $action_id, int $user_id ): int {
		// Don't award points to users flagged as spam-suspected
		$is_suspicious = get_user_meta( $user_id, '_anti_spam_flagged', true );
		if ( $is_suspicious && in_array( $action_id, [ 'wp_leave_comment', 'bp_activity_update' ], true ) ) {
			return 0;
		}

		return $points;
	},
	5,                                          // Highest priority — runs first to short-circuit
	3
);

/**
 * Bonus: track HOW the multiplier was applied.
 *
 * Hook the wb_gam_points_awarded action to log the final
 * value — useful for support / debugging when users ask "why did I
 * only get N points?"
 */
add_action(
	'wb_gam_points_awarded',
	function ( int $user_id, int $points, string $reason ) {
		if ( ! defined( 'YOURPLUGIN_DEBUG' ) || ! YOURPLUGIN_DEBUG ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[YourPlugin] User %d awarded %d points for %s',
			$user_id,
			$points,
			$reason
		) );
	},
	10,
	3
);
