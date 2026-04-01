# Leaderboard

The leaderboard ranks members by points earned over a selected time period. It updates automatically and can be embedded on any page.

## Time Periods

The leaderboard supports four time periods:

| Period | What It Shows |
|---|---|
| All-time | Total points since the member joined |
| Monthly | Points earned in the current calendar month |
| Weekly | Points earned in the current calendar week (Monday to Sunday) |
| Daily | Points earned today |

Each period is a separate leaderboard snapshot. Members who are active today appear on the daily leaderboard even if they rank lower on the all-time board.

## Group Scoping

When BuddyPress is active, you can scope the leaderboard to a single BuddyPress group. Members outside that group are excluded from the ranking. This lets you create group-specific leaderboards for course cohorts, team challenges, or private communities.

To scope a leaderboard to a group, set `scope_type="group"` and `scope_id` to the BuddyPress group ID in the block settings or shortcode.

## How Rankings Are Calculated

Leaderboard positions are calculated from the points ledger and stored in a snapshot cache. The cache is refreshed automatically on a schedule. This means:

- Very recent activity may take a short time to appear on the leaderboard
- Page loads are fast because the ranking query runs against the cached snapshot rather than recounting points from scratch

If you award points manually and need the leaderboard to update immediately, you can clear the snapshot cache from **Gamification > Settings**.

## The "Your Rank" Section

When a logged-in member views the leaderboard, the block highlights their current rank below the top list even if they are not in the visible top section. This way every member can see where they stand regardless of their position.

## Member Opt-Out

Members can opt out of appearing on the leaderboard from their notification preferences. An opted-out member is excluded from all leaderboard snapshots. Admins cannot override a member's opt-out choice.

See the Privacy documentation for more details on how to access preference settings.

## Adding the Leaderboard to a Page

**Using the Gutenberg block:**

1. Edit any page.
2. Click the block inserter (+) and search for **WB Gamification Leaderboard**.
3. Add the block. Use the sidebar panel to set the period, limit, and group scope.
4. Publish or update the page.

**Using a shortcode:**

```
[wb_gam_leaderboard period="week" limit="10"]
[wb_gam_leaderboard period="all" limit="20" scope_type="group" scope_id="5"]
```

Available `period` values: `all`, `month`, `week`, `day`

## Top Members Block

For a compact podium-style display of the top 3 members, use the **Top Members block** instead. It shows avatars in a visual podium layout and is well-suited for homepage sections or sidebars.

```
[wb_gam_top_members limit="3" layout="podium"]
[wb_gam_top_members limit="5" layout="list" show_badges="1"]
```
