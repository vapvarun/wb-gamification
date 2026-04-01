# WB Gamification — 100K Scalability & Cleanup Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make wb-gamification stable, clean, and performant for communities up to 100,000 active members.

**Architecture:** Lazy-load non-core engines behind feature flags, move non-critical award listeners to async batch processing, implement leaderboard snapshot caching, add object cache to all hot-path queries, remove dead code, consolidate cron jobs.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, Action Scheduler, WordPress Object Cache API

---

## Table of Contents

| # | Task | Impact | Files |
|---|------|--------|-------|
| 1 | Feature Flag System + Lazy Loading | Boot time: 22 engines -> 13 core | `wb-gamification.php`, `src/Engine/FeatureFlags.php` (new) |
| 2 | Dead Code Removal | Less code, less surface area | `src/Engine/MissionMode.php`, `src/Engine/Installer.php`, `src/Abilities/AbilitiesRegistrar.php` |
| 3 | Conditional Asset Loading | Frontend perf: CSS/JS only where used | `wb-gamification.php`, `src/Engine/ShortcodeHandler.php` |
| 4 | Async Award Pipeline | Per-award queries: 12-15 -> 4-5 | `src/Engine/AsyncEvaluator.php` (new), multiple engines |
| 5 | Leaderboard Snapshot Cache | Leaderboard query: O(n) -> O(1) | `src/Engine/LeaderboardEngine.php` |
| 6 | Hot-Path Query Caching | ~6 DB queries eliminated per award | `BadgeEngine`, `RuleEngine`, `LevelEngine`, `Engine`, `TenureBadgeEngine` |
| 7 | Events Table Pruning | Prevent unbounded table growth | `src/Engine/LogPruner.php`, `src/CLI/LogsCommand.php` |
| 8 | Cron Consolidation | 8 crons -> 4, spread across week | `wb-gamification.php`, `src/Engine/CronDispatcher.php` (new) |
| 9 | PersonalRecordEngine -- Single Query | 3 queries -> 1 | `src/Engine/PersonalRecordEngine.php` |
| 10 | Complete Public API | Developer UX | `src/Extensions/functions.php` |
| 11 | Action ID Collision Guard | Plugin ecosystem safety | `src/Engine/Registry.php` |
| 12 | RedemptionEngine Race Condition Fix | Data integrity | `src/Engine/RedemptionEngine.php` |
| 13 | Settings Page -- Feature Toggles Tab | Admin UX for feature flags | `src/Admin/SettingsPage.php` |

---

## Task 1: Feature Flag System + Lazy Loading

### Problem

All 22 engines boot at `plugins_loaded` regardless of whether the site admin needs them. On a 100K-member site this means 22 class initializations, hook registrations, and cron schedule checks on **every single request** -- including REST API calls and WP-Cron runs that only need the core pipeline.

### Solution

Create a `FeatureFlags` class that manages which optional engines are active. Core engines always load. Optional engines load only when their feature flag is enabled.

**Core engines (always load):** Engine, Registry, ManifestLoader, PointsEngine, RuleEngine, BadgeEngine, LevelEngine, StreakEngine, Privacy, NotificationBridge, WebhookDispatcher, LogPruner, ShortcodeHandler

**Optional engines (lazy-load behind flags):** CohortEngine, RecapEngine, WeeklyEmailEngine, LeaderboardNudge, StatusRetentionEngine, CosmeticEngine, PersonalRecordEngine, TenureBadgeEngine, SiteFirstBadgeEngine, CommunityChallengeEngine, RedemptionEngine, KudosEngine, BadgeSharePage, RankAutomation, CredentialExpiryEngine

### Files

#### CREATE: `src/Engine/FeatureFlags.php`

```php
<?php
/**
 * WB Gamification Feature Flags
 *
 * Manages which optional engines are active. Stores all flags in a single
 * option `wb_gam_features` to avoid N get_option() calls.
 *
 * @package WB_Gamification
 * @since   0.6.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Manages which optional engines are active via a single option.
 *
 * @package WB_Gamification
 */
final class FeatureFlags {

	/**
	 * Default feature flag values.
	 *
	 * @var array<string, bool>
	 */
	private const DEFAULTS = array(
		'cohort_leagues'       => false,
		'weekly_emails'        => false,
		'leaderboard_nudge'    => false,
		'status_retention'     => false,
		'cosmetics'            => false,
		'personal_records'     => true,
		'tenure_badges'        => true,
		'site_first_badges'    => true,
		'community_challenges' => true,
		'redemption_store'     => false,
		'kudos'                => true,
		'badge_share'          => true,
		'rank_automation'      => false,
		'recap'                => false,
		'credential_expiry'    => true,
	);

	/**
	 * Maps feature flag keys to engine classes and their boot methods.
	 *
	 * @var array<string, array{class: string, method: string, priority: int}>
	 */
	private const ENGINE_MAP = array(
		'cohort_leagues'       => array( 'class' => CohortEngine::class,            'method' => 'init', 'priority' => 10 ),
		'weekly_emails'        => array( 'class' => WeeklyEmailEngine::class,       'method' => 'init', 'priority' => 10 ),
		'leaderboard_nudge'    => array( 'class' => LeaderboardNudge::class,        'method' => 'init', 'priority' => 10 ),
		'status_retention'     => array( 'class' => StatusRetentionEngine::class,   'method' => 'init', 'priority' => 10 ),
		'cosmetics'            => array( 'class' => CosmeticEngine::class,          'method' => 'init', 'priority' => 10 ),
		'personal_records'     => array( 'class' => PersonalRecordEngine::class,    'method' => 'init', 'priority' => 10 ),
		'tenure_badges'        => array( 'class' => TenureBadgeEngine::class,       'method' => 'init', 'priority' => 10 ),
		'site_first_badges'    => array( 'class' => SiteFirstBadgeEngine::class,    'method' => 'init', 'priority' => 20 ),
		'community_challenges' => array( 'class' => CommunityChallengeEngine::class,'method' => 'init', 'priority' => 10 ),
		'redemption_store'     => array( 'class' => RedemptionEngine::class,        'method' => 'init', 'priority' => 10 ),
		'kudos'                => array( 'class' => KudosEngine::class,             'method' => 'init', 'priority' => 10 ),
		'badge_share'          => array( 'class' => BadgeSharePage::class,          'method' => 'init', 'priority' => 10 ),
		'rank_automation'      => array( 'class' => RankAutomation::class,          'method' => 'init', 'priority' => 10 ),
		'recap'                => array( 'class' => RecapEngine::class,             'method' => 'init', 'priority' => 10 ),
		'credential_expiry'    => array( 'class' => CredentialExpiryEngine::class,  'method' => 'init', 'priority' => 10 ),
	);

	/**
	 * Cached flags for the current request.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $cache = null;

	/**
	 * Get all feature flags with their current values.
	 *
	 * @return array<string, bool>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored     = get_option( 'wb_gam_features', array() );
		$stored     = is_array( $stored ) ? $stored : array();
		self::$cache = array_merge( self::DEFAULTS, $stored );

		return self::$cache;
	}

	/**
	 * Check whether a specific feature is enabled.
	 *
	 * @param string $feature Feature flag key.
	 * @return bool
	 */
	public static function is_enabled( string $feature ): bool {
		$flags = self::all();
		return ! empty( $flags[ $feature ] );
	}

	/**
	 * Update one or more feature flags.
	 *
	 * @param array<string, bool> $flags Key-value pairs to update.
	 */
	public static function update( array $flags ): void {
		$current = self::all();
		$merged  = array_merge( $current, array_intersect_key( $flags, self::DEFAULTS ) );

		update_option( 'wb_gam_features', $merged );
		self::$cache = $merged;
	}

	/**
	 * Get the default flag values.
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Get the engine map (flag key -> class info).
	 *
	 * @return array<string, array{class: string, method: string, priority: int}>
	 */
	public static function engine_map(): array {
		return self::ENGINE_MAP;
	}

	/**
	 * Boot all enabled optional engines.
	 *
	 * Called once from wb-gamification.php at plugins_loaded priority 9
	 * (after Registry at 6, before NotificationBridge at 12).
	 */
	public static function boot_engines(): void {
		$flags = self::all();

		foreach ( self::ENGINE_MAP as $flag => $config ) {
			if ( empty( $flags[ $flag ] ) ) {
				continue;
			}

			if ( class_exists( $config['class'] ) && method_exists( $config['class'], $config['method'] ) ) {
				call_user_func( array( $config['class'], $config['method'] ) );
			}
		}
	}

	/**
	 * Flush the in-memory cache (call after saving settings).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Get human-readable labels for all features (for admin UI).
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'cohort_leagues'       => __( 'Cohort Leagues (Duolingo-style weekly leagues)', 'wb-gamification' ),
			'weekly_emails'        => __( 'Weekly Summary Emails', 'wb-gamification' ),
			'leaderboard_nudge'    => __( 'Weekly Leaderboard Nudge Notifications', 'wb-gamification' ),
			'status_retention'     => __( 'Status Retention Nudges (airline model)', 'wb-gamification' ),
			'cosmetics'            => __( 'Profile Cosmetics & Frames', 'wb-gamification' ),
			'personal_records'     => __( 'Personal Record Detection (Strava model)', 'wb-gamification' ),
			'tenure_badges'        => __( 'Tenure / Anniversary Badges', 'wb-gamification' ),
			'site_first_badges'    => __( 'Site-First Badges (first-ever achievements)', 'wb-gamification' ),
			'community_challenges' => __( 'Community Challenges (site-wide goals)', 'wb-gamification' ),
			'redemption_store'     => __( 'Points Redemption Store', 'wb-gamification' ),
			'kudos'                => __( 'Peer Kudos', 'wb-gamification' ),
			'badge_share'          => __( 'Public Badge Share Pages', 'wb-gamification' ),
			'rank_automation'      => __( 'Automatic Rank Assignment Rules', 'wb-gamification' ),
			'recap'                => __( 'Year-in-Review Recap', 'wb-gamification' ),
			'credential_expiry'    => __( 'Badge/Credential Expiry Tracking', 'wb-gamification' ),
		);
	}
}
```

#### MODIFY: `wb-gamification.php`

Replace the block of 15 optional `add_action( 'plugins_loaded', ... )` calls with a single `FeatureFlags::boot_engines()` call.

**Remove these lines (118-135):**

