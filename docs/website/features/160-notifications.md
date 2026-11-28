# Notifications

WB Gamification tells members what they have earned in real time. Notifications appear as toast popups on the frontend, as BuddyPress inbox notifications, and as activity feed entries.

## Toast Notifications

Toast notifications are small popups that appear at the **bottom center** of the page shortly after a member earns a reward. Change the corner under **Gamification > Experience > Notifications**. Every toast closes by itself: after **4 seconds**, or **8 seconds** when it carries a link such as **See progress**. Pointing at a toast or tabbing into it pauses the timer, and **Escape** closes the newest one.

There are six notification types:

| Type | When It Shows | Example |
|---|---|---|
| **Points** | After any point-earning action | "+10 points - Activity update posted" |
| **Badge** | When a badge is earned | "New badge" / "Community Pillar", with the badge's picture |
| **Level up** | When advancing to a new level | A celebration card: "You reached Contributor!" |
| **Streak milestone** | When hitting a streak milestone | "30-day streak!", as part of the summary toast |
| **Challenge completed** | When a challenge is finished | "Challenge complete" / "Weekend Writer" |
| **Kudos received** | When someone sends kudos | "Kudos received" / "From [Member]: [their message]" |

Each toast is dismissible. Members can click it to close it early, or just wait for it to disappear.

A toast's title is always one short line; names (a badge, a challenge, a member) are on the line below it.

### How toasts are paced

- **Each toast shows once.** A member sees an award once, on whichever page delivers it first. It does not show again on the next page.
- **Never lost on a reload.** A toast counts as delivered only once it has actually appeared on screen. If the member reloads while a summary is waiting behind a level-up card, the summary shows on the next page, and the level-up card is not shown again in that tab. With two tabs open, each tab may show the same new toast once.
- **Awards that land together are one toast.** A post that pays points, finishes a streak day and earns a badge shows one summary: "+17 Points and a new badge", with what earned it underneath ("Publish a blog post, 5-day streak!, Conversation Starter") and the badge's picture. The number counts up (with BuddyNext 1.3.0, the build that supports toast counts and icons; otherwise the final number shows as text), and the toast keeps adding if more arrives while it shows. Challenge and streak bonus points are included in the total.
- **A level-up shows alone first.** The level-up celebration (and a league promotion or community goal) shows on its own; the summary toast follows when it closes.
- **A long absence is one line.** A member back after a long time with more waiting than the page reads sees one toast, "Since your last visit" / "You earned points and rewards.", with **See progress**.
- **One at a time on phones.** At 640px wide and below, a toast that arrives while another is showing waits its turn instead of stacking over the page. Nothing is dropped.
- **Never over what the member is using.** While a popup or dialog is open, or while the member is writing in a field (a post, a comment, a search), toasts wait and show as soon as the popup closes or the text is sent. This works the same inside a BuddyNext community and on its own.
- **Quick after the member's own action.** When a post, comment or any other action earns something, the toast arrives within a few seconds, whichever plugin handled the action.
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

Members choose whether they get reward emails with **Email me about my rewards** in the **Your gamification choices** panel on their own profile (and on the BuddyPress Achievements tab). It is stored as `notification_mode`: `smart` (emails on, the default) or `none` (no gamification emails). Toasts and on-site notifications are not affected. See [Email Notifications](../usage/170-email-notifications.md).

## Admin-Side Notifications

Admins do not receive individual member-event notifications. Instead, the **Analytics dashboard** gives an overview of community-wide activity. The dashboard refreshes every 10 minutes, and straight away after you award, deduct or revoke points, and shows trends in points, badges, and active members.
