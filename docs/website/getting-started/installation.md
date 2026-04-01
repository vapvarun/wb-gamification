# Installation

## Requirements

Before installing WB Gamification, confirm your environment meets these minimums:

- WordPress 6.4 or higher
- PHP 8.1 or higher
- MySQL 5.7 or MariaDB 10.3 or higher

BuddyPress is **optional**. The plugin works on any standard WordPress site and automatically activates BuddyPress-specific features when BuddyPress is detected.

## Installing the Plugin

**From the WordPress admin:**

1. Go to **Plugins > Add New Plugin**.
2. Search for **WB Gamification**.
3. Click **Install Now**, then **Activate**.

**Manual upload:**

1. Download the plugin ZIP file.
2. Go to **Plugins > Add New Plugin > Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

## What Happens on Activation

When you activate WB Gamification, the plugin does the following automatically:

- Creates **20 custom database tables** to store events, points, badges, levels, challenges, streaks, kudos, leaderboard snapshots, member preferences, and more.
- Seeds your site with **5 default levels** (Newcomer, Member, Contributor, Regular, Champion) and **30 default badges**.
- Registers all gamification actions appropriate for your active plugins. If BuddyPress is active, BuddyPress triggers load automatically.
- Redirects you to the **Setup Wizard** so you can choose a starter template.

You do not need to configure anything manually. The plugin detects your active plugins and loads the right point-earning actions automatically.

## After Activation

You are redirected to the Setup Wizard. Choose a starter template that matches your site type — this takes about one minute. After completing the wizard, your site is fully configured and ready for members to start earning points.

If you skip the wizard, default point values are used. You can always return to **Gamification > Settings** to adjust them.

## Multisite

WB Gamification can be activated per-site on a WordPress multisite network. Network-wide activation is not recommended because each site maintains its own gamification data and settings independently.
