# Weekly Recap Emails

Weekly Recap Emails automatically send every member a personalized summary of their gamification activity from the past seven days. The `WeeklyEmailEngine` handles scheduling, content assembly, and delivery via WordPress's built-in mail system.

## What the Email Contains

Each email shows the member:

- Points earned during the week
- Badges unlocked during the week
- Current streak status
- Leaderboard position (if leaderboard is active)

The email is sent from your site's admin email address, with your site title as the sender name.

## Configuration

1. Go to **WB Gamification > Habits & Profiles**.
2. In **Weekly Recap Email**, tick or untick **Send the weekly recap email**.
3. Optionally change the **Subject line**.

Emails go out every Monday at 08:30 UTC via WordPress cron (`wb_gam_weekly_email`), to members who earned points in the last 7 days. A member whose week had no points and no badges is skipped. The day and time are fixed.

Weekly Recap Emails are on by default.

## Disabling for Individual Members

Members opt out by turning off **Email me about my rewards** on their profile, or with the one-click unsubscribe link at the end of every recap. Either way they are skipped from then on.

## Troubleshooting Delivery

If emails are not sending, check:

1. WordPress cron is running (`wp cron event list`).
2. Your hosting environment allows `wp_mail()` outbound connections.
3. **Send the weekly recap email** is still ticked under **Habits & Profiles**.

For reliable delivery on high-volume sites, use a transactional email service (SendGrid, Mailgun, Postmark) connected via a WordPress SMTP plugin.
