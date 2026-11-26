# Installation

## Requirements

Before installing WB Gamification, confirm your environment meets these minimums:

- WordPress 6.5 or higher
- PHP 8.1 or higher
- MySQL 8.0 or higher, or MariaDB 10.4 or higher - the leaderboard uses SQL window functions (`RANK() OVER`), so older database versions are not supported

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
- Seeds your site with **5 default levels** (Newcomer, Member, Contributor, Regular, Champion) and **37 default badges**.
- Registers all gamification actions appropriate for your active plugins. If BuddyPress is active, BuddyPress triggers load automatically.
- Opens **Setup** on your next admin page load, so you can choose which plugins reward members and how generously.

You do not need to configure anything manually. The plugin detects your active plugins and loads the right point-earning actions automatically.

## After Activation

Setup opens. It lists the plugins on your site that have gamification actions and walks you through four short steps, about two minutes. Click **Launch gamification** at the end and members start earning from their next action. See [Setup](20-setup-wizard.md).

If you click **Skip setup, I'll configure it myself**, nothing is saved and members earn with each plugin's own default values. You can adjust them any time under **Gamification > Earning > Actions and points**, or run setup again from **Gamification > Tools > Setup**.

## Languages

WB Gamification includes German, French, Spanish, Italian and Brazilian Portuguese translations. Set **Settings > General > Site Language** to one of them and the admin screens and blocks follow.

## Updates and Licence

Licence and update checks go to the Wbcom update server, and fall back to wbcomdesigns.com automatically if it cannot be reached. Before WordPress installs an update, the plugin checks the package against its release signature. The check only warns: the result is logged and the update still installs.

## Multisite

WB Gamification can be activated per-site on a WordPress multisite network. Network-wide activation is not recommended because each site maintains its own gamification data and settings independently.
