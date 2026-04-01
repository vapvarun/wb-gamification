# Architecture

## Design Principle

WB Gamification is event-sourced. The only write path is:

> **Event in → Rules evaluate → Effects out**

All gamification state (points, badges, levels, streaks) is derived from the immutable `wb_gam_events` table. BuddyPress display, WooCommerce triggers, and the REST API are consumers of this pipeline — they never write state directly.

## Boot Sequence

All hooks register on `plugins_loaded`. The priority order ensures each layer is ready before the next depends on it.

| Priority | Component | What It Does |
|----------|-----------|-------------|
| 0 | `WB_Gamification::instance()` | Registers all hooks, constants, autoloader |
| 1 | `DbUpgrader::init()` | Runs schema migrations if `wb_gam_db_version` is behind |
| 5 | `ManifestLoader::scan()` | Auto-discovers `wb-gamification.php` manifests in every plugin directory |
| 6 | `Registry::init()` | Registers discovered actions and badge triggers; fires `wb_gamification_register` |
| 8 | `Engine::init()`, `WPHooks`, `BPHooks` | Boots the event pipeline; registers WordPress and BuddyPress trigger hooks |
| 10 | `BadgeEngine`, `ChallengeEngine`, `StreakEngine`, etc. | Secondary engines subscribe to `wb_gamification_points_awarded` |
| 12 | `NotificationBridge` | Subscribes to badge/level/streak hooks to dispatch BP notifications |
| 15 | `Privacy` | Registers GDPR erasure and export handlers |
| 20 | `SiteFirstBadgeEngine` | Subscribes last so site-first-action checks see all other state |
| `bp_loaded` | `ProfileIntegration`, `DirectoryIntegration`, `ActivityIntegration` | BuddyPress surface integrations, after BP is fully booted |

## PSR-4 Namespace Map

| Namespace | Directory | Purpose |
|-----------|-----------|---------|
| `WBGam\Engine\` | `src/Engine/` | Core engines, event bus, DB, cron |
| `WBGam\API\` | `src/API/` | REST controllers (16 controllers) |
| `WBGam\Admin\` | `src/Admin/` | Admin pages, setup wizard, analytics |
| `WBGam\BuddyPress\` | `src/BuddyPress/` | BP profile, directory, activity integrations |
| `WBGam\Integrations\` | `src/Integrations/` | WordPress, WooCommerce, and other plugin hooks |
| `WBGam\Abilities\` | `src/Abilities/` | WordPress Abilities API capability registrations |
| `WBGam\Blocks\` | `src/Blocks/` | Gutenberg block render callbacks |
| `WBGam\Extensions\` | `src/Extensions/` | Public helper functions (`functions.php`) |
| `WBGam\CLI\` | `src/CLI/` | WP-CLI command classes |

## Engine Pipeline

Every gamification event flows through `Engine::process()` in this order:

```
1. Validate (user_id > 0, action_id not empty)
2. Check action enabled  (get_option wb_gam_enabled_{action_id})
3. Rate-limit gate       (daily_cap, cooldown — PointsEngine::passes_rate_limits())
4. Enrich metadata       (apply_filters: wb_gamification_event_metadata)
5. Before-evaluate gate  (apply_filters: wb_gamification_before_evaluate — return false to abort)
6. Persist event         (INSERT wb_gam_events — UUID PK, immutable)
7. Calculate points      (admin option → wb_gamification_points_for_action filter → RuleEngine multipliers)
8. Write points ledger   (INSERT wb_gam_points with event_id FK)
9. Fire hooks            (do_action: wb_gamification_points_awarded)
10. Side effects         (LevelEngine::maybe_level_up, StreakEngine::record_activity, WebhookDispatcher::dispatch)
```

Steps 1–5 are synchronous and write nothing. Steps 6–10 are the only database writes. This means `apply_filters('wb_gamification_before_evaluate', true, $event)` can abort processing without leaving any trace in the database.

## Async Processing

High-volume actions can use `Engine::process_async()` instead of `Engine::process()`. This performs the rate-limit check synchronously (fast, no writes) then queues the full pipeline via Action Scheduler under the `wb-gamification` group.

If Action Scheduler is unavailable (unit tests, early boot), `process_async()` falls back to synchronous processing automatically.

## Manifest Auto-Discovery

At priority 5, `ManifestLoader::scan()` runs two passes:

1. **First-party:** loads every `*.php` file in `wb-gamification/integrations/`
2. **Third-party:** scans `WP_PLUGIN_DIR/*/wb-gamification.php` — any installed plugin can declare triggers by dropping this file

Manifest files return a plain PHP array. They are read-only configuration — no dependency on WB Gamification being loaded when the file is included. If the gamification plugin is not installed, the file is simply ignored.

## Constants

```php
WB_GAM_VERSION   // '1.0.0'
WB_GAM_FILE      // absolute path to wb-gamification.php
WB_GAM_PATH      // plugin dir path (trailing slash)
WB_GAM_URL       // plugin dir URL (trailing slash)
WB_GAM_BASENAME  // 'wb-gamification/wb-gamification.php'
```

## Database Version Tracking

The current schema version is stored in `get_option('wb_gam_db_version')`. On each boot at priority 1, `DbUpgrader::init()` compares this to `WB_GAM_VERSION`. If behind, it runs the appropriate `upgrade_to_X_Y_Z()` methods in sequence. Each version gets its own upgrade method — no compound migrations.
