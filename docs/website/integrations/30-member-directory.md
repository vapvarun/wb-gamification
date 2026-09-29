# BuddyPress Member Directory Integration

## Overview

WB Gamification adds a compact rank badge next to each member's name in the BuddyPress member directory. The badge shows the member's current level name only — no point count or progress bar, keeping the directory listing clean.

## What Appears in the Directory

A single `<span>` with the level name is injected via the `bp_directory_members_item` action hook:

```html
<span class="wb-gam-directory-rank">Contributor</span>
```

This appears inside each member list item, adjacent to the member's name and avatar.

## Who Sees a Rank Badge

The badge only appears under two conditions:

1. The member has earned a level (i.e., `wb_gam_level_name` user meta is set and non-empty).
2. The member has not opted out of showing their rank (`wb_gam_member_prefs.show_rank` is not `0`).

Members who have never earned any points — and therefore have no level assigned — show no badge at all. "Newcomer" (the default starting level) will appear once the user meta is written, which happens on the first level-up evaluation.

## Opt-Out Behaviour

The directory badge respects the same `show_rank` preference as the profile header. If a member sets `show_rank = 0` in `wb_gam_member_prefs`, their badge is hidden from both the profile header and the directory listing.

A `NULL` value (no row in the preferences table) defaults to visible.

## Styling the Directory Badge

```css
/* Target the rank badge in the member directory */
.wb-gam-directory-rank {
  display: inline-block;
  padding: 2px 8px;
  font-size: 0.75rem;
  background: var(--wb-gam-rank-bg, #f3f4f6);
  color: var(--wb-gam-rank-color, #374151);
  border-radius: 9999px;
  margin-left: 6px;
  vertical-align: middle;
}
```

Add this to your child theme's stylesheet or via **Appearance → Customize → Additional CSS**.

## No Configuration Required

`DirectoryIntegration::init()` is called on `bp_loaded`. It guards with `function_exists('buddypress')` before registering any hooks. Deactivating BuddyPress removes the integration silently.
