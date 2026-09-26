# Streaks

Streaks reward members for consistent, ongoing participation. The longer a member stays active day after day, the higher their streak count — and at key milestones, they earn bonus points.

## How Streaks Work

A streak counts how many consecutive days a member has been active. "Active" means earning at least one point from any action. Streaks are not tied to logins — the member needs to actually do something.

Streaks are **timezone-aware**. Midnight is calculated using the member's own timezone setting. If they have not set a timezone, the site timezone is used. This means a member in Tokyo and a member in New York each get a fair day boundary.

## The Grace Period

Life happens. The streak engine includes a **1-day grace period** by default. This means:

- If a member misses exactly one day, their streak continues — the grace period covers the gap
- The grace period can only be used once per streak. Missing two consecutive days breaks the streak.
- The grace period resets after each consecutive day. If you use it on a Wednesday, you can use it again after a full consecutive sequence.

Admins can adjust the grace period from 0 to 3 days in **Gamification > Settings > Streaks**.

## Streak Milestones

When a member's current streak reaches one of these milestones, they earn a bonus and see a milestone notification:

| Milestone | Event |
|---|---|
| 7 days | Bonus points + toast notification |
| 14 days | Bonus points + toast notification |
| 30 days | Bonus points + toast notification |
| 60 days | Bonus points + toast notification |
| 100 days | Bonus points + toast notification |
| 180 days | Bonus points + toast notification |
| 365 days | Bonus points + toast notification |

The number of bonus points awarded at each milestone is configurable in **Gamification > Settings > Streaks**. Set to 0 to give a notification without bonus points.

## What Resets a Streak

A streak resets to 1 when:
- A member misses more than one consecutive day (beyond the grace period)
- A member's grace period was already used and they miss another day

When a streak resets, the previous streak length is saved as the member's **longest streak** record. Members can always work toward beating their personal best.

## Displaying Streaks

**Gutenberg block:** Add the **WB Gamification Streak** block to any page. It shows the current streak count prominently, plus an optional heatmap of activity over the past 90 days (similar to GitHub's contribution graph).

**Shortcode:**

```
[wb_gam_streak]
[wb_gam_streak show_longest="1" show_heatmap="1" heatmap_days="90"]
```

| Attribute | Default | Description |
|---|---|---|
| `show_longest` | 0 | Also display the member's longest-ever streak |
| `show_heatmap` | 0 | Show the activity heatmap calendar |
| `heatmap_days` | 90 | How many days the heatmap covers (1–365) |
| `user_id` | 0 (current user) | Show streak for a specific user ID |

## Tips

- Add the streak block to the member's profile page or dashboard so they see their streak every time they visit.
- Set milestone bonus points high enough to feel rewarding. A 30-day streak is a significant commitment and deserves meaningful recognition.
- Use the heatmap on member profiles to give members a visual record of their activity history.