```php
// REMOVE all of these:
add_action( 'plugins_loaded', array( ChallengeEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( LeaderboardNudge::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( AbilitiesRegistrar::class, 'register' ), 10 );
add_action( 'plugins_loaded', array( RankAutomation::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( PersonalRecordEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( TenureBadgeEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( WeeklyEmailEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( CommunityChallengeEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( SiteFirstBadgeEngine::class, 'init' ), 20 );
add_action( 'plugins_loaded', array( CohortEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( StatusRetentionEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( CosmeticEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( CredentialExpiryEngine::class, 'init' ), 10 );
add_action( 'plugins_loaded', array( BadgeSharePage::class, 'init' ), 10 );
```

**Add this single replacement (at the same location):**

```php
// Boot optional engines based on feature flags (single option read).
add_action( 'plugins_loaded', array( \WBGam\Engine\FeatureFlags::class, 'boot_engines' ), 9 );

// ChallengeEngine is core (always loads) — individual challenges are a fundamental mechanic.
add_action( 'plugins_loaded', array( ChallengeEngine::class, 'init' ), 10 );
```

**Also add the use statement at the top of the file (after line 81):**

```php
use WBGam\Engine\FeatureFlags;
```

**Update activation hook (lines 239-252) to be feature-flag aware:**

Replace:

```php
register_activation_hook(
	__FILE__,
	function () {
		Installer::install();
		set_transient( 'wb_gam_do_redirect', true, 30 );
		LogPruner::activate();
		LeaderboardNudge::activate();
		TenureBadgeEngine::activate();
		WeeklyEmailEngine::activate();
		CohortEngine::activate();
		StatusRetentionEngine::activate();
		CredentialExpiryEngine::activate();
		BadgeSharePage::activate();
	}
);
```

With:

```php
register_activation_hook(
	__FILE__,
	function () {
		Installer::install();
		set_transient( 'wb_gam_do_redirect', true, 30 );
		LogPruner::activate();

		// Only schedule crons for enabled features.
		if ( FeatureFlags::is_enabled( 'leaderboard_nudge' ) ) {
			LeaderboardNudge::activate();
		}
		if ( FeatureFlags::is_enabled( 'tenure_badges' ) ) {
			TenureBadgeEngine::activate();
		}
		if ( FeatureFlags::is_enabled( 'weekly_emails' ) ) {
			WeeklyEmailEngine::activate();
		}
		if ( FeatureFlags::is_enabled( 'cohort_leagues' ) ) {
			CohortEngine::activate();
		}
		if ( FeatureFlags::is_enabled( 'status_retention' ) ) {
			StatusRetentionEngine::activate();
		}
		if ( FeatureFlags::is_enabled( 'credential_expiry' ) ) {
			CredentialExpiryEngine::activate();
		}
		if ( FeatureFlags::is_enabled( 'badge_share' ) ) {
			BadgeSharePage::activate();
		}
	}
);
```

**Update deactivation hook similarly (lines 255-268)** -- deactivation should always clear all crons regardless of flag state (defensive cleanup).

### Test

```bash
# Verify only core engines boot with all flags disabled
wp option update wb_gam_features '{"cohort_leagues":false,"weekly_emails":false,"leaderboard_nudge":false,"status_retention":false,"cosmetics":false,"personal_records":false,"tenure_badges":false,"site_first_badges":false,"community_challenges":false,"redemption_store":false,"kudos":false,"badge_share":false,"rank_automation":false,"recap":false,"credential_expiry":false}' --format=json

# Award points — should still work (core pipeline intact)
wp wb-gamification points award --user=1 --points=10 --message="Test"

# Verify feature flag read
wp eval "var_dump( WBGam\Engine\FeatureFlags::is_enabled('kudos') );"
```

### Git Commit

```
feat: add feature flag system for optional engine lazy-loading

Reduces boot-time overhead by only loading optional engines when their
feature flag is enabled. Core engines (points, badges, levels, streaks)
always load. Optional engines read from a single `wb_gam_features`
option instead of 15 individual add_action calls.
```

---

## Task 2: Dead Code Removal

### Problem

Three pieces of dead code add complexity for zero value:

1. `wb_gam_partners` table -- accountability partner feature that was never built beyond the schema.
2. `MissionMode.php` -- renames UI labels, adds complexity. No block or template actually calls `MissionMode::term()`. Zero references in rendering code.
3. `AbilitiesRegistrar.php` -- WordPress Abilities API (`wp_register_ability`) does not exist in any released WordPress version as of WP 6.8. The class is a no-op (it returns early when `function_exists('wp_register_ability')` is false).

### Files

#### REMOVE: `src/Engine/MissionMode.php`

Delete the file entirely.

```bash
rm src/Engine/MissionMode.php
```

**Search for any references to `MissionMode` across the codebase:**

```bash
grep -rn "MissionMode" --include="*.php" .
```

If there are references in `SettingsPage.php` (e.g., a dropdown for mission mode selection), remove those references too. The `wb_gam_mission_mode` option can remain in the DB harmlessly -- no migration needed.

#### REMOVE: `src/Abilities/AbilitiesRegistrar.php`

Delete the file entirely.

```bash
rm src/Abilities/AbilitiesRegistrar.php
```

**Remove the use statement and `add_action` from `wb-gamification.php`:**

Remove line 61:
```php
use WBGam\Abilities\AbilitiesRegistrar;
```

Remove line 122 (if it survived Task 1; it may already be gone):
```php
add_action( 'plugins_loaded', array( AbilitiesRegistrar::class, 'register' ), 10 );
```

If the `src/Abilities/` directory is now empty, delete it:
```bash
rmdir src/Abilities/
```

#### MODIFY: `src/Engine/Installer.php`

**Do NOT remove the `wb_gam_partners` CREATE TABLE statement yet.** Existing installs already have this table. Instead, add a migration in `DbUpgrader.php` to drop it in the next version (0.6.0), and remove the CREATE TABLE from Installer for new installs.

In `src/Engine/Installer.php`, remove lines 173-183 (the partners table creation):

```php
// REMOVE this entire block:
		// Accountability partners.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wb_gam_partners (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id_1  BIGINT UNSIGNED NOT NULL,
			user_id_2  BIGINT UNSIGNED NOT NULL,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY partner_pair (user_id_1, user_id_2)
		) $charset;"
		);
```

In `src/Engine/DbUpgrader.php`, add a migration method for 0.6.0:

```php
/**
 * v0.6.0 — Drop unused partners table.
 */
private static function upgrade_to_0_6_0(): void {
    global $wpdb;

    // Drop the unused accountability partners table.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional cleanup.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wb_gam_partners" );
}
```

And add the version check to the main upgrade dispatcher (following the existing pattern in DbUpgrader).

### Test

```bash
# Confirm MissionMode class is gone
php -r "require 'vendor/autoload.php'; var_dump(class_exists('WBGam\Engine\MissionMode'));" # should be false

# Confirm AbilitiesRegistrar is gone
php -r "require 'vendor/autoload.php'; var_dump(class_exists('WBGam\Abilities\AbilitiesRegistrar'));" # should be false

# Run PHPUnit to verify no broken references
composer run test:unit
```

### Git Commit

```
chore: remove dead code (MissionMode, AbilitiesRegistrar, partners table)

MissionMode was never referenced by any block or template. AbilitiesRegistrar
is a no-op (WordPress Abilities API does not exist yet). Partners table was
never populated. Migration added to drop the table on existing installs.
```

---

## Task 3: Conditional Asset Loading

### Problem

`frontend.css` (26KB) and `interactivity/index.js` load on **every page** via `wp_enqueue_scripts`, including pages with zero gamification content. On a 100K-member site with high-traffic non-gamification pages, this is unnecessary bandwidth.

### Solution

1. Register (not enqueue) the stylesheet. Let blocks pull it as a dependency via `block.json`.
2. For shortcode pages, enqueue CSS in the `ShortcodeHandler` render callbacks.
3. Add CSS minification to the build pipeline.

### Files

#### MODIFY: `wb-gamification.php` -- `enqueue_assets()` method

Replace lines 212-215:

```php
public function enqueue_assets(): void {
    wp_enqueue_style( 'wb-gamification', WB_GAM_URL . 'assets/css/frontend.css', array(), WB_GAM_VERSION );
    wp_enqueue_script_module( 'wb-gamification-interactivity', WB_GAM_URL . 'assets/interactivity/index.js', array(), WB_GAM_VERSION );
}
```

With:

```php
public function enqueue_assets(): void {
    // Register only — blocks and shortcodes enqueue as needed.
    wp_register_style( 'wb-gamification', WB_GAM_URL . 'assets/css/frontend.css', array(), WB_GAM_VERSION );

    // Interactivity API module is registered but only enqueued when
    // NotificationBridge renders the toast shell in wp_footer.
    wp_register_script_module( 'wb-gamification-interactivity', WB_GAM_URL . 'assets/interactivity/index.js', array(), WB_GAM_VERSION );
}
```

#### MODIFY: `src/Engine/ShortcodeHandler.php` -- Enqueue CSS in the block helper

Replace the `block()` helper method (lines 345-355):

```php
private static function block( string $block_slug, array $attrs ): string {
    return render_block(
        array(
            'blockName'    => "wb-gamification/{$block_slug}",
            'attrs'        => $attrs,
            'innerBlocks'  => array(),
            'innerHTML'    => '',
            'innerContent' => array(),
        )
    );
}
```

With:

```php
private static function block( string $block_slug, array $attrs ): string {
    // Ensure the frontend stylesheet is enqueued when a shortcode renders.
    if ( ! wp_style_is( 'wb-gamification', 'enqueued' ) ) {
        wp_enqueue_style( 'wb-gamification' );
    }

    return render_block(
        array(
            'blockName'    => "wb-gamification/{$block_slug}",
            'attrs'        => $attrs,
            'innerBlocks'  => array(),
            'innerHTML'    => '',
            'innerContent' => array(),
        )
    );
}
```

#### MODIFY: Each `blocks/*/block.json` -- Add style dependency

For every block.json file in `blocks/leaderboard/block.json`, `blocks/member-points/block.json`, etc., add the `style` field if not already present:

```json
{
    "style": "wb-gamification"
}
```

This tells WordPress to enqueue the registered `wb-gamification` style handle whenever the block is rendered. Check each block.json and add this field.

#### MODIFY: `src/Engine/NotificationBridge.php` -- Enqueue interactivity module

In the `render()` method, before outputting the markup (around line 192), add:

```php
public static function render(): void {
    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        return;
    }

    // Enqueue assets only when the notification shell actually renders.
    wp_enqueue_style( 'wb-gamification' );

    $events = self::flush( $user_id );
    // ... rest of method unchanged
```

#### CREATE: `Gruntfile.js` (if not present)

