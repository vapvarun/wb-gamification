# Build Your First Integration

Add gamification to your WordPress plugin in 5 minutes. No PHP dependency on WB Gamification is required — your manifest file is silently ignored when the plugin is not installed.

---

## Step 1: Create the manifest file

Create a file named `wb-gamification.php` in your plugin's root directory:

```
your-plugin/
├── your-plugin.php        (main plugin file)
└── wb-gamification.php    (gamification manifest)
```

Paste the following into `wb-gamification.php` and customise it:

```php
<?php
/**
 * WB Gamification manifest for My Reviews Plugin.
 */

defined( 'ABSPATH' ) || exit;

return array(
    'plugin'   => 'my-reviews-plugin',
    'version'  => '1.0.0',
    'triggers' => array(

        // Award 10 points every time a member submits a review.
        array(
            'id'             => 'my_reviews_submitted',
            'label'          => 'Submitted a Review',
            'description'    => 'Awarded each time a member publishes a product review.',
            'hook'           => 'my_reviews_after_submit',
            'user_callback'  => function ( $review_id, $user_id ) {
                return (int) $user_id;
            },
            'default_points' => 10,
            'category'       => 'reviews',
            'icon'           => 'dashicons-star-half',
            'repeatable'     => true,
            'cooldown'       => 3600,   // One review per hour max.
            'daily_cap'      => 3,      // Up to 3 reviews per day.
        ),

        // One-time 50-point bonus for the very first review.
        array(
            'id'             => 'my_reviews_first_review',
            'label'          => 'First Review Bonus',
            'description'    => 'One-time bonus when a member submits their first review.',
            'hook'           => 'my_reviews_after_submit',
            'user_callback'  => function ( $review_id, $user_id ) {
                return (int) $user_id;
            },
            'default_points' => 50,
            'category'       => 'reviews',
            'repeatable'     => false,
        ),
    ),
);
```

### Required fields

Every trigger must include these three keys or it will be skipped:

| Key | Type | Description |
|-----|------|-------------|
| `id` | string | Unique action identifier. Use a `plugin_action` naming convention |
| `hook` | string | The WordPress action hook that fires when the event occurs |
| `default_points` | int | Default points awarded. Admins can override this in the settings UI |

See the [Manifest Files reference](manifest-files.md) for the full list of optional fields (`user_callback`, `metadata_callback`, `cooldown`, `daily_cap`, `async`, etc.).

---

## Step 2: Activate both plugins

1. Install and activate **WB Gamification**.
2. Install and activate **your plugin**.

WB Gamification scans every active plugin directory for a `wb-gamification.php` file at `plugins_loaded` priority 5. No registration code is needed.

---

## Step 3: Verify

1. Go to **WP Admin > Gamification > Settings > Points** tab.
2. Your custom actions should appear in the actions list with their default point values.
3. Trigger the action (e.g. submit a review) and confirm points are awarded.

You can also verify via WP-CLI:

```bash
wp wb-gamification actions list
```

---

## Advanced: Programmatic registration

If your trigger logic is too complex for a static manifest, register actions programmatically from your plugin's `functions.php` or a class constructor:

```php
add_action( 'wb_gamification_register', function () {
    if ( ! function_exists( 'wb_gamification_register_action' ) ) {
        return;
    }

    wb_gamification_register_action( array(
        'id'             => 'my_reviews_submitted',
        'label'          => 'Submitted a Review',
        'hook'           => 'my_reviews_after_submit',
        'user_callback'  => function ( $review_id, $user_id ) {
            return (int) $user_id;
        },
        'default_points' => 10,
        'category'       => 'reviews',
        'repeatable'     => true,
        'cooldown'       => 3600,
        'daily_cap'      => 3,
    ) );
} );
```

The `wb_gamification_register` action fires at `plugins_loaded` priority 6, after all manifests have been loaded.

---

## Available developer hooks

### `wb_gam_manifest_paths` (filter)

Add custom directories for the manifest scanner to check. Useful if you store manifests in a theme or mu-plugin:

```php
add_filter( 'wb_gam_manifest_paths', function ( array $paths ): array {
    $paths[] = get_stylesheet_directory() . '/gamification/';
    return $paths;
} );
```

### `wb_gam_manifests_loaded` (action)

Fires after all manifest files have been loaded and validated. Receives the full array of discovered action definitions:

```php
add_action( 'wb_gam_manifests_loaded', function ( array $actions ): void {
    // Log how many actions were discovered.
    error_log( 'WB Gamification loaded ' . count( $actions ) . ' manifest actions.' );
} );
```

---

## Validation and debugging

When `WP_DEBUG` is enabled, the ManifestLoader logs warnings for:

- **Manifest files that do not return an array** — check that your file ends with `return array( ... );`
- **Triggers missing required keys** (`id`, `hook`, `default_points`) — the trigger is skipped and a message is logged with the file path and missing key name

Check your debug log at `wp-content/debug.log` to diagnose manifest issues.

---

## Next steps

- [Manifest Files reference](manifest-files.md) — full field reference and conditional trigger flags
- [PHP Helper Functions](helper-functions.md) — `wb_gam_get_user_points()`, `wb_gam_award_points()`, and more
- [Hooks & Filters Reference](hooks-filters.md) — all available hooks for customisation
