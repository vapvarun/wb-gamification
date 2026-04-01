# Challenges

Challenges give members a specific goal to work toward, with a bonus point reward for completing it. They are time-bound, trackable, and displayed with a live progress bar.

## What a Challenge Is

A challenge asks a member to perform a specific action a set number of times within a defined period. When they hit the target, the challenge completes automatically and bonus points are awarded immediately.

Example challenges:
- "Post 5 activity updates this week" — 50 bonus points
- "Leave 10 comments this month" — 75 bonus points
- "Complete 3 LearnDash lessons" — 100 bonus points

## Creating a Challenge

1. Go to **Gamification > Challenges**.
2. Click **Add New Challenge**.
3. Fill in the following fields:

**Title** — The name members see. Make it action-oriented and specific (for example, "Weekend Writer" or "Community Builder").

**Action** — Choose the action members must perform. The list shows every active gamification trigger on your site.

**Target** — The number of times the action must be completed.

**Bonus Points** — Points awarded when the challenge is completed. This is on top of the regular points members earn for each action.

**Start Date / End Date** — Optional. Leave blank for an always-on challenge. Set dates for seasonal or event-based challenges. Members cannot start a dated challenge before its start date, and it closes automatically after the end date.

**Status** — Set to Active to make it visible to members. Draft challenges are not shown.

4. Click **Save Challenge**.

## How Members Track Progress

Members see their challenge progress in the **Challenges block** or via the shortcode. For each active challenge, they see:

- The challenge title
- A progress bar showing completions versus target
- How many days remain (if the challenge has an end date)
- A "Completed" badge once they finish

When a challenge completes, the member receives a toast notification and, if BuddyPress is active, a BuddyPress notification.

## Completion and Bonus Points

When a member reaches the target count for a challenge, the system:

1. Marks the challenge as completed for that member
2. Awards the bonus points immediately
3. Sends a completion notification
4. Posts a completion event to the BuddyPress activity stream (if active)

A member who has completed a challenge will not earn the bonus points again for the same challenge, even if they continue performing the action.

## Challenge Display

**Gutenberg block:** Add the **WB Gamification Challenges** block to any page. The sidebar lets you set how many challenges to show and whether to include completed ones.

**Shortcode:**

```
[wb_gam_challenges limit="3"]
[wb_gam_challenges show_completed="0" limit="5"]
```

| Attribute | Default | Description |
|---|---|---|
| `limit` | 0 (all) | Maximum number of challenges to show |
| `show_completed` | 1 | Whether to include completed challenges |
| `show_progress_bar` | 1 | Whether to show the progress bar |
| `user_id` | 0 (current user) | Show challenges for a specific user ID |

## Tips

- Short-duration challenges (1 week) maintain more urgency than open-ended ones.
- Set bonus points higher than what a member would earn just from the regular action points — the challenge should feel worth it.
- Run a new challenge each week or month to give returning members a reason to engage.
- Use challenges to highlight specific areas: if forum activity is low, create a "bbPress Reply" challenge.
