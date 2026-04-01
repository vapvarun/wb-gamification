# BuddyPress Activity Feed Integration

## Overview

WB Gamification posts four types of events to the BuddyPress activity stream automatically. These entries create social proof — other members see achievements as they happen and are naturally encouraged to participate.

All four event types are registered under the `wb_gamification` component and appear in the "Gamification" filter group in the activity stream.

## Event Types

| Event | Activity Type | Hook That Triggers It |
|-------|--------------|----------------------|
| Badge earned | `badge_earned` | `wb_gamification_badge_awarded` |
| Level reached | `level_changed` | `wb_gamification_level_changed` |
| Kudos given | `kudos_given` | `wb_gamification_kudos_given` |
| Challenge completed | `challenge_completed` | `wb_gamification_challenge_completed` |

Each activity entry links back to the member's BuddyPress profile URL via `bp_core_get_user_domain()`.

### Example Activity Text

- **Badge earned:** "Jane Smith earned the **Community Voice** badge"
- **Level reached:** "Jane Smith reached the **Contributor** level"
- **Kudos given:** "Jane Smith gave kudos to Tom Harris: *Great answer!*"
- **Challenge completed:** "Jane Smith completed the **7-Day Streak** challenge"

## Toggling Individual Event Types

Each stream event type is individually toggled via a WordPress option. The default for all four is enabled (`1`).

| Option Key | Default | Controls |
|-----------|---------|---------|
| `wb_gam_bp_stream_badge_earned` | 1 | Badge earned posts |
| `wb_gam_bp_stream_level_changed` | 1 | Level-up posts |
| `wb_gam_bp_stream_kudos_given` | 1 | Kudos given posts |
| `wb_gam_bp_stream_challenge_completed` | 1 | Challenge completed posts |

### Disable a specific event type

```php
// Disable kudos posts in the activity stream.
update_option( 'wb_gam_bp_stream_kudos_given', 0 );
```

### Re-enable via admin (Settings → Gamification → BuddyPress)

You can also toggle these from the admin settings page without writing code.

## Quality-Weighted Reactions

`ActivityIntegration` also hooks into the `wb_gamification_points_for_action` filter to apply a quality bonus. When a member receives a reaction on an `activity_update` post (rather than a comment or other type), the points awarded for `bp_reactions_received` are boosted to a minimum of 5 (compared to the default 3).

```php
// This filter runs at priority 10 inside ActivityIntegration.
add_filter( 'wb_gamification_points_for_action', function( $points, $action_id, $user_id, $event ) {
    // Only modifies bp_reactions_received on activity_update posts.
    if ( 'bp_reactions_received' === $action_id && 'activity_update' === ( $event->metadata['activity_type'] ?? '' ) ) {
        return max( $points, 5 );
    }
    return $points;
}, 10, 4 );
```

You can override this behaviour by adding your own filter at a higher priority.

## How Posts Appear in the Stream

All posts use `bp_activity_add()` with `hide_sitewide: false`, so they appear on the site-wide activity feed as well as the member's profile feed. Posts are never marked as spam.

The `item_id` field varies per event:

- `badge_earned` — always `0`
- `level_changed` — the new level's DB row ID
- `kudos_given` — the `wb_gam_kudos` row ID (for linking)
- `challenge_completed` — the challenge's DB row ID

## No Configuration Required

`ActivityIntegration::init()` runs on `bp_loaded` and performs a `function_exists('buddypress')` guard. If BuddyPress is not active, no hooks are registered.
