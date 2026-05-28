# WB Gamification — CLAUDE.md

> **READ FIRST:** [`audit/manifest.json`](audit/manifest.json) is the canonical inventory — **56 REST endpoints**, **23 tables**, **19 blocks**, 17 shortcodes, ~10 cron hooks, **10 WP-CLI commands**, **13 admin pages**, **44 services**, **0 admin_post_* handlers** (Tier 0 REST migration intact), **0 wp_ajax_* handlers**. Quick index: [`audit/manifest.summary.json`](audit/manifest.summary.json) (≤3 KB). Refresh via `/wp-plugin-onboard --refresh` after non-trivial changes. Stability gates: [`audit/STABILITY-2026-05-27.md`](audit/STABILITY-2026-05-27.md). v1.4.0 release gates: WPCS clean, PHPStan level 9 clean, WP Plugin Check 0 errors, PHPUnit 109 tests green, all 14 local-CI stages green.
>
> **Folder map:**
> - [`audit/`](audit/) — machine-generated inventory + reports + journeys. Hand-edits get overwritten on refresh. See [`audit/README.md`](audit/README.md). Key: [`manifest.json`](audit/manifest.json), [`FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md), [`CODE_FLOWS.md`](audit/CODE_FLOWS.md), [`ROLE_MATRIX.md`](audit/ROLE_MATRIX.md), [`graph.html`](audit/graph.html).
> - [`plan/`](plan/) — human-authored evergreen design docs + the single roadmap. See [`plan/MASTER-CHECKLIST.md`](plan/MASTER-CHECKLIST.md) for what's shipped vs pending. Architecture in [`plan/ARCHITECTURE.md`](plan/ARCHITECTURE.md). Strategy in [`plan/PRODUCT-VISION.md`](plan/PRODUCT-VISION.md). Tech in [`plan/TECH-STACK.md`](plan/TECH-STACK.md). Dated release / bug-sweep / migration plans were folded into the master checklist on 2026-05-28; recover via git log if needed.
> - [`examples/`](examples/) — 10 third-party integration samples. See [`examples/README.md`](examples/README.md).
> - [`docs/`](docs/) — `docs/qa/` (release smoke runbook), `docs/website/` (customer-facing docs). Both active.
> - [`.wordpress-org/`](.wordpress-org/) — banner / icon / screenshots for SVN sync.
>
> **Browse as graph:** `cd audit && python3 -m http.server 8765` → http://localhost:8765/graph.html

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

1. **Read the spec first** — before writing a single line, check [`plan/MASTER-CHECKLIST.md`](plan/MASTER-CHECKLIST.md) for status and any linked architecture file under `plan/`
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

## Journey-per-fix rule (mandatory before close-out)

**Every Ready-for-Testing Basecamp card MUST add or update a journey under `audit/journeys/release/` before moving to Done.** The journey IS the regression test — if a bug recurs without it, the gate didn't catch it, and the fix was wasted.

This rule is non-procedural — `bin/architecture-checks.sh` (and the per-card commit message review) verifies it on every release. Reasoning + worked examples in `audit/STABILITY-2026-05-27.md` §2 (the wizard activation was reopened by QA 3× because no journey re-locked the boot invariant after each fix attempt).

**The pattern:**
1. **Pick from Bugs/UI Issues.** Reproduce locally; understand root cause.
2. **Write the journey first** under `audit/journeys/release/<NN>-<slug>.md` using the template at `audit/journeys/.template.md`. Confirm it fails today against the buggy code.
3. **Fix the code.** Re-run the journey — it must pass.
4. **Commit** with the journey + fix together. The commit message names both.
5. **Move card to Ready for Testing** with a comment listing the journey path.
6. **QA verifies** by re-running the journey (faster than re-walking the manual repro).

**Why this works:** any future commit that reintroduces the same root cause fails the gate, not QA. The bug-fix waves of v1.4.0 averaged 1.7 dev-QA round trips per fix; cards journey-covered before close-out averaged 1.1. That's not just speed — it's the difference between "fix landed" and "fix stays landed."

**Existing journey shelf:**
- `audit/journeys/release/01-tier-1-foundations.md` — static gates (WPCS, PHPStan, PHPUnit, manifest, blocks).
- `audit/journeys/release/02-editor-15-blocks.md` — block editor surface (now 19 blocks; needs refresh).
- `audit/journeys/release/03-frontend-15-blocks.md` — frontend block rendering (now 19; needs refresh).
- `audit/journeys/release/04-earning-journey.md` — points event → ledger → display.
- `audit/journeys/release/07-a11y-and-mobile.md` — a11y + 390px viewport.
- `audit/journeys/release/09-release-zip-gate.md` — dist package.
- `audit/journeys/release/10-boot-timing.md` — admin page registration + REST routes + nested plugins_loaded (added 2026-05-27 in response to wizard / community-challenges / notification bugs).

---

## Local CI pipeline (REQUIRED before push)

This plugin has a self-contained local-CI gate. No external service runs the gate — every contributor runs it on their own machine, and an opt-in pre-push hook runs it automatically before every `git push`.

```bash
composer install-hooks    # one-time per clone — activates .githooks/pre-commit + pre-push
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
| 1.4 JS build | `npm run build` (skipped if no `node_modules`) | block JS / interactivity compile |
| 2.1 Coding rules | `bin/coding-rules-check.sh` | plugin-specific rules (Rules 1-5, 11) |
| 2.2 Architecture | `bin/architecture-checks.sh` | Engine-boot contract invariants |
| 2.3 Block standard | `bin/check-block-standard.sh` | Wbcom Block Quality Standard |
| 2.4 UX audit | `bin/ux-audit.sh` (vendored from `~/.claude/skills/ux-audit/`) | ux-foundation compliance: inline `<style>`/`<script>`, native alert/confirm, theme-sidebar hidden, dashicons drift, raw RTL margins |
| 2.5 Plugin-dev rules | `bin/plugin-dev-rules-check.sh` | wp-plugin-development gates: no jQuery on frontend, no admin-ajax on customer surfaces, blocks declare `wb-gam-tokens` dep, per-block style.css present, BP integrations boot on `bp_loaded` |
| 2.6 wppqa baseline | `bin/wppqa-baseline-check.sh` | freshness + cleanliness of the `audit/wppqa-baseline-LATEST/SUMMARY.md` (output of the `wp-plugin-qa` MCP) |
| 3.1 Manifest | `jq` on `audit/manifest.json` | manifest validity + freshness |
| 4.1 Journeys | `bin/run-journeys.sh` | customer flows end-to-end (skipped if site unreachable) |

Individual stages can be run via composer scripts: `composer coding-rules`, `composer plugin-dev-rules`, `composer ux-audit`, `composer wppqa-baseline`.

**Plugin-specific coding rules** (in `bin/coding-rules-check.sh`):
- Rule 1 — `current_user_can('wb-gamification/...')` is BANNED. Those slugs are WP Abilities API discovery, not capabilities. Use a real cap (`manage_options` or `wb_gam_*`).
- Rule 2 — REST `__return_true` permission_callback is allowed only for the 12 documented public controllers (catalog reads, OG share, OpenBadges credential, leaderboard, OpenAPI spec, etc.). New `__return_true` outside the allowlist fails the gate. See `audit/ROLE_MATRIX.md` for the rationale.

**Bypass for emergencies only**: `SKIP_LOCAL_CI=1 git push`.

### Stability gates (added 2026-05-27 per `audit/STABILITY-2026-05-27.md`)

The stability audit added 6 new cross-layer contract gates plus PHPUnit + a coverage floor. Local-CI now runs 16 stages:

| Stage | Tool | Catches the bug class from |
|---|---|---|
| 1.5 PHPUnit | `composer test` | Stale-fixture failures (the QAPages drift test caught block-add bugs that nobody noticed) |
| 2.7 Enum drift | `bin/check-enum-drift.sh` | `point_multiplier` vs `points_multiplier` typo (caught a real bug on first run); Basecamp #9927682021 free-shipping 400; #9927027277 redemption error mapping |
| 2.8 CSS orphans | `bin/check-css-orphans.sh` | Basecamp #9925205802 — PHP wrote `__slider` but CSS only knew `__track`, Emails switch was invisible. Baseline `audit/css-orphan-baseline.txt` |
| 2.9 Action sync/async | `bin/check-action-async.sh` | Basecamp #9925589914 — WC events queued through Action Scheduler when admins expected immediate award. Baseline `audit/action-async-baseline.txt` |
| 2.10 Event wiring | `bin/check-event-wiring.sh` | Basecamp #9927383947 — `wb_gam_points_redeemed` fired but TransactionalEmailEngine never subscribed |
| 2.11 Coverage floor | `bin/check-coverage-floor.sh` (only when `build/coverage/coverage.txt` is fresh) | PHPUnit coverage silently sliding |
| 2.13 Boot invariants | `bin/check-boot-invariants.php` (wrapped by `bin/check-boot-invariants.sh`) | Class-hoisting guard regression — a file-scope `class_exists($name, false)` guard preceding the same file's top-level class declaration. PHP compile-time hoisting makes the guard always trigger, so the file aborts before any `add_action` past it registers. Surfaced 2026-05-27 after the wb-gamification.php#L222 guard (added in commit 06d811c) silently disabled the admin menu for ~9 hours. Findings cached at `audit/derived/boot-hoist-guards.json`. |
| 2.14 Badge condition contract | `bin/check-badge-condition-contract.php` | Seeded badge condition vs badge name/description drift — "First Comment" / "Published 10 posts" must be `action_count`, not `point_milestone`. Walks `Installer::seed_default_badges()` `$badges` + `$conditions` arrays and asserts every auto-awarded badge's `condition_type`, `action_id` and `count` match the promise in its name + description. Cross-references `action_id` against registered actions in `integrations/*.php`. Surfaced 2026-05-27 after Basecamp #9933079634 + #9933063928 audit found 5 badges seeded as `point_milestone` despite their names promising literal actions. |

PHPStan bumped from level 5 → **level 9** — codebase already passed at every level, so no baseline file needed; new code can't add type holes.

**Run coverage on demand**: `composer test:coverage` (chains the floor check). Default `composer ci` skips coverage for speed.

**Refresh a baseline** (after a legitimate fix made the gate noisy):
```bash
bin/check-css-orphans.sh   --update-baseline   # CSS orphan list
bin/check-action-async.sh  --update-baseline   # implicit-async action list
```

The audit doc (`audit/STABILITY-2026-05-27.md`) classifies which gate maps to which v1.4.0 bug.

## Production hosting requirements (100k+ user sites)

Plugin is built to scale per the v1.0 hardening sprint, but two host-level prerequisites are **required**, not optional, on sites with >10k active users:

1. **Persistent object cache** — Redis or Memcached. Every `PointsEngine::get_total()`, `PointTypeService::list()`, and `LeaderboardEngine::get_leaderboard()` reads through `wp_cache_get`. Without a persistent backend, the cache is per-request only — every page load re-runs the same SQL the cache was meant to avoid. Verified scale paths assume the cache survives across requests.
2. **Action Scheduler** (bundled with WooCommerce / activated by `as_*` functions) — async badge/level/streak evaluations queue here. With AS workers tuned for the install's throughput, the hot request path stays sub-100ms even during award bursts.
3. **MySQL 8.0+** — the leaderboard snapshot uses `RANK() OVER (...)` window functions. MySQL 5.7 and MariaDB <10.2 are not supported.

Scale benchmark gate (`composer scale:bench`) measures hot-path query budgets against a synthetic 1M-row dataset. All 6 hot-path queries pass under their budget today; re-run the benchmark before any release that touches read paths.

## Audit cache (`audit/derived/`)

Phase 2.5 derivations from the wp-plugin-onboard skill are cached here per the v2.1 token-efficiency layer. Each JSON file is keyed on its input file set; consumers (`pr-review`, `wp-plugin-development`'s pre-commit, future drift gates) read these instead of re-running the scan.

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

## 🟡 Next Up

See [`plan/MASTER-CHECKLIST.md`](plan/MASTER-CHECKLIST.md) — pending items live there. As of 2026-05-28: scale-benchmark customisation, SSE/WebSocket transport, hooks_fired re-enumeration, frontend_assets re-enumeration, capabilities manifest coverage, GraphQL API, AI intelligence layer, JS SDK, ActivityPub federation.
- **Task 30:** Admin design consistency audit (all pages match premium CSS system)

## 🔜 After UX Audit

- **Phase 3:** Build & release (Grunt, version bump, zip packaging)

> **No Pro tier.** wb-gamification is shipped as a single free plugin — every engine boots in this codebase. Any historical references to a "Pro plugin", "Pro engines", `WB_GAM_PRO_VERSION`, or `wb-gamification-pro` left in `plan/`, `audit/`, or older docs are stale.

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

Documentation: see [`docs/website/developer-guide/block-attributes.md`](docs/website/developer-guide/block-attributes.md). The Wbcom Block Quality Standard migration shipped in 1.0.0 — `bin/check-block-standard.sh` (CI stage 2.3) keeps it enforced.

---

## Recent Changes

| Version | Key Changes |
|---|---|
| **Bug-sweep** (2026-05-27 → 2026-05-28) | **21 Basecamp bug cards closed end-to-end** with real-data verification. 19 commits between `73f5db7` and current HEAD. **Two new local-CI gates added**: stage 2.13 `bin/check-boot-invariants.php` (catches `class_exists($name, false)` guards above a same-file class declaration — PHP class hoisting makes them always trigger, which silently disabled the admin menu in this session's first hour); stage 2.14 `bin/check-badge-condition-contract.php` (asserts every auto-awarded badge whose name/description promises a literal action is seeded as `action_count`, not `point_milestone`). **New engine**: `WBGam\Engine\MemberUploadCap` — bridges WP `upload_files` cap to logged-in members via `user_has_cap` filter so the achievement-editor Add Media button works for subscribers/contributors; opt-out filter `wb_gam_grant_member_uploads`. **Behaviour fixes**: WC purchase events now hook `woocommerce_payment_complete` (was `status_completed`, which never fires for paid-but-unshipped orders); 5 badge defaults switched from `point_milestone` to `action_count` (first_post, first_comment, prolific_writer, content_creator, engaged_reader); cohort tier names + new 5th-tier admin input wired through CohortEngine + REST + admin-cohort.js; CommunityChallengeEngine::award_community_bonus listener installed (the dead-letter that pre-fix queued AS jobs that no one handled); heartbeat interval → 5s (`fast`) for realtime toasts; toast.js dedupes by `_id` across footer/heartbeat/rest cursors; redemption email slug whitelisted in EmailSettingsController. **UX**: user-status-bar gets a proper chevron toggle + `--wb-gam-status-bar-top-offset` CSS var; cohort-rank tier-coloured accent dot (Bronze/Silver/Gold/Diamond/Obsidian); community-challenges completed-state visual treatment (green pill + 100% green bar + "Celebrating for X" copy) + per-block `style.css` now compiles; earning-guide card switched to two-row layout (label below icon+pts so long labels read horizontally); manual badge-award form on the Badge edit page; Hub Challenges card unites personal + community counts. **Boot**: the `class_exists` guard introduced in `06d811c` removed in `61f62ca` — it was the cause of the missing admin menu and the unfired setup wizard. **Inventory delta**: services 43 → 44 (MemberUploadCap added); structural counts (REST endpoints, AJAX, tables, blocks, shortcodes, admin pages, WP-CLI) unchanged at 56/0/23/19/17/13/10. |
| **QA-infra** (2026-05-09) | **Pre-release QA model adopted from Jetonomy + portfolio-wide refactor.** Scaffolded the four canonical `docs/qa/` files (`PRE_RELEASE_SMOKE.md`, `AGENT_SMOKE_RUNBOOK.md`, `UX_AUDIT.md`, `QA_RELEASE_CHECKLIST.md`) so this plugin matches the portfolio shape every Wbcom plugin shares. **Smoke execution architecture** — pre-release walks dispatch through ONE Claude-level skill at `~/.claude/skills/wp-plugin-smoke/` (used by every Wbcom plugin); plugin-specific variables live in `docs/qa/qa.config.json` (slug, version constant, site URL, personas, integrations, fixture-cleanup SQL, basecamp IDs, debug-log whitelist). No per-plugin smoke skill exists or should be scaffolded — the duplicated-prompt pattern was retired in favor of one-skill-per-portfolio. Injected the smoke-gate into `bin/build-release.sh` — the script now refuses to package unless `docs/qa/.last-smoke-pass.json` exists, matches the current `WB_GAM_VERSION`, and reports zero `from`-origin failures + zero `from`-origin `debug_log_issues`. Emergency bypass: `--skip-browser-smoke`. Added `composer smoke` convenience alias. Section D seeded with 16 regression rows (activation-rewrite, cap-drift-manual-award, leaderboard-cache-drift, streak-tz-offset, zero/negative-points handling, kudos-cooldown-bypass, openapi-stale, dark-mode-block-contrast, mobile-tabs-clipped, cli-replay-idempotent, action-scheduler-orphan, granular-cap-merge, etc.). `docs/qa/README.md` documents the four QA layers (static gates → journeys → agent runbook → human walk) and how each Jetonomy concept maps onto the existing infrastructure. Honors existing `plan/QA-MANUAL-TEST-PLAN.md` and `plan/PRE-RELEASE-CHECKLIST.md` — does not duplicate them, references them. **To run smoke:** invoke `/wp-plugin-smoke` from anywhere (auto-detects this plugin from CWD). |
| **1.0.0** (release-candidate, 2026-05-06) | **v1.0 launch — four critical gaps closed** (`plan/v1.0-release-plan.md`): #2 Transactional emails (`TransactionalEmailEngine` + 4 templates + `EmailSettingsController` + `wp wb-gamification email-test` CLI), #3 Public profile pages (`/u/{user_login}` via `ProfilePage` + privacy gate + OG meta + Schema.org JSON-LD), #4 Daily login bonus (`LoginBonusEngine` + tier ladder `[1=>10, 3=>20, 7=>50, 14=>100, 30=>250]` + `daily-bonus` block, user_meta-based, no schema migration), #5 UGC submission queue (`wb_gam_submissions` table + `SubmissionRepository` + `SubmissionService` with `DAILY_CAP=5` + 4 REST endpoints + admin queue page + `submit-achievement` block, approval routes through `PointsEngine::award` so badges/levels/totals stay consistent). **Release gates green**: WPCS clean (0 errors), PHPStan level 5 clean (0 errors), WP Plugin Check 0 errors with proper exclusions (`dist`, `examples`, `audit`, `tests`, `bin`, `plan`, `docs`, `.github`, `build`, dev `README.md`/`CLAUDE.md`), wppqa baseline `audit/wppqa-baseline-2026-05-07/SUMMARY.md` failed=0 across `plugin_dev_rules` + `rest_js_contract` + `wiring_completeness`, `composer ci:no-journeys` green. **Plugin Check fixes shipped**: 4 i18n translators-comment placement (moved inside `wp_kses` wrapper), 1 percent-sign rewording in RedemptionStorePage description, 2 heredoc → `implode( "\n", array(…) )` conversions in `QAPages` CLI, 2 `_doing_it_wrong` `esc_html()` wraps in `Registry`, 4 `phpcs:ignore` → `phpcs:disable…enable` blocks for legitimate bulk-INSERT prepare patterns in `PointsEngine::award_batch` + `ScaleCommand`. **Inventory**: 17 blocks (was 12), 55 REST routes (was 39), 22 tables (was 20), 12 admin pages (was 10), 6 WP-CLI commands (now `points`/`member` accept `--type=`, plus `email-test` and `scale` seed/bench). **Multi-point-types end-to-end** (Phases 1–5): 2 new tables (`wb_gam_point_types`, `wb_gam_point_type_conversions`); `point_type` column on `wb_gam_points` + `wb_gam_events` + `wb_gam_redemption_items`; 9 new REST routes (`/point-types*`, `/point-type-conversions*`, `/point-types/{from}/convert`); 4 new services (`PointTypeService`, `PointTypeConversionService`, `PointTypeRepository`, `PointTypeConversionRepository`); 2 new admin pages (Point Types CRUD, Conversions CRUD). **Currency conversion v1**: atomic `START TRANSACTION` + `FOR UPDATE` lock + shared `event_id` linking debit + credit ledger rows. **Hub block convert UI**: per-tile button + shared `<dialog>` modal + `assets/js/hub-convert.js` + responsive CSS. **Member-facing currency labels**: `member-points`, `points-history`, `leaderboard`, `top-members` blocks resolve labels via `PointTypeService`. **CLI `--type=`**: `wp wb-gamification points award --type=coins` + `wp wb-gamification member status` lists per-currency on multi-currency sites. **Code-flow audit**: removed 2 stale single-currency cache invalidations (Engine.php:257, RedemptionEngine.php:227); extended `GET /members/{id}` REST schema with `points_by_type`; Privacy export now multi-currency-aware (one summary row per currency + per-row Currency in history); `Engine::persist_event` stamps `point_type` on the immutable event log. **Resolution single source of truth**: `Registry::resolve_action_point_type()` is read by both award path AND rate-limit path so caps count the same ledger the action writes to. Cohort settings inlined into Settings sidebar (admin pages 11 → 12 net via -1 cohort + 3 new). wppqa baseline 2026-05-06 failed=0 across all 4 checks. |
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
