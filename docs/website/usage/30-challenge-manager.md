# Challenge Manager

Go to **WB Gamification > Challenges** in your admin menu.

Challenges give members a time-limited goal: perform a specific action a set number of times before the deadline to earn bonus points. Progress counts only while the challenge is active.

## Individual vs Community Challenges

*Unified in 1.4.0.* The Challenge Manager page now has two tabs:

- **Individual Challenges** — each member works toward their own copy of the goal. Points and bonuses are awarded per member.
- **Community Challenges** — the whole community works toward one shared goal. Every member's contribution counts toward the collective target.

The two tabs share the same admin page (the standalone *Community Challenges* submenu was removed). Existing direct links to `?page=wb-gam-community-challenges` continue to work — they now load the same page with the Community tab pre-selected.

## Time Zones

*Fixed in 1.4.0.* All challenge **Start Date** and **End Date** values are stored in UTC. When you open the edit form, the displayed time is automatically converted to your browser's local timezone — so a challenge created at "9:00 AM your time" is shown as 9:00 AM on every edit, regardless of how your server is configured. The activation check is also in UTC, so a challenge configured to start at 9:00 AM local time becomes active at 9:00 AM local time without drift.

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
