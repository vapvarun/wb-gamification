# WB Gamification v1.0.0 — Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship wb-gamification 1.0.0 as a stable, scalable, premium-quality gamification engine for 100K+ member communities. Free + Pro split. Plug-and-play for non-technical admins.

**Architecture:** Event-sourced core (free) with lazy-loaded Pro engines behind license check. Enhanced WordPress native admin UX with cards/toggles/toasts. Async pipeline for non-critical listeners. Leaderboard snapshot cache. 4 core integrations (BP, WC, LD, bbP).

**Tech Stack:** PHP 8.1+, WordPress 6.5+, Action Scheduler, WP Object Cache API, WP Interactivity API

---

## Phase 1: Core Cleanup & Scalability (Free Plugin)

### Task 1: Free/Pro Engine Split + Feature Flags

**Goal:** Separate engines into free (always loaded) and pro (loaded only when wb-gamification-pro is active). Create feature flag system for optional engines.

**Files:**
- Create: `src/Engine/FeatureFlags.php`
- Modify: `wb-gamification.php` (replace 22 individual add_action calls with categorized boot)

**Free engines (always boot):**
Engine, Registry, ManifestLoader, PointsEngine, RuleEngine, BadgeEngine, LevelEngine, StreakEngine, LeaderboardEngine, ChallengeEngine, KudosEngine, PersonalRecordEngine, Privacy, NotificationBridge, WebhookDispatcher, LogPruner, ShortcodeHandler

**Pro engines (boot only when pro active):**
CohortEngine, RecapEngine, RedemptionEngine, CosmeticEngine, WeeklyEmailEngine, LeaderboardNudge, StatusRetentionEngine, CommunityChallengeEngine, SiteFirstBadgeEngine, TenureBadgeEngine, BadgeSharePage

- [ ] Create `src/Engine/FeatureFlags.php` with `is_pro_active()`, `is_enabled()`, `get_defaults()`, `boot_engines()`
- [ ] Modify `wb-gamification.php` to use `FeatureFlags::boot_engines()` instead of individual add_action calls
- [ ] Add `wb_gam_pro_loaded` action hook for pro plugin to hook into
- [ ] Test: activate free only — pro engines should not load
- [ ] Commit

### Task 2: Dead Code Removal

**Files:**
- Delete: `src/Engine/MissionMode.php`
- Delete: `src/Abilities/AbilitiesRegistrar.php`
- Modify: `src/Engine/Installer.php` (remove `wb_gam_partners` table creation, add DROP migration)
- Modify: `wb-gamification.php` (remove MissionMode, AbilitiesRegistrar references)

- [ ] Remove MissionMode.php and all references (grep for MissionMode, mission_mode)
- [ ] Remove AbilitiesRegistrar.php and all references
- [ ] Add migration in DbUpgrader to DROP TABLE IF EXISTS wb_gam_partners
- [ ] Remove partners table from Installer.php CREATE TABLE section
- [ ] Remove 5 unused integration manifests (memberpress, lifterlms, the-events-calendar, givewp) — move to `integrations/contrib/` folder for 1.1.0
- [ ] Commit

### Task 3: Integration Cleanup

**Files:**
- Keep: `integrations/wordpress.php`, `integrations/buddypress.php`, `integrations/woocommerce.php`, `integrations/learndash.php`, `integrations/bbpress.php`
- Move to `integrations/contrib/`: memberpress.php, lifterlms.php, the-events-calendar.php, givewp.php
- Modify: `src/Engine/ManifestLoader.php` — only auto-scan `integrations/` (not contrib)

- [ ] Create `integrations/contrib/` directory
- [ ] Move 4 non-1.0.0 manifests to contrib/
- [ ] Fix buddypress.php friendship hook arg order (verify against BP source)
- [ ] Fix the-events-calendar.php duplicate hook before moving to contrib
- [ ] Commit

### Task 4: Async Award Pipeline

**Goal:** Reduce per-award DB queries from 12-15 to ~5. Move non-critical listeners to async batch.

