# Architecture-Driven Plan (post-v1.0.0)

> **Single source of truth.** Every change below is grounded in the existing architecture (`TECH-STACK.md` design principles + `PRODUCT-VISION.md` core philosophy). No patches-on-patches; no random fixes; no duplicate or dead code.

**Generated**: 2026-05-02
**Status**: Active. Supersedes the per-item lists in `INTEGRATION-GAPS-ROADMAP.md` (which becomes historical context) and the recommendations at the end of `audit/FEATURE-COMPLETENESS-2026-05-02.md`.

## Why this document exists

The completeness audit surfaced ~12 items the team could fix. A "checklist of patches" is the wrong way to prioritize them. This plan classifies each item against the architecture, decides whether the underlying engine should exist at all, and only then schedules implementation. The work that lands is **architecturally consistent**, not opportunistic.

## Architectural principles (drawn from existing docs)

These are the existing principles. Every plan item below MUST cite which principle it serves.

| # | Principle | Source |
|---|---|---|
| **P1** | API-first, rendering-agnostic | `TECH-STACK.md` Design Principle 1 |
| **P2** | Event-sourced engine — events in, rules evaluate, effects out | `PRODUCT-VISION.md` Core Philosophy |
| **P3** | Three engine surfaces: event normalization, rule evaluation, output | `PRODUCT-VISION.md` |
| **P4** | Privacy by architecture, not by patch | `TECH-STACK.md` Design Principle 4 |
| **P5** | Progressively enhanced — every feature works on shared hosting | `TECH-STACK.md` Design Principle 6 |
| **P6** | Standards-compliant (OpenBadges 3.0, REST, OpenAPI) | `TECH-STACK.md` Design Principle 3 |
| **P7** | Layered architecture (Engine / API / Real-time / Intelligence / Frontend) — strict separation | `TECH-STACK.md` Layer Overview |

## Engine surface contract

Every engine in `src/Engine/` MUST be classifiable into ONE of these tiers. Engines that don't fit a tier either get reclassified (fix surfaces) or removed (no place in the architecture).

| Tier | Required surfaces | Examples |
|---|---|---|
| **User-facing** | Engine + REST + (Block OR Shortcode) + Admin (where configurable) | PointsEngine, BadgeEngine, LevelEngine, LeaderboardEngine, ChallengeEngine, KudosEngine, StreakEngine, RecapEngine, RedemptionEngine, CommunityChallengeEngine, CohortEngine |
| **Admin-only** | Engine + Admin (REST optional, no frontend by design) | RankAutomation, RuleEngine, WebhookDispatcher (REST is the admin), Capabilities, FeatureFlags |
| **Internal-only** | Engine only (no admin, no frontend, no REST) | Engine, Event, Registry, ManifestLoader, AsyncEvaluator, RateLimiter, DbUpgrader, Installer, Email, BlockHooks, Privacy, NotificationBridge (bridges to BP, not standalone), StatusRetentionEngine, LogPruner, NudgeEngine |
| **Cron-only** | Engine + cron registration (writes via existing surfaces of other engines) | TenureBadgeEngine, SiteFirstBadgeEngine, CredentialExpiryEngine, LeaderboardNudge, WeeklyEmailEngine |

A second classification dimension orthogonal to tier: **does the engine write user-visible state?** If yes, it MUST have a way for users to read that state — either through its own REST/block, or through another engine's. No engine accumulates state that nobody can see.

## Engine-by-engine audit against the contract

The completeness audit listed surface gaps. This table classifies each engine and decides on resolution.

