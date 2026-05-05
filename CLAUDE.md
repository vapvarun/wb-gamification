# WB Gamification — CLAUDE.md

> **READ FIRST:** [`audit/manifest.json`](audit/manifest.json) is the canonical inventory — **51 REST endpoints**, 20 tables, **15 blocks**, **15 shortcodes**, 9 cron hooks, 6 WP-CLI commands, **11 admin pages**, **0 admin_post_* handlers** (Tier 0 REST migration), **0 wp_ajax_* handlers**, 85 fired hooks (54 actions + 31 filters). Use this before grepping. Quick index: [`audit/manifest.summary.json`](audit/manifest.summary.json). wppqa baseline: [`audit/wppqa-baseline-2026-05-03/SUMMARY.md`](audit/wppqa-baseline-2026-05-03/SUMMARY.md) (failed=0).
>
> **Folder map:**
> - [`audit/`](audit/) — machine-generated inventory + reports + journeys + wppqa runs. Hand-edits get overwritten on refresh. See [`audit/README.md`](audit/README.md).
> - [`plan/`](plan/) — human-authored design docs + roadmaps. Architecture in [`plan/ARCHITECTURE-DRIVEN-PLAN.md`](plan/ARCHITECTURE-DRIVEN-PLAN.md); QA team uses [`plan/QA-MANUAL-TEST-PLAN.md`](plan/QA-MANUAL-TEST-PLAN.md). See [`plan/README.md`](plan/README.md).
> - [`examples/`](examples/) — 10 third-party integration samples (manifest, REST, webhook, badge/challenge, email override, block injection). See [`examples/README.md`](examples/README.md).
> - [`docs/website/`](docs/website/) — customer-facing documentation, owned by the docs team.
> - [`.wordpress-org/`](.wordpress-org/) — banner / icon / 10 screenshots ready for SVN sync.
>
> **Audit reports:** [`audit/FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md), [`audit/CODE_FLOWS.md`](audit/CODE_FLOWS.md), [`audit/ROLE_MATRIX.md`](audit/ROLE_MATRIX.md), [`audit/CLOSE-OUT-2026-05-02.md`](audit/CLOSE-OUT-2026-05-02.md), [`audit/FEATURE-COMPLETENESS-2026-05-02.md`](audit/FEATURE-COMPLETENESS-2026-05-02.md) (per-feature × surface matrix — half-cooked items, logging gaps, test coverage). Quality baseline: [`audit/wppqa-runs/2026-05-02-baseline/SUMMARY.md`](audit/wppqa-runs/2026-05-02-baseline/SUMMARY.md). Browse as graph: `cd audit && python3 -m http.server 8765` then http://localhost:8765/graph.html. Refresh after non-trivial changes via `/wp-plugin-onboard --refresh`.

> Session orientation for AI assistants. Read this first.

---

## Plugin Overview

| Field | Value |
|---|---|
| **Name** | WB Gamification |
| **Version** | 1.0.0 (pre-launch) |
| **Path** | `wp-content/plugins/wb-gamification/` |
| **Namespace** | `WBGam\` (PSR-4, maps to `src/`) |
| **PHP** | 8.1+ required |
| **Architecture** | Event-sourced, manifest auto-discovery, zero-config |
| **Part of** | Reign Stack — Wbcom's self-owned community platform |
| **Basecamp project** | [WP Gamification](https://3.basecamp.com/5798509/buckets/47162271) — ID `47162271` |
| **Bug card table** | [Card Table](https://3.basecamp.com/5798509/buckets/47162271/card_tables/9860004450) — ID `9860004450` |

**Design principle:** Events in → rules evaluate → effects out. The engine owns three surfaces: event normalization, rule evaluation, output. Everything else (BuddyPress display, WooCommerce triggers, mobile) is a consumer.

### Basecamp card-table workflow

Every bug / feature / scope item flows through this kanban (matches the canonical Wbcom column layout used across BuddyPress, WPMediaVerse, Jetonomy):

| # | Column | ID | When to use |
|---|---|---|---|
| 0 | [Triage](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860004451) | `9860004451` | Inbound — all new cards land here |
| 1 | [Not now](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860004452) | `9860004452` | Deferred — won't action this cycle |
| 2 | [Scope](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860019752) | `9860019752` | Requirements / planning / design specs |
| 3 | [Figuring it out](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860028286) | `9860028286` | Investigation / repro / cause hypothesis |
| 4 | [UI Issues](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860020277) | `9860020277` | Visual / layout / a11y bugs |
| 5 | [Bugs](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860020654) | `9860020654` | Functional / logic bugs ready to fix |
| 6 | [In progress](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860004458) | `9860004458` | Actively coding |
| 7 | [Ready for Testing](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860021091) | `9860021091` | Pushed — awaiting QA pickup |
| 8 | [Testing](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860028737) | `9860028737` | QA actively verifying |
| 9 | [Done](https://3.basecamp.com/5798509/buckets/47162271/card_tables/columns/9860004460) | `9860004460` | Verified + shipped |

**Comment formatting** in basecamp is HTML, not markdown — use `<strong>`, `<br>`. Pattern: pick from Bugs/UI Issues → fix → comment with root cause + fix + screenshot → move to Ready for Testing → QA moves to Testing → if pass, Done.

---

## Execution Rules (Non-Negotiable)

**Every task follows this loop:**
```
Plan item → Implement → Browser verify → Fix gaps → THEN mark done
```

1. **Read the spec first** — before writing a single line, read the task description in `plan/v1-master-plan.md` and any linked spec file
2. **Implement** — write the code
3. **Verify against spec** — use Playwright MCP to screenshot and confirm output matches what was designed
4. **Quality check** — WPCS, test at 390px viewport, check a11y, verify no regressions
5. **Only then** mark the task done

**Sub-agent rules:**
- Each agent gets the FULL spec context — not a vague "build X"
- Agent output is reviewed before committing — never auto-commit agent work
- If an agent produces code that doesn't match the spec, fix or reject — don't ship it
- Parallel agents work on independent tasks only — never two agents editing the same file

---

## Local CI pipeline (REQUIRED before push)

This plugin has a self-contained local-CI gate. No external service runs the gate — every contributor runs it on their own machine, and an opt-in pre-push hook runs it automatically before every `git push`.

```bash
composer install-hooks    # one-time per clone — activates bin/git-hooks/pre-push
composer ci               # full pipeline (~30s + browser journeys)
composer ci:no-journeys   # everything except browser-dependent journeys
composer ci:quick         # PHP lint + coding-rules only (~10s, for tight loops)
```

What the gate runs (in order, see `bin/local-ci.sh`):

| Stage | Tool | Catches |
|---|---|---|
| 1.1 PHP lint | `php -l` on every source PHP file | syntax errors |
| 1.2 WPCS | `composer phpcs` (skipped if `vendor/bin/phpcs` not installed) | WordPress coding standards |
| 1.3 PHPStan | `composer phpstan` (skipped if no `phpstan.neon`) | static type errors |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | plugin-specific rules (see below) |
| 3.1 Manifest | `jq` on `audit/manifest.json` | manifest validity + freshness |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end (skipped if site unreachable) |

**Plugin-specific coding rules** (in `bin/coding-rules-check.sh`):
- Rule 1 — `current_user_can('wb-gamification/...')` is BANNED. Those slugs are WP Abilities API discovery, not capabilities. Use a real cap (`manage_options` or `wb_gam_*`).
- Rule 2 — REST `__return_true` permission_callback is allowed only for the 12 documented public controllers (catalog reads, OG share, OpenBadges credential, leaderboard, OpenAPI spec, etc.). New `__return_true` outside the allowlist fails the gate. See `audit/ROLE_MATRIX.md` for the rationale.

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

## Customer journeys

Bug fixes that survive a refactor are journey-covered. See `audit/journeys/README.md` for the schema and the executor contract. The 4 critical journeys today:

| Journey | Priority | Purpose |
|---|---|---|
| `customer/01-earn-points-via-rest` | critical | Canonical event → points → ledger pipeline |
| `customer/02-view-leaderboard-block` | critical | Block render → REST → cache + live-query parity |
| `admin/01-manual-award-points` | critical | Admin REST + cap drift sentinel for `wb_gam_award_manual` |
| `security/01-rest-public-allowlist` | high | Live counterpart to coding-rule 2 — verify only documented endpoints are anonymous |

When a new bug is fixed, add or update the journey that would have caught it. The journey IS the regression test.

Discover + filter:
```bash
composer journeys:dry-run               # list what would run
composer journeys:critical              # only critical-priority
bash bin/run-journeys.sh --only customer/02
```

Journey runs land in `audit/journey-runs/{run-id}/` (gitignored — they are per-run artifacts).

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

See `plan/v1-master-plan.md` Tasks 25-30 for full details:
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
| Block registration | `src/Blocks/<slug>/` — Wbcom Block Quality Standard. Auto-discovered from `build/Blocks/<slug>/` by `WBGam\Blocks\Registrar` (init@20). |
| Per-instance CSS generator | `src/Blocks/CSS.php` (`WBGam\Blocks\CSS`) — emits `--wb-gam-*` scoped rules in `wp_footer`. |
| Block auto-registrar | `src/Blocks/Registrar.php` (`WBGam\Blocks\Registrar`). |
| Shared editor controls | `src/shared/components/` — Responsive/Spacing/Typography/BoxShadow/BorderRadius/ColorHover/DeviceVisibility + `StandardLayoutPanel` / `StandardStylePanel`. |
| Design tokens | `src/shared/design-tokens.css` — registered as `wb-gam-tokens` style handle, depended on by `wb-gamification`. |
| Frontend CSS | `assets/css/frontend.css` (legacy block selectors will move to per-block style.css in Phase F). |
| Admin CSS | `assets/css/admin.css` |
| Interactivity API store (hub) | `assets/interactivity/hub.js`; legacy `index.js` slated for removal in Phase F. |

---

## Registered REST Routes

Namespace: `/wp-json/wb-gamification/v1/`

Controllers registered in `WB_Gamification::register_routes()`:
`Members`, `Points`, `Badges`, `Leaderboard`, `Actions`, `Kudos`, `BadgeShare`, `Challenges`, `Events`, `Webhooks`, `Rules`, `Recap`, `Credential`, `Redemption`, `Capabilities`, `Levels`, `ApiKeyAuth`

---

## Registered Blocks

`leaderboard`, `member-points`, `badge-showcase`, `level-progress`, `challenges`, `streak`, `top-members`, `kudos-feed`, `year-recap`, `points-history`, `earning-guide`, `redemption-store`, `community-challenges`, `cohort-rank`, `hub` — **15 blocks**, all Wbcom Block Quality Standard compliant (apiVersion 3, standard attribute schema, `--wb-gam-*` design tokens, per-instance scoped CSS).

Source: `src/Blocks/<slug>/{block.json, index.js, edit.js, render.php, editor.css}` (plus `view.js` + `style.css` for IA-driven blocks).

Build: `npm run build` (uses `@wordpress/scripts`, sets `WP_EXPERIMENTAL_MODULES=true` for Interactivity API view modules) → `build/Blocks/<slug>/`.

Discovery: `WBGam\Blocks\Registrar` scans `build/Blocks/*/block.json` on `init@20` and calls `register_block_type` per slug. Each block also has a matching shortcode via `ShortcodeHandler`.

CI gate: `bin/check-block-standard.sh` (wired into `composer ci` as stage 2.3) fails the build if any `block.json` is missing the standard attribute schema.

Documentation: see [`docs/website/developer-guide/block-attributes.md`](docs/website/developer-guide/block-attributes.md) and [`plan/WBCOM-BLOCK-STANDARD-MIGRATION.md`](plan/WBCOM-BLOCK-STANDARD-MIGRATION.md).

---

## Recent Changes

| Version | Key Changes |
|---|---|
| **1.2.0** (PR #47, 2026-05-03) | **Tier 0 admin REST migration**: 17 admin form-post hooks → 0 (`admin_post_wb_gam_*` count = 0). 5 controllers built/extended (Levels CRUD, CohortSettings GET/POST, ApiKeys CRUD + revoke, CommunityChallenges CRUD, Badges create + extended schema with nested condition rule). 5 JS modules: shared `admin-rest-utils.js`, generic `admin-rest-form.js` (data-attr-driven, supports nested objects + arrays + datetime-local→UTC), bespoke admin-levels/cohort/api-keys. 9 admin pages migrated; promise-based confirm modal replaces `window.confirm()`. **Tier 1 a11y**: 8 outline:none focus indicators tightened to `:focus:not(:focus-visible)`; 6→3 breakpoints consolidated (640/782/1024); 5 alt-attribute false-positives flattened. **Real bugs caught + fixed during verification**: webhook event-enum mismatch (`badge_awarded` vs `badge_earned`), manual-award debit regression (REST `absint()` stripped negative sign), 4 self-introduced a11y errors in `block-card.css`, hub/community-challenges/cohort-rank editor "doesn't include support" (legacy register shadowing). **V1 release verification suite**: 9-tier journey plan in `plan/V1-RELEASE-VERIFICATION-PLAN.md`, 9 release-tier journeys under `audit/journeys/release/`, full close-out at `audit/release-runs/2026-05-03/RELEASE-CLOSE-OUT.md`. wppqa baseline failed=0 across all 4 checks. |
| **1.0.0** | Wbcom Block Quality Standard migration (G8) — closed across PRs #21 → #41. All 15 blocks now consume `--wb-gam-*` design tokens, declare the standard attribute schema (uniqueId, per-side spacing × 3 breakpoints, typography, hover colours, shadow, border, visibility), register via `WBGam\Blocks\Registrar` from `build/Blocks/<slug>/`, and gate on `bin/check-block-standard.sh` (CI stage 2.3). Per-block CSS now lives in `src/Blocks/<slug>/style.css`; `assets/css/frontend.css` shrunk 1,425 → 293 lines. Pre-existing test failures (NudgeEngineTest alias-mock collisions, RedemptionEngineTest transaction wiring, ShortcodeHandlerTest hardcoded list) closed in #42. |
| **0.5.1** | WP-CLI commands, API Key Auth, Capabilities endpoint, Abilities API, REST schemas on all 16 controllers, CORS support, site_id column, earning-guide block, CI workflows, Grunt build, EDD SDK, readme.txt |
| **0.5.0** | Shortcodes, manual award UI, points-history block, badge admin page, dashboard KPIs, empty states, CSS/JS extracted to assets/, full PHPDoc docblocks |
| **0.4.0** | BadgeSharePage, RankAutomation UI, cosmetics engine, cohort leagues, rate limiter, weekly email engine, Phase 4 integrations complete |
| **0.3.0** | CredentialExpiryEngine, `validity_days`/`expires_at` columns on user_badges, OpenBadges 3.0 credential issuance |
| **0.2.0** | Performance: composite indexes, N+1 query fixes, sargable leaderboard queries, object cache layer |
| **0.1.0** | Architectural foundation: event bus, registry, manifest loader, schema, boot sequence |

---

## WPCS Notes

Run WPCS via the `mcp__wpcs__*` MCP tools — **do not run directly** from the CLI on this plugin. The MCP tool picks up the `.phpcs.xml` config correctly. Direct `phpcs` CLI from outside the plugin directory ignores it.