**Files:**
- Create: `src/Engine/AsyncEvaluator.php`
- Modify: `src/Engine/PersonalRecordEngine.php` (unhook from sync, register with AsyncEvaluator)
- Modify: `src/Engine/SiteFirstBadgeEngine.php` (same)

**Keep synchronous:** BadgeEngine (instant badge feedback), ChallengeEngine (instant completion toast), KudosEngine, NotificationBridge

**Move to async:** PersonalRecordEngine, SiteFirstBadgeEngine (Pro: CommunityChallengeEngine, TenureBadgeEngine)

- [ ] Create AsyncEvaluator that collects events in static array, enqueues single AS job on shutdown
- [ ] Unhook PersonalRecordEngine from `wb_gamification_points_awarded`, register with AsyncEvaluator
- [ ] Unhook SiteFirstBadgeEngine from `wb_gamification_points_awarded`, register with AsyncEvaluator
- [ ] Test: award points → badge appears instantly, personal record check happens ~30s later
- [ ] Commit

### Task 5: Leaderboard Snapshot Cache

**Goal:** Eliminate 2-5 second leaderboard query. Support 100K users.

**Files:**
- Modify: `src/Engine/LeaderboardEngine.php`

- [ ] Add object cache layer (2-min TTL) to `get_leaderboard()` and `get_user_rank()`
- [ ] Add `cache_users()` call before avatar loop (fix N+1)
- [ ] Create cron job (every 5 min) to write snapshots to `wb_gam_leaderboard_cache` table
- [ ] For sites with 5K+ users, read from snapshot table automatically
- [ ] Bust cache in `Engine::process()` after points awarded (generation counter pattern)
- [ ] Commit

### Task 6: Hot-Path Query Caching

**Files:**
- Modify: `src/Engine/BadgeEngine.php` (cache rules, 5-min TTL)
- Modify: `src/Engine/RuleEngine.php` (cache multipliers, 5-min TTL)
- Modify: `src/Engine/LevelEngine.php` (static + object cache, 1-hr TTL)
- Modify: `src/Engine/Engine.php` (static cache for enabled action checks)
- Modify: `src/Engine/TenureBadgeEngine.php` (option flag after first seed)

- [ ] BadgeEngine: cache badge rules query with `wp_cache_get/set`, invalidate on admin save
- [ ] RuleEngine: cache multiplier rules with `wp_cache_get/set`
- [ ] LevelEngine: static array + object cache for level thresholds
- [ ] Engine: static array for `get_option('wb_gam_enabled_' . $action_id)` per request
- [ ] TenureBadgeEngine: add `wb_gam_tenure_seeded` option, skip ensure_badges_exist() when set
- [ ] Commit

### Task 7: PersonalRecordEngine — Single Query

**Files:**
- Modify: `src/Engine/PersonalRecordEngine.php`

- [ ] Replace 3 separate SUM queries with single CASE WHEN query
- [ ] Since this runs async (Task 4), it processes in batch — even better
- [ ] Commit

### Task 8: Events Table Pruning

**Files:**
- Modify: `src/Engine/LogPruner.php`

- [ ] Add `wb_gam_events_retention_months` option (default: 12)
- [ ] Prune `wb_gam_events` alongside `wb_gam_points` using DELETE LIMIT 5000 pattern
- [ ] Add CLI command `wp wb-gamification logs prune-events --before=12months --dry-run`
- [ ] Commit

### Task 9: Conditional Asset Loading

**Files:**
- Modify: `wb-gamification.php::enqueue_assets()`
- Modify: `src/Engine/ShortcodeHandler.php`
- Modify: `src/Engine/NotificationBridge.php`

- [ ] Change `wp_enqueue_style` to `wp_register_style` in main file
- [ ] Enqueue CSS in ShortcodeHandler render callbacks and NotificationBridge render
- [ ] Blocks pull CSS via block.json dependency
- [ ] Commit

### Task 10: Complete Public API

**Files:**
- Modify: `src/Extensions/functions.php`

