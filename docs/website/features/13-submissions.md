# Submissions (UGC Achievements)

Submissions let members claim achievements that the system cannot automatically detect — volunteer hours, offline milestones, custom community goals. Members submit, admins approve, points and badges fire through the standard award pipeline.

## What Submissions Are

A submission is a member-supplied claim that they did something earnable. Each submission has:

- **Title** — short description ("Volunteered 3 hours at Saturday cleanup")
- **Details + photo** — a short write-up of what they did, with an **Add Media** button to attach a photo as proof right in the form (a screenshot, event photo, or certificate). Every logged-in member can attach a photo this way — they don't need to be an admin.
- **Optional URL** — or just paste a link to evidence hosted elsewhere (a social post, a Google Drive file)
- **Action** — the gamification action this submission represents (admin defines the catalog)

When an admin approves the submission, the system fires the standard event for that action. The member earns the configured points exactly as if the action had fired automatically. Badges, levels, streaks, and the leaderboard all update through the same path — there is no parallel "submitted points" track.

## Member Flow

1. Member places the **Submit Achievement block** on a page (or admins place it on the Hub).
2. Member fills the form: a title, a short description (with **Add Media** to attach a proof photo), an optional evidence URL, and picks an action from the allowed list.
3. The submission lands in the moderation queue with status `pending`.
4. Admin approves (→ points fire) or rejects (→ no points, member optionally notified).
5. Approved submission appears on the member's points history with a "submitted" badge.

## Admin Flow

**Gamification → Submissions** in the WordPress admin.

The queue lists every pending submission with member, title, URL, action, and submission timestamp. Each row has Approve and Reject buttons. Bulk actions support batch approve/reject.

Approving routes through `PointsEngine::award` so:
- The configured points for the action are granted
- The action's daily cap (if any) is enforced
- All downstream effects (badge unlock, level up, streak credit) fire normally

Rejecting marks the submission as `rejected` and (optionally) emails the member with a reason.

## Daily Cap

To prevent abuse, members can submit at most **5 achievements per day**. The cap is configurable.

## Member History

The member's points history (`/u/{user}/?tab=history`) shows each approved submission inline with their auto-earned actions. Rejected submissions are private to the member and the admin team.

## Notifications

- **Submission received** — admin email + admin-bar count badge
- **Submission approved** — member email (if enabled) + toast notification + points awarded
- **Submission rejected** — optional member email with reason

## Configuration

Settings → Submissions.

| Setting | Default |
|---|---|
| Enabled | On |
| Daily cap per member | 5 |
| Allowed actions | All actions tagged `submittable: true` in the action manifest |
| Default reject reason | "We could not verify this achievement at this time." |
| Auto-approve | Off |

To make an action submittable, set `submittable: true` on the action manifest entry. By default, only a curated subset of actions are submittable (volunteer-hours-style ones).

## Privacy

Submissions are stored in the `wb_gam_submissions` table with the submitter's user ID, the title, optional URL, action ID, and timestamps. Approved submissions are GDPR-exported alongside other gamification data. Rejected submissions are erased on user deletion.

## See Also

- **[Points](01-points.md)** — how the standard award pipeline integrates submissions
- **[Notifications](18-notifications.md)** — toast / email behavior on approval
- **[Privacy](22-privacy.md)** — what's exported and erased
- **[Submit Achievement block](../blocks/01-blocks-overview.md)** — front-end submission form
