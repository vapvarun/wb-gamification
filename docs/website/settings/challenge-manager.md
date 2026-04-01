# Challenge Manager

Go to **WB Gamification > Challenges** in your admin menu.

Challenges give members a time-limited goal: perform a specific action a set number of times before the deadline to earn bonus points. Progress counts only while the challenge is active.

## Creating a Challenge

The **Create Challenge** form is at the top of the page.

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| **Title** | Yes | — | A short name shown to members on the challenge card, e.g. "Post 10 photos this week." |
| **Action** | Yes | — | The user behavior that counts toward this challenge. Choose from all registered actions (same list as the Points tab). |
| **Target Count** | Yes | 10 | How many times the member must perform the action to complete the challenge. Minimum 1. |
| **Bonus Points** | No | 50 | Extra points awarded when the member hits the target. Set to 0 for a challenge with no point bonus (e.g. badge-only reward). |
| **Start Date** | No | Now | When the challenge becomes available. Actions before this date do not count. |
| **End Date** | No | 7 days from now | The deadline. Members must reach the target before this date and time. |

Click **Create Challenge** to save. The new challenge appears in the **All Challenges** list below.

## The Challenge List

The table shows all challenges with these columns:

| Column | Description |
|--------|-------------|
| **Title** | Challenge name |
| **Action** | The action being tracked |
| **Target** | Required action count |
| **Bonus** | Bonus points on completion |
| **Status** | Active or other status |
| **Dates** | Start date → End date |
| **Actions** | Edit or Delete buttons |

## Editing a Challenge

Click **Edit** next to any challenge. The form at the top of the page fills with the existing values. Make your changes and click **Update Challenge**. Click **Cancel** to go back without saving.

## Deleting a Challenge

Click **Delete** in the challenge row. Confirm the prompt. This removes the challenge definition. Member progress toward the deleted challenge is not removed from the events log, but the challenge no longer appears to members.

## Notes on Challenge Design

- **Default 7-day duration.** The end date defaults to 7 days from when you create the challenge. Adjust this for shorter sprints (24-hour flash challenges) or longer campaigns.
- **Actions must be enabled.** If you disable an action on the Points tab, it will not count toward challenges that use it.
- **One action per challenge.** Each challenge tracks a single action type. To create multi-action goals, create multiple challenges and use a badge as the reward for completing all of them.
- **Bonus points stack with regular points.** Members earn their normal per-action points throughout the challenge period, plus the bonus on completion.