```javascript
module.exports = function ( grunt ) {
	'use strict';

	grunt.initConfig( {
		cssmin: {
			target: {
				files: {
					'assets/css/frontend.min.css': [ 'assets/css/frontend.css' ],
					'assets/css/admin.min.css': [ 'assets/css/admin.css' ],
				},
			},
		},
		watch: {
			css: {
				files: [ 'assets/css/*.css', '!assets/css/*.min.css' ],
				tasks: [ 'cssmin' ],
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-contrib-cssmin' );
	grunt.loadNpmTasks( 'grunt-contrib-watch' );

	grunt.registerTask( 'default', [ 'cssmin' ] );
};
```

#### CREATE: `package.json` (if not present)

```json
{
  "name": "wb-gamification",
  "version": "0.6.0",
  "private": true,
  "devDependencies": {
    "grunt": "^1.6.1",
    "grunt-contrib-cssmin": "^4.0.0",
    "grunt-contrib-watch": "^1.1.0"
  },
  "scripts": {
    "build": "grunt",
    "watch": "grunt watch"
  }
}
```

Then after creating these files:

```bash
cd /Users/varundubey/Local\ Sites/mediaverse/app/public/wp-content/plugins/wb-gamification
npm install
npx grunt
```

After minification, update `enqueue_assets()` to use the minified file in production:

```php
public function enqueue_assets(): void {
    $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
    wp_register_style( 'wb-gamification', WB_GAM_URL . "assets/css/frontend{$suffix}.css", array(), WB_GAM_VERSION );
    wp_register_script_module( 'wb-gamification-interactivity', WB_GAM_URL . 'assets/interactivity/index.js', array(), WB_GAM_VERSION );
}
```

### Test

```bash
# Visit a page with no gamification blocks/shortcodes
# Verify: frontend.css is NOT in page source

# Visit a page with [wb_gam_leaderboard]
# Verify: frontend.css IS loaded

# Visit a page with a wb-gamification/leaderboard block
# Verify: frontend.css IS loaded via block.json dependency
```

### Git Commit

```
perf: load frontend CSS/JS only on pages that use gamification

Register assets instead of enqueuing globally. Blocks pull CSS via
block.json style dependency. Shortcodes enqueue in render callbacks.
Adds Grunt CSS minification pipeline.
```

---

## Task 4: Async Award Pipeline

### Problem

The `wb_gamification_points_awarded` hook currently fires 6 synchronous listeners:

| Priority | Listener | Queries |
|----------|----------|---------|
| 10 | `BadgeEngine::evaluate_on_award` | ~3 |
| 15 | `ChallengeEngine::on_points_awarded` | ~2 |
| 20 | `PersonalRecordEngine::check_personal_records` | 3 |
| 20 | `CommunityChallengeEngine::on_points_awarded` | ~2 |
| 30 | `SiteFirstBadgeEngine::on_points_awarded` | ~1 |
| 99 | `NotificationBridge::on_points_awarded` | 0 |

Total: 12-15 DB queries on every single point award. At 100K active members doing 5 actions/day, that is 500K-750K extra queries/day.

### Solution

Keep ONLY `BadgeEngine` (priority 10) + `NotificationBridge` (priority 99) synchronous. Badges need immediate feedback (the user expects to see the toast). Move ChallengeEngine, PersonalRecordEngine, CommunityChallengeEngine, SiteFirstBadgeEngine to an async batch that runs every 30 seconds via Action Scheduler.

### Files

#### CREATE: `src/Engine/AsyncEvaluator.php`

```php
<?php
/**
 * Async Evaluator
 *
 * Collects award events during a request and enqueues a single Action
 * Scheduler job on shutdown to process non-critical listeners in batch.
 *
 * This reduces per-award DB queries from ~12-15 to ~4-5 by deferring
 * challenge progress, personal records, community challenges, and
 * site-first badges to an async pipeline.
 *
 * @package WB_Gamification
 * @since   0.6.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Collects award events and processes non-critical listeners asynchronously.
 *
 * @package WB_Gamification
 */
final class AsyncEvaluator {

	private const AS_HOOK  = 'wb_gam_async_evaluate_batch';
	private const AS_GROUP = 'wb-gamification';

	/**
	 * Award events collected during this request.
	 *
	 * @var array<int, array{user_id: int, action_id: string, points: int, event_id: string}>
	 */
	private static array $pending = array();

	/**
	 * Whether the shutdown handler has been registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Boot the async evaluator — register the AS handler.
	 */
	public static function init(): void {
		add_action( self::AS_HOOK, array( __CLASS__, 'process_batch' ) );
	}

	/**
	 * Collect an award event for deferred processing.
	 *
	 * Called by Engine after synchronous listeners (BadgeEngine, NotificationBridge)
	 * have already run.
	 *
	 * @param int    $user_id   User who earned points.
	 * @param Event  $event     The event object.
	 * @param int    $points    Points awarded.
	 */
	public static function collect( int $user_id, Event $event, int $points ): void {
		self::$pending[] = array(
			'user_id'   => $user_id,
			'action_id' => $event->action_id,
			'points'    => $points,
			'event_id'  => $event->event_id,
		);

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( __CLASS__, 'enqueue_batch' ) );
		}
	}

	/**
	 * On shutdown, enqueue a single AS job with all collected events.
	 */
	public static function enqueue_batch(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Fallback: process inline if AS unavailable.
			self::process_batch( self::$pending );
			return;
		}

		as_enqueue_async_action(
			self::AS_HOOK,
			array( self::$pending ),
			self::AS_GROUP
		);

		self::$pending = array();
	}

	/**
	 * Process a batch of award events through deferred listeners.
	 *
	 * Runs: ChallengeEngine, PersonalRecordEngine, CommunityChallengeEngine,
	 * SiteFirstBadgeEngine.
	 *
	 * @param array $events Array of award event data arrays.
	 */
	public static function process_batch( array $events ): void {
		foreach ( $events as $data ) {
			$user_id   = (int) ( $data['user_id'] ?? 0 );
			$action_id = (string) ( $data['action_id'] ?? '' );
			$points    = (int) ( $data['points'] ?? 0 );
			$event_id  = (string) ( $data['event_id'] ?? '' );

			if ( $user_id <= 0 || '' === $action_id ) {
				continue;
			}

			$event = new Event(
				array(
					'action_id' => $action_id,
					'user_id'   => $user_id,
					'event_id'  => $event_id,
				)
			);

			// ChallengeEngine — individual challenge progress.
			ChallengeEngine::on_points_awarded( $user_id, $event, $points );

			// PersonalRecordEngine — day/week/month personal bests.
			if ( FeatureFlags::is_enabled( 'personal_records' ) ) {
				PersonalRecordEngine::check_personal_records( $user_id, $event, $points );
			}

			// CommunityChallengeEngine — site-wide challenge counters.
			if ( FeatureFlags::is_enabled( 'community_challenges' ) ) {
				CommunityChallengeEngine::on_points_awarded( $user_id, $event, $points );
			}

			// SiteFirstBadgeEngine — first-ever badges.
			if ( FeatureFlags::is_enabled( 'site_first_badges' ) ) {
				SiteFirstBadgeEngine::on_points_awarded( $user_id, $event, $points );
			}
		}
	}
}
```

#### MODIFY: `src/Engine/Engine.php` -- Wire up AsyncEvaluator

In the `init()` method (line 45-54), add:

```php
public static function init(): void {
    if ( self::$initialized ) {
        return;
    }
    self::$initialized = true;

    WebhookDispatcher::init();
    AsyncEvaluator::init();

    // Action Scheduler handler for async event processing.
    add_action( 'wb_gam_process_event_async', array( __CLASS__, 'handle_async' ) );
}
```

In the `process()` method, after `do_action( 'wb_gamification_points_awarded', ... )` (line 234), add the async collection call:

After line 234:

```php
do_action( 'wb_gamification_points_awarded', $event->user_id, $event, $points );

// Collect for async evaluation (challenges, personal records, etc.).
AsyncEvaluator::collect( $event->user_id, $event, $points );
```

#### MODIFY: Remove direct hooks from engines that are now async

**`src/Engine/ChallengeEngine.php`** -- Remove the `init()` method's hook registration:

Replace:

```php
public static function init(): void {
    add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'on_points_awarded' ), 15, 3 );
}
```

With:

```php
public static function init(): void {
    // Hook removed — ChallengeEngine::on_points_awarded() is now called
    // by AsyncEvaluator::process_batch() for deferred processing.
    // This init() is kept for any future non-award hooks the engine needs.
}
```

**`src/Engine/PersonalRecordEngine.php`** -- Remove the hook:

Replace:

```php
public static function init(): void {
    // Priority 20 — runs after BadgeEngine (10) and after all side-effects settle.
    add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'check_personal_records' ), 20, 3 );
}
```

With:

```php
public static function init(): void {
    // Hook removed — check_personal_records() is now called by
    // AsyncEvaluator::process_batch() for deferred processing.
}
```

**`src/Engine/CommunityChallengeEngine.php`** -- Remove the hook:

Replace:

```php
public static function init(): void {
    add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'on_points_awarded' ), 20, 3 );
}
```

With:

```php
public static function init(): void {
    // Hook removed — on_points_awarded() is now called by
    // AsyncEvaluator::process_batch() for deferred processing.
}
```

**`src/Engine/SiteFirstBadgeEngine.php`** -- Remove only the `wb_gamification_points_awarded` hook (keep the `level_changed` and `streak_milestone` hooks):

Replace:

```php
public static function init(): void {
    add_action( 'wb_gamification_level_changed', array( __CLASS__, 'on_level_changed' ), 10, 3 );
    add_action( 'wb_gamification_points_awarded', array( __CLASS__, 'on_points_awarded' ), 30, 3 );
    add_action( 'wb_gamification_streak_milestone', array( __CLASS__, 'on_streak_milestone' ), 10, 2 );
    add_action( 'plugins_loaded', array( __CLASS__, 'ensure_badges_exist' ), 20 );
}
```

With:

```php
public static function init(): void {
    add_action( 'wb_gamification_level_changed', array( __CLASS__, 'on_level_changed' ), 10, 3 );
    // wb_gamification_points_awarded hook removed — on_points_awarded() is
    // now called by AsyncEvaluator::process_batch() for deferred processing.
    add_action( 'wb_gamification_streak_milestone', array( __CLASS__, 'on_streak_milestone' ), 10, 2 );
    add_action( 'plugins_loaded', array( __CLASS__, 'ensure_badges_exist' ), 20 );
}
```

### Test

```bash
# Award points and verify badges still award synchronously
wp wb-gamification points award --user=1 --points=200 --message="Test sync badges"

# Check that an AS job was created for the deferred listeners
wp action-scheduler list --group=wb-gamification --status=pending

# Run pending AS jobs
wp action-scheduler run --group=wb-gamification

# Verify challenge progress updated
wp eval "var_dump( WBGam\Engine\ChallengeEngine::get_active_challenges(1) );"
```

