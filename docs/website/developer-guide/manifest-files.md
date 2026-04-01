# Manifest Files

## How Manifests Work

Any WordPress plugin can award gamification points without depending on WB Gamification at runtime. Create a file named `wb-gamification.php` in your plugin's root directory. The file returns a plain PHP array. WB Gamification discovers and loads it automatically at `plugins_loaded` priority 5 — before any hooks fire.

If WB Gamification is not installed, your manifest file is simply never loaded. No dependency errors, no fatal calls.

## File Location

```
your-plugin/
├── your-plugin.php        (main plugin file)
└── wb-gamification.php    (gamification manifest)
```

## Manifest Structure

```php
<?php
/**
 * WB Gamification manifest for My Plugin.
 *
 * This file is auto-discovered by WB Gamification at plugins_loaded priority 5.
 * It is safe to ship in the free version — WB Gamification is an optional dependency.
 */
return [
    'plugin'   => 'my-plugin',          // Used in Registry collision reports.
    'version'  => '1.0.0',              // Your plugin version (informational).
    'triggers' => [
        // Each array in 'triggers' is a gamification action definition.
        [
            'id'                  => 'my_plugin_action',
            'label'               => 'Did Something',
            'description'         => 'Awarded when a member does something in My Plugin.',
            'hook'                => 'my_plugin_action_hook',
            'user_callback'       => function( $user_id, $data ) { return $user_id; },
            'metadata_callback'   => function( $user_id, $data ) { return [ 'item_id' => $data->id ]; },
            'default_points'      => 10,
            'category'            => 'my_plugin',
            'icon'                => 'dashicons-star-filled',
            'repeatable'          => true,
            'cooldown'            => 3600,
            'daily_cap'           => 5,
            'async'               => false,
            'standalone_only'     => false,
            'requires_buddypress' => false,
        ],
    ],
];
```

## Trigger Field Reference

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | Unique action identifier. Use `plugin_name_action` format to avoid collisions |
| `label` | string | Yes | Human-readable label shown in the admin actions list |
| `description` | string | No | Longer description shown in tooltips and the setup wizard |
| `hook` | string | Yes | WordPress action hook name to listen on |
| `user_callback` | callable | Yes | Receives the hook arguments. Must return the WordPress user ID to award points to |
| `metadata_callback` | callable | No | Receives the hook arguments. Returns an array merged into event metadata (available in `wb_gamification_points_for_action` filter) |
| `default_points` | int | Yes | Default points awarded. Admins can override this in the settings UI |
| `category` | string | No | Category slug for grouping in the admin UI (e.g. `buddypress`, `woocommerce`) |
| `icon` | string | No | Dashicon class (e.g. `dashicons-heart`) for the admin UI |
| `repeatable` | bool | No | Whether the action can be awarded more than once. Default `true` |
| `cooldown` | int | No | Minimum seconds between repeated awards for the same user. `0` = no cooldown |
| `daily_cap` | int | No | Maximum awards per calendar day per user. `0` = unlimited |
| `async` | bool | No | Route through Action Scheduler instead of processing synchronously. Use for high-volume events |
| `standalone_only` | bool | No | Set `true` to skip this trigger when BuddyPress is active (because BP's own hooks cover the same event better) |
| `requires_buddypress` | bool | No | Set `true` to only register this trigger when BuddyPress is active |

## Complete Working Example

This example awards points when a member submits a contact form in a fictional forms plugin, with a daily cap and metadata enrichment:

```php
<?php
return [
    'plugin'   => 'my-forms-plugin',
    'version'  => '2.1.0',
    'triggers' => [
        // Award points for submitting any form.
        [
            'id'              => 'my_forms_submission',
            'label'           => 'Submitted a Form',
            'description'     => 'Awarded each time a member submits a form.',
            'hook'            => 'my_forms_submission_complete',
            'user_callback'   => function( $form_id, $user_id ) {
                return (int) $user_id;
            },
            'metadata_callback' => function( $form_id, $user_id ) {
                return [ 'form_id' => (int) $form_id ];
            },
            'default_points'  => 5,
            'category'        => 'forms',
            'icon'            => 'dashicons-feedback',
            'repeatable'      => true,
            'cooldown'        => 0,
            'daily_cap'       => 3,
            'async'           => false,
        ],

        // Award a one-time bonus for the first form submission.
        [
            'id'              => 'my_forms_first_submission',
            'label'           => 'First Form Submission',
            'description'     => 'One-time bonus for a member\'s very first form submission.',
            'hook'            => 'my_forms_submission_complete',
            'user_callback'   => function( $form_id, $user_id ) {
                return (int) $user_id;
            },
            'default_points'  => 25,
            'category'        => 'forms',
            'repeatable'      => false,   // Only once per member.
            'cooldown'        => 0,
            'daily_cap'       => 0,
            'async'           => false,
        ],

        // BP-only trigger: award points for form submissions inside a group.
        [
            'id'                  => 'my_forms_group_submission',
            'label'               => 'Submitted a Group Form',
            'description'         => 'Awarded when a member submits a form inside a BuddyPress group.',
            'hook'                => 'my_forms_group_submission_complete',
            'user_callback'       => function( $form_id, $user_id, $group_id ) {
                return (int) $user_id;
            },
            'default_points'      => 8,
            'category'            => 'forms',
            'repeatable'          => true,
            'cooldown'            => 3600,
            'daily_cap'           => 5,
            'requires_buddypress' => true,  // Only registers when BP is active.
        ],
    ],
];
```

## Conditional Triggers: `standalone_only` and `requires_buddypress`

These two flags let you ship one manifest that works correctly in both BuddyPress and non-BuddyPress environments.

**Scenario:** Your plugin fires `my_plugin_post_published`. When WordPress is running standalone, you want to award points for it. But when BuddyPress is active, the BuddyPress `bp_publish_post` integration already covers this event more richly — so you want to skip your version.

```php
[
    'id'              => 'my_plugin_post_published_standalone',
    'hook'            => 'my_plugin_post_published',
    'standalone_only' => true,   // Skip when BuddyPress is active.
    // ...
],
[
    'id'                  => 'my_plugin_post_published_bp',
    'hook'                => 'my_plugin_post_published',
    'requires_buddypress' => true,   // Only register when BuddyPress is active.
    // ...
],
```

Both flags are stripped from the trigger before it is passed to `Registry::register_action()`.

## Registering Actions Programmatically

You can also call the PHP helper directly instead of using a manifest file. This is useful when your trigger logic is complex enough to warrant a full class:

```php
add_action( 'wb_gamification_register', function() {
    wb_gamification_register_action( [
        'id'             => 'my_plugin_action',
        'label'          => 'My Action',
        'hook'           => 'my_plugin_hook',
        'user_callback'  => fn( $user_id ) => $user_id,
        'default_points' => 10,
        'category'       => 'my_plugin',
        'repeatable'     => true,
        'cooldown'       => 0,
        'daily_cap'      => 0,
    ] );
} );
```

This fires after `Registry::init()` at `plugins_loaded` priority 6.
