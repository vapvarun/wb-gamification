# Admin Dashboard

Go to **WB Gamification > Dashboard** in your admin menu (`admin.php?page=wb-gamification`).

The Dashboard answers one question: is gamification running, and who is winning? It shows the last 30 days at a glance. Every card links to the screen where you can change what you see.

Only administrators open this screen. To give a moderator part of the job, see [Staff Permissions](140-staff-permissions.md).

## Getting Started Card

On a brand-new site, a **Getting Started** card sits at the top. It shows only while no points were awarded in the last 30 days, and only until you click **Dismiss**.

- **Send a test event (award yourself 10 points)** gives your own account 10 points. Use it to watch the numbers below move.
- **Re-run wizard** reopens the [Setup Wizard](../getting-started/20-setup-wizard.md).
- The quick links jump to your Hub page, the point values, **Point Types**, **Challenges** (only when the Challenges module is on) and the badge library.

Right after the Setup Wizard finishes, a **Setup complete - your Gamification Hub is ready** card appears at the top, with **View Hub page** and **Edit Hub page** buttons.

## Setup Checklist

The **Setup checklist** card counts the steps you have finished, for example "Setup checklist (3 / 5)". It checks your site by itself. You never tick anything.

| Step | Counts as done when | Button |
|---|---|---|
| Run the Setup Wizard | The wizard finished and you picked a starter template | **Run wizard** |
| Define your levels | At least one level exists | **Add levels** |
| Build your badge library | At least one badge exists | **Add badges** |
| Place the Hub block on a page | A Hub page is set | **Edit Hub page** (or **Create page**) |
| See points flow end-to-end | Points were awarded in the last 30 days | **Open Hub** |

The card disappears once every step is done. Click **Dismiss** to hide it earlier. The choice is saved for your account only.

## The Six Number Cards

All six numbers cover the last 30 days. They are cached for 10 minutes, so a brand-new award can take a few minutes to show.

| Card | What the big number means | What the small line means |
|---|---|---|
| **Points Awarded** | Points members earned. Points spent in the redemption store are not counted. | Last 30 days |
| **Active Members** | Members who earned at least one point. | Your total number of WordPress users, for example "169 total members". |
| **Badges Earned** | Badges awarded. | The share of active members who earned a badge, for example "40% of active members". |
| **Challenges Completed** | Challenges finished. | Completed divided by started in the same 30 days, shown as "% completion rate". |
| **Active Streaks** | Members with a running daily streak that has not lapsed. | Active streaks divided by all members, shown as "% streak health". |
| **Kudos Given** | Kudos sent between members. | Last 30 days |

Members who have since been deleted are left out of the active member, badge earner and streak counts, so the percentages stay honest.

## Top Members

**Top members - last 30 days** lists the six members who earned the most points. Each row shows the rank, avatar, name and points. Click **View all** to open **Analytics** for the full picture. See [Analytics](../features/190-analytics.md).

Before anyone earns points, the card says "No earnings yet - points will appear here as members engage."

## Top Actions

**Top actions - last 30 days** lists the six actions that paid out the most points. Each row shows the action name, how many times it happened ("12 events") and the points it paid.

Use it to spot an action that pays too much or one nobody uses. Click **Configure** to change the values on the Points screen. See [Points & Actions](00-points-actions.md).

Before anything happens, the card says "No actions logged yet."

## Recent Kudos

**Recent kudos** shows the six latest kudos: who gave, who received, the message and how long ago. The card appears only once someone has given kudos. Click **Manage kudos** to open the kudos settings. See [Kudos Settings](40-kudos-settings.md).

## Quick Links

Three buttons sit under the number cards.

| Button | Where it goes |
|---|---|
| **Full Analytics** | **WB Gamification > Analytics** |
| **Award Points** | **WB Gamification > Award Points**. See [Manual Point Awards](60-manual-awards.md). |
| **Configure Points** | The Points section of this same screen |

## The Menu on the Left

The Dashboard is the first item in the left menu of the main screen. The same menu holds **Points**, **Levels**, **Challenges**, **Badges**, **Kudos**, **Cohort Leagues**, **Rules**, **Emails**, **Access**, **Realtime**, **Integrations**, **Modules**, **Habits & Profiles**, **Appearance**, **Tools** and **API Keys**. **Levels**, **Challenges**, **Badges** and **API Keys** open their own screens.

## Tips

- The numbers look low or empty? Wait 10 minutes, then reload. The totals are cached.
- Active Members only counts members who earned points, so a quiet month lowers it even if nobody left.
- The **Challenges** item in the left menu hides when you switch the Challenges module off. See [Modules](100-modules.md).