| Engine | Tier | Compliant? | Resolution |
|---|---|---|---|
| `PointsEngine` | User-facing | ✓ | none |
| `LevelEngine` | User-facing | ✓ | none |
| `BadgeEngine` | User-facing | ✓ | none |
| `LeaderboardEngine` | User-facing | ✓ | none |
| `ChallengeEngine` | User-facing | ✓ | none |
| `KudosEngine` | User-facing | ✓ | none |
| `StreakEngine` | User-facing | ✓ | none |
| `RecapEngine` | User-facing | ✓ | none |
| `RedemptionEngine` | User-facing | ◐ admin + REST present, **no frontend block** | **Build `redemption-store` block** — closes a real surface gap. Without a frontend block site owners can't display the store; they have to embed REST consumers manually. |
| `CommunityChallengeEngine` | User-facing | ◐ admin only, **no REST + no frontend** | **Add REST routes + `community-challenges` block + shortcode**. Today members can't see challenges that exist for them. |
| `CohortEngine` | User-facing | ◐ admin only, **no REST + no frontend** | **Add REST route + `cohort-rank` block + shortcode**. Today members never see their cohort. |
| `WebhookDispatcher` | Admin-only | ◐ REST present, **no admin page** | **Add `WebhooksAdminPage`**. REST-only management is not the admin contract; this engine should be admin-tier with REST as an alternative entry point. |
| `RankAutomation` | Admin-only | ✓ admin tab in SettingsPage + REST via RulesController | none |
| `RuleEngine` | Admin-only | ✓ rules CRUD via RulesController + per-feature tabs in SettingsPage | none |
| `Capabilities` | Admin-only | ✓ granted on activation; no admin UI for granting (intentional — use User Role Editor) | none |
| `FeatureFlags` | Admin-only | ✓ option-based, no admin UI today (intentional v1) | none |
| `WeeklyEmailEngine` | Cron-only | ✓ writes via `wp_mail`; admin toggle in SettingsPage | none |
| `LeaderboardNudge` | Cron-only | ✓ writes via `wp_mail` + BP notifications | none |
| `TenureBadgeEngine` | Cron-only | ✓ writes via BadgeEngine | none |
| `SiteFirstBadgeEngine` | Cron-only | ✓ writes via BadgeEngine | none |
| `CredentialExpiryEngine` | Cron-only | ✓ writes via BadgeEngine + CredentialController | none |
| `StatusRetentionEngine` | Internal-only | ✓ pure cron worker, no surface needed | none |
| `LogPruner` | Internal-only | ✓ pure cron | none |
| `NudgeEngine` | Internal-only | ✓ generic dispatcher consumed by LeaderboardNudge | none |
| `AsyncEvaluator` | Internal-only | ✓ Action Scheduler integration | none |
| `RateLimiter` | Internal-only | ✓ called by other engines | none |
| `Engine`, `Event`, `Registry`, `ManifestLoader` | Internal-only | ✓ event bus core | none |
| `Privacy` | Internal-only | ✓ wires WP core privacy hooks | none |
| `NotificationBridge` | Internal-only | ✓ bridges plugin events to BP notifications | none |
| `DbUpgrader`, `Installer` | Internal-only | ✓ schema lifecycle | none |
| `Email`, `BlockHooks` | Internal-only (helpers) | ✓ cross-cutting | none |
| `ShortcodeHandler` | Internal-only | ✓ thin dispatcher | none |
| `BadgeSharePage` | User-facing (read-only) | ✓ public OG share URL via REST | none |
| `PersonalRecordEngine` | Internal-only | ✓ (reclassified after deeper review) | none — it produces `wb_gam_pr_best_week/day/month` user_meta that `WeeklyEmailEngine` reads to render the "Personal best!" callout. The completeness audit's flag was a false positive: this engine has a consumer, it's just not user-facing directly. Documented as Internal-only. |
| `CosmeticEngine` | **Unclassifiable as written** | ✗ engine + DB tables exist, ZERO surfaces. No admin to create cosmetics, no block to display, no REST to award. Pure dead code today. | **DECISION: REMOVE.** Engine class, the two DB tables (`wb_gam_cosmetics`, `wb_gam_user_cosmetics`), the `wb_gamification_cosmetic_granted` hook, and any references — all deleted. Add a one-line note to the v1.1.0 plan: "if cosmetics are revisited, design the full 4-surface contract (Engine + REST + Block + Admin) up front, don't ship the engine alone." |

The two unclassifiable engines (`PersonalRecord`, `Cosmetic`) are the only architectural violations. Everything else is either compliant or has a defined fix that lands a missing surface.

## Cross-cutting concerns — single helper per concern

The engine + API layers should share helpers for cross-cutting concerns. Today some are consolidated, some aren't.

