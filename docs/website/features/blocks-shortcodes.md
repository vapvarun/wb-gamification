# Blocks and Shortcodes

WB Gamification includes 11 Gutenberg blocks and 11 matching shortcodes. Every shortcode renders identically to its block counterpart — use whichever fits your page-building workflow.

All shortcodes begin with `[wb_gam_` and all blocks are found in the block inserter under the **WB Gamification** category.

---

## Leaderboard

Displays a ranked list of members sorted by points for the chosen time period.

**Block:** WB Gamification Leaderboard
**Shortcode:**
```
[wb_gam_leaderboard period="all" limit="10" show_avatars="1"]
[wb_gam_leaderboard period="week" limit="5" scope_type="group" scope_id="12"]
```

| Attribute | Default | Options / Notes |
|---|---|---|
| `period` | `all` | `all`, `month`, `week`, `day` |
| `limit` | `10` | 1–100 |
| `scope_type` | _(empty)_ | `group` to scope to a BuddyPress group |
| `scope_id` | `0` | BuddyPress group ID when `scope_type="group"` |
| `show_avatars` | `1` | `0` to hide member avatars |

Shows each member's avatar, name, rank number, and points. Highlights the current logged-in member's rank below the list even if they are not in the visible top entries.

---

## Member Points

Shows the current (or specified) member's total points, level name, and progress toward the next level.

**Block:** WB Gamification Member Points
**Shortcode:**
```
[wb_gam_member_points]
[wb_gam_member_points user_id="42" show_level="1" show_progress_bar="1"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `show_level` | `1` | Show the level name |
| `show_progress_bar` | `1` | Show the progress bar toward next level |

---

## Badge Showcase

Displays a grid of badges the member has earned. Optionally shows locked (unearned) badges grayed out.

**Block:** WB Gamification Badge Showcase
**Shortcode:**
```
[wb_gam_badge_showcase]
[wb_gam_badge_showcase show_locked="1" category="buddypress" limit="12"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `show_locked` | `0` | `1` to show unearned badges grayed out |
| `category` | _(empty)_ | Filter by badge category (e.g. `wordpress`, `buddypress`, `general`) |
| `limit` | `0` | 0 = show all; set a number to cap the display |

---

## Level Progress

Focused view of the member's current level, level icon, progress bar, and the points needed to reach the next level.

**Block:** WB Gamification Level Progress
**Shortcode:**
```
[wb_gam_level_progress]
[wb_gam_level_progress show_next_level="1" show_icon="1"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `show_progress_bar` | `1` | Show progress toward next level |
| `show_next_level` | `1` | Show the name and threshold of the next level |
| `show_icon` | `1` | Show the current level icon |

---

## Challenges

Displays active challenges with progress bars and time remaining.

**Block:** WB Gamification Challenges
**Shortcode:**
```
[wb_gam_challenges]
[wb_gam_challenges limit="3" show_completed="0"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `limit` | `0` | 0 = show all active challenges |
| `show_completed` | `1` | `0` to hide challenges the member has already finished |
| `show_progress_bar` | `1` | Show progress bar on each challenge |

---

## Streak

Displays the member's current streak count and optional activity heatmap.

**Block:** WB Gamification Streak
**Shortcode:**
```
[wb_gam_streak]
[wb_gam_streak show_longest="1" show_heatmap="1" heatmap_days="90"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `show_longest` | `0` | Also show the member's all-time longest streak |
| `show_heatmap` | `0` | Show a GitHub-style activity heatmap |
| `heatmap_days` | `90` | Days of history to show in the heatmap (1–365) |

---

## Top Members

Compact podium or list display of the top-ranking members. Ideal for sidebars and homepage sections.

**Block:** WB Gamification Top Members
**Shortcode:**
```
[wb_gam_top_members]
[wb_gam_top_members limit="5" layout="list" show_badges="1" show_level="1"]
```

| Attribute | Default | Options / Notes |
|---|---|---|
| `limit` | `3` | 1–20 |
| `period` | `all_time` | `all_time`, `month`, `week`, `day` |
| `layout` | `podium` | `podium` (visual 1st/2nd/3rd display) or `list` |
| `show_badges` | `0` | Show the member's top earned badge |
| `show_level` | `0` | Show the member's current level name |

---

## Kudos Feed

Displays a stream of recent kudos activity showing who gave kudos, who received it, and the message.

**Block:** WB Gamification Kudos Feed
**Shortcode:**
```
[wb_gam_kudos_feed]
[wb_gam_kudos_feed limit="5" show_messages="0"]
```

| Attribute | Default | Notes |
|---|---|---|
| `limit` | `10` | 1–50 |
| `show_messages` | `1` | `0` to hide the kudos message text |

---

## Year Recap

Displays a member's year-in-review summary: total points, badges earned, top actions, and kudos sent and received. Best used on a dedicated "My Year" page.

**Block:** WB Gamification Year Recap
**Shortcode:**
```
[wb_gam_year_recap]
[wb_gam_year_recap year="2024" show_share="1" show_badges="1" show_kudos="1"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `year` | `0` | 0 = current year |
| `show_share` | `1` | Show a share button |
| `show_badges` | `1` | Include badges section |
| `show_kudos` | `1` | Include kudos section |
| `accent_color` | _(empty)_ | Hex color for accent elements (e.g. `#6366f1`) |

---

## Points History

Displays a paginated table of the member's point transaction history with action label, points earned, and date.

**Block:** WB Gamification Points History
**Shortcode:**
```
[wb_gam_points_history]
[wb_gam_points_history limit="20" show_action_label="1"]
```

| Attribute | Default | Notes |
|---|---|---|
| `user_id` | `0` | 0 = currently logged-in member |
| `limit` | `20` | 1–100 rows to display |
| `show_action_label` | `1` | Show the human-readable action name |

---

## Earning Guide

Displays a formatted grid of all active point-earning actions and their values. Use this on a help or onboarding page to explain how members can earn points.

**Block:** WB Gamification Earning Guide
**Shortcode:**
```
[wb_gam_earning_guide]
[wb_gam_earning_guide columns="3" show_category_headers="true"]
```

| Attribute | Default | Notes |
|---|---|---|
| `columns` | `3` | Number of columns in the grid (1–4) |
| `show_category_headers` | `true` | Show section headers per integration (WordPress, BuddyPress, etc.) |

---

## Using Shortcodes in Classic Widgets and Theme Files

All shortcodes work anywhere WordPress processes shortcodes — pages, posts, text widgets, and theme template files using `do_shortcode()`. They do not require the block editor to be active.
