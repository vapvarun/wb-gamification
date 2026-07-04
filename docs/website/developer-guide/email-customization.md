# Customizing gamification emails

WB Gamification sends transactional emails (level-up, badge earned, challenge
completed, redemption confirmed) and digests (weekly recap, leaderboard nudge).
You can rebrand and extend them three ways, from lightest to fullest control.

## 1. Filters — change subject, recipients, or body (no file editing)

Applied once for every transactional email, so one hook covers all events.
Each passes the email `$slug` (e.g. `level_up`, `badge_earned`,
`challenge_completed`, `redemption`) and the `$user_id` the email is about.

```php
// BCC the site owner on every gamification email.
add_filter( 'wb_gam_email_recipients', function ( $to, $slug, $user_id ) {
    return $to; // return '' to suppress the send entirely
}, 10, 3 );

// Prefix the subject with your community name.
add_filter( 'wb_gam_email_subject', function ( $subject, $slug, $user_id ) {
    return '[Acme Community] ' . $subject;
}, 10, 3 );

// Append a footer to the rendered HTML body.
add_filter( 'wb_gam_email_body', function ( $html, $slug, $user_id ) {
    return $html . '<p style="text-align:center;color:#888">Sent by Acme.</p>';
}, 10, 3 );
```

Digest emails also expose body/message filters: `wb_gam_weekly_email_body`
and `wb_gam_nudge_message`.

## 2. Template footer hook — inject at the end of the body

Every template fires `wb_gam_email_footer` just before `</body>`, passing the
template slug and its in-scope variables (recipient `$user`, `$name`,
`$site_name`, and event-specific vars):

```php
add_action( 'wb_gam_email_footer', function ( $template, $vars ) {
    echo '<p style="text-align:center"><a href="' . esc_url( $vars['site_url'] ) . '">Manage preferences</a></p>';
}, 10, 2 );
```

## 3. Template override — replace a whole email (theme file)

Copy any template into your theme and edit it freely. The loader
(`Email::locate()`) checks, in order:

1. the `wb_gam_template_path` filter,
2. `{child-theme}/wb-gamification/emails/{slug}.php`,
3. `{parent-theme}/wb-gamification/emails/{slug}.php`,
4. the plugin's `templates/emails/{slug}.php`.

So dropping `wp-content/themes/your-theme/wb-gamification/emails/level-up.php`
overrides just that email, survives plugin updates, and keeps every other
email on the default. The `wb_gam_email_from_header` filter overrides the
From: header.

## Which to use

- Change wording / add a BCC / append a banner → **filters** (§1).
- Add a consistent footer to all emails → **footer hook** (§2).
- Redesign an email's layout → **template override** (§3).
