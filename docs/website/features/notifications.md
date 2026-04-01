# Notifications

WB Gamification tells members what they have earned in real time. Notifications appear as toast popups on the frontend, as BuddyPress inbox notifications, and as activity feed entries.

## Toast Notifications

Toast notifications are small popups that appear in the **bottom-right corner** of the page immediately after a member earns a reward. They disappear automatically after **4 seconds**.

There are six notification types:

| Type | When It Shows | Example |
|---|---|---|
| **Points** | After any point-earning action | "+10 points — Activity update posted" |
| **Badge** | When a badge is earned | "Badge earned: Community Pillar" |
| **Level up** | When advancing to a new level | "You reached Contributor!" |
| **Streak milestone** | When hitting a streak milestone | "30-day streak! Keep it up." |
| **Challenge completed** | When a challenge is finished | "Challenge complete: Weekend Writer" |
| **Kudos received** | When someone sends kudos | "[Member] sent you kudos" |

Each toast is dismissible. Members can click it to close it early, or just wait for it to disappear.

**Note:** Silent awards (challenge bonus points, streak bonus points) do not show a points toast — only the challenge or streak notification fires.

## BuddyPress Notifications

When BuddyPress is active, every significant gamification event creates a BuddyPress notification. Members see these in their notification bell in the header, just like friend requests and group invites. The following events create BuddyPress notifications:

- Badge earned
- Level-up
- Challenge completed
- Kudos received
- Streak milestone hit

Members can mark these as read from the BuddyPress notifications panel the same way as any other notification.

## Activity Feed Events

When BuddyPress is active, major achievements are also posted to the BuddyPress activity stream. This makes achievements visible to the whole community, not just the member who earned them. Activity feed events are created for:

- Badge earned
- Level-up
- Kudos given (shows giver, receiver, and message)
- Challenge completed

Activity feed posts from gamification events look and behave exactly like other BuddyPress activity. Members can like and comment on them.

## Member Notification Preferences

Members can control how they receive notifications from their profile settings. The available preference is the **notification mode**:

| Mode | Behavior |
|---|---|
| `smart` (default) | Notifications appear for meaningful events (badges, levels, milestones). Routine point toasts are shown but at reduced frequency to avoid noise. |
| `all` | Every point award, badge, level-up, and other event shows a notification. |
| `quiet` | Only major events (badge earned, level-up) trigger notifications. Routine point toasts are suppressed. |

Members access their preference from their profile settings page. Admins can set the default mode in **Gamification > Settings**.

## Admin-Side Notifications

Admins do not receive individual member-event notifications. Instead, the **Analytics dashboard** gives an overview of community-wide activity. The dashboard refreshes every 10 minutes and shows trends in points, badges, and active members.
