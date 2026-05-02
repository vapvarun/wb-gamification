<?php
/**
 * WB Gamification — Manifest file (drop-a-file integration)
 *
 * Drop this file in YOUR plugin's root directory (named exactly
 * `wb-gamification.php`). The WB Gamification ManifestLoader auto-
 * discovers it at plugins_loaded@5 by globbing
 * WP_PLUGIN_DIR/{your-plugin}/wb-gamification.php.
 *
 * No coupling: this file just `return`s a PHP array. If WB Gamification
 * is not installed, the file is dead code — no fatal errors, no
 * class_exists() guards needed.
 *
 * Use case: your plugin emits a custom WP action ("forminator_form_submitted")
 * and you want users to earn points when that fires.
 *
 * @package YourPlugin
 */

defined( 'ABSPATH' ) || exit;

return [
	// Top-level metadata — shows up in the admin UI / collision messages.
	'plugin'   => 'Your Plugin Name',
	'version'  => '1.0.0',

	// Each trigger registers one tracked event.
	'triggers' => [

		[
			// Stable identifier — must be globally unique. Convention:
			// {your-plugin-slug-prefix}_{event_id}
			'id'             => 'yourplugin_form_submitted',

			// Human-readable label shown in the Settings UI.
			'label'          => __( 'Submit a form', 'your-plugin' ),
			'description'    => __( 'Awarded when a user submits any form created with Your Plugin.', 'your-plugin' ),

			// The WordPress action your plugin already fires.
			'hook'           => 'yourplugin_form_submitted',

			// Closure that returns the user_id from the hook's args.
			// Signature must match the hook's parameter list.
			// Return 0 to skip awarding (e.g. anonymous submitters).
			'user_callback'  => function ( int $form_id, int $submitter_id ) {
				return $submitter_id ?: 0;
			},

			// Default points awarded. Site owners can override per-action
			// via Settings → Points or programmatically via the
			// wb_gam_points_yourplugin_form_submitted option.
			'default_points' => 5,

			// Visual category in Settings → Points (any string).
			'category'       => 'yourplugin',

			// Dashicon for the row icon in admin UI.
			'icon'           => 'dashicons-feedback',

			// Award once per user (for milestones), or every time?
			'repeatable'     => true,

			// Optional daily cap to prevent farming. 0 = unlimited.
			'daily_cap'      => 5,
		],

		[
			'id'             => 'yourplugin_first_form',
			'label'          => __( 'Submit first form', 'your-plugin' ),
			'description'    => __( 'One-time bonus on the very first form submission.', 'your-plugin' ),
			'hook'           => 'yourplugin_form_submitted',
			'user_callback'  => function ( int $form_id, int $submitter_id ) {
				if ( ! $submitter_id ) {
					return 0;
				}
				// Only award if user has never submitted before.
				$count = get_user_meta( $submitter_id, '_yourplugin_submission_count', true );
				return ( 0 === (int) $count ) ? $submitter_id : 0;
			},
			'default_points' => 25,
			'category'       => 'yourplugin',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => false,
		],
	],
];
