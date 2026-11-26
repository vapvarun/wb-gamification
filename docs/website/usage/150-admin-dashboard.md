# Admin Dashboard

Go to **Gamification > Overview > Dashboard** in your admin menu (`admin.php?page=wb-gamification`).

The Dashboard answers two questions: is gamification working, and what should you do next? It shows the last 30 days at a glance, and every card links to the screen where you change what you see.

Only administrators open this screen. To give a moderator part of the job, see [Staff Permissions](140-staff-permissions.md).

## Two Buttons at the Top

| Button | What it does |
|---|---|
| **Award points** | Opens **Members > Award points**. See [Manual Point Awards](60-manual-awards.md). |
| **View as a member** | Opens the Gamification page on your site in a new tab, so you see what members see. |

## Finish Setting Up

A short checklist sits at the top until every step is done, for example "Finish setting up, 2 of 4". It checks your site by itself, so you never tick anything, and it disappears when the last step is done. **Dismiss** hides it for your account.

| Step | Done when | Button |
|---|---|---|
| **Choose your plugins and rewards** | You finished Setup. | **Open setup** |
| **See a toast as a member** | Your own account has earned points once. | **Send me a test toast** gives you 10 points; open the site to see the toast. |
| **Add the Gamification page to your menu** | The page is in one of your site menus (classic menus or the block theme's Navigation). | **Open menus** |
| **Create a first challenge** | At least one challenge exists. Hidden when the Challenges module is off. | **Start one** |

## The Four Numbers

All four cover the last 30 days. They are cached for 10 minutes, so a brand-new award can take a few minutes to show.

| Number | What it counts |
|---|---|
| **Points awarded, 30 days** | All points given, by members taking part and by hand. |
| **Members earning** | Members who earned points, "of N members" on the site. |
| **Badges earned** | Badges awarded. |
| **Challenges completed** | Challenges finished. When it is 0, **Create your first challenge** takes you there. Hidden when the Challenges module is off. |

## Needs a Look

When 30% or more of the month's points were awarded by hand, a note says so, for example "45% of this month's points were awarded by hand". After a migration or a one-off prize there is nothing to do. **See manual awards** opens **Award points**.

## Top Actions and Top Members

**Top actions, 30 days** lists the five actions that paid the most points. Points you awarded by hand are shown on their own line, **Awarded by hand**, so they never hide what members earn by taking part. **Earning** opens the action settings.

**Top members, 30 days** lists the five members who earned the most, with rank, avatar, name and points. **Open the leaderboard** opens the Gamification page.

For the full picture, open **Overview > Analytics**. See [Analytics](../features/190-analytics.md).

## Tips

- The numbers look low or empty? Wait 10 minutes, then reload. The totals are cached.
- **Members earning** only counts members who earned points, so a quiet month lowers it even if nobody left.
- Run setup again any time from **Tools > Setup**. It opens with your current values.