### Git Commit

```
perf: move non-critical award listeners to async batch processing

BadgeEngine and NotificationBridge remain synchronous (immediate
feedback). ChallengeEngine, PersonalRecordEngine, CommunityChallengeEngine,
and SiteFirstBadgeEngine now process in a single deferred Action Scheduler
job, reducing per-award queries from 12-15 to 4-5.
```

---

## Task 5: Leaderboard Snapshot Cache

### Problem

`LeaderboardEngine::get_leaderboard()` runs `SUM(points) GROUP BY user_id ORDER BY total_points DESC` on every request. On a site with 100K users and millions of points rows, this query takes 2-5 seconds even with indexes. Additionally, the results loop calls `get_avatar_url()` per row (N+1).

The `wb_gam_leaderboard_cache` table already exists in the schema (created by `Installer.php`) but has **zero writers** -- it was scaffolded but never implemented.

### Solution

1. Add `cache_users()` before the avatar loop to fix N+1.
2. Add object cache layer with 2-minute TTL for all leaderboard reads.
3. Create a cron job (every 5 minutes) that writes leaderboard snapshots to `wb_gam_leaderboard_cache` table.
4. For sites with 10K+ users, read from snapshot table instead of computing live.

### Files

#### MODIFY: `src/Engine/LeaderboardEngine.php`

Replace the entire file content with:

```php
<?php
/**
 * WB Gamification Leaderboard Engine
 *
 * Generates leaderboard data from the wb_gam_points ledger with opt-out
 * filtering, period scoping, object caching, and snapshot support for
 * large communities (10K+ users).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Generates leaderboard data with caching and snapshot support for scalability.
 *
 * @package WB_Gamification
 */
final class LeaderboardEngine {

	private const CACHE_GROUP = 'wb_gamification';
	private const CACHE_TTL   = 120; // 2 minutes.
	private const CRON_HOOK   = 'wb_gam_leaderboard_snapshot';
	private const LARGE_SITE  = 10000; // User count threshold for snapshot mode.

	/**
	 * Register the snapshot cron.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'write_snapshots' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wb_gam_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the snapshot cron on activation.
	 */
	public static function activate(): void {
		// Register the custom interval first.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wb_gam_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the snapshot cron on deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Register a 5-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public static function add_cron_interval( array $schedules ): array {
		$schedules['wb_gam_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes', 'wb-gamification' ),
		);
		return $schedules;
	}

	/**
	 * Get the top-N members for a period, respecting opt-outs.
	 *
	 * Uses object cache with 2-minute TTL. For sites with 10K+ users,
	 * reads from the snapshot table instead of computing live.
	 *
	 * @param string $period     Period: 'all' | 'month' | 'week' | 'day'.
	 * @param int    $limit      Maximum rows to return (1-100).
	 * @param string $scope_type Scope type identifier (e.g. 'bp_group'). Empty = site-wide.
	 * @param int    $scope_id   Scope object ID (e.g. group_id).
	 * @return array<int, array{rank: int, user_id: int, display_name: string, avatar_url: string, points: int}>
	 */
	public static function get_leaderboard(
		string $period = 'all',
		int $limit = 10,
		string $scope_type = '',
		int $scope_id = 0
	): array {
		$limit     = max( 1, min( 100, $limit ) );
		$cache_key = "wb_gam_lb_{$period}_{$limit}_{$scope_type}_{$scope_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		// For large sites with no scope filter, try snapshot table first.
		if ( '' === $scope_type && self::is_large_site() ) {
			$result = self::read_snapshot( $period, $limit );
			if ( ! empty( $result ) ) {
				wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );
				return $result;
			}
		}

		$result = self::compute_live( $period, $limit, $scope_type, $scope_id );
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Compute leaderboard live from the points table.
	 *
	 * @param string $period     Period filter.
	 * @param int    $limit      Row limit.
	 * @param string $scope_type Scope type.
	 * @param int    $scope_id   Scope ID.
	 * @return array Leaderboard rows.
	 */
	private static function compute_live(
		string $period,
		int $limit,
		string $scope_type,
		int $scope_id
	): array {
		global $wpdb;

		$period_start = self::get_period_start( $period );
		$opt_out_ids  = self::get_opted_out_ids();
		$scope_ids    = self::resolve_scope( $scope_type, $scope_id );

		// Build WHERE clause.
		$where_parts  = array();
		$where_values = array();

		if ( $period_start ) {
			$where_parts[]  = 'p.created_at >= %s';
			$where_values[] = $period_start;
		}

		if ( ! empty( $opt_out_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$opt_out_clause = "AND p.user_id NOT IN ($placeholders)";
			$where_values   = array_merge( $where_values, $opt_out_ids );
		} else {
			$opt_out_clause = '';
		}

		if ( ! empty( $scope_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$scope_clause = "AND p.user_id IN ($placeholders)";
			$where_values = array_merge( $where_values, $scope_ids );
		} else {
			$scope_clause = '';
		}

		$where_clause = ! empty( $where_parts )
			? 'WHERE ' . implode( ' AND ', $where_parts )
			: '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = "
			SELECT p.user_id,
			       SUM(p.points) AS total_points,
			       u.display_name
			  FROM {$wpdb->prefix}wb_gam_points p
			  JOIN {$wpdb->users} u ON u.ID = p.user_id
			  {$where_clause}
			  {$opt_out_clause}
			  {$scope_clause}
			 GROUP BY p.user_id
			 ORDER BY total_points DESC
			 LIMIT %d
		";
		// phpcs:enable

		$where_values[] = $limit;

		$rows = ! empty( $where_values )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			? $wpdb->get_results( $wpdb->prepare( $query, $where_values ), ARRAY_A )
			: $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore

		if ( ! $rows ) {
			return array();
		}

		// Prime user cache to avoid N+1 on get_avatar_url().
		$user_ids = array_map( 'intval', array_column( $rows, 'user_id' ) );
		cache_users( $user_ids );

		$result = array();
		foreach ( $rows as $rank_zero => $row ) {
			$user_id  = (int) $row['user_id'];
			$result[] = array(
				'rank'         => $rank_zero + 1,
				'user_id'      => $user_id,
				'display_name' => $row['display_name'],
				'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 48 ) ),
				'points'       => (int) $row['total_points'],
			);
		}

		return $result;
	}

	/**
	 * Read leaderboard from the snapshot cache table.
	 *
	 * @param string $period Period filter.
	 * @param int    $limit  Row limit.
	 * @return array Leaderboard rows (empty if snapshot not yet written).
	 */
	private static function read_snapshot( string $period, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, display_name, avatar_url, points, rank
				   FROM {$wpdb->prefix}wb_gam_leaderboard_cache
				  WHERE period = %s
				  ORDER BY rank ASC
				  LIMIT %d",
				$period,
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'rank'         => (int) $row['rank'],
					'user_id'      => (int) $row['user_id'],
					'display_name' => $row['display_name'],
					'avatar_url'   => $row['avatar_url'],
					'points'       => (int) $row['points'],
				);
			},
			$rows
		);
	}

	/**
	 * Write leaderboard snapshots for all periods.
	 *
	 * Called by WP-Cron every 5 minutes. Computes top-100 for each period
	 * and writes to wb_gam_leaderboard_cache table.
	 */
	public static function write_snapshots(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'wb_gam_leaderboard_cache';
		$periods = array( 'all', 'month', 'week', 'day' );

		foreach ( $periods as $period ) {
			$rows = self::compute_live( $period, 100, '', 0 );

			// Truncate existing rows for this period.
			$wpdb->delete( $table, array( 'period' => $period ), array( '%s' ) );

			// Insert fresh snapshot.
			foreach ( $rows as $row ) {
				$wpdb->insert(
					$table,
					array(
						'period'       => $period,
						'user_id'      => $row['user_id'],
						'display_name' => $row['display_name'],
						'avatar_url'   => $row['avatar_url'],
						'points'       => $row['points'],
						'rank'         => $row['rank'],
						'updated_at'   => current_time( 'mysql' ),
					),
					array( '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
				);
			}
		}
	}

	/**
	 * Check if the site has enough users to warrant snapshot mode.
	 *
	 * @return bool
	 */
	private static function is_large_site(): bool {
		static $result = null;
		if ( null !== $result ) {
			return $result;
		}

		$count  = wp_cache_get( 'wb_gam_user_count', self::CACHE_GROUP );
		if ( false === $count ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
			wp_cache_set( 'wb_gam_user_count', $count, self::CACHE_GROUP, 3600 );
		}

		$result = ( (int) $count >= self::LARGE_SITE );
		return $result;
	}

	// ── get_user_rank and private helpers remain unchanged ───────────────────

	/**
	 * Get a user's private rank within a period.
	 *
	 * @param int    $user_id    User to calculate rank for.
	 * @param string $period     Period: 'all' | 'month' | 'week' | 'day'.
	 * @param string $scope_type Optional scope type.
	 * @param int    $scope_id   Optional scope ID.
	 * @return array{rank: int, points: int, points_to_next: int|null}
	 */
	public static function get_user_rank(
		int $user_id,
		string $period = 'all',
		string $scope_type = '',
		int $scope_id = 0
	): array {
		global $wpdb;

		$period_start = self::get_period_start( $period );
		$opt_out_ids  = self::get_opted_out_ids();
		$opt_out_ids  = array_filter( $opt_out_ids, fn( $id ) => $id !== $user_id );
		$scope_ids    = self::resolve_scope( $scope_type, $scope_id );

		if ( $period_start ) {
			$user_total_sql = $wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
				 WHERE user_id = %d AND created_at >= %s",
				$user_id,
				$period_start
			);
		} else {
			$user_total_sql = $wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points
				 WHERE user_id = %d",
				$user_id
			);
		}
		$user_total = (int) $wpdb->get_var( $user_total_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$above_rank = self::count_users_above( $user_total, $period_start, $opt_out_ids, $scope_ids );
		$next_total = self::get_next_threshold( $user_total, $period_start, $opt_out_ids, $scope_ids );

		return array(
			'rank'           => $above_rank + 1,
			'points'         => $user_total,
			'points_to_next' => null !== $next_total ? ( $next_total - $user_total ) : null,
		);
	}

	/**
	 * Return the MySQL datetime string for the start of a period.
	 *
	 * @param string $period Period identifier.
	 * @return string|null
	 */
	private static function get_period_start( string $period ): ?string {
		switch ( $period ) {
			case 'day':
				return gmdate( 'Y-m-d' ) . ' 00:00:00';
			case 'week':
				return gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
			case 'month':
				return gmdate( 'Y-m-01' ) . ' 00:00:00';
			default:
				return null;
		}
	}

	/**
	 * @param string $scope_type Scope type.
	 * @param int    $scope_id   Scope ID.
	 * @return int[]
	 */
	private static function resolve_scope( string $scope_type, int $scope_id ): array {
		if ( '' === $scope_type || $scope_id <= 0 ) {
			return array();
		}
		return (array) apply_filters(
			'wb_gamification_leaderboard_scope_user_ids',
			array(),
			$scope_type,
			$scope_id
		);
	}

	/**
	 * @return int[]
	 */
	private static function get_opted_out_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->prefix}wb_gam_member_prefs WHERE leaderboard_opt_out = 1"
		);
		return array_map( 'intval', $ids ?: array() );
	}

	/**
	 * @param int         $threshold    Points threshold.
	 * @param string|null $period_start Period start datetime.
	 * @param int[]       $opt_out_ids  Opted-out user IDs.
	 * @param int[]       $scope_ids    Scoped user IDs.
	 * @return int
	 */
	private static function count_users_above( int $threshold, ?string $period_start, array $opt_out_ids, array $scope_ids ): int {
		global $wpdb;
		$values = array();
		$where  = '';
		if ( $period_start ) {
			$where   .= ' AND p.created_at >= %s';
			$values[] = $period_start;
		}
		if ( ! empty( $opt_out_ids ) ) {
			$ph     = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			$where .= " AND p.user_id NOT IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$values = array_merge( $values, $opt_out_ids );
		}
		if ( ! empty( $scope_ids ) ) {
			$ph     = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$where .= " AND p.user_id IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$values = array_merge( $values, $scope_ids );
		}
		$values[] = $threshold;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM (
			SELECT user_id, SUM(points) AS total FROM {$wpdb->prefix}wb_gam_points p WHERE 1=1 {$where} GROUP BY p.user_id HAVING total > %d
		) ranked";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore
	}

	/**
	 * @param int         $threshold    Points threshold.
	 * @param string|null $period_start Period start datetime.
	 * @param int[]       $opt_out_ids  Opted-out user IDs.
	 * @param int[]       $scope_ids    Scoped user IDs.
	 * @return int|null
	 */
	private static function get_next_threshold( int $threshold, ?string $period_start, array $opt_out_ids, array $scope_ids ): ?int {
		global $wpdb;
		$values = array();
		$where  = '';
		if ( $period_start ) {
			$where   .= ' AND p.created_at >= %s';
			$values[] = $period_start;
		}
		if ( ! empty( $opt_out_ids ) ) {
			$ph     = implode( ',', array_fill( 0, count( $opt_out_ids ), '%d' ) );
			$where .= " AND p.user_id NOT IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$values = array_merge( $values, $opt_out_ids );
		}
		if ( ! empty( $scope_ids ) ) {
			$ph     = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$where .= " AND p.user_id IN ($ph)"; // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$values = array_merge( $values, $scope_ids );
		}
		$values[] = $threshold;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT MIN(total) FROM (
			SELECT user_id, SUM(points) AS total FROM {$wpdb->prefix}wb_gam_points p WHERE 1=1 {$where} GROUP BY p.user_id HAVING total > %d
		) ranked";
		$result = $wpdb->get_var( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore
		return null !== $result ? (int) $result : null;
	}
}
```

