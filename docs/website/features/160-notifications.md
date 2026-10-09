# Notifications

WB Gamification tells members what they have earned in real time. Notifications appear as toast popups on the frontend, as BuddyPress inbox notifications, and as activity feed entries.

## Toast Notifications

Toast notifications are small popups that appear at the **bottom center** of the page shortly after a member earns a reward. Change the corner under **Gamification > Settings > Realtime**. They disappear automatically after **4 seconds**.

There are six notification types:

| Type | When It Shows | Example |
|---|---|---|
| **Points** | After any point-earning action | "+10 points - Activity update posted" |
| **Badge** | When a badge is earned | "Badge earned: Community Pillar" |
| **Level up** | When advancing to a new level | "You reached Contributor!" |
| **Streak milestone** | When hitting a streak milestone | "30-day streak! Keep it up." |
| **Challenge completed** | When a challenge is finished | "Challenge complete: Weekend Writer" |
| **Kudos received** | When someone sends kudos | "[Member] sent you kudos" |

Each toast is dismissible. Members can click it to close it early, or just wait for it to disappear.

**Note:** Silent awards (challenge bonus points, streak bonus points) do not show a points toast - only the challenge or streak notification fires.

### How toasts are paced

- **Each toast shows once.** A member sees an award once, on whichever page delivers it first. It does not show again on the next page.
- **A burst becomes one summary.** When four or more toasts are waiting (a new member's first visit often has a welcome bonus, a badge and several point awards), they arrive as one toast instead: "Welcome - you earned 55 Points", with the badge (or "3 badges earned") underneath and a **See my progress** link. Up to three waiting toasts still arrive one by one. Level-up and streak celebrations always show on their own.
- **One at a time on phones.** At 640px wide and below, a toast that arrives while another is showing waits its turn instead of stacking over the page. Nothing is dropped.
- **Held on entry screens.** A community plugin can hold toasts on screens where a popup would cover the task, such as sign-up, onboarding or checkout. Held toasts are not lost; they show on the next page that does not hold them. BuddyNext and BuddyNext Pro hold them on their entry and checkout screens. Developers use the `wb_gam_hold_toasts` filter.
- **Visitors are not polled.** Logged-out visitors cannot earn, so pages no longer check for their toasts in the background. The only exception is a page that shows a live leaderboard.

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
