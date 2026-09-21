# Points

Points are the core currency of WB Gamification. Every action a member takes that the plugin is configured to track results in points being added to their permanent point total.

## What Points Are

A member's point total is the sum of every point award they have ever received. Points never expire and never decrease (unless you manually adjust them). The total drives badge unlocks, level progression, leaderboard ranking, and challenge completion.

## How Members Earn Points

Points are awarded automatically. The moment a member completes a tracked action, points are added to their account. No member needs to claim points or do anything special to receive them.

The plugin tracks actions from your active plugins automatically. Below are the default point values by category.

### WordPress Actions

| Action | Default Points | Repeatable |
|---|---|---|
| Join the site (register) | 15 | No |
| First login | 10 | No |
| Complete WordPress profile (add bio) | 10 | No |
| Post receives a comment | 3 | Yes |
| Publish a blog post | 25 | Yes |
| Publish first post ever | 20 | No |
| Leave a comment | 5 | Yes |
| Comment approved from moderation | 5 | Yes |

Note: The publish and comment actions in the WordPress category are only active when BuddyPress is **not** installed. When BuddyPress is active, the BuddyPress manifest covers those same actions.

### BuddyPress Actions

| Action | Default Points | Repeatable |
|---|---|---|
| Post an activity update | 10 | Yes |
| Comment on an activity | 5 | Yes |
| Accept a friendship | 8 | Yes |
| Join a group | 8 | Yes |
| Create a group | 20 | Yes |
| Complete extended profile | 15 | No |
| Receive a reaction | 3 | Yes |
| Create a poll | 10 | Yes |
| Publish a member blog post | 25 | Yes |
| Upload media | 5 | Yes |

### WooCommerce Actions

| Action | Default Points | Repeatable |
|---|---|---|
| Complete a purchase | 25 | Yes |
| Complete first purchase ever | 50 | No |
| Leave a product review | 15 | Yes |
| Add a product to wishlist (YITH) | 5 | Yes |

### LearnDash Actions

| Action | Default Points | Repeatable |
|---|---|---|
| Complete a course | 100 | Yes |
| Complete a lesson | 15 | Yes |
| Complete a topic | 5 | Yes |
| Pass a quiz | 25 | Yes |
| Assignment approved by instructor | 20 | Yes |

Additional integrations are available for bbPress, LifterLMS, MemberPress, GiveWP, and The Events Calendar.

## Changing Point Values

Go to **Gamification > Settings** and find the Points section. Every active action is listed with its current point value. Click the value field, enter a new number, and save.

Setting a value to 0 effectively disables point awards for that action without disabling the action itself (badge and challenge tracking still fires).

## Where Members See Their Points

Members can see their points in several places:

- **BuddyPress profile** — the Gamification tab shows total points, current level, and recent activity (requires BuddyPress)
- **Member Points block** — place this Gutenberg block on any page; it shows the logged-in member's total, level name, and progress bar toward the next level
- **Leaderboard** — members can see their rank relative to others

## Viewing the Earning Guide

The Earning Guide block and shortcode (`[wb_gam_earning_guide]`) displays a formatted list of every active point-earning action with its point value. Add this to a "How to earn points" page to help members understand what actions are worth taking.

## Manual Point Awards

Admins can award or adjust points manually. Go to **Gamification > Manual Award**, select a member, enter a point amount and reason, and click **Award Points**. The award is logged in the member's point history with the reason you entered.

Manual awards show up in point history the same way as automatic awards.

## Points History

Every point transaction is permanently logged. Members can see their full earning history in the Points History block (`[wb_gam_points_history]`), which shows the action, points earned, and date for each transaction.
