# Frequently Asked Questions

## Does WB Gamification need BuddyPress?

No. WB Gamification works on any WordPress site. BuddyPress adds social triggers (activity updates, friendships, groups) and profile integration, but the core points, badges, and levels system works with standard WordPress actions such as registration, publishing posts, and leaving comments.

## How do members see their gamification status?

Three ways:

1. **Automatically on BuddyPress profiles** — level name, points, and a progress bar appear in the profile header when BuddyPress is active.
2. **Gutenberg blocks or shortcodes** placed on any page (see [Shortcodes & Blocks](../shortcodes-blocks/00-blocks-overview.md)).
3. **Real-time toast notifications** (small pop-ups) when a member earns points or a badge.

## Can I change point values?

Yes. Every action has a configurable point value under **WB Gamification > Points**. You can also enable or disable individual actions and, where the action allows it, set daily caps and cooldowns. The setup wizard pre-configures sensible defaults based on your site type.

## Will WB Gamification slow down my site?

No. The engine uses an async award pipeline (via Action Scheduler), object caching on the hot read paths, and a cached leaderboard snapshot. On sites with more than about 10,000 active members, a persistent object cache (Redis or Memcached) is required so the cache survives across requests.

## Can other plugins award points?

Yes. Any plugin can drop a file named `wb-gamification.php` in its own folder that returns an array of trigger definitions. WB Gamification auto-discovers it at boot — no registration call needed. See [Manifest Files](../developer-guide/30-manifest-files.md) for the format.

## How many actions can award points?

It depends on which plugins you run, so there is no single number. A vanilla WordPress site exposes 8 triggers. Adding WooCommerce, an LMS, and BuddyPress brings it to roughly 50. Every action your own site exposes is listed under **WB Gamification > Points** — that list is the only count that matters for you.

## Is WB Gamification GDPR compliant?

Yes. It integrates with the standard WordPress privacy tools. Members can request a data export (points, badges, levels, streaks, kudos) and data erasure through the built-in WordPress privacy request system, and they can opt out of the leaderboard and hide their rank.

## What happens if I deactivate or delete the plugin?

Deactivating preserves all data in the database — reactivating restores everything. Deleting the plugin from the Plugins screen runs `uninstall.php`, which removes all of the plugin's custom tables, options, cron jobs, and transients for a clean uninstall.
