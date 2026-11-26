# Submissions (UGC Achievements)

Submissions let members claim achievements the plugin cannot detect on its own, such as volunteer hours, offline milestones or custom community goals. A member submits, you approve, and the points arrive through the normal award path.

## What Submissions Are

A submission is a member's claim that they did something worth points. Each submission has:

- **Action** - which of your actions the member is claiming. Members can only pick actions you have opened for submission (see [Choose What Members Can Submit](#choose-what-members-can-submit)).
- **Details and photo** - a short write-up of what they did. The **Add Media** button lets any logged-in member attach a photo as proof (a screenshot, an event photo, a certificate).
- **Optional URL** - a link to evidence hosted elsewhere, such as a social post or a shared file.

When you approve a submission, the member earns the points set for that action, exactly as if it had happened automatically. Badges, levels, streaks and the leaderboard all update the same way. There is no separate "submitted points" track.

## Choose What Members Can Submit

Nothing can be submitted until you choose it. On a new site, and after updating to 1.6.8, the Submit Achievement form has no actions to pick from and tells members so.

To open an action for submission:

1. Go to **WB Gamification** and click **Points** in the left menu.
2. Open the group the action belongs to, for example **WordPress**.
3. Tick **Members can submit** on the action's row. The tick saves straight away.

Untick it to close the action again. Submissions already waiting in the queue for that action can still be approved or rejected.

Pick actions that need a person to check them, such as "Volunteer for an event". Leave automatic actions (publishing a post, leaving a comment) unticked: they already pay points on their own, and opening them lets members claim them a second time.

A plugin that adds actions can mark one as submittable in its action definition with `'submittable' => true`. Your tick on the Points screen wins over that default.

## Member Flow

1. Add the **Submit Achievement** block (or its shortcode) to a page.
2. A logged-in member fills in the form: picks an action from the list you opened, describes what they did (with **Add Media** for a proof photo) and adds an optional link.
3. The submission waits in your review queue as **pending**.
4. You approve it (points are paid) or reject it (no points, with an optional reason).

Visitors who are not logged in see a prompt to log in instead of the form.

## Admin Flow

Go to **Gamification > Members > Submissions**.

The queue lists every pending submission with the member, the action, the details and any link. Each row has **Approve** and **Reject** buttons. Approving pays the points set for the action and runs everything that follows from them: badges, level-ups and streaks. Rejecting records your reason for the member.

## Daily Limit

Each member can submit at most **5 achievements per day**. Developers can change this with the `wb_gam_submission_daily_cap` filter.

## Privacy

Submissions are stored with the member's user ID, the action, the details, the link and the dates. They are included when a member exports or erases their personal data through the WordPress privacy tools.

## FAQ

**Why does the Submit Achievement form say there is nothing to submit?**
No action is open for submission yet. Tick **Members can submit** on at least one action under **Points**.

**Do I need to untick an action to stop new claims while old ones are still waiting?**
Yes. Untick it. The waiting submissions stay in the queue, and you can still approve or reject them.

## Troubleshooting

**A member says an action is missing from the form.** Check that **Members can submit** is ticked for it under **Points**.

**Approving gives the member no points.** Check that the action pays more than 0 points under **Points**.

## See Also

- **[Points](00-points.md)** - how the standard award path handles submissions
- **[Notifications](160-notifications.md)** - what the member sees on approval
- **[Privacy](200-privacy.md)** - what is exported and erased
- **[Submit Achievement block](../shortcodes-blocks/00-blocks-overview.md)** - the front-end submission form
