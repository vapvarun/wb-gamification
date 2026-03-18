# Quick Start Guide

From a fresh install to a working gamification setup in five steps.

## Step 1: Review point actions

Go to **Gamification → Settings → Points**. Every trackable action is listed with its current point value and an enable/disable toggle.

Common defaults:

| Action | Points |
|---|---|
| Publish a post | 10 |
| Leave a comment | 5 |
| Receive kudos | 3 |
| Daily login | 1 |
| Complete a profile | 20 |

Disable any actions your site doesn't use — WooCommerce purchase actions on a blog-only site, for example. Enable anything turned off that's relevant.

## Step 2: Set your level thresholds

Go to **Settings → Levels**. The default ladder has 10 levels from Newcomer (0 pts) to Legend (10,000 pts).

Adjust the point thresholds to match your community's expected pace. A very active community may need higher thresholds to keep levelling meaningful.

## Step 3: Add a leaderboard to a page

1. Create or edit any page.
2. Add the **Gamification Leaderboard** block from the block inserter.
3. Publish.

The leaderboard shows all-time top members by default. To show this week's leaders, set **Period** to `week` in the block settings panel.

## Step 4: Check member profiles

If BuddyPress is active, members' current rank, points, and earned badges automatically appear on their profile pages — no configuration needed.

Without BuddyPress, add the **Level Progress** or **Badge Showcase** block to any page. Set `user_id` to `0` to show the current logged-in member.

## Step 5: Award your first badge manually

Go to **Gamification → Manual Award**. Select a member, pick a badge, and click **Award**. The member receives a notification immediately.

---

That's it. Points now accumulate as members take actions on your site, badges unlock automatically when criteria are met, and the leaderboard updates in real time. You don't need to configure anything further to have a working system — the defaults cover most WordPress and BuddyPress sites out of the box.
