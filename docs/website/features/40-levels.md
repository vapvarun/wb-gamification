# Levels

Levels give members a visible sense of status and long-term progression. As members accumulate points, they advance through a sequence of levels — each with a name, a threshold, and an optional icon.

## Default Levels

Five levels are created automatically on activation:

| Level | Minimum Points |
|---|---|
| Newcomer | 0 |
| Member | 100 |
| Contributor | 500 |
| Regular | 1,500 |
| Champion | 5,000 |

Every member starts as a Newcomer. As they earn points, they advance through the ladder automatically.

## How Progression Works

Level state is calculated from the member's total points. After every point award, the plugin compares the member's new total against all level thresholds. If the new total puts them in a higher level, the level is updated immediately.

Level is never stored independently — it is always derived from the points ledger. This means if you change a level threshold, member levels update automatically on their next point award.

## Level-Up Notifications

When a member advances to a new level, they receive:

- A **toast notification** in the bottom-right corner of the page with the new level name and icon
- A **BuddyPress notification** (if BuddyPress is active)
- An **activity feed post** announcing the level-up to the community (if BuddyPress is active)

## Progress Bar

The Level Progress block and the Member Points block both display a visual progress bar showing how far the member is toward the next level. The bar updates in real time after each page load.

Members can also see their current level name and the points needed for the next level.

## Adding Custom Levels

1. Go to **Gamification > Levels**.
2. Click **Add New Level**.
3. Enter a level name (for example, "Expert" or "Ambassador").
4. Enter the minimum points required to reach this level.
5. Optionally upload a level icon image.
6. Click **Save**.

Levels are always sorted by their minimum points threshold. You can add as many levels as your community needs.

**Tips for setting thresholds:**
- Think about how many points a typical active member earns per week
- Space levels so members advance roughly every 2–4 weeks of active participation
- Reserve your highest level for genuinely long-term members — it loses meaning if everyone reaches it quickly

## Editing and Removing Levels

You can rename any level or change its point threshold at any time. Changes take effect immediately. Members who have already passed the new threshold stay at that level; members who are below it will drop to the appropriate lower level on their next page load.

You can remove custom levels, but you cannot remove the five default levels. To effectively disable a default level, raise its threshold very high so it is unreachable.

## Displaying Levels

Level information appears in:
- The **Level Progress block** — shows current level name, icon, progress bar, and points needed for next level (`[wb_gam_level_progress]`)
- The **Member Points block** — shows total points, current level name, and progress bar (`[wb_gam_member_points]`)
- **BuddyPress profiles** — the Gamification tab shows current level (if BuddyPress is active)
- **Top Members block** — optionally shows each member's level label beneath their name (`[wb_gam_top_members show_level="1"]`)
