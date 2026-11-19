# Email Notifications

Go to **WB Gamification > Settings > Emails** in your admin menu.

WB Gamification can email members when they earn something, and once a week with a recap. This page lists every email, shows where you switch each one on or off, and explains how a member opts out. Reward emails are off until you turn them on, so an update never floods your members.

For how toasts and BuddyPress notifications work, see [Notifications](../features/160-notifications.md). For the recap in detail, see [Weekly Recap Emails](../features/170-weekly-emails.md).

## Every Email at a Glance

| Email | Subject line | Sent when | Default | Where you switch it |
|---|---|---|---|---|
| Level up | You reached *level name*! | A member climbs to a new level. | Off | **Emails** |
| Badge earned | You earned the *badge name* badge! | A member earns a badge. | Off | **Emails** |
| Challenge completed | Challenge completed: *title* | A member finishes a challenge. | Off | **Emails** |
| Redemption confirmation | Your redemption: *reward* | A member redeems a reward. | Off | **Emails** |
| Weekly recap | Your week in *site name* | Every Monday at 08:30 UTC. | On | **Habits & Profiles** |
| Leaderboard nudge | Your weekly community ranking at *site name* | Once a week, when a member is close to overtaking someone. | Off | **Habits & Profiles** |

A level drop (for example after points expire) never sends an email. Only a climb does.

## Turn the Reward Emails On or Off

The four reward emails are called transactional emails: each one answers one thing a member just did.

1. Go to **WB Gamification** and click **Emails** in the left menu, under **Automation**.
2. Under **Transactional emails**, switch on the emails you want: **Level up**, **Badge earned**, **Challenge completed** and **Redemption confirmation**.
3. Click **Save email settings**.

The change applies at once. The **Redemption confirmation** email includes the points spent, the remaining balance and the coupon code, if the reward made one.

The [Setup Wizard](../getting-started/20-setup-wizard.md) can also turn on the first three.

### Test Before You Switch On

The Emails screen shows a WP-CLI command that sends a sample to a member:

```bash
wp wb-gamification email-test --user=1 --event=level_up
```

## Turn the Weekly Recap and the Nudge On or Off

These two live on a different screen.

1. Go to **WB Gamification** and click **Habits & Profiles** in the left menu.
2. For the recap, tick or untick **Send the weekly recap email**. Edit the **Subject line** if you want your own.
3. For the nudge, tick **Also send the nudge by email**.
4. Click **Save Engagement Settings** at the bottom of the screen.

The recap goes to members who earned points in the past 7 days. It is skipped for a member who earned no points and no badge that week. When the week was a personal best, or the streak is 7 days or more, the subject gets a short note added: "Personal best!" or "Streak active".

The nudge is an in-app notification when BuddyPress is active. The email setting reaches members on any other setup. A member who turned off **Show me on leaderboards** or **Email me about my rewards** is skipped.

## Who the Emails Come From

The sender is the same for every email.

| Part | Where it comes from |
|---|---|
| From address | The **Administration Email Address** in **Settings > General** in WordPress. |
| From name | Your site title, from **Settings > General**. |

There is no sender screen in WB Gamification. A developer can change the whole From line with the `wb_gam_email_from_header` filter. See [Customizing gamification emails](../developer-guide/210-email-customization.md).

If emails do not arrive, the cause is almost always your site's mail setup, not the plugin. Use an SMTP or transactional mail plugin. WB Gamification sends through the normal WordPress mail function.

## How Members Opt Out

A member controls their own email. There are two ways, and both do the same thing.

**From their profile.** The member opens their own profile page, or the **Achievements** tab if you run BuddyPress. A box called **Your gamification choices** has three checkboxes:

- **Show my level and points on my profile**
- **Show me on leaderboards**
- **Email me about my rewards**

Unticking **Email me about my rewards** saves at once and shows "Saved." Ticking it again turns the emails back on. Nobody else, including you, sees these boxes on another member's profile.

**From the recap.** Every weekly recap ends with an **Unsubscribe from weekly emails** link. One click turns the same choice off, with no login. It stops the recap and the reward emails together.

What the choice stops:

| Email | Stopped by the opt out |
|---|---|
| Level up, Badge earned, Challenge completed, Redemption confirmation | Yes |
| Weekly recap | Yes |
| Leaderboard nudge | Yes |

The choice is stored as `notification_mode` with the value `smart` (send, the default) or `none` (send nothing). It is checked again when the email is about to go out, so a member who opts out right after an event does not get a message that was already queued.

Toasts and BuddyPress notifications on your site are not emails and are not affected.

## Good to Know

- Reward emails are queued and sent in the background, so the page a member is on does not wait for the mail server.
- One member receives at most 5 emails of the same type in 5 minutes. A bulk award does not flood an inbox.

## Change How the Emails Look

The look of each email comes from a template you can replace in your theme. Developers can also change the subject, the recipients and the body with filters. See [Customizing gamification emails](../developer-guide/210-email-customization.md).

## Who Can Change Email Settings

Administrators only on this screen. Staff with the **Manage emails** permission can change the four reward email switches through the REST API, but not on the screen. See [Staff Permissions](140-staff-permissions.md).

## See Also

- [Weekly Recap Emails](../features/170-weekly-emails.md)
- [Notifications](../features/160-notifications.md)
- [Customizing gamification emails](../developer-guide/210-email-customization.md)