| Concern | Helper | Status |
|---|---|---|
| Permission checks | `WBGam\Engine\Capabilities::user_can()` | ✓ DONE (PR #6, #9) |
| Email rendering | `WBGam\Engine\Email::render() / from_header()` | ✓ DONE (PR #13) |
| Block extension hooks | `WBGam\Engine\BlockHooks::before/after/filter_data()` | ✓ DONE (PR #14) |
| Logging | (none — every engine fails silently) | ✗ **NEW: `WBGam\Engine\Log`** |

Adding `Log` is the only outstanding cross-cutting helper. Every engine that has a silent failure path becomes a consumer of this helper. No engine should call `error_log()` directly after this lands.

## Consequence-driven implementation order

Working from the architecture above, the implementation order falls out naturally:

### Phase 1 — Cross-cutting helper (must come first)

**1.1** Add `WBGam\Engine\Log` helper. Every other change in this plan can use it.

### Phase 2 — Architectural violations (engines that don't fit)

**2.1** Remove `CosmeticEngine` + its DB tables + its hook + FeatureFlag + Installer + Doctor refs. Add a `1.1.0 → 1.1.0` migration in `DbUpgrader` that drops the two tables on existing installs. (Principle: P3 strict separation — engines without surfaces have no architectural place.)

**2.2** Reclassify `PersonalRecordEngine` as Internal-only (consumed by WeeklyEmailEngine via user_meta). No code change. (The completeness audit's flag was a false positive; corrected in the engine table above.)

### Phase 3 — Surface completion (engines missing one of their required surfaces)

**3.1** `WebhookDispatcher` ← `WebhooksAdminPage` (admin-tier engine missing admin).

**3.2** `RedemptionEngine` ← `redemption-store` block (user-facing engine missing frontend).

**3.3** `CommunityChallengeEngine` ← REST routes + `community-challenges` block + shortcode.

**3.4** `CohortEngine` ← REST routes + `cohort-rank` block + shortcode.

### Phase 4 — Operational tooling (closes G4 from gaps roadmap)

**4.1** `wp wb-gamification replay` CLI — re-evaluate badge rules against current cumulative state. Operational, not architectural — but the architecture supports it (event-sourced means replay is mechanically possible).

### Phase 5 — Test parity

**5.1** Tests for `BadgeEngine`, `LevelEngine`, `StreakEngine`, `RuleEngine` — the four most central untested engines.

## Order is non-arbitrary

Phase 1 must precede every other phase because Phase 1 (Log helper) gives subsequent phases a place to log their failures.

Phase 2 must precede Phase 3 because adding new admin pages and blocks should be against the *cleaned* engine set, not the messy one. Removing CosmeticEngine first means we don't accidentally add a Cosmetic admin page during Phase 3 confusion.

Phase 4 (replay CLI) needs the Log helper from Phase 1 to report what it did.

Phase 5 (tests) is last because tests should test the *final* engine shape, not the transient one.

## What's NOT in this plan (and why)

These items from the completeness audit are intentionally out of scope:

| Item | Reason for deferring |
|---|---|
| GraphQL surface | Phase 5+ per existing TECH-STACK roadmap. Standalone companion plugin, not core. |
| JS SDK | Phase 5+. The OpenAPI spec exists; SDK generation is a downstream concern. |
| Service container / DI | No concrete consumer asking. Speculative refactor. |
| Admin UI pluggability | Sibling top-level menus already work. |
| Block column-injection | Real consumer not asking. The hooks added in PR #14 are sufficient for the use cases we know about. |
| Mobile SDK | Phase 5+. |
| AI intelligence layer | Phase 5+. |
| ActivityPub / Fediverse | Phase 5+. |

## Acceptance for the whole effort

A single PR or a tightly-coupled series of PRs (with the same branch root) lands all of Phases 1-5. Acceptance:

- [ ] `Log` helper exists and is consumed in ≥6 distinct engine error paths
- [ ] CosmeticEngine + tables + references fully removed; uninstall script no longer references them
- [ ] PersonalRecordEngine removed OR collapsed into StreakEngine/LeaderboardEngine reads
- [ ] WebhooksAdminPage live, reachable from Gamification menu, gated by `wb_gam_manage_webhooks`
- [ ] redemption-store + community-challenges + cohort-rank blocks all render against their engines
- [ ] CommunityChallengeEngine + CohortEngine REST routes registered + documented in manifest
- [ ] `wp wb-gamification replay` CLI works (dry-run + real)
- [ ] 4 new test files (`BadgeEngineTest`, `LevelEngineTest`, `StreakEngineTest`, `RuleEngineTest`) load and run
- [ ] CI green (PHP lint × 5 + PHPStan + WPCS)
- [ ] Manifest still validates
- [ ] All 12 existing blocks still render (Hub smoke test)

## What this plan supersedes

- `plan/INTEGRATION-GAPS-ROADMAP.md` — G2/G1 already closed; G4 closed in Phase 4 here; G3/G5/G6/G7 stay deferred per the table above. The roadmap doc moves to historical-context status.
- `audit/FEATURE-COMPLETENESS-2026-05-02.md` recommended next moves — every numbered item is now mapped to a phase here, OR is in the deferred table above.

There is one plan, in this file. Execution follows.
