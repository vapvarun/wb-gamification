# Points & Actions

Go to **WB Gamification > Points** in your admin sidebar.

## The Actions Table

Each row represents one user behavior that can earn points. Actions are detected automatically from active plugins — no setup required. You will not see any rows until at least one supported plugin (WordPress core, BuddyPress, WooCommerce, etc.) is active.

The table has five columns:

| Column | What it controls |
|--------|-----------------|
| **On** | Checkbox. Uncheck to disable the action entirely. Disabled actions earn zero points and fire no badges or challenges. |
| **Action** | The name and description of the behavior, e.g. "Publish a post" or "Upload media." |
| **Points** | How many points a member earns each time this action fires. Enter any whole number from 0 to 9999. |
| **Repeat** | Whether the action can be earned more than once. **Yes** means every occurrence earns points. **Once** means the member earns points only the first time. This is set per-action in the manifest and cannot be changed from the admin. |
| **Daily cap** | The maximum number of times this action counts in a single day. Shows a number or ∞ (unlimited). Like Repeat, this is defined in the manifest and is read-only in the table. |

### Changing point values

1. Find the action row.
2. Edit the number in the **Points** column.
3. Click **Save Changes** at the bottom of the page.

Changes take effect immediately for any future action. Past points are not recalculated.

### Enabling and disabling actions

Uncheck the **On** toggle next to any action and save. Members performing that action will no longer earn points, badges, or challenge progress from it.

## Action categories

Actions are grouped into cards by category:

| Category | Source |
|----------|--------|
| **WordPress** | Core WordPress events — publishing posts, comments, user registration |
| **BuddyPress** | Social events — profile updates, activity posts, friendships, group joins |
| **Commerce** | WooCommerce — purchases, product reviews |
| **Learning** | LearnDash — course completions, quiz scores |
| **Social** | Reactions, kudos, and other peer-recognition events |
| **General** | Actions from other integrations or custom manifests |

If a category does not appear, the plugin that provides it is not active on your site.

## Understanding "Repeatable" and "Daily Cap"

**Repeatable: Once** — Useful for one-time milestones like "Complete your profile" or "Write your first post." The member earns the points exactly one time, no matter how many times they perform the action.

**Repeatable: Yes** — The action counts every time. Use the Daily Cap to prevent abuse.

**Daily cap** — A hard ceiling on how many times an action can earn points in one calendar day (UTC). For example, if commenting has a daily cap of 10, a member who posts 50 comments earns points for only the first 10 each day.

## Log Retention

Below the action categories, the **Log Retention** card controls how long the points history table keeps rows.

| Field | Default | Range |
|-------|---------|-------|
| Keep points history for | 6 months | 1–24 months |

Rows older than this value are pruned automatically by WP-Cron once per day. The **events table** (the source of truth) is never pruned. This means you can always recalculate totals from events even after the points log is trimmed.

Reduce this value on high-traffic sites to keep the database lean. Increase it if you rely on per-period analytics.

Click **Save Changes** to apply.