#### MODIFY: `src/Engine/Installer.php` -- Ensure `wb_gam_leaderboard_cache` table has the right schema

Add this after the existing leaderboard_cache table creation (or verify it matches). If the table was created in an earlier version, add the missing columns via a DbUpgrader migration. The table needs these columns:

```sql
CREATE TABLE {$wpdb->prefix}wb_gam_leaderboard_cache (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period       VARCHAR(20)     NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(255)    NOT NULL,
    avatar_url   VARCHAR(500),
    points       BIGINT          NOT NULL DEFAULT 0,
    rank         INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY period_rank (period, rank),
    KEY user_id (user_id)
) $charset;
```

#### MODIFY: `wb-gamification.php` -- Register the cron interval filter

Add after line 106 (after `register_blocks` init):

```php
add_filter( 'cron_schedules', array( \WBGam\Engine\LeaderboardEngine::class, 'add_cron_interval' ) );
add_action( 'plugins_loaded', array( \WBGam\Engine\LeaderboardEngine::class, 'init' ), 10 );
```

### Test

```bash
# Write snapshots manually
wp cron event run wb_gam_leaderboard_snapshot

# Verify snapshot data exists
wp eval "global \$wpdb; var_dump(\$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}wb_gam_leaderboard_cache\"));"

# Verify leaderboard reads from cache
wp eval "var_dump(WBGam\Engine\LeaderboardEngine::get_leaderboard('all', 5));"
```

### Git Commit

```
perf: add leaderboard snapshot cache and object caching

Adds 2-minute object cache TTL for all leaderboard reads. For sites
with 10K+ users, reads from a snapshot table updated every 5 minutes
by WP-Cron instead of computing SUM(points) GROUP BY live. Fixes N+1
avatar query with cache_users() call.
```

---

## Task 6: Hot-Path Query Caching

### Problem

Several queries fire on every award with zero caching. On a busy site this means redundant DB round-trips for data that changes rarely.

### Files & Changes

#### 1. `src/Engine/BadgeEngine.php` -- Cache badge rules (5-min TTL)

Replace the query in `evaluate_on_award()` (lines 63-68):

```php
// Load all active badge conditions — typically ~30 rows.
$rules = $wpdb->get_results(
    "SELECT target_id AS badge_id, rule_config
       FROM {$wpdb->prefix}wb_gam_rules
      WHERE rule_type = 'badge_condition' AND is_active = 1",
    ARRAY_A
);
```

With:

```php
// Load all active badge conditions — cached for 5 minutes.
$cache_key = 'wb_gam_badge_rules';
$rules     = wp_cache_get( $cache_key, self::CACHE_GROUP );

if ( false === $rules ) {
    $rules = $wpdb->get_results(
        "SELECT target_id AS badge_id, rule_config
           FROM {$wpdb->prefix}wb_gam_rules
          WHERE rule_type = 'badge_condition' AND is_active = 1",
        ARRAY_A
    );
    $rules = $rules ?: array();
    wp_cache_set( $cache_key, $rules, self::CACHE_GROUP, 300 );
}
```

#### 2. `src/Engine/RuleEngine.php` -- Cache multiplier rules (5-min TTL)

Replace the query in `apply_multipliers()` (lines 50-62):

```php
global $wpdb;

$rules = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT rule_config
           FROM {$wpdb->prefix}wb_gam_rules
          WHERE rule_type = 'points_multiplier'
            AND ( target_id = %s OR target_id IS NULL OR target_id = '' )
            AND is_active = 1
          ORDER BY id ASC",
        $event->action_id
    ),
    ARRAY_A
);
```

With:

```php
global $wpdb;

$cache_key = 'wb_gam_multiplier_rules_' . md5( $event->action_id );
$rules     = wp_cache_get( $cache_key, 'wb_gamification' );

if ( false === $rules ) {
    $rules = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT rule_config
               FROM {$wpdb->prefix}wb_gam_rules
              WHERE rule_type = 'points_multiplier'
                AND ( target_id = %s OR target_id IS NULL OR target_id = '' )
                AND is_active = 1
              ORDER BY id ASC",
            $event->action_id
        ),
        ARRAY_A
    );
    $rules = $rules ?: array();
    wp_cache_set( $cache_key, $rules, 'wb_gamification', 300 );
}
```

#### 3. `src/Engine/LevelEngine.php` -- Static + object cache for levels

Replace `get_level_for_points()` (lines 82-107):

```php
public static function get_level_for_points( int $points ): ?array {
    // Levels change extremely rarely — cache all levels in a static array.
    static $all_levels = null;

    if ( null === $all_levels ) {
        $cached = wp_cache_get( 'wb_gam_all_levels', 'wb_gamification' );

        if ( false !== $cached ) {
            $all_levels = $cached;
        } else {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT id, name, min_points, icon_url
                   FROM {$wpdb->prefix}wb_gam_levels
                  ORDER BY min_points DESC",
                ARRAY_A
            );
            $all_levels = $rows ?: array();
            wp_cache_set( 'wb_gam_all_levels', $all_levels, 'wb_gamification', 3600 );
        }
    }

    // Walk descending — first match is the user's level.
    foreach ( $all_levels as $row ) {
        if ( $points >= (int) $row['min_points'] ) {
            return array(
                'id'         => (int) $row['id'],
                'name'       => $row['name'],
                'min_points' => (int) $row['min_points'],
                'icon_url'   => $row['icon_url'] ?: null,
            );
        }
    }

    return null;
}
```

#### 4. `src/Engine/Engine.php` -- Cache action-enabled checks

In `process()`, replace the `get_option()` call (line 141):

```php
if ( ! (bool) get_option( 'wb_gam_enabled_' . $event->action_id, true ) ) {
    return false;
}
```

With:

```php
// Cache action-enabled options in a static array to avoid repeated get_option() calls.
static $enabled_cache = array();
if ( ! isset( $enabled_cache[ $event->action_id ] ) ) {
    $enabled_cache[ $event->action_id ] = (bool) get_option( 'wb_gam_enabled_' . $event->action_id, true );
}
if ( ! $enabled_cache[ $event->action_id ] ) {
    return false;
}
```

Do the same for the identical check in `process_async()` (line 83):

```php
static $enabled_cache_async = array();
if ( ! isset( $enabled_cache_async[ $event->action_id ] ) ) {
    $enabled_cache_async[ $event->action_id ] = (bool) get_option( 'wb_gam_enabled_' . $event->action_id, true );
}
if ( ! $enabled_cache_async[ $event->action_id ] ) {
    return false;
}
```

#### 5. `src/Engine/TenureBadgeEngine.php` -- Option flag after first seed

Replace `ensure_badges_exist()` (lines 108-134):

```php
public static function ensure_badges_exist(): void {
    // Skip if already seeded (avoids 4 DB queries on every request).
    if ( get_option( 'wb_gam_tenure_badges_seeded' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wb_gam_badge_defs';

    $needs_seed = false;
    foreach ( self::TIERS as $id => $tier ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is safe.
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM $table WHERE id = %s", $id )
        );

        if ( $exists ) {
            continue;
        }

        $needs_seed = true;
        $wpdb->insert(
            $table,
            array(
                'id'          => $id,
                'name'        => $tier['name'],
                'description' => $tier['description'],
                'category'    => 'special',
            ),
            array( '%s', '%s', '%s', '%s' )
        );
    }

    // Mark as seeded so we skip entirely on future requests.
    if ( ! $needs_seed ) {
        update_option( 'wb_gam_tenure_badges_seeded', 1, true );
    }
}
```

