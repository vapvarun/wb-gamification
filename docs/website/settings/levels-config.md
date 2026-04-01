# Levels Configuration

Go to **WB Gamification > Levels** in your admin sidebar.

Levels give members a visible rank as they accumulate points. A member's level updates automatically the moment their cumulative points cross a threshold — no cron job or manual refresh needed.

## The Levels Table

The table lists every level ordered from lowest to highest threshold.

| Column | Description |
|--------|-------------|
| **Level Name** | The display name members see on their profile, e.g. "Newcomer," "Member," "Veteran." |
| **Min Points Required** | The cumulative point total a member needs to reach this level. |
| **Delete** | Removes the level. The starting level (0 points) cannot be deleted. |

### Editing existing levels

1. Click into the **Level Name** or **Min Points Required** field for any row.
2. Change the value.
3. Click **Save Levels**.

All members are re-evaluated against the new thresholds on their next activity. If you lower a threshold, members already past that point keep their current (higher) level — they are not downgraded.

The 0-point starting level has its Min Points field locked at 0 and displays "Starting level." You can rename it but not delete or change its threshold.

## Adding a New Level

The **Add New Level** card below the table adds a new threshold.

| Field | Description |
|-------|-------------|
| **Level Name** | Required. A short name for the new level. |
| **Min Points Required** | Required. Must be at least 1. |

Fill both fields and click **Add Level**. The new row appears in the table sorted by points threshold.

### Recommended structure

- Always keep one level at 0 points. This is the default rank for all new members.
- Space thresholds far enough apart that members must genuinely engage to advance.
- Give levels meaningful names that reflect community status.

**Example:**

| Level Name | Min Points |
|------------|-----------|
| Newcomer | 0 |
| Member | 100 |
| Regular | 500 |
| Veteran | 2,000 |
| Legend | 10,000 |

## Deleting a Level

Click **Delete** next to any non-zero level. You will see a confirmation prompt. Deleting a level does not remove any member's points. Members who were at the deleted level fall back to the highest remaining level below their point total.

> The starting level (Min Points = 0) is protected and cannot be deleted. Always keep at least one level at 0 so new members have a default rank immediately.