- [ ] Add `wb_gam_has_badge( int $user_id, string $badge_id ): bool`
- [ ] Add `wb_gam_get_user_badges( int $user_id ): array`
- [ ] Add `wb_gam_get_user_streak( int $user_id ): array`
- [ ] Add `wb_gam_get_leaderboard( string $period, int $limit ): array`
- [ ] Add `wb_gam_is_feature_enabled( string $feature ): bool`
- [ ] Commit

### Task 11: Action ID Collision Guard

**Files:**
- Modify: `src/Engine/Registry.php`

- [ ] Add duplicate detection in `register_action()` with `_doing_it_wrong()` and early return
- [ ] Add `plugin` key to action array for attribution in error messages
- [ ] Commit

### Task 12: Critical Bug Fixes

**Files:**
- Modify: `src/Engine/RedemptionEngine.php` (race condition + cache key)
- Modify: `src/API/EventsController.php` (return type)
- Modify: `src/Engine/ChallengeEngine.php` (REPLACE clears completed_at)
- Modify: `src/Engine/Registry.php` (badge triggers user resolution)
- Modify: `src/Engine/PointsEngine.php` (cooldown timezone)

- [ ] RedemptionEngine: atomic debit with SELECT FOR UPDATE, fix cache key to `wb_gam_total_`
- [ ] RedemptionEngine: reorder — debit first, then stock decrement
- [ ] EventsController: check bool return from Engine::process(), not array access
- [ ] ChallengeEngine: move completion guard before upsert_log() in process_team()
- [ ] ChallengeEngine: replace REPLACE INTO with INSERT ON DUPLICATE KEY UPDATE
- [ ] Registry: badge triggers use user_callback instead of get_current_user_id()
- [ ] PointsEngine: use gmdate() consistently for cooldown comparison
- [ ] Commit

### Task 13: Cron Consolidation

**Files:**
- Modify: `wb-gamification.php` (activation hook)
- Modify: all cron-registering engines

- [ ] Guard all cron callbacks with FeatureFlags::is_enabled() check
- [ ] Spread Monday crons: Mon 02:00 (weekly), Wed 18:00 (retention), Fri 06:00 (credentials)
- [ ] Commit

---

## Phase 2: Premium Admin UX (Free Plugin)

### Task 14: Admin CSS Design System

**Goal:** Notion-inspired admin UX — cards, toggles, whitespace, system fonts. Shared across all Wbcom plugins.

**Files:**
- Create: `assets/css/admin-premium.css`
- Modify: `wb-gamification.php::enqueue_admin_assets()`

Design tokens:
- Cards: white bg, 1px border #e0e0e0, 8px radius, 24px padding, subtle shadow on hover
- Toggles: iOS-style switch replacing all checkboxes
- Status pills: rounded, color-coded (green=active, amber=pending, red=inactive, blue=info)
- Typography: -apple-system, system-ui. 14px base. 600 weight for headings.
- Spacing: 24px section gaps, 16px inner gaps
- Toasts: fixed bottom-right, slide-in animation, auto-dismiss 4s
- RTL: all layout uses logical properties (margin-inline-start, padding-inline-end)

- [ ] Create admin-premium.css with full design system (cards, toggles, pills, toasts, tables, forms)
- [ ] Include RTL support via logical CSS properties
- [ ] Enqueue conditionally on wb-gamification admin pages only
- [ ] Commit

### Task 15: Dashboard Page (Redesign)

**Goal:** Replace current AnalyticsDashboard with a clean KPI dashboard.

**Files:**
- Modify: `src/Admin/AnalyticsDashboard.php`

Layout:
- Top row: 4 KPI cards (Total Points Awarded, Active Members, Badges Earned, Challenges Completed) with sparkline trend
- Second row: 2 cards — "Recent Activity" feed (last 10 events) + "Quick Actions" (Award Points, Create Challenge, View Leaderboard)
- Third row: "Top Members This Week" mini-leaderboard (5 rows)
- Period selector: 7d / 30d / 90d / All

