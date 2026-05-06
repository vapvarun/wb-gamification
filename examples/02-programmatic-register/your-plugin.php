<?php
/**
 * WB Gamification — Programmatic registration (runtime register)
 *
 * Use this pattern when you need conditional registration:
 *   - Theme integration (manifests in plugin directories don't auto-scan themes)
 *   - mu-plugin integration
 *   - Trigger only registered when an option is set
 *   - Trigger registered only on certain post types
 *
 * Unlike the drop-a-file pattern (Example 01), this requires a guard so
 * your plugin doesn't fatal when WB Gamification is inactive.
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register your gamification triggers when the engine is ready.
 *
 * The wb_gam_engines_booted action fires after all engines are wired —
 * the plugin is fully bootstrapped and the registration helpers are
 * available. We use that as our entry point.
 *
 * If WB Gamification isn't installed/active, this hook never fires —
 * your registration code never runs — no fatal, no errors.
 */
add_action( 'wb_gam_engines_booted', 'yourplugin_register_gamification_triggers' );

/**
 * Define the actions your plugin tracks.
 *
 * Called only when WB Gamification has booted, so we can safely call
 * wb_gam_register_action() without function_exists() checks.
 */
function yourplugin_register_gamification_triggers(): void {

	// Conditional 1: only register if your plugin's "track points" setting is on.
	if ( ! get_option( 'yourplugin_track_points', false ) ) {
		return;
	}

	// Always-on trigger.
	wb_gam_register_action( [
		'id'             => 'yourplugin_thing_done',
		'label'          => __( 'Did the thing', 'your-plugin' ),
		'description'    => __( 'Awarded when the user completes the primary action.', 'your-plugin' ),
		'hook'           => 'yourplugin_thing_did_happen',
		'user_callback'  => fn( int $user_id ) => $user_id,
		'default_points' => 10,
		'category'       => 'yourplugin',
		'icon'           => 'dashicons-yes-alt',
		'repeatable'     => true,
	] );

	// Conditional 2: only register the "premium" trigger for sites running
	// your premium add-on. Demonstrates mid-runtime conditional registration.
	if ( defined( 'YOURPLUGIN_PREMIUM_ACTIVE' ) && YOURPLUGIN_PREMIUM_ACTIVE ) {
		wb_gam_register_action( [
			'id'             => 'yourplugin_premium_thing_done',
			'label'          => __( 'Did the premium thing', 'your-plugin' ),
			'hook'           => 'yourplugin_premium_event',
			'user_callback'  => fn( int $user_id ) => $user_id,
			'default_points' => 50,
			'category'       => 'yourplugin',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => false,
		] );
	}

	// Conditional 3: only register on specific post types.
	// This pattern lets a theme add per-CPT triggers.
	$cpts_to_track = (array) apply_filters( 'yourplugin_gamification_cpts', [ 'product', 'course' ] );
	foreach ( $cpts_to_track as $cpt ) {
		wb_gam_register_action( [
			'id'             => "yourplugin_publish_{$cpt}",
			/* translators: %s: post type label */
			'label'          => sprintf( __( 'Publish a %s', 'your-plugin' ), ucfirst( $cpt ) ),
			'hook'           => "publish_{$cpt}",
			'user_callback'  => function ( int $post_id, \WP_Post $post ) {
				return (int) $post->post_author;
			},
			'default_points' => 25,
			'category'       => 'yourplugin',
			'icon'           => 'dashicons-megaphone',
			'repeatable'     => true,
		] );
	}
}

/**
 * Optional: register a custom badge trigger that fires the same event.
 *
 * Badge triggers complement actions — actions award POINTS when they
 * fire, badge triggers test a CONDITION and award a badge.
 */
add_action( 'wb_gam_engines_booted', function () {
	if ( ! function_exists( 'wb_gam_register_badge_trigger' ) ) {
		return;
	}

	wb_gam_register_badge_trigger( [
		'id'          => 'yourplugin_super_user',
		'label'       => __( 'Super User', 'your-plugin' ),
		'description' => __( 'Awarded after 100 things done.', 'your-plugin' ),
		'hook'        => 'yourplugin_thing_did_happen',
		'condition'   => function ( int $user_id ) {
			$count = (int) get_user_meta( $user_id, 'yourplugin_thing_count', true );
			return $count >= 100;
		},
	] );
} );
