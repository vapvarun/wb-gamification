# What's New in 1.7.0

**Included in Free.** Version 1.7.0 rebuilds Setup, the admin and the reward toasts around the plugins on your site. Nothing you set before is lost: every amount, limit, badge and level carries over.

## What you should do

- **Find your screens in the new menu.** Every screen moved into six groups; the table below maps the old place to the new one.
- **Look over Earning > Actions and points.** Each change now saves on its own with **Undo**, so there is no Save button any more.
- **Open the Overview.** The **Finish setting up** checklist shows what is left, such as adding the Gamification page to your menu.
- **Developers:** the `wb_gamification_setup_wizard_started` and `_completed` actions now pass `'launch'` or `'skip'` instead of a template slug. See [Actions reference](../developer-guide/120-actions-reference.md).

## Where every screen went

All screens live under **Gamification** in six groups, and each plugin screen shows the same groups as tabs.

| Before 1.7.0 | In 1.7.0 |
|---|---|
| Gamification > Dashboard | Overview > Dashboard |
| Analytics | Overview > Analytics |
| Dashboard > Points | Earning > Actions and points |
| Point Types, Conversions, Multipliers | Earning > Point types, Conversions, Multipliers |
| Dashboard > Rules | Earning > Rules |
| Levels, Badges, Challenges, Community Challenges, Streaks | Rewards > Levels, Badges, Challenges, Streaks |
| Dashboard > Leagues | Rewards > Leagues |
| Rewards (redemption) | Rewards > Redemption store |
| Members, Award Points, Submissions | Members > Members, Award points, Submissions |
| Dashboard > Kudos | Members > Kudos settings |
| Dashboard > Access | Members > Access |
| Dashboard > Realtime | Experience > Notifications |
| Dashboard > Emails | Experience > Emails |
| Dashboard > Habits & Profiles | Experience > Profiles and habits |
| Dashboard > Integrations | Experience > Integrations |
| Dashboard > Modules | Tools > Modules |
| Import | Tools > Import from another plugin |
| Dashboard > Tools | Tools > Export and maintenance |
| Webhooks, API Keys | Tools > Webhooks, API keys |
| Setup Wizard | Tools > Setup |

## Setup starts from your plugins

Setup now opens with the plugins on your site and what members earn in each. You choose which plugins to reward and how generous to be (Relaxed, Balanced, Competitive, or keep your limits), see the levels, badges and leaderboard members will get, and review everything before **Launch**. Nothing is saved until you launch, and **Leave without changes** keeps your site as it is. The starter templates are gone. See [Setup](../getting-started/20-setup-wizard.md).

## Earning, one screen for every action

**Earning > Actions and points** lists every action by plugin, with search and filters (On, Off, Takes points, Has a limit, by plugin). Each change saves on its own, says **Saved.** and can be undone for 10 seconds. Limits read in words ("20 a day, 30s apart"), and an empty limit means no limit. Each plugin's group can be restored to its defaults or switched off in one click. See [Points & Actions](../usage/00-points-actions.md).

## The Overview tells you what to do next

The dashboard shows four numbers for the last 30 days, a **Finish setting up** checklist that checks your site by itself, points you awarded by hand apart from what members earned, and a note when 30% or more of the month's points were awarded by hand. See [Admin Dashboard](../usage/150-admin-dashboard.md).

## Toasts that fit any site

Awards that land together become one summary toast that counts up, with the badge's picture. A level-up shows on its own first. At most two toasts show at once (one on a phone), each closes by itself, Escape closes the newest, and they use your site's font and accent colour. Titles are short fixed phrases with names on the line below, so nothing is cut off. See [Notifications](../features/160-notifications.md).

## Also new

- **Forum points from Jetonomy.** Topics and replies earn points you control, with limits that stop farming (topics 5 a day, replies 20 a day and 30 seconds apart). Removing a topic takes its replies' points back. See [Jetonomy](../integrations/150-jetonomy.md).
- **Big badge collections fold.** The Badge Showcase block shows 12 badges per tab with **Show all**, and its header counts the whole collection. See [Badge Showcase](../shortcodes-blocks/30-badge-showcase.md).

## Fixed

- Saving the Points settings could switch every earning action off.
- With Jetonomy and no BuddyNext, points toasts on forum pages showed "[object Object]".
- A fresh install with several plugins activated together could log database errors.
- Accent buttons inside BuddyNext now meet text contrast, and the gold #1 badge is readable in dark mode.
- Streak and challenge bonus points now appear in the toast, so it agrees with the points history.
- A suspended or excluded member no longer earns points from kudos, streak bonuses or challenge bonuses, and your own rank follows the same exclusions as the board.
- Sharing a post or voting in a poll over and over pays once, and deleting a post takes back the points its comments and reactions earned.
- Owner Card background and Accent colours stay readable on every block in the light and the dark theme.
- The Overview totals update straight away after you award, deduct or revoke points, and small hand awards are counted.
- Filtered Earning rows hide, and every Earning change keeps its own 10 second Undo.
- Finishing Setup no longer sends you back to the first step, and a blank or out of range amount is reported next to the field.
- Toasts, notifications, emails and activity posts read in each member's own language.
- Badge Showcase counts a badge returned twice once and keeps its tabs when nothing is earned yet.

## For developers

- New filter `wb_gam_leaderboard_exclusion_sql` adds your own rule for who appears on every leaderboard and rank. See [Filters reference](../developer-guide/130-filters-reference.md).
- New filter `wb_gam_badge_showcase_page_size` sets how many badges show before **Show all** (`0` shows all).
- `POST /actions/{id}/overrides` now also takes `points` and `point_type`, accepts any registered action id, and `DELETE` restores the whole row. See [REST: admin and webhooks](../developer-guide/180-rest-admin-webhooks.md).
