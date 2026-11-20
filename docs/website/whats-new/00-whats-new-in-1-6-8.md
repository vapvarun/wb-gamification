# What's New in 1.6.8

**Included in Free.** Version 1.6.8 makes earning fair across every integration: points follow the content that earned them, nobody earns from their own content, and the plugin speaks five languages.

## What you should do

- If you saved **Gamification > Dashboard > Points** on 1.6.8 before this fix and have actions from other plugins with capitals, slashes or dots in their id, re-check those actions: the old save could switch them off.
- Open **Gamification > Dashboard > Points** and look at your actions. Eleven built-in actions that rewarded behaviour owners did not want to pay for were removed. Past awards keep their names in history and reports.
- If you want members to claim actions through the Submit Achievement form, tick **Members can submit** on those actions. Every action starts unticked.
- If you use webhooks, check that each destination is a public `https://` address. See [Outbound Webhooks](../features/180-webhooks.md).
- Block settings you saved before may now look different on the page, because spacing, border, shadow and colours now apply as set.

## New

- German, French, Spanish, Italian and Brazilian Portuguese translations, including every block's settings in the editor. See [Installation](../getting-started/00-installation.md#languages).
- Tutor LMS integration: members earn for completing a course or lesson, passing a quiz, enrolling, reviewing a course and asking a question. See [Tutor LMS](../integrations/160-tutor-lms.md).
- LearnDash adds enrol, assignment upload and group completion. LifterLMS adds enrol and membership, and its course, lesson and quiz awards now fire.
- A BuddyPress Community starter template in the [Setup Wizard](../getting-started/20-setup-wizard.md).
- Members choose on their own profile whether their rank shows, whether they appear on leaderboards and whether they get reward emails. See [Privacy](../features/200-privacy.md#member-preferences).
- **Members can submit** tick on each action under **Gamification > Dashboard > Points**. Submit Achievement lists only the actions you opened. See [Submissions](../features/120-submissions.md).
- The `[wb_gam_daily_bonus]` shortcode, and a **Copy link** button on shared badge links. See [Badge Sharing](../features/30-badge-sharing.md).

## Improved

- Course, lesson, quiz and similar awards pay once per member per item, however often it is retaken.
- Block design settings now apply on the page: spacing, border, shadow, colours, **Hide on device** and **Show progress bar**.
- Every block meets WCAG AA contrast in light and dark mode, including accent-coloured text and buttons.
- Email buttons open the Gamification Hub on the matching panel.
- Activity cards show as one compact card on BuddyPress, BuddyBoss and BuddyNext.
- Members with equal points share a rank on the Leaderboard, Top Members and Cohort Rank blocks.
- Badges that need a plugin your site does not run, such as BuddyPress badges without BuddyPress, are hidden from members unless they already earned them. The Badge Library marks them **Not earnable here**. See [Badges](../features/20-badges.md).
- Licence and update checks use the Wbcom update server, with automatic fallback. Update packages are checked against their release signature before WordPress installs them.

## Fixed

- Deleting, trashing, rejecting or refunding content takes back the points it earned, and restoring it pays them back once. See [Points](../features/00-points.md#when-content-is-removed).
- Acting on your own content (comments, votes, bookmarks, reactions, reviews) no longer earns points.
- Content created before this update, or copied by BuddyNext or WPMediaVerse, is never paid a second time.
- Reviews and comments held for moderation pay when they are approved, not when they are submitted.
- WooCommerce: free and unpaid orders no longer pay or use up the first-purchase award, and a refund takes back what that order paid.
- A Learnomy quiz pays once per member, and deleting a group takes back its cover-photo award.
- Reactions pay the amount you set instead of a forced minimum of 5.
- Year Recap names a year with no points and leaves removed content out of its totals.
- Redemption Store rewards save the status, quantity and limit you set.
- Import waits for a run already going in another tab instead of starting a second one.
- Private profiles hide their rank in the members directory, and an unknown and a private profile answer the same way.
- Reward toasts wait while a dialog is open, and the moment card no longer blocks the page.
- A block's own **Card background** and **Accent** colours stay readable in dark mode on Reign, BuddyX and BuddyX Pro, and list rows stay visible on their card.
- Every Gamification admin screen works in a translated admin, and messages from the server show in the admin's own language.
- Community challenges updated through the REST API keep their reward and action. One created without dates runs for 14 days instead of never counting.
- The currency you pick for an action in **Dashboard > Points** is the one its awards land in. Deleting a currency clears that choice on every action that used it. See [Multiple Currencies](../features/10-multi-currency-points.md#action-configuration).
- Each balance shows its own currency's icon in the members directory, the profile, the leaderboard, the status bar and the Member Points block. The leaderboard block's **Your rank** line uses the block's currency.
- Rank, points and badges show in the BuddyBoss members directory once per member, and the rank line sits centred under the name in the member grid on phones.
- Webhooks keep the active setting you choose, editing one no longer turns it back on, and a refused address says why.

## Security

- Webhook URLs that point at the server itself or a private network address are refused. Deliveries use WordPress's safe request, so they cannot reach internal services.
- Update packages are checked against their release signature before WordPress installs them. The check only warns for now: the result is logged and the update still installs.
- Members cannot fire `POST /events` for themselves. Only staff and API keys can. See [REST: Admin](../developer-guide/180-rest-admin-webhooks.md).

## For developers

- New REST routes `GET` and `POST /members/{id}/preferences`, and `GET /issuer` (the OpenBadges 3.0 issuer profile). See [Members and Points](../developer-guide/150-rest-members-points.md).
- New action `wb_gam_render_member_prefs` prints a member's choices form, and a new filter `wb_gam_profile_publicly_visible`. See the [Actions](../developer-guide/120-actions-reference.md) and [Filters](../developer-guide/130-filters-reference.md) references.
- Webhook and community challenge REST changes are in [Admin and Webhooks](../developer-guide/180-rest-admin-webhooks.md) and [Challenges and Kudos](../developer-guide/170-rest-challenges-kudos.md).

## FAQ

**Will my members lose points after updating?**
No. The update itself takes nothing back. From now on, when content that earned points is deleted, trashed, rejected or refunded, those points come back off; restoring it pays them again once.

**Why did a badge disappear for my members?**
It needs a plugin your site does not run. Members who already earned it keep seeing it.

## Troubleshooting

**A webhook will not save.** Use a public `https://` address. The message names the problem: not https, address not found, or a private network address.