- [ ] Redesign render_page() with card-based layout
- [ ] Use object-cached queries (from Task 6) for KPIs
- [ ] Add Quick Actions card with icon buttons
- [ ] Add Recent Activity feed from wb_gam_events (last 10)
- [ ] Commit

### Task 16: Settings Page (Redesign)

**Goal:** Card-based settings with toggle switches, inline descriptions, AJAX save (no page reload).

**Files:**
- Modify: `src/Admin/SettingsPage.php`
- Create: `assets/js/admin-settings.js` (AJAX save + toast)

Tabs: Points | Levels | Features | Integrations

- [ ] Points tab: card per action category (WordPress, BuddyPress, etc.), toggle + point value per action
- [ ] Levels tab: sortable level cards with name, threshold, icon preview
- [ ] Features tab: toggle grid for all optional engines (from FeatureFlags)
- [ ] Integrations tab: status cards per integration (active/inactive based on plugin detection)
- [ ] AJAX save handler with nonce + wp_send_json_success
- [ ] Toast notification on save ("Settings saved" slide-in, no reload)
- [ ] Commit

### Task 17: Badge Library (Redesign)

**Goal:** Grid of badge cards with built-in icon picker.

**Files:**
- Modify: `src/Admin/BadgeAdminPage.php`
- Create: `assets/images/badge-icons/` (ship 50-100 SVG badge icons)
- Create: `assets/js/admin-badge-picker.js`

- [ ] Badge grid: cards showing icon, name, earned count, status pill
- [ ] Create/edit modal (not separate page) with: name, description, icon picker, auto-award conditions
- [ ] Icon picker: grid of 50+ built-in SVGs + "Upload Custom" option
- [ ] Auto-award conditions: simple dropdowns — "When user reaches [X] points" / "When user earns [action] [N] times"
- [ ] Commit

### Task 18: Challenge Manager (New Admin Page)

**Goal:** Admins can create/manage challenges without code. Plug-and-play.

**Files:**
- Create: `src/Admin/ChallengeManagerPage.php`

- [ ] Register submenu page "Challenges" under Gamification
- [ ] List view: card per challenge with title, action, target, progress bar, status pill, dates
- [ ] Create form (inline or modal): Title, Action (dropdown of registered actions), Target Count, Start Date, End Date, Bonus Points
- [ ] Smart defaults: start=now, end=+7days, target=10, bonus=50
- [ ] Status management: Active/Paused/Completed with one-click toggle
- [ ] Commit

### Task 19: Toast Notification System

**Goal:** Instant feedback when users earn points, badges, or complete challenges.

**Files:**
- Modify: `src/Engine/NotificationBridge.php`
- Create: `assets/js/toast.js`
- Add toast CSS to `assets/css/frontend.css`

- [ ] NotificationBridge stores pending toasts in user transient on award
- [ ] Frontend JS polls `/wp-json/wb-gamification/v1/members/me/notifications` every 30s (or on page focus)
- [ ] Toast slides in from bottom-right: icon + message + point count, auto-dismiss 4s
- [ ] Badge earned: special toast with badge icon + "Badge Earned: [name]!"
- [ ] Challenge complete: "Challenge Complete! +[X] bonus points"
- [ ] Commit

---

## Phase 3: Pro Plugin Scaffold

### Task 20: Create wb-gamification-pro Plugin

**Files:**
- Create: new plugin directory `wb-gamification-pro/`
- Create: `wb-gamification-pro.php` (main file)
- Create: `includes/Core/Plugin.php`
- Create: `includes/Core/LicenseManager.php` (EDD SDK, same pattern as WPMediaVerse Pro)