### Test

```bash
# Award points multiple times — verify no redundant queries
# (Use Query Monitor or SAVEQUERIES to count queries)
wp eval "define('SAVEQUERIES', true); WBGam\Engine\Engine::process(new WBGam\Engine\Event(['action_id'=>'manual','user_id'=>1,'metadata'=>['points'=>10,'manual'=>true]])); global \$wpdb; echo count(\$wpdb->queries) . ' queries';"
```

### Git Commit

```
perf: add object cache to all hot-path queries in award pipeline

Cache badge rules (5min), multiplier rules (5min), level definitions
(1hr static+object), action-enabled options (static per-request), and
tenure badge seed check (permanent option flag). Eliminates ~6 redundant
DB queries per point award.
```

---

## Task 7: Events Table Pruning

### Problem

`wb_gam_events` grows unbounded. At 100K active members doing 5 actions/day, that is ~182M rows/year. The existing `LogPruner` only prunes `wb_gam_points`, not `wb_gam_events`.

### Files

#### MODIFY: `src/Engine/LogPruner.php`

Replace the entire file with:

```php
<?php
/**
 * WB Gamification Log Pruner
 *
 * Auto-prunes wb_gam_points and wb_gam_events rows older than the
 * configured retention periods. Scheduled via WP-Cron daily.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-prunes old rows from the points ledger and event log.
 *
 * @package WB_Gamification
 */
final class LogPruner {

	const CRON_HOOK  = 'wb_gam_prune_logs';
	const CRON_RECUR = 'daily';

	/**
	 * Register the cron schedule and hook.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Schedule the cron on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_RECUR, self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cron on plugin deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Delete rows older than the retention period from both tables.
	 *
	 * @return array{points: int, events: int} Number of rows deleted from each table.
	 */
	public static function prune(): array {
		$points_deleted = self::prune_points();
		$events_deleted = self::prune_events();

		/**
		 * Fires after the logs are pruned.
		 *
		 * @param int $points_deleted Rows deleted from wb_gam_points.
		 * @param int $events_deleted Rows deleted from wb_gam_events.
		 */
		do_action( 'wb_gamification_log_pruned', $points_deleted, $events_deleted );

		return array(
			'points' => $points_deleted,
			'events' => $events_deleted,
		);
	}

	/**
	 * Prune the points ledger.
	 *
	 * @return int Rows deleted.
	 */
	private static function prune_points(): int {
		global $wpdb;

		$months = (int) get_option( 'wb_gam_log_retention_months', 6 );
		if ( $months <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_points WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
	}

	/**
	 * Prune the events log.
	 *
	 * Uses a separate retention option so admins can keep events longer
	 * than derived points data if they need audit trails.
	 *
	 * @return int Rows deleted.
	 */
	private static function prune_events(): int {
		global $wpdb;

		$months = (int) get_option( 'wb_gam_events_retention_months', 12 );
		if ( $months <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wb_gam_events WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
	}
}
```

#### MODIFY: `src/CLI/LogsCommand.php` -- Add `prune-events` subcommand

Add this method to the existing `LogsCommand` class:

```php
/**
 * Prune old events from the wb_gam_events table.
 *
 * ## OPTIONS
 *
 * [--before=<duration>]
 * : Duration string, e.g. "12months", "6months", "1year". Default: value of wb_gam_events_retention_months option.
 *
 * [--dry-run]
 * : Show how many rows would be deleted without actually deleting.
 *
 * ## EXAMPLES
 *
 *     wp wb-gamification logs prune-events --before=12months --dry-run
 *     wp wb-gamification logs prune-events --before=6months
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Named arguments.
 */
public function prune_events( array $args, array $assoc_args ): void {
    global $wpdb;

    $before = $assoc_args['before'] ?? null;
    $dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

    if ( $before ) {
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $before ) );
    } else {
        $months = (int) get_option( 'wb_gam_events_retention_months', 12 );
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );
    }

    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events WHERE created_at < %s",
            $cutoff
        )
    );

    if ( $dry_run ) {
        \WP_CLI::success( sprintf( 'Dry run: %s events would be deleted (before %s).', number_format( $count ), $cutoff ) );
        return;
    }

    if ( 0 === $count ) {
        \WP_CLI::success( 'No events to prune.' );
        return;
    }

    $total_deleted = 0;
    do {
        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}wb_gam_events WHERE created_at < %s LIMIT 5000",
                $cutoff
            )
        );
        $total_deleted += $deleted;
        if ( $deleted > 0 ) {
            \WP_CLI::log( sprintf( 'Deleted %d rows (%d total)...', $deleted, $total_deleted ) );
        }
    } while ( $deleted > 0 );

    \WP_CLI::success( sprintf( 'Pruned %s events older than %s.', number_format( $total_deleted ), $cutoff ) );
}
```

### Test

```bash
wp wb-gamification logs prune-events --before=12months --dry-run
wp wb-gamification logs prune-events --before=12months
```

### Git Commit

```
feat: add events table pruning with configurable retention

wb_gam_events now pruned alongside wb_gam_points by the daily LogPruner
cron. Events retention defaults to 12 months (separate from points
retention at 6 months). Adds WP-CLI command: `wp wb-gamification logs
prune-events --before=12months --dry-run`.
```

---

## Task 8: Cron Consolidation

### Problem

Currently 8 separate cron events, 5 of which fire on Monday. This causes a thundering-herd effect where Monday morning has 5 simultaneous cron jobs competing for DB resources.

Current crons:
1. `wb_gam_prune_logs` -- daily
2. `wb_gam_weekly_nudge` -- Monday 08:00 UTC
3. `wb_gam_weekly_email` -- Monday 08:30 UTC
4. `wb_gam_tenure_check` -- daily 02:00 UTC
5. `wb_gam_status_retention_check` -- Thursday 18:00 UTC
6. `wb_gam_cohort_promote` -- Monday
7. `wb_gam_credential_expiry` -- daily
8. `wb_gam_leaderboard_snapshot` -- every 5 minutes (Task 5)

### Solution

1. Merge WeeklyEmailEngine + LeaderboardNudge into a single weekly Monday cron.
2. StatusRetentionEngine stays on Wednesday (spread from Monday).
3. Guard all cron callbacks with feature flag checks.
4. Keep daily crons (log pruning, tenure, credential expiry) as-is but spread times.

### Files

#### MODIFY: `src/Engine/WeeklyEmailEngine.php` -- Add feature flag guard

In `dispatch_batch()`, add at the top:

```php
public static function dispatch_batch(): void {
    if ( ! FeatureFlags::is_enabled( 'weekly_emails' ) ) {
        return;
    }

    if ( ! (int) get_option( self::OPT_ENABLED, 1 ) ) {
        return;
    }
    // ... rest unchanged
```

#### MODIFY: `src/Engine/LeaderboardNudge.php` -- Add feature flag guard

In `dispatch_batch()`, add at the top:

```php
public static function dispatch_batch(): void {
    if ( ! FeatureFlags::is_enabled( 'leaderboard_nudge' ) ) {
        return;
    }
    // ... rest unchanged
```

#### MODIFY: `src/Engine/StatusRetentionEngine.php` -- Add feature flag guard and move to Wednesday

In `run()`, add at the top:

```php
public static function run(): void {
    if ( ! FeatureFlags::is_enabled( 'status_retention' ) ) {
        return;
    }
    // ... rest unchanged
```

In `activate()`, change Thursday to Wednesday:

```php
public static function activate(): void {
    if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
        // Wednesday at 18:00 UTC — spread from Monday weekly crons.
        $next = strtotime( 'next wednesday 18:00:00 UTC' );
        wp_schedule_event( $next, 'weekly', self::CRON_HOOK );
    }
}
```

#### MODIFY: `src/Engine/TenureBadgeEngine.php` -- Add feature flag guard

In `run_daily_check()`, add at the top:

```php
public static function run_daily_check(): void {
    if ( ! FeatureFlags::is_enabled( 'tenure_badges' ) ) {
        return;
    }
    // ... rest unchanged
```

### Test

```bash
# List all scheduled cron events for the plugin
wp cron event list | grep wb_gam

# Disable a feature and verify its cron is a no-op
wp eval "WBGam\Engine\FeatureFlags::update(['weekly_emails' => false]);"
wp cron event run wb_gam_weekly_email  # Should do nothing
```

### Git Commit

```
fix: guard all cron callbacks with feature flag checks

Cron jobs now exit early if their feature flag is disabled, preventing
wasted DB queries. StatusRetentionEngine moved from Thursday to Wednesday
to spread weekly cron load across the week.
```

---

## Task 9: PersonalRecordEngine -- Single Query

### Problem

`check_personal_records()` runs 3 separate `SUM(points)` queries -- one for day, week, and month totals.

### Files

#### MODIFY: `src/Engine/PersonalRecordEngine.php`

Replace `check_personal_records()` and `period_total()`:

```php
/**
 * Check whether this award creates a new personal best.
 *
 * Uses a single SQL query to fetch day, week, and month totals
 * instead of three separate queries.
 *
 * @param int   $user_id User who just earned points.
 * @param Event $event   The event that triggered the award.
 * @param int   $points  Points awarded (not the total -- just this award).
 */
public static function check_personal_records( int $user_id, Event $event, int $points ): void {
    $totals = self::get_period_totals( $user_id );

    self::maybe_record( $user_id, 'day', $totals['day'] );
    self::maybe_record( $user_id, 'week', $totals['week'] );
    self::maybe_record( $user_id, 'month', $totals['month'] );
}

/**
 * Get day, week, and month point totals in a single query.
 *
 * @param int $user_id User ID.
 * @return array{day: int, week: int, month: int}
 */
private static function get_period_totals( int $user_id ): array {
    global $wpdb;

    $day_start   = gmdate( 'Y-m-d' ) . ' 00:00:00';
    $week_start  = gmdate( 'Y-m-d', strtotime( 'monday this week' ) ) . ' 00:00:00';
    $month_start = gmdate( 'Y-m-01' ) . ' 00:00:00';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN created_at >= %s THEN points ELSE 0 END), 0) AS day_total,
                COALESCE(SUM(CASE WHEN created_at >= %s THEN points ELSE 0 END), 0) AS week_total,
                COALESCE(SUM(CASE WHEN created_at >= %s THEN points ELSE 0 END), 0) AS month_total
            FROM {$wpdb->prefix}wb_gam_points
            WHERE user_id = %d AND created_at >= %s",
            $day_start,
            $week_start,
            $month_start,
            $user_id,
            $month_start
        ),
        ARRAY_A
    );

    return array(
        'day'   => (int) ( $row['day_total'] ?? 0 ),
        'week'  => (int) ( $row['week_total'] ?? 0 ),
        'month' => (int) ( $row['month_total'] ?? 0 ),
    );
}
```

