# WB Gamification — Master Checklist

> **Single source of truth for what's shipped and what's pending.** Replaces the dated release-plan + bug-sweep + UX-audit + migration files that accumulated through v1.0 → v1.4.0. Older plans live in git history.

**Status:** 90 of 100 items shipped (90% complete). Most green entries trace back to a specific commit; click the linked file/path for the implementation.

---

## ✅ Shipped (90)

### Architecture & boot (8)
- [x] Event-sourced engine (`PointsEngine::award` → `wp_wb_gam_events` immutable log → `wp_wb_gam_points` ledger)
- [x] Auto-discovery via `ManifestLoader::scan` — each `integrations/<plugin>.php` returns a manifest array
- [x] Registry (`Engine\Registry`) maps `action_id` → hook + user_callback + metadata
- [x] Async award pipeline via Action Scheduler (`AsyncEvaluator`) for repeatable actions
- [x] Boot order: ManifestLoader (5) → Registry (6) → Engine/WPHooks/ApiKeyAuth (8) → integrations (10)
- [x] Self-heal on `plugins_loaded@0` via `Installer::maybe_install` — covers CLI activation, restores, container clones
- [x] PSR-4 autoloader (`WBGam\` namespace, `src/` root) via composer
- [x] Class-hoist guard regression caught + gated (`bin/check-boot-invariants.php`, local-CI stage 2.13)

### Multi-currency / point types (6)
- [x] Two tables: `wp_wb_gam_point_types`, `wp_wb_gam_point_type_conversions`
- [x] `point_type` column on `wp_wb_gam_points` + `wp_wb_gam_events` + `wp_wb_gam_redemption_items`
- [x] `PointTypeService` resolves labels + slug fallbacks
- [x] 9 REST routes (`/point-types*`, `/conversions*`, `/convert`)
- [x] Atomic conversion: `START TRANSACTION` + `FOR UPDATE` + shared `event_id` linking debit + credit ledger rows
- [x] Resolution single source of truth: `Registry::resolve_action_point_type()` read by award AND rate-limit paths

### Engines (14 core, all booted via FeatureFlags::CORE_ENGINES)
- [x] BadgeEngine — rule-based + admin-awarded badges
- [x] ChallengeEngine — individual challenges
- [x] CommunityChallengeEngine — group challenges (with bonus-award listener installed in 1.5.0)
- [x] KudosEngine — peer kudos with cooldown
- [x] LogPruner — daily ledger cleanup
- [x] ActionSchedulerCleaner — daily AS-table prune + circuit breaker for runaway state (PERF-002)
- [x] RankAutomation — auto-rank assignment
- [x] PersonalRecordEngine — per-user weekly/monthly records
- [x] NotificationBridge — events → toasts via transient + 3 consumer cursors
- [x] Privacy — GDPR export/erase
- [x] CredentialExpiryEngine — `validity_days` + `expires_at` enforcement
- [x] TransactionalEmailEngine — per-event email gating, AS-backed delivery
- [x] LoginBonusEngine — daily login bonus with tier ladder
- [x] ProfilePage — public `/u/{user_login}` page with privacy gate, OG meta, Schema.org JSON-LD
- [x] MemberUploadCap — grants `upload_files` to logged-in members for the achievement editor (1.5.0)

### Optional engines (gated via flags, 8)
- [x] CohortEngine — Duolingo-style weekly leagues with promote/demote percentages
- [x] WeeklyEmailEngine
- [x] LeaderboardNudge — fixed the infinite-AS recursion in 1.5.0 (PERF-001)
- [x] StatusRetentionEngine
- [x] SiteFirstBadgeEngine
- [x] TenureBadgeEngine
- [x] BadgeSharePage — public OG-ready share URL
- [x] CohortSettingsPage — admin tier-name + percentages (5 tier slots from 1.5.0)

### Blocks (19)
- [x] All blocks on Wbcom Block Quality Standard (`--wb-gam-*` tokens, per-side spacing × 3 breakpoints, hover/shadow/border/visibility)
- [x] Per-block CSS now compiles for every block (cohort-rank + community-challenges fixed in 1.5.0)
- [x] hub (with new panel_blocks array supporting multi-block panels)
- [x] leaderboard, member-points, badge-showcase, level-progress
- [x] challenges, streak, top-members, kudos-feed
- [x] year-recap, points-history, earning-guide (two-row card layout in 1.5.0)
- [x] community-challenges (completed-state visual treatment in 1.5.0)
- [x] cohort-rank (tier-coloured accents in 1.5.0)
- [x] redemption-store (stock=0 → Unlimited contract aligned in 1.5.0)
- [x] submit-achievement (Add Media now works for members in 1.5.0)
- [x] user-status-bar (chevron toggle + theme-aware position in 1.5.0)
- [x] daily-bonus, give-kudos

### Admin (13 pages)
- [x] Settings (Points/Levels/Automation/Emails/Cohort tabs)
- [x] Setup Wizard with starter templates
- [x] Analytics Dashboard
- [x] Badge Library (with manual-award form in 1.5.0)
- [x] Challenge Manager
- [x] Manual Award Points
- [x] API Keys
- [x] Redemption Store
- [x] Community Challenges
- [x] Webhooks
- [x] Point Types
- [x] Point Type Conversions
- [x] Submissions queue
- [x] All admin write paths flow through REST (Tier 0 migration — `admin_post_*` count = 0)

### Local-CI gates (15 stages)
- [x] 1.1 PHP lint
- [x] 1.2 WPCS (skipped if vendor/bin/phpcs absent)
- [x] 1.3 PHPStan (level 9)
- [x] 1.4 JS build
- [x] 1.5 PHPUnit (109 tests)
- [x] 2.1 Coding rules (Rules 1-5 + 11)
- [x] 2.2 Architecture invariants
- [x] 2.3 Wbcom Block Quality Standard
- [x] 2.4 UX audit (ux-foundation compliance)
- [x] 2.5 Plugin-dev rules
- [x] 2.6 wppqa baseline freshness
- [x] 2.7 Enum drift (cross-layer contract)
- [x] 2.8 CSS orphans (PHP → CSS contract)
- [x] 2.9 Action sync/async invariant
- [x] 2.10 Critical event wiring
- [x] 2.11 Coverage floor
- [x] 2.12 WP.org Plugin Check (0 errors)
- [x] 2.13 Boot invariants (class-hoist guard contract) — **added 1.5.0**
- [x] 2.14 Badge condition contract (seed-vs-name) — **added 1.5.0**
- [x] 3.1 Manifest freshness
- [x] 4.1 Customer journeys

### Audit infrastructure
- [x] `audit/manifest.json` v2.2 canonical inventory (refreshed 1.5.0)
- [x] `audit/manifest.summary.json` ≤3 KB CLAUDE.md READ-FIRST companion
- [x] `audit/FEATURE_AUDIT.md`, `audit/CODE_FLOWS.md`, `audit/ROLE_MATRIX.md`
- [x] `audit/graph.html` (Cytoscape) for visual inventory browsing
- [x] `audit/derived/` cache for 16 static-analysis sub-checks (boot-hoist-guards added in 1.5.0)
- [x] `audit/journeys/` framework with 10 release-tier journeys
- [x] `audit/wppqa-baseline-latest/SUMMARY.md` failed=0 across 4 checks

### Bugs closed in 1.5.0 sweep (21 of 21)
- [x] #9914460166 Setup wizard not triggered on activation
- [x] #9914967346 UI of gamification activity
- [x] #9925151443 Real-time toasts (heartbeat → fast / 5s)
- [x] #9925589914 WC events beyond add_to_cart (payment_complete switch)
- [x] #9927383947 No redemption emails (whitelist fix)
- [x] #9932592127 Stock 0 shows "Out of stock" not "Unlimited"
- [x] #9932683754 PERF: LeaderboardNudge recursion (3.5M rows)
- [x] #9932684843 PERF: AS cleaner circuit-breaker + drain CLI
- [x] #9932736444 Add Media button missing for non-admin
- [x] #9932791974 Duplicate notifications (cursor-race dedupe)
- [x] #9932937581 Cohort Leagues block UI
- [x] #9932977222 Hub Challenges card missing community challenges
- [x] #9932980514 Community Challenges block UI
- [x] #9932994598 Completed community challenges disappear instantly
- [x] #9933021972 Community-challenge bonus dead-letter
- [x] #9933063928 First Comment badge default tied to 50 points
- [x] #9933079634 Auto-award badge condition audit
- [x] #9933208551 No admin UI for manually-awarded badges
- [x] #9933291154 Cohort tier names not reflecting on frontend
- [x] #9933306809 User-status block alignment + sticky panel
- [x] #9860091282 QA verification — Hub + Kudos + Display Blocks

---

## ⏳ Pending (10)

### Stable-foundation wave (both commits shipped)
- [x] **Release-prep orchestrator + drift-impossible generators** — `bin/cut-release.sh <X.Y.Z>` bumps version in 5 spots; `bin/build-readme.php` inlines feature counts from the manifest into `readme.txt`; `bin/build-docs-config.php` keeps `docs/website/docs_config.json` in sync with on-disk `.md` files (errors on dangling entries); `bin/build-blocks.js` safety-net copies orphaned per-block `style.css` into `build/` and warns when an `import './style.css'` is missing. `bin/cut-release.sh --check` proves idempotency by exiting non-zero on any drift.
- [x] **AS-schedule guard contract** — `bin/check-as-schedule-guard.php` (PHP token-walker) flags every `as_schedule_*` / `as_enqueue_*` call without an `as_has_scheduled_action()` guard in the same method AND without an `@as-fire-once` docblock annotation. The 7 existing fire-once call sites (`AsyncEvaluator::flush_queue`, `Engine::process_async`, `WeeklyEmailEngine::dispatch_batch`, `CommunityChallengeEngine::complete_challenge`, `WebhookDispatcher::dispatch`, `WebhookDispatcher::maybe_schedule_retry`, `TransactionalEmailEngine::send`) carry the annotation with a specific reason. Wired into `bin/coding-rules-check.sh` as Rule 12 — extends the existing stage 2.1, no new local-CI stage. Originally intended as a PHPStan rule, pivoted after PHPStan was found silently broken in the Local-by-Flywheel PHP build (exits 0 with zero output regardless of input — see `audit/` if added).

### Performance / scale (3)
- [ ] **Scale benchmark customisation** — `src/CLI/ScaleCommand.php` exists per skill scaffold but `BUDGETS_MS`, `seed()`, `benchmark()` bodies still ship as stubs. Customise for the actual hot paths (`PointsEngine::get_total`, `LeaderboardEngine::get_leaderboard`, `PointTypeService::list`) before claiming 100k-user readiness.
- [ ] **SSE / WebSocket transport** — Replace the 5s heartbeat poll for realtime toasts with a true push channel. Out of scope for the realtime card (#9925151443) but the obvious follow-up.
- [ ] **`wbGamRealtime.ping()` API surface** — Let user-driven actions force an immediate broker tick instead of waiting up to 5s. Reduces median latency further without changing transport.

### Architecture / hygiene (3)
- [ ] **Hooks_fired coverage gap** — manifest `.hooks_fired[]` has 43 action entries vs ground-truth 53, and 12 filter entries vs ground-truth 46. The `consumed_by[]` map needs a full re-enumeration so dead-listener detection works on the new `wb_gam_block_*_data` filters.
- [ ] **frontend_assets re-enumeration** — manifest tracks 6 CSS / 4 JS files vs ground-truth 24 / 19. CSS architecture refactor (admin.css per-page split) added 17+ files no one back-filled.
- [ ] **capabilities[] manifest coverage** — declares 7 of 10 caps. Missing: `wb_gam_manage_rules`, `wb_gam_manage_levels`, `wb_gam_manage_submissions`, `wb_gam_manage_email_settings`.

### Feature next-ups (4)
- [ ] **GraphQL API** — flexible queries for mobile / headless front-ends.
- [ ] **AI intelligence layer** — churn prediction, adaptive challenges, anti-gaming detection.
- [ ] **JS SDK** — `@wbcom/wb-gamification-js-sdk` for third-party integrators.
- [ ] **ActivityPub federation** — gamification events into the fediverse.

---

## How to use this file

- After a wave of work, add the new shipped items here (one bullet per logical unit, not one per commit).
- Move pending → shipped when verified end-to-end.
- Don't keep multiple parallel checklists — this is the single source. Older release-plan / bug-sweep / migration markdown files were folded in and removed.
- For the per-commit story: `git log --oneline`. For the per-feature story: `audit/FEATURE_AUDIT.md`. For the per-route shape: `audit/manifest.json`. This file is the **executive view**.
