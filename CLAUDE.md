# WB Gamification — CLAUDE.md

> Session orientation for AI assistants. Read this first.

---

## Plugin Overview

| Field | Value |
|---|---|
| **Name** | WB Gamification |
| **Version** | 1.0.0 |
| **Path** | `wp-content/plugins/wb-gamification/` |
| **Namespace** | `WBGam\` (PSR-4, maps to `src/`) |
| **PHP** | 8.1+ required |
| **Architecture** | Event-sourced, manifest auto-discovery, zero-config |
| **Part of** | Reign Stack — Wbcom's self-owned community platform |

**Design principle:** Events in → rules evaluate → effects out. The engine owns three surfaces: event normalization, rule evaluation, output. Everything else (BuddyPress display, WooCommerce triggers, mobile) is a consumer.

---

## Key Commands

```bash
# Run all tests
cd /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/plugins/wb-gamification
php -d auto_prepend_file=tests/prepend.php vendor/bin/phpunit --configuration phpunit.xml.dist

# Run unit tests only
composer run test:unit

# WPCS — use MCP tool, NOT direct CLI (ignores .phpcs.xml otherwise)
# mcp__wpcs__wpcs_check_directory or wpcs_check_file

# WP-CLI (from wp-cli root or via Local)
wp wb-gamification points award --user=42 --points=100 --message="Great work"
wp wb-gamification member status --user=42
wp wb-gamification actions list
wp wb-gamification logs prune --before=6months --dry-run
wp wb-gamification export user --user=42 > export.json

