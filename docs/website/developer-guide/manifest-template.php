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
