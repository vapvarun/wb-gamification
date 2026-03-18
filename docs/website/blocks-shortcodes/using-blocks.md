# Blocks & Shortcodes Reference

WB Gamification provides 10 Gutenberg blocks and a matching shortcode for each. All blocks are server-side rendered and work in any WordPress theme.

## Adding a Block

In the block editor, click **+** and search for "Gamification". All blocks appear under the Widgets category.

---

## Gamification Leaderboard

Shows ranked members with avatars, display names, and point totals. When a logged-in member is not in the visible top N, a private nudge appears below the table showing their rank and points gap.

**Block:** `wb-gamification/leaderboard` | **Shortcode:** `[wb_gam_leaderboard]`

| Attribute | Default | Options | Description |
|---|---|---|---|
| `period` | `all` | `all`, `month`, `week`, `day` | Time window for points |
| `limit` | `10` | 1–100 | Number of rows to show |
| `scope_type` | — | `bp_group` | Restrict to a scope |
| `scope_id` | `0` | group ID | Scope object ID |
| `show_avatars` | `true` | true/false | Show member avatars |

**Examples:**
```
[wb_gam_leaderboard period="week" limit="5"]
[wb_gam_leaderboard scope_type="bp_group" scope_id="12"]
```

---

## Member Points

Displays a single member's total points, level name, and progress bar toward the next level.

**Block:** `wb-gamification/member-points` | **Shortcode:** `[wb_gam_member_points]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `show_level` | `true` | Show the member's level name |
| `show_progress_bar` | `true` | Show progress bar to next level |

---

## Badge Showcase

Lists a member's earned badges. Optionally shows locked badges greyed out so members can see what they're working toward.

**Block:** `wb-gamification/badge-showcase` | **Shortcode:** `[wb_gam_badge_showcase]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `show_locked` | `false` | Show unearned badges (greyed out) |
| `category` | — | Filter by badge category slug |
| `limit` | `0` | Max badges. `0` = all |

---

## Level Progress

Shows a member's current level, a progress bar, and the gap to the next level.

**Block:** `wb-gamification/level-progress` | **Shortcode:** `[wb_gam_level_progress]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `show_progress_bar` | `true` | Show progress bar |
| `show_next_level` | `true` | Show next level name and points remaining |
| `show_icon` | `true` | Show level icon |

---

## Challenges

Lists active challenges with the current member's progress on each.

**Block:** `wb-gamification/challenges` | **Shortcode:** `[wb_gam_challenges]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `show_completed` | `true` | Include completed challenges |
| `show_progress_bar` | `true` | Show per-challenge progress bar |
| `limit` | `0` | Max challenges. `0` = all |

---

## Streak

Shows a member's current activity streak and longest streak. Optionally renders a GitHub-style heatmap of recent activity.

**Block:** `wb-gamification/streak` | **Shortcode:** `[wb_gam_streak]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `show_longest` | `true` | Show all-time longest streak |
| `show_heatmap` | `false` | Show activity heatmap |
| `heatmap_days` | `90` | Number of days shown in heatmap |

---

## Top Members

Highlights the top-ranked members with avatars, points, level, and badge count. Supports podium and list layouts.

**Block:** `wb-gamification/top-members` | **Shortcode:** `[wb_gam_top_members]`

| Attribute | Default | Options | Description |
|---|---|---|---|
| `limit` | `3` | 1–10 | Number of members |
| `period` | `all_time` | `all_time`, `this_week`, `this_month` | Time window |
| `show_badges` | `true` | true/false | Show badge count |
| `show_level` | `true` | true/false | Show level name |
| `layout` | `podium` | `podium`, `list` | Display layout |

---

## Kudos Feed

Displays a live feed of recent peer-to-peer kudos sent on your site.

**Block:** `wb-gamification/kudos-feed` | **Shortcode:** `[wb_gam_kudos_feed]`

| Attribute | Default | Description |
|---|---|---|
| `limit` | `10` | Max entries. 1–50 |
| `show_messages` | `true` | Show the kudos message text |

---

## Year in Community Recap

A Spotify Wrapped-style shareable card showing a member's highlights for the year: top actions, badges earned, kudos sent and received, and streak record.

**Block:** `wb-gamification/year-recap` | **Shortcode:** `[wb_gam_year_recap]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `year` | `0` | Year. `0` = current year |
| `show_share_button` | `true` | Show share button |
| `show_badges` | `true` | Include badge highlights |
| `show_kudos` | `true` | Include kudos highlights |
| `accent_color` | — | Custom hex accent color (e.g. `#7c3aed`) |

---

## Points History

Shows a member's recent point transactions in reverse chronological order with action labels and dates.

**Block:** `wb-gamification/points-history` | **Shortcode:** `[wb_gam_points_history]`

| Attribute | Default | Description |
|---|---|---|
| `user_id` | `0` | User ID. `0` = currently logged-in user |
| `limit` | `20` | Max transactions to show |
| `show_action_label` | `true` | Show the action label next to each entry |

---

## Using Shortcodes in Classic Pages

All shortcodes accept the same attributes as their block counterparts. Boolean attributes accept `1`/`0` or `true`/`false`.

```
[wb_gam_leaderboard period="month" limit="10"]
[wb_gam_badge_showcase user_id="42" show_locked="1"]
[wb_gam_streak show_heatmap="1" heatmap_days="180"]
[wb_gam_top_members layout="list" period="this_week"]
[wb_gam_points_history limit="10" show_action_label="1"]
```
