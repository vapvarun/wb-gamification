# Privacy

WB Gamification is built with GDPR compliance in mind. Member data is exportable, erasable, and partially controllable by the member themselves through preference settings.

## What Data Is Collected

WB Gamification stores the following data associated with each member's user ID:

- **Point events** - a log of every action that earned points, including the action type, points awarded, timestamp, and optional metadata (such as word count)
- **Badge records** - which badges were earned and when, plus expiry dates where applicable
- **Level state** - the member's current level, derived from their point total
- **Streak data** - current streak count, longest streak, last active date, and timezone
- **Challenge progress** - progress toward each challenge and completion timestamps
- **Kudos sent and received** - giver, receiver, message, and timestamp for every kudos transaction
- **Member preferences** - leaderboard opt-out status, show_rank setting, and notification mode

No payment data, private messages beyond kudos messages, or off-site tracking is stored.

## WordPress Privacy Tools Integration

WB Gamification integrates with WordPress's built-in privacy tools found at **Tools > Privacy**. You can use these tools to handle data subject requests.

### Personal Data Export

When an admin initiates a personal data export for a member (from **Tools > Export Personal Data**), WB Gamification adds the following to the export file:

- Full point history with action labels, points, and dates
- All earned badges with names and earned dates
- Current level name and total points
- All kudos sent, including recipient names and messages
- All kudos received, including sender names and messages
- Current streak count and longest streak
- Challenge completion records
- Notification and privacy preference settings

The export is provided as a ZIP file containing an HTML report the member can read directly.

### Personal Data Erasure

When an admin initiates a personal data erasure for a member (from **Tools > Erase Personal Data**), WB Gamification deletes:

- All point event records for that user
- All earned badge records for that user
- All kudos records where the user is either giver or receiver
- The streak record for that user
- All challenge progress records for that user
- The member preference record for that user

After erasure, the member's gamification history is permanently gone. This action is irreversible.

**Note:** Erasing a member's data does not affect leaderboard snapshots that were already generated before the erasure. Those snapshots are anonymized automatically when cached data expires.

## Member Preferences

Members control three choices from the **Your gamification choices** box on their own profile:

**Show me on leaderboards** (leaderboard opt-out) - When unticked, the member is excluded from all leaderboard snapshots. They will not appear on any public leaderboard, including the Leaderboard block and Top Members block. This setting takes effect on the next leaderboard cache refresh (within 10 minutes).

**Show my level and points on my profile** (show rank) - When unticked, the member's rank is hidden on their public profile and in the members directory. A private profile also hides its rank, and an unknown profile and a private one answer the same way.

**Email me about my rewards** (notification mode) - When unticked, the member gets no gamification emails. See [Email Notifications](../usage/170-email-notifications.md).

Members find the box on their own profile page, or on the **Achievements** tab if you run BuddyPress.

## Admin Data Controls

Admins can:
- View any member's full point history via **Gamification > Members > Members**
- Award or adjust points manually with a logged reason
- Award or revoke badges manually
- View all kudos activity in the Analytics dashboard
- Prune old event logs via WP-CLI (`wp wb-gamification logs prune --before=6months`) to manage database size without affecting member-facing data

## Data Retention

By default, point event logs are kept indefinitely. On large sites, this can add up to significant database storage over time. Use the WP-CLI log prune command to delete raw event logs older than a specified date. Point totals, badge records, and level state are not affected by pruning - only the detailed event log entries are removed.
