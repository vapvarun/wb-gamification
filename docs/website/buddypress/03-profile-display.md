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
  <div class="wb-gam-progress-bar" title="30%">
    <div class="wb-gam-progress-fill" style="--wb-gam-fill:30%"></div>
  </div>
</div>
```

The fill width is driven by a CSS custom property `--wb-gam-fill`. Override it in your theme to change the bar colour or animation.

## Styling with CSS Variables

```css
/* Override in your child theme or Custom CSS */
.wb-gam-profile-rank {
  --wb-gam-bar-color: #6366f1;
  --wb-gam-bar-bg: #e5e7eb;
  --wb-gam-bar-height: 6px;
}
```

## Opt-Out: Hiding the Rank

Members can hide their rank badge. The preference is stored in `wb_gam_member_prefs.show_rank` (default `1` = visible).

When `show_rank = 0`, the `render_rank()` method returns early and nothing is output. The `NULL` state (no row in the table) also defaults to visible.

### Setting the preference via SQL (admin use only)

```sql
-- Hide rank for user 42
INSERT INTO wp_wb_gam_member_prefs (user_id, show_rank)
VALUES (42, 0)
ON DUPLICATE KEY UPDATE show_rank = 0;
```

### Setting the preference via REST API

```http
POST /wp-json/wb-gamification/v1/members/42/prefs
Authorization: Bearer <cookie nonce>
Content-Type: application/json

{ "show_rank": false }
```

## No Configuration Needed

`ProfileIntegration::init()` is called on the `bp_loaded` action. It performs a `function_exists('buddypress')` guard before registering any hooks. If BuddyPress is deactivated, the block silently does nothing.
