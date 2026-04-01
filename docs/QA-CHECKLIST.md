# WB Gamification v1.0.0 — Complete QA Checklist

> Assign each section to a different QA agent. Every item must be verified against actual code, not assumed.

---

## Section 1: Plugin Bootstrap & Architecture

### Free Plugin (`wb-gamification`)

- [ ] `wb-gamification.php` loads without fatal on PHP 8.1, 8.2, 8.3, 8.4
- [ ] Constants defined: `WB_GAM_VERSION` (1.0.0), `WB_GAM_FILE`, `WB_GAM_PATH`, `WB_GAM_URL`, `WB_GAM_BASENAME`
- [ ] Composer autoloader loads (`vendor/autoload.php`)
- [ ] PSR-4 namespace `WBGam\` maps to `src/`
- [ ] Boot sequence order: DbUpgrader(1) → ManifestLoader(5) → Registry(6) → Engine(8) → AsyncEvaluator(8) → FeatureFlags.boot_engines(10) → BuddyPress(bp_loaded)
- [ ] Admin pages only load inside `is_admin()` block
- [ ] EDD SDK loads with `file_exists()` guard
- [ ] EDD auto-activate guarded by `file_exists()` AND `$item_id > 0`

### Pro Plugin (`wb-gamification-pro`)

- [ ] `wb-gamification-pro.php` loads without fatal when free plugin is active
- [ ] Shows admin notice when free plugin is NOT active
- [ ] Defines `WB_GAM_PRO_VERSION` constant (1.0.0)
- [ ] PSR-4 namespace `WBGamPro\` maps to `includes/`
- [ ] Hooks into `wb_gam_engines_booted` action from free plugin
- [ ] `Plugin::init()` calls `LicenseManager::init()`
- [ ] `Plugin::init()` calls `boot_engines()` and `load_integrations()`

---

## Section 2: Feature Flags & Engine Loading

- [ ] `FeatureFlags.php` exists with `CORE_ENGINES` and `PRO_ENGINES` constants
- [ ] `is_pro_active()` checks `defined('WB_GAM_PRO_VERSION')`
- [ ] Core engines boot WITHOUT pro plugin: BadgeEngine, ChallengeEngine, KudosEngine, PersonalRecordEngine, Privacy, NotificationBridge, LogPruner, RankAutomation, CredentialExpiryEngine
- [ ] Pro engines DO NOT boot without pro plugin: CohortEngine, WeeklyEmailEngine, LeaderboardNudge, StatusRetentionEngine, CosmeticEngine, CommunityChallengeEngine, SiteFirstBadgeEngine, TenureBadgeEngine, BadgeSharePage, RecapEngine, RedemptionEngine
- [ ] Pro engines boot when pro IS active and feature flag is enabled
- [ ] `wb_gam_engines_booted` action fires after all engines
- [ ] Feature flags stored in single option `wb_gam_features`
- [ ] `wb_gam_is_feature_enabled()` public function works

---

## Section 3: Dead Code Verification

- [ ] `MissionMode.php` does NOT exist in src/
- [ ] No references to `MissionMode` in any PHP file
- [ ] `AbilitiesRegistrar.php` does NOT exist (old one — `AbilitiesRegistration.php` is the new one)
- [ ] `wb_gam_partners` table is dropped in `DbUpgrader::upgrade_to_1_0_0()`
- [ ] No `CREATE TABLE` for `wb_gam_partners` in `Installer.php`
- [ ] No references to partners table in `Privacy.php`

---

## Section 4: Integrations

### Core Integrations (in `integrations/`)

- [ ] `wordpress.php` — 8-10 triggers, proper hook signatures
- [ ] `buddypress.php` — 8-9 triggers, `bp_friends_accepted` uses correct arg order `($friendship_id, $initiator_id, $friend_id)`
- [ ] `woocommerce.php` — 4 triggers, guards on `class_exists('WooCommerce')`
- [ ] `learndash.php` — 5 triggers, guards on `learndash_get_course_id()`
- [ ] `bbpress.php` — 3 triggers, guards on `bbpress()`

### Contrib Integrations (in `integrations/contrib/`)

- [ ] `memberpress.php` present in contrib/
- [ ] `lifterlms.php` present in contrib/
- [ ] `the-events-calendar.php` present in contrib/ with FIXED `tec_ticket_purchased` hook (NOT `event_tickets_checkin`)
- [ ] `givewp.php` present in contrib/
- [ ] `ManifestLoader::scan()` does NOT load contrib/ files (only `integrations/*.php`)

### Pro Integrations (in pro plugin `integrations/`)

- [ ] Same 4 contrib files present in Pro plugin's `integrations/`
- [ ] Pro `Plugin::init()` loads them via `wb_gamification_register_action()`

---

## Section 5: REST API — 100% Coverage

### Controllers (16 total)

| Controller | GET | POST | PUT | DELETE | Schema | Perms |
|------------|:---:|:----:|:---:|:------:|:------:|:-----:|
| MembersController | [ ] /members/{id}, /members/{id}/points, /members/{id}/badges, /members/{id}/streak, /members/{id}/events, /members/{id}/level, /members/me/toasts | | | | [ ] | [ ] |
| PointsController | | [ ] /points/award | | [ ] /points/{id} | [ ] | [ ] |
| BadgesController | [ ] /badges, /badges/{id} | [ ] /badges/{id}/award | [ ] /badges/{id} | [ ] /badges/{id} | [ ] | [ ] |
| LeaderboardController | [ ] /leaderboard, /leaderboard/group/{id}, /leaderboard/me | | | | [ ] | [ ] |
| ChallengesController | [ ] /challenges, /challenges/{id} | [ ] /challenges, /challenges/{id}/complete | [ ] /challenges/{id} | [ ] /challenges/{id} | [ ] | [ ] |
| ActionsController | [ ] /actions, /actions/{id} | | | | [ ] | [ ] |
| EventsController | | [ ] /events | | | [ ] | [ ] |
| KudosController | [ ] /kudos, /kudos/me | [ ] /kudos | | | [ ] | [ ] |
| WebhooksController | [ ] /webhooks, /webhooks/{id} | [ ] /webhooks | [ ] /webhooks/{id} | [ ] /webhooks/{id} | [ ] | [ ] |
| RulesController | [ ] /rules, /rules/{id} | [ ] /rules | [ ] /rules/{id} | [ ] /rules/{id} | [ ] | [ ] |
| RedemptionController | [ ] /redemptions/items, /redemptions/items/{id}, /redemptions/me | [ ] /redemptions/items, /redemptions | [ ] /redemptions/items/{id} | [ ] /redemptions/items/{id} | [ ] | [ ] |
| LevelsController | [ ] /levels | | | | [ ] | [ ] |
| CapabilitiesController | [ ] /capabilities | | | | [ ] | [ ] |
| AbilitiesRegistration | [ ] /abilities | | | | [ ] | [ ] |
| RecapController | [ ] /members/{id}/recap | | | | [ ] | [ ] |
| CredentialController | [ ] /badges/{id}/credential/{user_id} | | | | [ ] | [ ] |
| BadgeShareController | [ ] /badges/{id}/share/{user_id} | | | | [ ] | [ ] |

### API Key Authentication

- [ ] `ApiKeyAuth.php` exists
- [ ] Authenticates via `X-WB-Gam-Key` header
- [ ] Authenticates via `?api_key` query param
- [ ] Sets `$GLOBALS['wb_gam_remote_site_id']` on successful auth
- [ ] Injects `_site_id` into event metadata via filter
- [ ] CORS headers sent when API key auth is active
- [ ] `create_key()`, `revoke_key()`, `delete_key()`, `get_keys()` all work

### WP Abilities API

- [ ] `AbilitiesRegistration.php` exists
- [ ] 12 abilities registered (7 read, 3 write, 2 admin)
- [ ] Fallback REST endpoint `GET /abilities` works on any WP version
- [ ] Calls `wp_register_ability()` on WP 6.9+ (if function exists)

---

## Section 6: Core Engine Flow

### Points Award Pipeline

- [ ] Event created → `Engine::process()` called
- [ ] Rate limit checks: cooldown, daily cap
- [ ] `wb_gamification_before_evaluate` filter can block event
- [ ] Event persisted to `wb_gam_events` table with `site_id` column
- [ ] Points calculated: base + `wb_gamification_points_for_action` filter
- [ ] `RuleEngine::apply_multipliers()` applies rules (cached)
- [ ] `PointsEngine::insert_point_row()` writes to ledger
- [ ] `LevelEngine::maybe_level_up()` fires (levels cached)
- [ ] `StreakEngine::record_activity()` fires
- [ ] `WebhookDispatcher::dispatch()` fires
- [ ] `wb_gamification_points_awarded` action fires with 3 args
- [ ] BadgeEngine evaluates at priority 10 (sync, cached rules)
- [ ] ChallengeEngine evaluates at priority 15 (sync)
- [ ] AsyncEvaluator collects at priority 50
- [ ] NotificationBridge queues toast at priority 99
- [ ] AsyncEvaluator flushes on shutdown → single AS job
- [ ] PersonalRecordEngine runs async in batch

### Async Pipeline

- [ ] `AsyncEvaluator.php` exists
- [ ] `init()` registers AS hook `wb_gam_async_evaluate`
- [ ] `enqueue()` hooked at priority 50 on `wb_gamification_points_awarded`
- [ ] `flush_queue()` fires on `shutdown`
- [ ] `process_batch()` calls all registered evaluators
- [ ] PersonalRecordEngine registered via `AsyncEvaluator::register()`

### Leaderboard Cache

- [ ] `get_leaderboard()` uses object cache (2-min TTL)
- [ ] `get_user_rank()` uses object cache (2-min TTL)
- [ ] `cache_users()` called before avatar loop
- [ ] Snapshot cron `wb_gam_leaderboard_snapshot` runs every 5 minutes
- [ ] `write_snapshot()` writes to `wb_gam_leaderboard_cache` table
- [ ] Fresh requests read from snapshot when available

### Hot-Path Caching

- [ ] BadgeEngine: badge rules cached (5-min TTL)
- [ ] RuleEngine: multiplier rules cached (5-min TTL)
- [ ] LevelEngine: static + object cache (1-hr TTL)
- [ ] Engine: static cache for `is_action_enabled()` per request
- [ ] TenureBadgeEngine: `wb_gam_tenure_seeded` option skips boot queries

---

## Section 7: Database Schema

### Tables (20 in free, verified via Installer.php)

- [ ] `wb_gam_events` — with `site_id` column and `idx_site_id` index
- [ ] `wb_gam_points` — with composite index `idx_user_action_created`
- [ ] `wb_gam_user_badges` — with `expires_at` column
- [ ] `wb_gam_badge_defs` — with `validity_days`, `closes_at`, `max_earners`
- [ ] `wb_gam_levels`
- [ ] `wb_gam_streaks`
- [ ] `wb_gam_challenges`
- [ ] `wb_gam_challenge_log` — with UNIQUE on `(user_id, challenge_id)`
- [ ] `wb_gam_kudos`
- [ ] `wb_gam_member_prefs`
- [ ] `wb_gam_rules`
- [ ] `wb_gam_webhooks`
- [ ] `wb_gam_community_challenges`
- [ ] `wb_gam_community_challenge_contributions`
- [ ] `wb_gam_cohort_members`
- [ ] `wb_gam_redemption_items`
- [ ] `wb_gam_redemptions`
- [ ] `wb_gam_cosmetics`
- [ ] `wb_gam_user_cosmetics`
- [ ] `wb_gam_leaderboard_cache`
- [ ] `wb_gam_partners` — should NOT exist (dropped in migration)

### Migrations

- [ ] `upgrade_to_0_1_0()` exists
- [ ] `upgrade_to_0_2_0()` exists
- [ ] `upgrade_to_0_3_0()` exists
- [ ] `upgrade_to_0_5_0()` exists
- [ ] `upgrade_to_1_0_0()` — drops partners table + adds site_id to events

---

## Section 8: Admin UI

### Admin Pages (7 total)

- [ ] Dashboard (AnalyticsDashboard) — KPI cards, activity feed, quick actions
- [ ] Settings (SettingsPage) — Points/Levels tabs, AJAX save, toast feedback
- [ ] Badges (BadgeAdminPage) — card grid, icon picker, create/edit
- [ ] Challenges (ChallengeManagerPage) — create/edit/list, smart defaults
- [ ] Manual Awards (ManualAwardPage) — form + recent history
- [ ] API Keys (ApiKeysPage) — create/view/revoke/delete keys
- [ ] Setup Wizard (SetupWizard) — onboarding flow

### Premium CSS Design System

- [ ] `assets/css/admin-premium.css` exists (1500+ lines)
- [ ] RTL support via logical CSS properties
- [ ] Components: cards, toggles, pills, toasts, tables, forms, buttons, tabs, grids, modals, empty states, progress bars, skeletons, avatars, dropdowns
- [ ] Enqueued only on wb-gamification admin pages

### Admin JavaScript

- [ ] `assets/js/admin-settings.js` — AJAX save + tab switching + toast helper
- [ ] `assets/js/admin-badge.js` — wp.media integration for badge icons

---

## Section 9: Frontend

### Blocks (10)

- [ ] `leaderboard`, `member-points`, `badge-showcase`, `level-progress`, `challenges`, `streak`, `top-members`, `kudos-feed`, `year-recap`, `points-history`
- [ ] Each has `block.json` in `blocks/{slug}/`
- [ ] Registered dynamically via `register_block_type()`

### Shortcodes (10)

- [ ] `[wb_gam_leaderboard]`, `[wb_gam_member_points]`, `[wb_gam_badge_showcase]`, `[wb_gam_level_progress]`, `[wb_gam_challenges]`, `[wb_gam_streak]`, `[wb_gam_top_members]`, `[wb_gam_kudos_feed]`, `[wb_gam_year_recap]`, `[wb_gam_points_history]`
- [ ] Each enqueues `wb-gamification` CSS when rendered

### Toast Notifications

- [ ] `assets/js/toast.js` exists
- [ ] Enqueued for logged-in users only
- [ ] Polls `GET /members/me/toasts` every 30s + on tab focus
- [ ] Shows slide-in toast with auto-dismiss (4s)
- [ ] NotificationBridge stores toasts in user transient

### Conditional Asset Loading

- [ ] `frontend.css` is REGISTERED (not enqueued) globally
- [ ] CSS enqueued only when shortcode renders or NotificationBridge renders
- [ ] Blocks pull CSS via block.json dependency

---

## Section 10: Public PHP API

### Functions in `src/Extensions/functions.php` (12 total)

- [ ] `wb_gamification_register_action( array $args ): void`
- [ ] `wb_gamification_register_badge_trigger( array $args ): void`
- [ ] `wb_gamification_register_challenge_type( array $args ): void`
- [ ] `wb_gam_get_user_points( int $user_id ): int`
- [ ] `wb_gam_get_user_action_count( int $user_id, string $action_id ): int`
- [ ] `wb_gam_get_user_level( int $user_id ): ?array`
- [ ] `wb_gam_award_points( int $user_id, int $points, string $action_id, int $object_id ): bool`
- [ ] `wb_gam_has_badge( int $user_id, string $badge_id ): bool`
- [ ] `wb_gam_get_user_badges( int $user_id ): array`
- [ ] `wb_gam_get_user_streak( int $user_id ): array`
- [ ] `wb_gam_get_leaderboard( string $period, int $limit ): array` — period accepts 'all'|'week'|'month'|'day'
- [ ] `wb_gam_is_feature_enabled( string $feature ): bool`

---

## Section 11: Security & Data Integrity

### Action ID Collision Guard

- [ ] `Registry::register_action()` detects duplicate IDs
- [ ] Fires `_doing_it_wrong()` on collision
- [ ] Returns early (does not overwrite)
- [ ] ManifestLoader passes `plugin` key for attribution

### RedemptionEngine

- [ ] Uses `START TRANSACTION` + `SELECT FOR UPDATE` for atomic balance check
- [ ] Debit happens BEFORE stock decrement
- [ ] Cache key is `wb_gam_total_{user_id}` (not `wb_gam_points_`)
- [ ] `ROLLBACK` on insufficient balance or out-of-stock

### ChallengeEngine

- [ ] `upsert_log()` uses `INSERT ON DUPLICATE KEY UPDATE` (not REPLACE)
- [ ] `process_team()` checks completion BEFORE upsert_log()

### Badge Triggers

- [ ] `register_badge_trigger()` uses `user_callback` when available
- [ ] Falls back to `get_current_user_id()` only if no callback

### PointsEngine

- [ ] Cooldown comparison uses consistent timezone (both site timezone)

### Events Table

- [ ] `site_id` column exists for cross-site attribution
- [ ] `LogPruner` prunes events with configurable retention (default 12 months)

---

## Section 12: Build & Distribution

### Grunt Pipeline

- [ ] `Gruntfile.js` exists in free plugin
- [ ] `package.json` exists with grunt devDependencies
- [ ] `npx grunt dist` produces `dist/wb-gamification-1.0.0.zip`
- [ ] ZIP excludes: docs/, tests/, node_modules/, .git/, plans/, *.md, dev vendor packages, integrations/contrib/
- [ ] ZIP includes: src/, assets/, blocks/, integrations/ (core only), vendor/edd-sdk/, languages/

### EDD SDK

- [ ] `vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php` exists in free plugin
- [ ] `vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php` exists in pro plugin
- [ ] Free: preset key registration with `file_exists()` guard
- [ ] Pro: `LicenseManager::init()` called, `ITEM_ID` placeholder (0)

### Version Consistency

- [ ] `wb-gamification.php` header: 1.0.0
- [ ] `WB_GAM_VERSION` constant: 1.0.0
- [ ] `package.json` version: 1.0.0
- [ ] `CLAUDE.md` version: 1.0.0
- [ ] Pro plugin version: 1.0.0
- [ ] Git tag `v1.0.0` exists

---

## Section 13: CI/CD

### Free Plugin GitHub Actions

- [ ] PHPStan Level 5: PASS
- [ ] PHP Lint 8.0: PASS
- [ ] PHP Lint 8.1: PASS
- [ ] PHP Lint 8.2: PASS
- [ ] PHP Lint 8.3: PASS
- [ ] PHP Lint 8.4: PASS

### Pro Plugin GitHub Actions

- [ ] PHPStan with free dependency: PASS
- [ ] PHP Lint 8.0-8.4: ALL PASS

### Local Quality Checks

- [ ] `vendor/bin/phpcs` with `.phpcs.xml`: 0 errors, 0 warnings
- [ ] `vendor/bin/phpstan analyse`: 0 errors

---

## Section 14: BuddyPress Integration

- [ ] `ProfileIntegration` renders rank on member profiles (hooks `bp_loaded`)
- [ ] `DirectoryIntegration` renders rank in member directory
- [ ] `BPActivity` posts to activity stream for badge_earned, level_changed, etc.
- [ ] Activity types individually toggleable via options
- [ ] Quality-weighted reaction points via filter

---

## Section 15: Cron & Background Jobs

### Scheduled Events

- [ ] `wb_gam_leaderboard_snapshot` — every 5 minutes
- [ ] `wb_gam_prune_logs` — monthly (points + events)
- [ ] `wb_gam_tenure_check` — daily (Pro: TenureBadgeEngine)
- [ ] `wb_gam_weekly_email` — weekly Mon 08:30 (Pro: WeeklyEmailEngine)
- [ ] `wb_gam_cohort_assign` — weekly Mon 00:05 (Pro: CohortEngine)
- [ ] `wb_gam_cohort_process` — weekly Sun 23:00 (Pro: CohortEngine)
- [ ] `wb_gam_status_retention` — weekly Wed 18:00 (Pro: StatusRetentionEngine)
- [ ] `wb_gam_credential_expiry` — weekly Fri 06:00 (Pro: CredentialExpiryEngine)

### Feature Flag Guards

- [ ] All Pro cron callbacks check `FeatureFlags::is_enabled()` before executing
- [ ] Cron jobs spread across the week (not all on Monday)

### Action Scheduler

- [ ] `wb_gam_process_event_async` — async event processing
- [ ] `wb_gam_async_evaluate` — batch evaluation of non-critical listeners
- [ ] `wb_gam_leaderboard_snapshot` — snapshot writing
- [ ] `wb_gam_webhook_dispatch_async` — webhook delivery

---

## Section 16: WP-CLI Commands

- [ ] `wp wb-gamification points award --user=<id> --points=<n>`
- [ ] `wp wb-gamification actions list`
- [ ] `wp wb-gamification member status --user=<id>`
- [ ] `wp wb-gamification export user --user=<id>`
- [ ] `wp wb-gamification logs prune --before=<time> --dry-run`

---

## How to Use This Checklist

1. Assign Section 1-4 to **Architecture Agent**
2. Assign Section 5 to **REST API Agent**
3. Assign Section 6 to **Engine Flow Agent**
4. Assign Section 7-8 to **Database & Admin Agent**
5. Assign Section 9-10 to **Frontend & SDK Agent**
6. Assign Section 11-12 to **Security & Build Agent**
7. Assign Section 13-16 to **CI & Integration Agent**

Each agent reads the actual code files and marks items as PASS/FAIL with file:line evidence.