- [ ] Scaffold plugin with PSR-4 autoload under `WBGamPro\`
- [ ] Require wb-gamification (free) as dependency — show admin notice if missing
- [ ] Hook into `wb_gam_pro_loaded` from free plugin
- [ ] Move Pro engines from free to pro: CohortEngine, RecapEngine, RedemptionEngine, CosmeticEngine, WeeklyEmailEngine, LeaderboardNudge, StatusRetentionEngine, CommunityChallengeEngine, SiteFirstBadgeEngine, TenureBadgeEngine, BadgeSharePage
- [ ] Move contrib integrations: memberpress, lifterlms, the-events-calendar, givewp
- [ ] EDD SDK with placeholder product ID (same pattern as WPMediaVerse Pro)
- [ ] Commit

### Task 21: Pro Admin Pages

**Files:**
- Create: `wb-gamification-pro/includes/Admin/RedemptionPage.php`
- Create: `wb-gamification-pro/includes/Admin/CommunityChallengePage.php`
- Create: `wb-gamification-pro/includes/Admin/CohortSettingsPage.php`

- [ ] Redemption Store admin: product-card layout, create reward items, set point costs, stock management
- [ ] Community Challenges admin: create group challenges, set targets, view progress
- [ ] Cohort Settings: enable/disable leagues, set tier names, promotion/demotion percentages
- [ ] All pages use the premium CSS design system from Task 14
- [ ] Commit

---

## Phase 4: Build & Release

### Task 22: Grunt Build Pipeline (Both Plugins)

**Files:**
- Create: `Gruntfile.js` + `package.json` for free plugin
- Create: `wb-gamification-pro/Gruntfile.js` + `wb-gamification-pro/package.json`

- [ ] RTL CSS generation, CSS/JS minification, .pot file generation
- [ ] Dist task: clean → copy (exclude docs/, tests/, node_modules/, .git/, plans/) → compress to versioned zip
- [ ] Free zip: `wb-gamification-1.0.0.zip`
- [ ] Pro zip: `wb-gamification-pro-1.0.0.zip`
- [ ] Commit

### Task 23: EDD SDK Integration

**Files:**
- Modify: `wb-gamification.php` (free preset key, same pattern as WPMediaVerse)
- Modify: `wb-gamification-pro/includes/Core/LicenseManager.php`

- [ ] Free: preset key `wbcomfree...` pattern, auto-activate on first load
- [ ] Pro: EDD SL SDK with product ID (placeholder until EDD product created)
- [ ] Commit

### Task 24: Version Bump + Final Checks

- [ ] Bump all version references to 1.0.0 (plugin headers, constants, package.json)
- [ ] Run WPCS via MCP tool on both plugins
- [ ] Generate .pot files
- [ ] Build zips to Desktop
- [ ] Commit + push + tag v1.0.0

---

## Execution Order

| Phase | Tasks | Estimated |
|-------|-------|-----------|
| Phase 1: Core Cleanup | Tasks 1-13 | 6-8 hours |
| Phase 2: Premium UX | Tasks 14-19 | 6-8 hours |
| Phase 3: Pro Scaffold | Tasks 20-21 | 3-4 hours |
| Phase 4: Build & Release | Tasks 22-24 | 2 hours |
| **Total** | **24 tasks** | **~18-22 hours** |

---

## What Ships in 1.0.0

### Free Plugin
- Points, Badges, Levels, Streaks, Leaderboard, Challenges, Kudos, PersonalRecord
- 4 integrations (BuddyPress, WooCommerce, LearnDash, bbPress)
- Premium admin UX (dashboard, settings, badge library, challenge manager)
- Toast notifications
- 10 blocks + 10 shortcodes
- Full REST API + WP-CLI
- Public SDK (7 functions)
- RTL support
- Object cache everywhere, async pipeline, leaderboard snapshots
- Scales to 100K members

### Pro Plugin
- Cohort leagues, Recap, Redemption store, Cosmetics
- Weekly emails, Leaderboard nudge, Status retention
- Community challenges, Site-first badges, Tenure badges, Badge share
- Extra integrations (MemberPress, LifterLMS, Events Calendar, GiveWP)
- Premium admin pages (redemption, community challenges, cohort settings)

### Deferred to 1.1.0+
- KudosEngine advanced features (karma, cooldown UI)
- MemberPress/LifterLMS/Events Calendar/GiveWP integrations polish

### Deferred to 1.2.0
- Dark mode
- Multi-site support
