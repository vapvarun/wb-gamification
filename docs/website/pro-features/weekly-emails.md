# Weekly Recap Emails

Weekly Recap Emails automatically send every member a personalized summary of their gamification activity from the past seven days. The `WeeklyEmailEngine` handles scheduling, content assembly, and delivery via WordPress's built-in mail system.

## What the Email Contains

Each email shows the member:

- Points earned during the week
- Badges unlocked during the week
- Current streak status
- Leaderboard position (if leaderboard is active)

The email uses your site name and custom sender details.

## Configuration

1. Enable the feature under **WB Gamification → Settings → Pro Features → Weekly Emails**.
2. Go to **WB Gamification → Settings → Emails**.
3. Set the **sender name** and **sender email address**.
4. Optionally customize the email subject line.

Emails send automatically once per week via WordPress cron (`wp_wb_gam_weekly_email`). The send day and time are configurable in settings.

## Disabling for Individual Members

Members can opt out of recap emails from their profile notification preferences. The engine respects the `wb_gam_member_prefs` table — members with email notifications disabled are skipped.

## Troubleshooting Delivery

If emails are not sending, check:

1. WordPress cron is running (`wp cron event list`).
2. Your hosting environment allows `wp_mail()` outbound connections.
3. The feature flag is enabled.

For reliable delivery on high-volume sites, use a transactional email service (SendGrid, Mailgun, Postmark) connected via a WordPress SMTP plugin.

## Requirements

- Pro add-on active
- `weekly_emails` feature flag enabled