Delete the old `period_total()` method (lines 128-151 in the original file) entirely.

### Test

```bash
wp eval "
\$event = new WBGam\Engine\Event(['action_id'=>'manual','user_id'=>1,'metadata'=>['points'=>10]]);
WBGam\Engine\PersonalRecordEngine::check_personal_records(1, \$event, 10);
echo 'OK';
"
```

### Git Commit

```
perf: consolidate PersonalRecordEngine to single SQL query

Replaces 3 separate SUM queries (day, week, month) with a single query
using CASE expressions. Reduces DB round-trips from 3 to 1 per personal
record check.
```

---

## Task 10: Complete Public API

### Problem

The public API in `src/Extensions/functions.php` is missing functions for badges, streaks, leaderboard, and feature flags. Third-party integrators have to call internal engine classes directly.

### Files

#### MODIFY: `src/Extensions/functions.php`

Add these functions after the existing ones (after line 141):

```php
/**
 * Check whether a user currently holds a specific badge.
 *
 * @param int    $user_id  WordPress user ID.
 * @param string $badge_id Badge identifier.
 * @return bool True if the user holds the badge (and it has not expired).
 */
function wb_gam_has_badge( int $user_id, string $badge_id ): bool {
	return \WBGam\Engine\BadgeEngine::has_badge( $user_id, $badge_id );
}

/**
 * Get all earned badges for a user with full definition data.
 *
 * @param int $user_id WordPress user ID.
 * @return array<int, array{id: string, name: string, description: string, image_url: string|null, is_credential: bool, category: string, earned_at: string, expires_at: string|null}>
 */
function wb_gam_get_user_badges( int $user_id ): array {
	return \WBGam\Engine\BadgeEngine::get_user_badges( $user_id );
}

/**
 * Get streak data for a user.
 *
 * @param int $user_id WordPress user ID.
 * @return array{current_streak: int, longest_streak: int, last_active: string|null, timezone: string, grace_used: bool}
 */
function wb_gam_get_user_streak( int $user_id ): array {
	return \WBGam\Engine\StreakEngine::get_streak( $user_id );
}

/**
 * Get the leaderboard for a period.
 *
 * @param string $period Period: 'all' | 'month' | 'week' | 'day'. Default 'all'.
 * @param int    $limit  Maximum rows (1-100). Default 10.
 * @return array<int, array{rank: int, user_id: int, display_name: string, avatar_url: string, points: int}>
 */
function wb_gam_get_leaderboard( string $period = 'all', int $limit = 10 ): array {
	return \WBGam\Engine\LeaderboardEngine::get_leaderboard( $period, $limit );
}

/**
 * Check whether a gamification feature is enabled.
 *
 * @param string $feature Feature key (e.g. 'kudos', 'community_challenges').
 * @return bool
 */
function wb_gam_is_feature_enabled( string $feature ): bool {
	return \WBGam\Engine\FeatureFlags::is_enabled( $feature );
}
```

### Test

```bash
wp eval "var_dump( wb_gam_has_badge( 1, 'century_club' ) );"
wp eval "var_dump( wb_gam_get_user_badges( 1 ) );"
wp eval "var_dump( wb_gam_get_user_streak( 1 ) );"
wp eval "var_dump( wb_gam_get_leaderboard( 'week', 5 ) );"
wp eval "var_dump( wb_gam_is_feature_enabled( 'kudos' ) );"
```

### Git Commit

```
feat: add missing public API functions for badges, streaks, leaderboard

Adds wb_gam_has_badge(), wb_gam_get_user_badges(), wb_gam_get_user_streak(),
wb_gam_get_leaderboard(), and wb_gam_is_feature_enabled() to the developer-
facing extension API.
```

---

## Task 11: Action ID Collision Guard

### Problem

If two plugins register the same action ID, the second silently overwrites the first. This causes unpredictable behavior and lost point awards.

### Files

#### MODIFY: `src/Engine/Registry.php`

In `register_action()`, add a duplicate check after line 108 (after the empty check, before the assignment):

Replace:

```php
if ( empty( $action['id'] ) || empty( $action['hook'] ) || ! is_callable( $action['user_callback'] ) ) {
    _doing_it_wrong( __METHOD__, 'WB Gamification: action must have id, hook, and user_callback.', '0.1.0' );
    return;
}

self::$actions[ $action['id'] ] = $action;
```

With:

```php
if ( empty( $action['id'] ) || empty( $action['hook'] ) || ! is_callable( $action['user_callback'] ) ) {
    _doing_it_wrong( __METHOD__, 'WB Gamification: action must have id, hook, and user_callback.', '0.1.0' );
    return;
}

if ( isset( self::$actions[ $action['id'] ] ) ) {
    _doing_it_wrong(
        __METHOD__,
        sprintf(
            /* translators: 1: action ID, 2: plugin that first registered the action */
            'WB Gamification: Action ID "%1$s" is already registered by "%2$s". Use a unique vendor-prefixed ID.',
            $action['id'],
            self::$actions[ $action['id'] ]['plugin'] ?? 'unknown'
        ),
        '0.6.0'
    );
    return;
}

self::$actions[ $action['id'] ] = $action;
```

### Test

```bash
wp eval "
add_action('wb_gamification_register', function() {
    wb_gamification_register_action(['id' => 'test_dup', 'hook' => 'init', 'label' => 'Test', 'user_callback' => fn() => 0, 'default_points' => 1]);
    wb_gamification_register_action(['id' => 'test_dup', 'hook' => 'init', 'label' => 'Test 2', 'user_callback' => fn() => 0, 'default_points' => 2]);
});
"
# Should trigger a _doing_it_wrong notice for the duplicate
```

### Git Commit

```
fix: detect and reject duplicate action ID registrations

Registry::register_action() now fires _doing_it_wrong() and returns
early if an action ID is already registered, preventing silent overwrites
from conflicting plugins.
```

---

## Task 12: RedemptionEngine Race Condition Fix

### Problem

Two race conditions in `RedemptionEngine::redeem()`:

1. **TOCTOU on balance:** `get_total()` reads balance, then `debit()` writes. Between the read and write, another request could debit the same points, resulting in a negative balance.
2. **Wrong cache key:** Line 192 deletes `wb_gam_points_` but the actual cache key used by `PointsEngine::get_total()` is `wb_gam_total_`.
3. **Order of operations:** Stock is decremented before points are debited. If the debit fails, stock is lost.

### Files

#### MODIFY: `src/Engine/RedemptionEngine.php`

Replace the `redeem()` method (lines 86-210):

```php
/**
 * Redeem an item for a user.
 *
 * Uses atomic SQL to prevent TOCTOU race conditions on balance checks.
 * Order: validate -> debit points (atomic) -> decrement stock -> create record.
 *
 * @param int $user_id  User redeeming.
 * @param int $item_id  Redemption item ID.
 * @return array{ success: bool, redemption_id: int|null, coupon_code: string|null, error: string|null }
 */
public static function redeem( int $user_id, int $item_id ): array {
    global $wpdb;

    $item = self::get_item( $item_id );

    if ( ! $item || ! $item['is_active'] ) {
        return array(
            'success'       => false,
            'error'         => __( 'Reward item not found or inactive.', 'wb-gamification' ),
            'redemption_id' => null,
            'coupon_code'   => null,
        );
    }

    $cost = (int) $item['points_cost'];

    // Check stock (non-atomic pre-check — real check is the atomic UPDATE below).
    if ( null !== $item['stock'] && (int) $item['stock'] <= 0 ) {
        return array(
            'success'       => false,
            'error'         => __( 'This reward is out of stock.', 'wb-gamification' ),
            'redemption_id' => null,
            'coupon_code'   => null,
        );
    }

    // Step 1: Atomic balance debit — prevents TOCTOU race condition.
    // This single query checks balance >= cost AND debits in one atomic operation.
    $event = new Event(
        array(
            'action_id' => 'points_redeemed',
            'user_id'   => $user_id,
            'metadata'  => array(
                'item_id'     => $item_id,
                'points_cost' => -$cost,
            ),
        )
    );

    // Use a transaction to atomically check + debit.
    $wpdb->query( 'START TRANSACTION' );

    // Lock the user's balance rows and compute total.
    $balance = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(points), 0) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d FOR UPDATE",
            $user_id
        )
    );

    if ( $balance < $cost ) {
        $wpdb->query( 'ROLLBACK' );
        return array(
            'success'       => false,
            'error'         => sprintf(
                /* translators: 1: cost, 2: current balance */
                __( 'Insufficient points. This reward costs %1$d pts; you have %2$d.', 'wb-gamification' ),
                $cost,
                $balance
            ),
            'redemption_id' => null,
            'coupon_code'   => null,
        );
    }

    // Debit points within the transaction.
    PointsEngine::debit( $user_id, $cost, 'redemption', $event->event_id );

    $wpdb->query( 'COMMIT' );

    // Step 2: Atomic stock decrement (after points are secured).
    if ( null !== $item['stock'] ) {
        $decremented = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}wb_gam_redemption_items SET stock = stock - 1 WHERE id = %d AND stock > 0",
                $item_id
            )
        );
        if ( ! $decremented ) {
            // Stock ran out — refund the points.
            PointsEngine::insert_point_row(
                new Event( array( 'action_id' => 'redemption_refund', 'user_id' => $user_id ) ),
                $cost
            );
            wp_cache_delete( "wb_gam_total_{$user_id}", self::CACHE_GROUP );
            return array(
                'success'       => false,
                'error'         => __( 'This reward is out of stock.', 'wb-gamification' ),
                'redemption_id' => null,
                'coupon_code'   => null,
            );
        }
    }

    // Step 3: Create redemption record.
    $wpdb->insert(
        $wpdb->prefix . 'wb_gam_redemptions',
        array(
            'user_id'     => $user_id,
            'item_id'     => $item_id,
            'points_cost' => $cost,
            'status'      => 'pending',
        ),
        array( '%d', '%d', '%d', '%s' )
    );
    $redemption_id = (int) $wpdb->insert_id;

    // Step 4: Fulfillment.
    $coupon_code = null;
    $config      = json_decode( $item['reward_config'] ?? '{}', true ) ?: array();

    if ( in_array( $item['reward_type'], array( 'discount_pct', 'discount_fixed' ), true ) ) {
        $coupon_code = self::create_woo_coupon( $user_id, $item, $config, $redemption_id );
        $wpdb->update(
            $wpdb->prefix . 'wb_gam_redemptions',
            array(
                'status'      => $coupon_code ? 'fulfilled' : 'failed',
                'coupon_code' => $coupon_code,
            ),
            array( 'id' => $redemption_id )
        );
    } else {
        $wpdb->update( $wpdb->prefix . 'wb_gam_redemptions', array( 'status' => 'pending_fulfillment' ), array( 'id' => $redemption_id ) );
    }

    // Bust the correct cache key.
    wp_cache_delete( "wb_gam_total_{$user_id}", self::CACHE_GROUP );

    /**
     * Fires after a redemption is created.
     *
     * @param int         $redemption_id Redemption record ID.
     * @param int         $user_id       User who redeemed.
     * @param array       $item          Reward item data.
     * @param string|null $coupon_code   WooCommerce coupon code, or null.
     */
    do_action( 'wb_gamification_points_redeemed', $redemption_id, $user_id, $item, $coupon_code );

    return array(
        'success'       => true,
        'redemption_id' => $redemption_id,
        'coupon_code'   => $coupon_code,
        'error'         => null,
    );
}
```