# Git log
git -C /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/plugins/wb-gamification log --oneline -20
```

---

## Architecture Quick Reference

### Boot Sequence (`plugins_loaded` priority order)

```
Priority 0  → WB_Gamification::instance() (registers all hooks)
Priority 1  → DbUpgrader::init()           (schema migrations)
Priority 5  → ManifestLoader::scan()       (auto-discovers action manifests)
Priority 6  → Registry::init()             (registers discovered actions)
Priority 8  → Engine::init(), WPHooks, BPHooks
Priority 10 → BadgeEngine, ChallengeEngine, StreakEngine, etc.
Priority 12 → NotificationBridge
Priority 15 → Privacy
Priority 20 → SiteFirstBadgeEngine
bp_loaded   → ProfileIntegration, DirectoryIntegration, BPActivity
```

### Key Constants

```php
WB_GAM_VERSION   // '1.0.0'
WB_GAM_FILE      // absolute path to wb-gamification.php
WB_GAM_PATH      // plugin dir path (trailing slash)
WB_GAM_URL       // plugin dir URL (trailing slash)
WB_GAM_BASENAME  // 'wb-gamification/wb-gamification.php'
```

### PSR-4 Namespaces under `src/`

| Namespace | Directory | Purpose |
|---|---|---|
| `WBGam\Engine\` | `src/Engine/` | Core engines, event bus, DB, cron |
| `WBGam\API\` | `src/API/` | REST controllers |
| `WBGam\Admin\` | `src/Admin/` | Admin pages, wizard, analytics |
| `WBGam\BuddyPress\` | `src/BuddyPress/` | BP-specific integrations |
| `WBGam\Integrations\` | `src/Integrations/` | WordPress, WooCommerce, etc. |
| `WBGam\Abilities\` | `src/Abilities/` | WP Abilities API registrations |
| `WBGam\Blocks\` | `src/Blocks/` | Block render callbacks |
| `WBGam\Extensions\` | `src/Extensions/` | Helper functions (`functions.php`) |

---

## ✅ Done — Phase Summary

### Phase 0 — Architectural Foundation
- Event bus (`Engine.php`, `Registry.php`, `ManifestLoader.php`)
- DB schema (Installer + DbUpgrader with version-gated migrations)
- Core constants and boot sequence

### Phase 1 — Core MVP
- `PointsEngine` (append-only event log, action-scheduler async processing)
- `LevelEngine` (thresholds configurable per community)
- WordPress hooks integration (`WPHooks`)
- REST API read endpoints (members, points, badges, leaderboard)
- Setup wizard (`SetupWizard`) + starter templates

### Phase 2 — Badges + Social
- `BadgeEngine` — rule-based badge evaluation
- `LeaderboardEngine` — daily/weekly/monthly/all-time with scopes
- `KudosEngine` — peer kudos with cooldown
- `BadgeSharePage` — public OG-ready badge share URL
- `RankAutomation` — automatic rank assignment UI
- `CredentialExpiryEngine` — badge expiry (`validity_days`, `expires_at`)

### Phase 3 — Engagement Mechanics
- `StreakEngine` — daily/weekly streak tracking
- `ChallengeEngine` — individual challenges
- `CommunityChallengeEngine` — group challenges
- `AnalyticsDashboard` — admin analytics tab
- `TenureBadgeEngine` — anniversary/tenure badges
- `SiteFirstBadgeEngine` — site-first-action badges
- `RecapEngine` — year-in-review recap
- `MissionMode` — structured mission sequences

### Phase 4 — Platform + Integrations
- OpenBadges 3.0 credential issuance (`CredentialController`)
- `RedemptionEngine` + `RedemptionController` — rewards store
- `CosmeticEngine` — profile cosmetics/frames
- 8 plugin integrations (LearnDash, WooCommerce, bbPress, BP Reactions, BP Media, BP Groups, Elementor, ACF)
- `CohortEngine` — cohort-based leaderboard leagues
- `RateLimiter` — per-action daily caps
- `WeeklyEmailEngine` — weekly recap emails
- `NotificationBridge` — connects to BP notifications
- `WebhookDispatcher` — outbound webhooks for Zapier/Make/n8n
- PHPUnit test suite (Unit + Integration)

### v0.5.0 — Usability Pass
- `ShortcodeHandler` — `[wb_gam_leaderboard]`, `[wb_gam_member_points]`, etc.
- `ManualAwardPage` — admin UI for manual point awards
- `points-history` Gutenberg block
- `BadgeAdminPage` — badge library admin UI
- Dashboard KPI widgets in `AnalyticsDashboard`
- Empty states throughout admin UI
- CSS/JS extracted to `assets/css/admin.css` and `assets/css/frontend.css`
- Full PHPDoc docblocks across all classes

---

## 🟡 Next Up — Frontend UX Audit (Phase 2.5)

See `plans/v1-master-plan.md` Tasks 25-30 for full details:
- **Task 25:** Modal/overlay accessibility (ARIA, ESC key, focus trap)
- **Task 26:** Mobile 390px viewport audit (all 11 blocks + all admin pages)
- **Task 27:** First-run UX (skip button help text, welcome card browser test)
- **Task 28:** Empty states audit (verify all blocks handle zero-data)
- **Task 29:** Interactivity polish (leaderboard period switch, heatmap tooltips, color-blind labels)
- **Task 30:** Admin design consistency audit (all pages match premium CSS system)

## 🔜 After UX Audit

- **Phase 3:** Pro plugin scaffold (split pro engines to `wb-gamification-pro`)
- **Phase 4:** Build & release (Grunt, EDD SDK, version bump, zip packaging)

## 🟣 Phase 5+ (Deferred)

- **GraphQL API** — flexible queries for mobile/headless frontends
- **WebSocket real-time** — enterprise tier (SSE already done; WS is bidirectional layer)
- **ActivityPub federation** — gamification events into the fediverse
- **AI intelligence layer** — churn prediction, adaptive challenges, anti-gaming detection
- **JS SDK** — `@wbcom/wb-gamification-js-sdk`
- **React Native SDK** — `@wbcom/wb-gamification-rn-sdk`
- **PHPStan CI** — currently manual, needs GitHub Actions integration

---

## DB Schema Quick Reference

Version tracked by `get_option('wb_gam_db_version')`. Migrations live in `DbUpgrader.php` — each version gets its own `upgrade_to_X_Y_Z()` method.

| Table | Purpose |
|---|---|
| `wb_gam_events` | Immutable event log (UUID PK, source of truth) |
| `wb_gam_points` | Points ledger (derived; event_id FK to events) |
| `wb_gam_user_badges` | Earned badges (`expires_at` nullable) |
| `wb_gam_badge_defs` | Badge definitions (name, description, image) |
| `wb_gam_rules` | All rule conditions (points, badge, level thresholds) |
| `wb_gam_levels` | Level definitions (name, threshold, icon) |
| `wb_gam_challenges` | Individual challenge definitions |
| `wb_gam_community_challenges` | Group/community challenge definitions |
| `wb_gam_kudos` | Kudos given/received log |
| `wb_gam_member_prefs` | Per-user notification/privacy preferences |
| `wb_gam_leaderboard_cache` | Leaderboard snapshot cache |
| `wb_gam_webhooks` | Registered webhook endpoints |
| `wb_gam_streaks` | Streak state per user |

**Key column notes (post v0.2.0 renames):**
- `wb_gam_user_badges.expires_at` — nullable DATETIME (added v0.3.0)
- `wb_gam_user_badges.earned_at` — DATETIME (not `created_at`)
- Composite indexes on `(user_id, action_id, created_at)` for sargable leaderboard queries

---

## Files of Interest

| Task | File |
|---|---|
| Plugin entry / boot hooks | `wb-gamification.php` |
| DB table creation | `src/Engine/Installer.php` |
| DB migrations | `src/Engine/DbUpgrader.php` |
| Points awarding | `src/Engine/PointsEngine.php` |
| Badge evaluation | `src/Engine/BadgeEngine.php` |
| Action manifest loading | `src/Engine/ManifestLoader.php` |
| Action/rule registry | `src/Engine/Registry.php` |
| Shortcodes | `src/Engine/ShortcodeHandler.php` |
| Admin settings | `src/Admin/SettingsPage.php` |
| Analytics dashboard | `src/Admin/AnalyticsDashboard.php` |
| Manual award UI | `src/Admin/ManualAwardPage.php` |
| Badge library UI | `src/Admin/BadgeAdminPage.php` |
| Challenge manager UI | `src/Admin/ChallengeManagerPage.php` |
| API keys UI | `src/Admin/ApiKeysPage.php` |
| Badge share page | `src/Engine/BadgeSharePage.php` |
| API key auth | `src/API/ApiKeyAuth.php` |
| Capabilities API | `src/API/CapabilitiesController.php` |
| Abilities registration | `src/API/AbilitiesRegistration.php` |
| Feature flags | `src/Engine/FeatureFlags.php` |
| Async evaluator | `src/Engine/AsyncEvaluator.php` |
| Doctor CLI | `src/CLI/DoctorCommand.php` |
| REST members endpoint | `src/API/MembersController.php` |
| REST points endpoint | `src/API/PointsController.php` |
| OpenBadges 3.0 endpoint | `src/API/CredentialController.php` |
| Redemption store endpoint | `src/API/RedemptionController.php` |
| BuddyPress hooks | `src/BuddyPress/HooksIntegration.php` |
| WP-CLI commands | `src/CLI/` (PointsCommand, MemberCommand, ActionsCommand, LogsCommand, ExportCommand) |
| Block registration | `blocks/` (leaderboard, member-points, badge-showcase, etc.) |
| Frontend CSS | `assets/css/frontend.css` |
| Admin CSS | `assets/css/admin.css` |
| Interactivity API store | `assets/interactivity/index.js` |

---

## Registered REST Routes

Namespace: `/wp-json/wb-gamification/v1/`

Controllers registered in `WB_Gamification::register_routes()`:
`Members`, `Points`, `Badges`, `Leaderboard`, `Actions`, `Kudos`, `BadgeShare`, `Challenges`, `Events`, `Webhooks`, `Rules`, `Recap`, `Credential`, `Redemption`, `Capabilities`, `Levels`, `ApiKeyAuth`

---

## Registered Blocks

`leaderboard`, `member-points`, `badge-showcase`, `level-progress`, `challenges`, `streak`, `top-members`, `kudos-feed`, `year-recap`, `points-history`, `earning-guide`

All 11 blocks live in `blocks/{slug}/` and are registered dynamically from `WB_Gamification::register_blocks()`. Each has a matching shortcode via `ShortcodeHandler`.

---

## Recent Changes

| Version | Key Changes |
|---|---|
| **0.5.1** | WP-CLI commands, API Key Auth, Capabilities endpoint, Abilities API, REST schemas on all 16 controllers, CORS support, site_id column, earning-guide block, CI workflows, Grunt build, EDD SDK, readme.txt |
| **0.5.0** | Shortcodes, manual award UI, points-history block, badge admin page, dashboard KPIs, empty states, CSS/JS extracted to assets/, full PHPDoc docblocks |
| **0.4.0** | BadgeSharePage, RankAutomation UI, cosmetics engine, cohort leagues, rate limiter, weekly email engine, Phase 4 integrations complete |
| **0.3.0** | CredentialExpiryEngine, `validity_days`/`expires_at` columns on user_badges, OpenBadges 3.0 credential issuance |
| **0.2.0** | Performance: composite indexes, N+1 query fixes, sargable leaderboard queries, object cache layer |
| **0.1.0** | Architectural foundation: event bus, registry, manifest loader, schema, boot sequence |

---

## WPCS Notes

Run WPCS via the `mcp__wpcs__*` MCP tools — **do not run directly** from the CLI on this plugin. The MCP tool picks up the `.phpcs.xml` config correctly. Direct `phpcs` CLI from outside the plugin directory ignores it.
