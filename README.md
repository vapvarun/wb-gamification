# WB Gamification

Complete gamification engine for WordPress and BuddyPress. Points, badges, levels, leaderboards, challenges, streaks, and kudos — zero config, works out of the box.

**Part of the [Reign Stack](https://wbcomdesigns.com/) — Wbcom's self-owned community platform.**

---

## Quick Start

```bash
# Install & activate
wp plugin activate wb-gamification

# Choose a starter template (or skip to configure manually)
# → Setup wizard appears automatically on first activation

# Verify everything is working
wp wb-gamification doctor --verbose

# Award points manually
wp wb-gamification points award --user=42 --points=100 --message="Welcome bonus"

# Check a member's status
wp wb-gamification member status --user=42
```

## Features

### Free

| Feature | Details |
|---------|---------|
| **Points Engine** | Event-sourced, 30+ auto-detected actions, configurable per action |
| **Badges** | 30 default badges, auto-award conditions, custom badge editor |
| **Levels** | 5 default levels (Newcomer→Champion), customizable thresholds |
| **Leaderboard** | All-time / monthly / weekly / daily, group scoping, snapshot cache |
| **Challenges** | Time-bound goals, bonus points, admin manager |
| **Streaks** | Daily tracking, grace period, 7 milestones (7→365 days) |
| **Peer Kudos** | Daily limits, receiver + giver points, feed display |
| **Blocks** | 11 Gutenberg blocks + 11 shortcodes |
| **REST API** | 38 endpoints, 16 controllers, API key auth |
| **Integrations** | 9 auto-detected plugins (62 total actions) |
| **Notifications** | Toast popups, BP notifications, activity feed events |
| **Analytics** | 6 KPI cards, top actions/earners, daily sparkline |
| **WP-CLI** | 6 commands including `doctor` readiness checker |
| **Privacy** | GDPR export/erasure via WordPress privacy tools |

### Pro Add-on

| Feature | Description |
|---------|-------------|
| Cohort Leagues | Duolingo-style weekly competitions with promotion/demotion |
| Community Challenges | Team goals with global progress (Pokemon GO model) |
| Redemption Store | Spend points on rewards (discounts, custom) |
| Badge Sharing | OG share pages, LinkedIn deep-links, OpenBadges 3.0 credentials |
| Webhooks | HMAC-signed outbound webhooks for Zapier / Make / n8n |
| Weekly Emails | Automated weekly recap sent to members |
| Cosmetics | Profile frames and visual upgrades |
| Tenure Badges | Anniversary milestones (1yr, 2yr, 5yr, 10yr) |
| Site-First Badges | First member to perform an action earns a unique badge |

## Integrations

All integrations are **auto-detected** — install the plugin, gamification actions appear automatically.

| Plugin | Actions | Category |
|--------|---------|----------|
| WordPress Core | 8 | Registration, login, posts, comments, profile |
| BuddyPress | 10 | Activity, friends, groups, profile, reactions, polls |
| bbPress | 3 | Topics, replies, resolved |
| WooCommerce | 4 | Orders, first purchase, reviews, wishlists |
| LearnDash | 5 | Courses, lessons, topics, quizzes, assignments |
| LifterLMS | 5 | Courses, lessons, quizzes, achievements, certificates |
| MemberPress | 3 | Memberships, renewals, first signup |
| GiveWP | 4 | Donations, first donation, recurring, campaign goals |
| The Events Calendar | 3 | RSVPs, tickets, check-ins |
| WPMediaVerse Pro | 17 | Uploads, albums, likes, follows, battles, tournaments |

**Total: 62 gamification actions** across 10 integration manifests.

### Adding Your Own Integration

Drop a `wb-gamification.php` file in your plugin directory:

```php
<?php
return [
    'plugin'   => 'My Plugin',
    'version'  => '1.0.0',
    'triggers' => [
        [
            'id'             => 'my_custom_action',
            'label'          => 'Did something awesome',
            'hook'           => 'my_plugin_action_fired',
            'user_callback'  => fn( int $user_id ) => $user_id,
            'default_points' => 10,
            'category'       => 'custom',
            'repeatable'     => true,
        ],
    ],
];
```

WB Gamification auto-discovers it at boot — no registration call needed.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- BuddyPress 14.0+ (optional, for social triggers and profile display)

## Architecture

```
Trigger Sources (any WP hook, REST API, WP-CLI, manifest)
         │
         ▼
Event Normalization (ManifestLoader → Registry → Event)
         │
         ▼
Rule Evaluation (Points → Badges → Levels → Streaks → Challenges)
         │
         ▼
Effects (Ledger write, notifications, activity feed, webhooks, WP hooks)
         │
         ▼
Output Consumers (Blocks, BP display, REST API, mobile, Zapier)
```

**The engine owns three things:** event normalization, rule evaluation, and output surfaces. Everything else is a consumer.

## Documentation

**50 pages** across 7 categories at [`docs/website/`](docs/website/):

| Category | Pages | Topics |
|----------|-------|--------|
| [Getting Started](docs/website/getting-started/) | 5 | Installation, wizard, quick start, free vs pro, how it works |
| [Features](docs/website/features/) | 11 | Points, badges, levels, leaderboard, challenges, streaks, kudos, notifications, blocks, analytics, privacy |
| [Settings](docs/website/settings/) | 8 | Points, levels, badges, challenges, kudos, automation, manual awards, API keys |
| [Pro Features](docs/website/pro-features/) | 8 | Cohort leagues, community challenges, redemption store, badge sharing, webhooks, emails, cosmetics |
| [Integrations](docs/website/integrations/) | 7 | BuddyPress, WooCommerce, LearnDash, bbPress, LifterLMS, MemberPress, GiveWP, TEC, WPMediaVerse |
| [BuddyPress](docs/website/buddypress/) | 3 | Profile display, activity feed, member directory |
| [Developer Guide](docs/website/developer-guide/) | 8 | Architecture, hooks/filters, REST API, manifests, WP-CLI, helpers, cross-site API, DB schema |

## Developer Quick Reference

### Hooks

```php
// Points awarded
add_action( 'wb_gamification_points_awarded', function( $user_id, $event, $points ) {
    // $event->action_id, $event->metadata, etc.
}, 10, 3 );

// Badge earned
add_action( 'wb_gamification_badge_awarded', function( $user_id, $badge_id, $earned_at ) {
    // Send custom notification, log to CRM, etc.
}, 10, 3 );

// Level changed
add_action( 'wb_gamification_level_changed', function( $user_id, $old_level, $new_level ) {
    // Unlock content, assign role, etc.
}, 10, 3 );

// Modify points before award
add_filter( 'wb_gamification_points_for_action', function( $points, $action_id, $user_id ) {
    // Double points on weekends
    if ( in_array( gmdate( 'l' ), [ 'Saturday', 'Sunday' ] ) ) {
        return $points * 2;
    }
    return $points;
}, 10, 3 );
```

### Helper Functions

```php
// Get user's total points
$points = wb_gam_get_user_points( $user_id );

// Check if user has a badge
if ( wb_gam_has_badge( $user_id, 'first_post' ) ) { /* ... */ }

// Get leaderboard
$leaders = wb_gam_get_leaderboard( 'week', 10 );

// Register a custom action
wb_gamification_register_action( [
    'id'             => 'my_action',
    'label'          => 'My Custom Action',
    'hook'           => 'my_plugin_hook',
    'user_callback'  => fn( $user_id ) => $user_id,
    'default_points' => 15,
] );
```

### WP-CLI

```bash
wp wb-gamification doctor --verbose          # Full readiness check
wp wb-gamification points award --user=42 --points=100
wp wb-gamification member status --user=42
wp wb-gamification actions list --format=table
wp wb-gamification logs prune --before=6months --dry-run
wp wb-gamification export user --user=42 > export.json
```

## License

GPL-2.0+ — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Credits

Built by [Wbcom Designs](https://wbcomdesigns.com/). Part of the Reign Stack ecosystem.
