# WB Gamification

Points, badges, levels, leaderboards, challenges, streaks and kudos for WordPress and BuddyPress, with a setup wizard that gets members earning on day one.

## What it does

**Rewarding members**

- Points awarded automatically from activity on your site, with per-action cooldown, daily cap and weekly cap.
- More than one currency (point types), with conversions between them, manual awards with a reason, and points expiry.
- Badges with auto-award conditions, a badge editor, OpenBadges 3.0 credentials and public badge share pages.
- Levels with thresholds you set, and daily streaks with milestones at 7, 14, 30, 60, 100, 180 and 365 days.
- Peer kudos, member submissions that you review, and a redemption store where members spend points on rewards.

**Competition**

- Leaderboards for all-time, month, week and day, backed by a cached snapshot so they stay fast on large sites.
- Cohort leagues, individual challenges and community challenges with shared progress.

**Where members see it**

- 19 Gutenberg blocks and 17 shortcodes (full lists in [For developers](#for-developers)).
- An "Achievements" tab on BuddyPress profiles, an "Achievements" endpoint in WooCommerce My Account, and a LearnDash profile section.
- Public member profiles at `/{slug}/{user}`, toast notifications (WP Heartbeat by default, server-sent events as an option), and six email templates including a weekly recap.

**Administration**

- Setup wizard with starter templates, an analytics dashboard, a manual award screen, kudos moderation and a members screen.
- API keys, HMAC-signed outbound webhooks (for Zapier, Make, n8n and similar), and an importer.
- GDPR export and erasure through the WordPress privacy tools.

**Integrations** (auto-detected, no configuration)

WordPress core, BuddyPress, BuddyNext, bbPress, WooCommerce, LearnDash, LifterLMS, MemberPress, GiveWP, The Events Calendar, Eventonomy, Jetonomy, Learnomy, Listora, WP Career Board, WP Sell Services and WPMediaVerse, including the Pro editions where a manifest ships. Two platform integrations load when their plugin is active: ActivityPub and WPGraphQL.

## Who it is for

Site owners who run a community, course site, store or membership site and want members to earn recognition for taking part, without writing code. Developers can register their own triggers, badges and integrations through hooks and manifest files.

## Where to get it

- Download: https://wbcomdesigns.com/downloads/wordpress-gamification-plugin/ (see this page for current pricing)
- Source and issues: https://github.com/vapvarun/wb-gamification
- Not listed on WordPress.org.

## Documentation

- Documentation hub: https://docs.wbcomdesigns.com/products/wb-gamification/
- Release notes: https://wbcomdesigns.com/release-notes/wb-gamification/

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later
- MySQL 8.0 or later (the leaderboard snapshot uses window functions)
- BuddyPress, WooCommerce, LearnDash and the other integrations are optional.

## Installation

1. Download the plugin zip from the download page above.
2. In WordPress go to **Plugins > Add New > Upload Plugin**, upload the zip and activate it.
3. Open **Gamification > Setup** (`admin.php?page=wb-gamification-setup`) and pick a starter template, or skip it and configure points manually.
4. Add blocks or shortcodes to your pages, for example `[wb_gam_leaderboard]`, or use the **Hub** block for a single member page.
5. Optional: run `wp wb-gamification doctor --verbose` to check the install.

## For developers

**Identifiers.** PHP namespace `WBGam`, function, hook and option prefix `wb_gam_`, text domain `wb-gamification`, custom admin menu slug `wb-gamification`.

**Blocks (19).** `badge-showcase`, `challenges`, `cohort-rank`, `community-challenges`, `daily-bonus`, `earning-guide`, `give-kudos`, `hub`, `kudos-feed`, `leaderboard`, `level-progress`, `member-points`, `points-history`, `redemption-store`, `streak`, `submit-achievement`, `top-members`, `user-status-bar`, `year-recap`. Each lives in `src/Blocks/<name>/` with a `block.json`.

**Shortcodes (17).** `wb_gam_leaderboard`, `wb_gam_member_points`, `wb_gam_badge_showcase`, `wb_gam_level_progress`, `wb_gam_challenges`, `wb_gam_streak`, `wb_gam_top_members`, `wb_gam_kudos_feed`, `wb_gam_give_kudos`, `wb_gam_year_recap`, `wb_gam_points_history`, `wb_gam_earning_guide`, `wb_gam_hub`, `wb_gam_community_challenges`, `wb_gam_cohort_rank`, `wb_gam_redemption_store`, `wb_gam_my_rewards`.

**REST API.** Namespace `wb-gamification/v1`, with controllers in `src/API/` for members, points, badges, levels, leaderboard, challenges, kudos, redemption, webhooks, API keys and more. An OpenAPI description is served at `/wp-json/wb-gamification/v1/openapi.json`, and a TypeScript client lives in `sdk/`. Reference: https://docs.wbcomdesigns.com/products/wb-gamification/developer-guide/140-rest-overview/

**Hooks.** The plugin fires `wb_gam_*` actions and filters throughout. Full list: https://docs.wbcomdesigns.com/products/wb-gamification/developer-guide/110-hooks-overview/

```php
// Points awarded.
add_action( 'wb_gam_points_awarded', function ( $user_id, $event, $points ) {
	// $event->action_id, $event->metadata, and so on.
}, 10, 3 );

// Badge earned. $def is the badge definition array.
add_action( 'wb_gam_badge_awarded', function ( $user_id, $def, $badge_id ) {
	// Send a notification, log to a CRM, and so on.
}, 10, 3 );

// Level changed.
add_action( 'wb_gam_level_changed', function ( $user_id, $new_level, $old_level ) {
	// Unlock content, assign a role, and so on.
}, 10, 3 );

// Change points before they are awarded.
add_filter( 'wb_gam_points_for_action', function ( $points, $action_id, $user_id, $event ) {
	return in_array( gmdate( 'l' ), array( 'Saturday', 'Sunday' ), true ) ? $points * 2 : $points;
}, 10, 4 );
```

**Helper functions** (`src/Extensions/functions.php`): `wb_gam_register_action()`, `wb_gam_get_user_points()`, `wb_gam_has_badge()`, `wb_gam_get_user_streak()`, `wb_gam_get_leaderboard( $period, $limit )`. Guide: https://docs.wbcomdesigns.com/products/wb-gamification/developer-guide/40-helper-functions/

**Integration manifests.** Drop a PHP file that returns a `plugin` name, a `version` and a list of `triggers` into the plugin's `integrations/` folder (bundled manifests also live in `integrations/contrib/`). Guide: https://docs.wbcomdesigns.com/products/wb-gamification/developer-guide/30-manifest-files/

```php
<?php
return array(
	'plugin'   => 'My Plugin',
	'version'  => '1.0.0',
	'triggers' => array(
		array(
			'id'             => 'my_custom_action',
			'label'          => 'Did something awesome',
			'hook'           => 'my_plugin_action_fired',
			'user_callback'  => fn( int $user_id ) => $user_id,
			'default_points' => 10,
			'category'       => 'custom',
			'repeatable'     => true,
		),
	),
);
```

**WP-CLI.** Commands under `wp wb-gamification`: `points`, `member`, `actions`, `logs`, `export`, `doctor`, `replay`, `qa`, `scale`, `share`, `openapi`, `import`, `import-status`, `import-undo` and `email-test`. Reference: https://docs.wbcomdesigns.com/products/wb-gamification/developer-guide/100-wp-cli/

```bash
wp wb-gamification doctor --verbose
wp wb-gamification points award --user=42 --points=100
wp wb-gamification member status --user=42
wp wb-gamification actions list --format=table
```

**Template overrides.** Email templates and plugin partials resolve to `{child-theme}/wb-gamification/...`, then `{parent-theme}/wb-gamification/...`, then the plugin's `templates/` folder. The `wb_gam_template_path` filter overrides the path in code. Block `render.php` files are not theme-overridable; use the `wb_gam_block_<slug>_data` filter instead.

**Build and test.** Requires Node 20+ and Composer. The `build/` folder is not committed, so run `npm install && npm run build` after cloning or no blocks register. The release zip already contains the build.

```bash
npm install && composer install
npm run build          # compile blocks
npm run build:release  # blocks, RTL and minified assets
npm run build:pot      # regenerate the translation template
composer test          # PHPUnit
composer phpcs         # WordPress Coding Standards
composer phpstan       # static analysis
```

## Support

Questions and bug reports: https://wbcomdesigns.com/support/

## License

GPL-2.0 or later. See https://www.gnu.org/licenses/gpl-2.0.html.
