# BuddyPress Profile Display

## Overview

WB Gamification automatically adds a rank badge to every member's BuddyPress profile header. No shortcode or configuration is required. As soon as both plugins are active, every profile shows the member's current level name, total point count, and a progress bar toward the next level.

## What Members See

The rank block appears inside the `bp_before_member_header_meta` hook, directly below the member's avatar and name.

| Element | Source |
|---------|--------|
| Level name | `wb_gam_level_name` user meta, defaults to "Newcomer" |
| Point count | Summed from `wb_gam_points` ledger |
| Progress bar | `(current_points - current_level_min) / (next_level_min - current_level_min) × 100` |

The progress bar is only rendered when a next level exists in the `wb_gam_levels` table.

### Default Level Names

Fresh installs seed five levels:

| Level | Minimum Points |
|-------|---------------|
| Newcomer | 0 |
| Member | 100 |
| Contributor | 500 |
| Regular | 1,500 |
| Champion | 5,000 |

You can rename or add levels at **WP Admin → Gamification → Levels**.

## HTML Structure

```html
<div class="wb-gam-profile-rank">
  <span class="wb-gam-rank-badge">Contributor</span>
  <span class="wb-gam-points-count">650 pts</span>
  <div class="wb-gam-progress-bar" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100">
    <div class="wb-gam-progress-fill" style="--wb-gam-fill:30%"></div>
  </div>
</div>
```

The fill width is driven by the `--wb-gam-fill` custom property. The badge, points and bar take their colours from your theme (BuddyX, Reign and other Wbcom themes), and follow its dark mode.

## Styling with CSS Variables

```css
/* Override in your child theme or Custom CSS */
.wb-gam-profile-rank {
  --wb-gam-bar-color: #6366f1;
  --wb-gam-bar-bg: #e5e7eb;
  --wb-gam-bar-height: 6px;
}
```

## Who Sees It

The rank block shows only when all three are true:

1. **The site shows it.** WB Gamification > Settings > Engagement > BuddyPress > "Show level, points and progress in the profile header" (on by default). Turn it off if your theme already shows rank in the header.
2. **The member shows it.** Each member can untick "Show my level and points on my profile" under "Your gamification choices" on their own Achievements tab or public profile page.
3. **The visitor may see the member's profile.** A member who made their profile private is never shown to other visitors. The member and site admins always see it.

### Setting a member's choice via REST

The member themself, or staff who can manage members:

```http
POST /wp-json/wb-gamification/v1/members/42/preferences
Content-Type: application/json
X-WP-Nonce: <wp_rest nonce>

{ "show_rank": false }
```

The same route takes `leaderboard_opt_out` (true keeps the member off every leaderboard) and `notification_mode` (`smart` sends reward emails, `none` sends none). `GET` on the same route returns all three.

## No Configuration Needed

`ProfileIntegration::init()` is called on the `bp_loaded` action. It performs a `function_exists('buddypress')` guard before registering any hooks. If BuddyPress is deactivated, the block silently does nothing.

## See Also

- **[BuddyPress Achievements Tab](50-achievements-tab.md)** - the dedicated profile tab (Overview / Badges / Points / Streak) added by the same integration, separate from this header rank display.
