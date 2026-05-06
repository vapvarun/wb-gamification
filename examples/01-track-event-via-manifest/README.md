# Example 01 — Track Event via Manifest File (drop-a-file)

The simplest, lowest-coupling integration. Your plugin's directory contains a file named `wb-gamification.php` that returns a PHP array of triggers. WB Gamification's `ManifestLoader` auto-discovers it at `plugins_loaded@5`.

## Why this is the recommended path

- **Zero coupling** — the file works whether WB Gamification is installed or not. No `class_exists()` guards, no defines to check.
- **Pure data** — no PHP classes to instantiate, no engine references.
- **Auto-loaded** — drop the file, it works on next page load.
- **Localizable** — labels go through `__()` like any other plugin.

## How it works under the hood

`src/Engine/ManifestLoader.php:114-132` runs:
```php
$files = glob( WP_PLUGIN_DIR . '/*/wb-gamification.php' );
```

For each match, the file is `include`d. If it returns an array with a `triggers` key, each trigger is validated and registered with the engine via `wb_gam_register_action()`.

## Files in this example

- [`wb-gamification.php`](wb-gamification.php) — the manifest itself, ready to copy into your plugin's root directory.

## Trigger fields reference

| Field | Required | Purpose |
|---|---|---|
| `id` | yes | Globally unique identifier. Convention: `{plugin-prefix}_{event}`. |
| `label` | yes | Localized human-readable name (shown in admin). |
| `description` | no | Localized one-line description. |
| `hook` | yes | The WordPress action your plugin already fires. |
| `user_callback` | yes | Closure returning the user_id from the hook's args. Return 0 to skip. |
| `default_points` | yes | Default points awarded. Site owners can override per-action. |
| `category` | no | Grouping for the admin UI (default: `general`). |
| `icon` | no | Dashicon class (default: `dashicons-star-filled`). |
| `repeatable` | no | Award every time (`true`) or once-only (`false`). Default: `true`. |
| `daily_cap` | no | Max awards per user per day. `0` = unlimited (default). |
| `standalone_only` | no | Skip when BuddyPress is active (BP covers same event). |
| `requires_buddypress` | no | Skip when BuddyPress is NOT active. |

## Optional flags for BuddyPress-conditional triggers

```php
'requires_buddypress' => true,   // Only register if BP is active
'standalone_only'     => true,   // Skip if BP is active (BP has equivalent event)
```

## Verify your trigger landed

After dropping the file:

```bash
# CLI
wp wb-gamification actions list | grep yourplugin

# REST
curl http://your-site/wp-json/wb-gamification/v1/actions | jq '.[].id' | grep yourplugin
```

You should see `yourplugin_form_submitted` (and `yourplugin_first_form` from this example).

## Site owners can tune your trigger

Once registered, two things become customizable per site:

1. **Points** — via Settings → Points (saves to `wb_gam_points_yourplugin_form_submitted` option).
2. **Enable/disable** — via Settings → Points (saves to `wb_gam_enabled_yourplugin_form_submitted` option, default `true`).

You don't need to do anything special for this — just register the trigger.

## When to use the OTHER examples instead

- Need to register conditionally (only if some option is set)? → [`02-programmatic-register/`](../02-programmatic-register/)
- Event happens outside WordPress (mobile app, third-party server)? → [`03-rest-events-from-mobile/`](../03-rest-events-from-mobile/)
