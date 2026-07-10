<?php
/**
 * Example gamification manifest for [Your Plugin Name].
 *
 * Drop this file as `wb-gamification.php` in your plugin's root directory.
 * WB Gamification auto-discovers it at plugins_loaded priority 5.
 *
 * If WB Gamification is not installed, this file is never loaded —
 * no fatal errors, no dependency issues.
 *
 * ---------------------------------------------------------------------------
 * TIMING CONSTRAINT (read before adding a detection guard)
 * ---------------------------------------------------------------------------
 * This file is include()d at plugins_loaded priority 5. Anything you check in
 * the TOP-LEVEL body — i.e. to decide what array to return — must already be
 * true that early.
 *
 *   - Safe to gate on at parse time: constants (`defined( 'OTHER_PLUGIN' )`)
 *     and classes (`class_exists( 'Other_Plugin' )`) — both exist the moment
 *     the other plugin's file is parsed.
 *
 *   - NOT safe to gate on at parse time: functions another plugin defines
 *     INSIDE its own plugins_loaded callback (e.g. FluentCRM's
 *     `fluentCrmApi()`, defined at plugins_loaded priority 10). At priority 5
 *     `function_exists( 'fluentCrmApi' )` is still false, so the guard would
 *     make this file return an empty array on every request — silently, with
 *     no error or warning.
 *
 * If your integration needs to detect a plugin whose API is only available
 * after its own hook runs, do NOT use this drop-a-file manifest. Register
 * late instead — see examples/14-fluentcrm-hooked-api/. You can also always
 * put the detection INSIDE a `user_callback`, which runs when the real event
 * fires (long after every plugin has loaded), never at scan time.
 * ---------------------------------------------------------------------------
 *
 * @package Your_Plugin
 * @see     https://wbcomdesigns.com/docs/wb-gamification/developer-guide/manifest-files/
 */

defined( 'ABSPATH' ) || exit;

return array(
	'plugin'   => 'your-plugin-slug',
	'version'  => '1.0.0',
	'triggers' => array(

		// Example 1: A repeatable action with a daily cap.
		array(
			'id'             => 'your_plugin_action_name',
			'label'          => 'Action Label',
			'description'    => 'What this action rewards.',
			'hook'           => 'your_plugin_hook_name',
			'user_callback'  => function ( $arg1, $arg2 ) {
				// Return the user ID who should receive points.
				return get_current_user_id();
			},
			'default_points' => 10,
			'category'       => 'your-plugin',
			'icon'           => 'dashicons-star-filled',
			'repeatable'     => true,
			'cooldown'       => 0,
			'daily_cap'      => 0,
		),

		// Example 2: A one-time-only bonus (first action).
		array(
			'id'             => 'your_plugin_first_action',
			'label'          => 'First Action Bonus',
			'description'    => 'One-time bonus for the first time a member performs this action.',
			'hook'           => 'your_plugin_hook_name',
			'user_callback'  => function ( $arg1, $arg2 ) {
				return get_current_user_id();
			},
			'default_points' => 50,
			'category'       => 'your-plugin',
			'repeatable'     => false,
		),

	),
);