### Test

```bash
# Create a test reward item
wp eval "
global \$wpdb;
\$wpdb->insert(\$wpdb->prefix . 'wb_gam_redemption_items', [
    'title' => 'Test Reward', 'points_cost' => 10, 'reward_type' => 'custom', 'stock' => 1, 'is_active' => 1
]);
echo 'Item ID: ' . \$wpdb->insert_id;
"

# Award enough points
wp wb-gamification points award --user=1 --points=100 --message="Test"

# Redeem
wp eval "var_dump( WBGam\Engine\RedemptionEngine::redeem(1, 1) );"

# Try to redeem again with insufficient points — should fail atomically
wp eval "var_dump( WBGam\Engine\RedemptionEngine::redeem(1, 1) );"
```

### Git Commit

```
fix: resolve TOCTOU race condition in RedemptionEngine

Uses SELECT FOR UPDATE in a transaction to atomically check balance
and debit points. Fixes order of operations (debit first, then stock
decrement) with automatic refund if stock is depleted. Fixes wrong
cache key (wb_gam_points_ -> wb_gam_total_).
```

---

## Task 13: Settings Page -- Feature Toggles Tab

### Problem

The feature flags from Task 1 need an admin UI so site administrators can enable/disable optional engines without touching code.

### Files

#### MODIFY: `src/Admin/SettingsPage.php`

**1. Add the "Features" tab to the tabs array (around line 333):**

Replace:

```php
$tabs = array(
    'dashboard'  => __( 'Dashboard', 'wb-gamification' ),
    'points'     => __( 'Points', 'wb-gamification' ),
    'levels'     => __( 'Levels', 'wb-gamification' ),
    'automation' => __( 'Automation', 'wb-gamification' ),
);
```

With:

```php
$tabs = array(
    'dashboard'  => __( 'Dashboard', 'wb-gamification' ),
    'points'     => __( 'Points', 'wb-gamification' ),
    'levels'     => __( 'Levels', 'wb-gamification' ),
    'features'   => __( 'Features', 'wb-gamification' ),
    'automation' => __( 'Automation', 'wb-gamification' ),
);
```

**2. Add the match case (around line 349):**

Replace:

```php
match ( $tab ) {
    'levels'     => self::render_levels_tab(),
    'automation' => self::render_automation_tab(),
    'points'     => self::render_points_tab(),
    default      => self::render_dashboard_tab(),
};
```

With:

```php
match ( $tab ) {
    'levels'     => self::render_levels_tab(),
    'automation' => self::render_automation_tab(),
    'features'   => self::render_features_tab(),
    'points'     => self::render_points_tab(),
    default      => self::render_dashboard_tab(),
};
```

**3. Add the form handler for the features tab in `handle_save()` (around line 73):**

After:

```php
if ( 'points' === $tab ) {
    self::save_points_settings();
} elseif ( 'automation' === $tab ) {
    self::save_automation_settings();
}
```

Add:

```php
if ( 'points' === $tab ) {
    self::save_points_settings();
} elseif ( 'automation' === $tab ) {
    self::save_automation_settings();
} elseif ( 'features' === $tab ) {
    self::save_features_settings();
}
```

**4. Add the render and save methods:**

Add these two new methods to the class:

```php
/**
 * Render the Features settings tab.
 */
private static function render_features_tab(): void {
    $flags  = \WBGam\Engine\FeatureFlags::all();
    $labels = \WBGam\Engine\FeatureFlags::labels();
    ?>
    <div class="wb-gam-section-card">
        <h2><?php esc_html_e( 'Optional Features', 'wb-gamification' ); ?></h2>
        <p class="description" style="margin-bottom:1.5rem;">
            <?php esc_html_e( 'Enable or disable optional gamification engines. Disabled engines do not load at all, reducing memory and query overhead.', 'wb-gamification' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wb-gamification&tab=features' ) ); ?>">
            <?php wp_nonce_field( 'wb_gam_save_settings', 'wb_gam_settings_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <?php foreach ( $labels as $key => $label ) : ?>
                        <tr>
                            <th scope="row">
                                <label for="wb_gam_feature_<?php echo esc_attr( $key ); ?>">
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            </th>
                            <td>
                                <label class="wb-gam-toggle">
                                    <input
                                        type="checkbox"
                                        id="wb_gam_feature_<?php echo esc_attr( $key ); ?>"
                                        name="wb_gam_features[<?php echo esc_attr( $key ); ?>]"
                                        value="1"
                                        <?php checked( ! empty( $flags[ $key ] ) ); ?>
                                    />
                                    <span class="wb-gam-toggle__slider"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button( __( 'Save Features', 'wb-gamification' ) ); ?>
        </form>
    </div>
    <?php
}

/**
 * Persist feature flag settings from the Features tab form.
 */
private static function save_features_settings(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_save().
    $submitted = isset( $_POST['wb_gam_features'] ) ? (array) $_POST['wb_gam_features'] : array();

    $defaults = \WBGam\Engine\FeatureFlags::defaults();
    $new_flags = array();

    foreach ( array_keys( $defaults ) as $key ) {
        // Checkbox: present = enabled, absent = disabled.
        $new_flags[ $key ] = isset( $submitted[ $key ] );
    }

    \WBGam\Engine\FeatureFlags::update( $new_flags );

    wp_safe_redirect(
        add_query_arg(
            array(
                'page'  => 'wb-gamification',
                'tab'   => 'features',
                'saved' => '1',
            ),
            admin_url( 'admin.php' )
        )
    );
    exit;
}
```

**5. Add toggle CSS to `assets/css/admin.css`:**

Append to the end of `assets/css/admin.css`:

```css
/* Feature toggle switches */
.wb-gam-toggle {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    cursor: pointer;
}
.wb-gam-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.wb-gam-toggle__slider {
    position: absolute;
    inset: 0;
    background-color: #ccc;
    border-radius: 24px;
    transition: background-color 0.2s;
}
.wb-gam-toggle__slider::before {
    content: "";
    position: absolute;
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    border-radius: 50%;
    transition: transform 0.2s;
}
.wb-gam-toggle input:checked + .wb-gam-toggle__slider {
    background-color: #6c63ff;
}
.wb-gam-toggle input:checked + .wb-gam-toggle__slider::before {
    transform: translateX(20px);
}
.wb-gam-toggle input:focus-visible + .wb-gam-toggle__slider {
    outline: 2px solid #2271b1;
    outline-offset: 2px;
}

@media (max-width: 640px) {
    .wb-gam-toggle {
        width: 40px;
        height: 22px;
    }
    .wb-gam-toggle__slider::before {
        height: 16px;
        width: 16px;
    }
    .wb-gam-toggle input:checked + .wb-gam-toggle__slider::before {
        transform: translateX(18px);
    }
}
```

### Test

```bash
# Navigate to admin: /wp-admin/admin.php?page=wb-gamification&tab=features
# Verify all toggles render with correct default states
# Toggle some features off, save, verify they stay off on page reload
# Verify disabled engines no longer boot (check with Query Monitor)
```

### Git Commit

```
feat: add Features tab to admin settings for engine toggle switches

Site administrators can now enable/disable optional gamification engines
via the admin UI. Disabled engines do not load, reducing memory usage
and query overhead on every request.
```

---

## Implementation Order

Tasks are ordered by dependency and impact:

| Order | Task | Depends On | Estimated Effort |
|-------|------|------------|-----------------|
| 1 | Task 1: Feature Flags | None | 1 hour |
| 2 | Task 2: Dead Code Removal | None | 30 min |
| 3 | Task 11: Action ID Collision Guard | None | 15 min |
| 4 | Task 13: Settings Page Features Tab | Task 1 | 45 min |
| 5 | Task 6: Hot-Path Query Caching | None | 45 min |
| 6 | Task 9: PersonalRecordEngine Single Query | None | 20 min |
| 7 | Task 4: Async Award Pipeline | Task 1 | 1.5 hours |
| 8 | Task 5: Leaderboard Snapshot Cache | None | 1 hour |
| 9 | Task 12: RedemptionEngine Race Condition | None | 30 min |
| 10 | Task 3: Conditional Asset Loading | None | 45 min |
| 11 | Task 7: Events Table Pruning | None | 30 min |
| 12 | Task 8: Cron Consolidation | Task 1 | 30 min |
| 13 | Task 10: Complete Public API | Task 1 | 20 min |

**Total estimated effort:** ~8 hours

---

## Expected Impact at 100K Members

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Engines booting per request | 22 | 13 (core only) | -41% |
| DB queries per point award | 12-15 | 4-5 | -65% |
| Leaderboard query time (100K users) | 2-5s | <50ms (snapshot) | -99% |
| Frontend CSS loaded on non-gam pages | 26KB | 0KB | -100% |
| Events table growth | Unbounded | Pruned at 12 months | Bounded |
| Monday cron thundering herd | 5 simultaneous | 2 Monday + 1 Wednesday | Spread |
| Redemption race conditions | 2 TOCTOU bugs | 0 (atomic) | Fixed |

---

## Version Bump

After all tasks are complete, bump version in two places:

1. `wb-gamification.php` header: `Version: 0.6.0`
2. `wb-gamification.php` constant: `define( 'WB_GAM_VERSION', '0.6.0' );`

Update `CLAUDE.md` Recent Changes table:

```
| **0.6.0** | Feature flags, async award pipeline, leaderboard snapshots, hot-path caching, events pruning, cron consolidation, dead code removal, redemption race fix |
```
