# WB Gamification

Complete gamification engine for WordPress and BuddyPress. Points, badges, levels, leaderboards, challenges, streaks, and kudos - zero config, works out of the box.

**Part of the [Reign Stack](https://wbcomdesigns.com/) - Wbcom's self-owned community platform.**

- **Plugin page:** https://wbcomdesigns.com/downloads/wordpress-gamification-plugin/
- **Documentation:** https://store.wbcomdesigns.com/wb-gamification/docs/

> **Everything is free.** WB Gamification ships as a single plugin with no paid add-ons. Every engine, every integration, and every advanced mechanic (cohort leagues, redemption store, webhooks, OpenBadges, weekly emails) is included.

---

## Quick Start

```bash
# Install & activate
wp plugin activate wb-gamification

# A setup wizard appears automatically on first activation
# Choose a starter template, or skip to configure manually.

# Verify everything is working
wp wb-gamification doctor --verbose

# Award points manually
wp wb-gamification points award --user=42 --points=100 --message="Welcome bonus"

# Check a member's status
wp wb-gamification member status --user=42
```

## Features

| Feature | Details |
|---------|---------|
| **Points Engine** | Event-sourced, 30+ auto-detected actions, configurable per action, multi-currency |
| **Badges** | 30 default badges, auto-award conditions, custom badge editor, OpenBadges 3.0 credentials, badge share pages, expiry |
| **Levels** | 5 default levels (Newcomer to Champion), customizable thresholds |
| **Leaderboard** | All-time / monthly / weekly / daily, group scoping, snapshot cache |
| **Cohort Leagues** | Duolingo-style weekly competitions with promotion / demotion |
| **Challenges** | Time-bound goals + community challenges (shared global progress) |
| **Streaks** | Daily tracking, grace period, 7 milestones (7 to 365 days) |
| **Peer Kudos** | Daily limits, receiver + giver points, feed display |
| **Redemption Store** | Spend points on rewards (WooCommerce coupons, custom rewards, Wbcom Credits SDK) |
| **Member surfaces** | BuddyPress profile "Achievements" tab, WooCommerce My Account endpoint, opt-in LearnDash link - all reuse the same blocks and the mapped Hub page |
| **Blocks** | 19 Gutenberg blocks + 17 shortcodes (Wbcom Block Quality Standard) |
| **REST API** | 56 endpoints across 26 controllers, API key auth, OpenAPI spec + TypeScript SDK |
| **Integrations** | 12 auto-detected plugins (76 actions) + ActivityPub and GraphQL platform surfaces |
| **Realtime** | Toast notifications via WP Heartbeat (SSE opt-in), configurable placement |
| **Theming** | Follows BuddyX / BuddyX Pro light and dark mode automatically via theme tokens |
| **Webhooks** | HMAC-signed outbound webhooks for Zapier / Make / n8n |
| **Emails** | Weekly recap + transactional gamification emails (opt-out per user) |
| **Analytics** | 6 KPI cards, top actions / earners, daily sparkline |
| **WP-CLI** | 10 commands including `doctor` readiness checker and `scale` benchmark |
| **Privacy** | GDPR export / erasure via WordPress privacy tools; public profiles at `/u/{user}` |

## Integrations

All integrations are **auto-detected** - install the plugin, gamification actions appear automatically. The integration surface is tracked in [`audit/manifest.json#/integrations`](audit/manifest.json).

| Plugin | Actions | Category |
|--------|---------|----------|
| WordPress Core | 8 | Registration, login, posts, comments, profile |
| BuddyPress | 14 | Activity, comments, friends, groups, profile, reactions, polls, media, messages |
| bbPress | 3 | Topics, replies, resolved |
| WooCommerce | 5 | Orders, first purchase, reviews, wishlists |
| LearnDash | 5 | Courses, lessons, topics, quizzes, assignments |
| LifterLMS | 5 | Courses, lessons, quizzes, achievements, certificates |
| MemberPress | 3 | Memberships, renewals, first signup |
| GiveWP | 4 | Donations, first donation, recurring, campaign goals |
| The Events Calendar | 3 | RSVPs, tickets, check-ins |
| WPMediaVerse | 15 | Uploads, albums, likes, follows, battles, tournaments |
| Jetonomy | 4 | Space joins, gated admission, trust levels, membership |
| Jetonomy Pro | 7 | Polls, messages, conversations, badge earned, DMs |

**Total: 76 gamification actions across 12 integration manifests.** Plus two platform integrations - **ActivityPub** (federate events to the fediverse) and **GraphQL** (query the gamification graph).

### Jetonomy

On a Jetonomy site, wb-gamification mirrors Jetonomy reputation 1:1 into points and awards points when a Jetonomy badge is earned. Because the rankings are then identical, the wb-gam leaderboard defers to Jetonomy's reputation leaderboard (override with the `wb_gam_defer_leaderboard_to_jetonomy` filter). Badges are kept - the two badge sets are complementary.

### Adding Your Own Integration

Drop a manifest file in your plugin and WB Gamification auto-discovers it at boot - no registration call needed:

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

## Requirements

- WordPress 6.4+
- PHP 8.1+
- MySQL 8.0+ / MariaDB 10.3+ (leaderboard snapshot uses window functions)
- BuddyPress 14.0+ (optional, for social triggers and profile display)

For sites over 10k active users a persistent object cache (Redis / Memcached) and Action Scheduler are required - see the docs.

## Architecture

```
Trigger Sources (any WP hook, REST API, WP-CLI, manifest)
         |
         v
Event Normalization (ManifestLoader -> Registry -> Event)
         |
         v
Rule Evaluation (Points -> Badges -> Levels -> Streaks -> Challenges)
         |
         v
Effects (Ledger write, notifications, activity feed, webhooks, WP hooks)
         |
         v
Output Consumers (Blocks, BP / WooCommerce / LearnDash surfaces, REST, mobile, Zapier)
```

**The engine owns three things:** event normalization, rule evaluation, and output surfaces. Everything else is a consumer.

## Documentation

Full documentation is published at **https://store.wbcomdesigns.com/wb-gamification/docs/** and authored in [`docs/website/`](docs/website/):

| Category | Topics |
|----------|--------|
| [Getting Started](docs/website/getting-started/) | Installation, setup wizard, quick start, how it works |
| [Features](docs/website/features/) | Points, multi-currency, badges, levels, leaderboard, challenges, streaks, kudos, cohort leagues, redemption, member surfaces, public profiles |
| [Blocks](docs/website/blocks/) | All 19 blocks + the shortcode reference |
| [Settings](docs/website/settings/) | Points, levels, badges, challenges, kudos, automation, realtime / notifications, API keys |
| [Integrations](docs/website/integrations/) | BuddyPress, WooCommerce, LearnDash, bbPress, LifterLMS, MemberPress, GiveWP, TEC, WPMediaVerse, Jetonomy |
| [BuddyPress](docs/website/buddypress/) | Profile display, achievements tab, activity feed, member directory |
| [Developer Guide](docs/website/developer-guide/) | Architecture, hooks / filters, REST API, manifests, realtime transport, WP-CLI, helpers, cross-site API, DB schema |

## Developer Quick Reference

### Hooks

```php
// Points awarded
add_action( 'wb_gam_points_awarded', function( $user_id, $event, $points ) {
    // $event->action_id, $event->metadata, etc.
}, 10, 3 );

// Badge earned
add_action( 'wb_gam_badge_awarded', function( $user_id, $badge_id, $earned_at ) {
    // Send custom notification, log to CRM, etc.
}, 10, 3 );

// Level changed
add_action( 'wb_gam_level_changed', function( $user_id, $old_level, $new_level ) {
    // Unlock content, assign role, etc.
}, 10, 3 );

// Modify points before award
add_filter( 'wb_gam_points_for_action', function( $points, $action_id, $user_id ) {
    // Double points on weekends
    if ( in_array( gmdate( 'l' ), [ 'Saturday', 'Sunday' ], true ) ) {
        return $points * 2;
    }
    return $points;
}, 10, 3 );
```

### Helper Functions

```php
// Get a user's total points
$points = wb_gam_get_user_points( $user_id );

// Check if a user has a badge
if ( wb_gam_has_badge( $user_id, 'first_post' ) ) { /* ... */ }

// Get the leaderboard
$leaders = wb_gam_get_leaderboard( 'week', 10 );

// Register a custom action in code
wb_gam_register_action( [
    'id'             => 'my_action',
    'label'          => 'My Custom Action',
    'hook'           => 'my_plugin_hook',
    'user_callback'  => fn( $user_id ) => $user_id,
    'default_points' => 15,
] );
```

### WP-CLI

```bash
wp wb-gamification doctor --verbose            # Full readiness check
wp wb-gamification points award --user=42 --points=100
wp wb-gamification member status --user=42
wp wb-gamification actions list --format=table
wp wb-gamification logs prune --before=6months --dry-run
wp wb-gamification export user --user=42 > export.json
wp wb-gamification openapi export              # Refresh the OpenAPI spec
```

## License

GPL-2.0+ - see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

## Credits

Built by [Wbcom Designs](https://wbcomdesigns.com/). Part of the Reign Stack ecosystem.
