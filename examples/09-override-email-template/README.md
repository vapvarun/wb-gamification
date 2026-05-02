# Example 09 — Override the Email Template (theme override)

Replace the default weekly recap email with a custom-branded version. No PHP class subclassing, no fork — just drop a file in your theme.

## Path

```
YOUR-THEME/
└── wb-gamification/
    └── emails/
        └── weekly-recap.php   ← your custom template
```

The next weekly email send picks it up. Child themes win over parent themes (`locate_template()` handles the fallback chain).

## Files in this example

- [`wb-gamification/emails/weekly-recap.php`](wb-gamification/emails/weekly-recap.php) — copy this folder into your theme root.

## How resolution works

`Email::locate( 'weekly-recap' )` checks paths in this order, first match wins:

1. `wb_gamification_email_template_path` filter return value (full programmatic override)
2. `{theme}/wb-gamification/emails/weekly-recap.php` (this example's path)
3. `{plugin}/templates/emails/weekly-recap.php` (the plugin's bundled default)

Your theme override is path 2. The default is path 3. As long as your file exists at the theme path, it wins.

## Available template variables

Email templates have access to these locals (extracted by `Email::render`):

| Variable | Type | Notes |
|---|---|---|
| `$user` | `\WP_User` | Recipient. |
| `$name` | string | Already-escaped display name. |
| `$site_name` | string | Raw — escape on output. |
| `$site_url` | string | Site home URL. |
| `$unsub_url` | string | Already-escaped one-tap unsubscribe URL. |
| `$points_this_week` | int | Points earned in the last 7 days. |
| `$total_points` | int | Lifetime total. |
| `$is_best` | bool | True if this week beats personal best. |
| `$best_week` | int | Previous personal-best weekly total. |
| `$badges_this_week` | array | `[{name, description}, ...]`. |
| `$challenges_this_week` | array | `[{title}, ...]`. |
| `$streak` | array | `['current_streak' => int, 'longest_streak' => int]`. |
| `$rank` | int\|null | Leaderboard rank, or null if user opted out. |

The variables are unchanged whether you're rendering the default or a theme override. Your template can omit anything it doesn't want to display.

## Programmatic override (for plugins)

If you're building a plugin (not a theme), use the filter instead:

```php
add_filter( 'wb_gamification_email_template_path', function ( $path, $template, $context ) {
    if ( 'weekly-recap' === $template ) {
        return YOUR_PLUGIN_PATH . 'templates/wb-gam-weekly-recap.php';
    }
    return $path;
}, 10, 3 );
```

## Body-level override (replace the entire HTML)

If you want to skip the template system entirely and build the body string yourself:

```php
add_filter( 'wb_gamification_weekly_email_body', function ( $body, $user, $data ) {
    // $body is the rendered HTML from the template; replace it entirely.
    return your_custom_body_builder( $user, $data );
}, 10, 3 );
```

This filter runs AFTER the template renders, so even if a theme override is in place, your filter wins.

## From-name / From-email override

The From header uses `Email::from_header( 'wb_gam_weekly_email_from_name' )`. Override either of:

- The `wb_gam_weekly_email_from_name` option (admin → Settings → Gamification, or `update_option`).
- The `wb_gamification_email_from_header` filter for the full header string.

```php
add_filter( 'wb_gamification_email_from_header', function ( $header, $name, $email ) {
    return sprintf( 'Community Bot <community@%s>', wp_parse_url( home_url(), PHP_URL_HOST ) );
}, 10, 3 );
```

## Verifying your override works

1. Drop the file into `your-theme/wb-gamification/emails/weekly-recap.php`.
2. Trigger a weekly email manually:
   ```bash
   wp wb-gamification doctor send-test-weekly --user=42
   # or fire the cron directly:
   wp cron event run wb_gam_weekly_email
   ```
3. Check the recipient's inbox or `wp-content/mail.log` if you have a mail-logger plugin.
4. Confirm the email body matches your template, not the plugin's default.

If you don't see the override take effect, check:
- File path exact: `your-theme/wb-gamification/emails/weekly-recap.php`
- File readable (correct permissions)
- WordPress is using the right theme (child > parent — child theme wins if both exist)

## Related

- This closes [G2 in INTEGRATION-GAPS-ROADMAP.md](../../plans/INTEGRATION-GAPS-ROADMAP.md).
- For hooking events to send custom emails (instead of overriding the existing one), see [Example 04](../04-listen-via-webhook/) or use `add_action( 'wb_gamification_points_awarded', ... )` directly.
