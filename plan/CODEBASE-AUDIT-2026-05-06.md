# Codebase Audit — what we actually have vs the competitive-gap claims

**Date:** 2026-05-06
**Scope:** ground-truth verification of every claim in `plan/COMPETITIVE-ANALYSIS.md` against current code, plus organisational recommendations for long-run scale.
**Verdict:** the gap list is shorter than first claimed — substantial infrastructure already exists for several "missing" features.

---

## Stats

- **30,786 lines of PHP** in `src/`
- **20 domain engines** (`*Engine.php`) + 19 utility classes + 1 page class in `src/Engine/`
- **22 REST controllers** in `src/API/`
- **15 blocks** in `src/Blocks/<slug>/`
- **11 admin pages** in `src/Admin/`
- **9 CLI commands** in `src/CLI/`
- **22 unit test files** in `tests/Unit/{Admin,Blocks,Engine}/`
- **20 DB tables** declared in `Installer.php`

## What we have that wasn't on the marketing list

Discovered during this audit — these are real built features that didn't surface in the competitive analysis. Update marketing copy to lead with them:

| Feature | File | Why it's marketable |
|---|---|---|
| **Email template renderer with theme overrides** | `src/Engine/Email.php` | Locates templates via `{theme}/wb-gamification/emails/...` — same pattern as WooCommerce; child themes win over parent |
| **Weekly digest engine** | `src/Engine/WeeklyEmailEngine.php` | Personalised Monday email per active member. Action Scheduler-driven — no PHP timeouts |
| **Rule Engine with points multipliers** | `src/Engine/RuleEngine.php` | Phase 0: `points_multiplier` rule type with `day_of_week`, `action_id_match`, `metadata_gte` conditions. Already in place |
| **Async evaluator** | `src/Engine/AsyncEvaluator.php` | Heavy rule eval offloaded to background — keeps page response fast |
| **Webhook dispatcher with retry** | `src/Engine/WebhookDispatcher.php` | HMAC-signed; cron-driven retry queue (already mentioned but worth reiterating) |
| **Personal records engine** | `src/Engine/PersonalRecordEngine.php` | "Your best week ever" callouts — fertile ground for engagement copy |
| **Site-first / tenure / credential-expiry badge engines** | `Engine/SiteFirstBadgeEngine.php`, `TenureBadgeEngine.php`, `CredentialExpiryEngine.php` | "First N members" badges, "X years here" badges, time-bound credentials |
| **Status retention engine** | `src/Engine/StatusRetentionEngine.php` | Levels can decay — "use it or lose it" mechanic (rare in competitors) |
| **Rank automation** | `src/Engine/RankAutomation.php` | Auto-assign ranks based on rules |
| **Nudge engine + leaderboard nudge** | `src/Engine/NudgeEngine.php`, `LeaderboardNudge.php` | "You're 50pts from rank 3 — keep going" — engagement-loop polish |
| **Notification bridge (toast pipeline)** | `src/Engine/NotificationBridge.php` | Interactivity-API-driven toasts; transient-flush per request |
| **Privacy / GDPR compliance hooks** | `src/Engine/Privacy.php` | Ties into WP Privacy tools — data export + erasure |
| **Async log + log pruner** | `src/Engine/Log.php`, `LogPruner.php` | Self-rotating audit log |
| **Feature flags** | `src/Engine/FeatureFlags.php` | Per-feature rollout controls |
| **Rate limiter** | `src/Engine/RateLimiter.php` | Per-action, per-user rate limits |
| **`wp_login` + `wp_first_login` actions** | `integrations/wordpress.php:40` | Login points already wired (`wp_login` repeatable; `wp_first_login` once = 10pts) |

## Re-evaluation of the 5 v1.0 critical gaps

| # | Gap as claimed | Reality | Revised effort |
|---|---|---|---|
| 1 | Multiple point types | **Truly missing.** Schema has single `points` column, no `type`. | **L** (unchanged — schema migration + back-compat shim) |
| 2 | Transactional emails (level-up / badge / digest) | **Mostly built.** `Email.php` template renderer + `WeeklyEmailEngine` + `templates/emails/weekly-recap.php` already shipping. We just need 2-3 additional templates (level-up, badge-earned, challenge-completed) wired to existing event hooks. | **XS** (was S) — templates only |
| 3 | Public profile pages (`/u/{slug}`) | **Truly missing.** Hub block exists but no permalink template. | **S** (unchanged) |
| 4 | Login streak / daily-login bonus | **Partly built.** `wp_login` + `wp_first_login` actions exist; `StreakEngine` is timezone-aware with milestones at 7/14/30/60/100/180/365 days. We have everything except the marketing-distinct "login-only streak" branding + a daily-bonus tier UX block. | **XS** (was S) — extend StreakEngine with `type` column and add bonus-tier display block |
| 5 | Front-end achievement submission (UGC) | **Truly missing.** No submissions table, no UGC controller. | **M** (unchanged) |

**Net: of the 5 critical gaps, only 2 are net-new builds (multi-point types, UGC). The other 3 are extensions / polish on existing infra.**

That changes the v1.0 timeline meaningfully — closer to 2 sprints than 3.

## Re-evaluation of the 5 v1.1 high-value gaps

| # | Gap as claimed | Reality | Revised effort |
|---|---|---|---|
| 6 | Referral system | **Truly missing.** Zero matches in code. | **S** (unchanged) |
| 7 | Time-bound campaigns + multipliers | **50% built.** `RuleEngine` already supports `points_multiplier` rule_type with conditions. Just need: time-window condition (start/end timestamps), admin UI for campaign CRUD, frontend banner block. | **S** (was M) |
| 8 | Slack / Discord templates | **Webhook engine exists.** `WebhookDispatcher` with HMAC + retry already in place. Just need Slack-Block-Kit + Discord-Embed payload formatters and a setup wizard. | **XS** (was S) |
| 9 | Web push notifications | **Truly missing.** Net-new infra (VAPID + service worker + subscription table). | **M** (unchanged) |
| 10 | Visual rule builder | **Storage exists.** `wb_gam_rules` table is in place; `RuleEngine` already evaluates. Just need a visual editor UI on top. | **L** (still — UX is the project) |

## Updated build narrative for marketing

The story isn't "we're missing 10 things vs GamiPress". It's:

> **What we already have**: 20 domain engines, 51 REST endpoints, 15 native Gutenberg blocks, OpenBadges credential export, 37-SVG bundled badge library, weekly email engine with theme overrides, rule engine with points multipliers, async evaluator, webhook dispatcher with HMAC + retry, GDPR hooks, feature flags, rate limiter, timezone-aware streak engine.
>
> **What v1.0 adds**: multiple point types (true ledger split), public profile permalink, UGC achievement submission, plus polish on emails (more templates) and login streak (dedicated block).
>
> **What v1.1 adds**: referrals, time-bound campaigns (RuleEngine already evaluates them — just adds the time-window condition), Slack / Discord templates (riding existing webhook engine), web push, visual rule builder.

That positioning is far stronger than "feature-parity coming in v1.0."

---

# Code organisation for long-run scale

## Current shape (the good)

- **PSR-4 autoloading** maps `WBGam\` → `src/` cleanly
- **One class per file** convention is followed
- **Manifest-driven extension** is in place — `ManifestLoader` reads `integrations/*.php` for action discovery; new integrations don't touch core
- **Admin / API / Blocks / CLI / Engine / Integrations** are top-level subnamespaces — clear separation of concerns
- **Tests mirror src/** for Engine, Admin, Blocks (Unit tier only currently)
- **Block standard** compliance — every block under `src/Blocks/<slug>/` has block.json + render.php + index.js + view.js
- **Modern arch** — 0 `admin_post_*`, 0 `wp_ajax_*`; everything REST-driven

## Current shape (the issues for scale)

### Issue 1 — `src/Engine/` is becoming a kitchen sink (40 files)

Mixes:
- Core orchestration (`Engine.php`, `Event.php`, `Registry.php`, `ManifestLoader.php`)
- Domain engines (`PointsEngine`, `BadgeEngine`, `ChallengeEngine`, `KudosEngine`, `LeaderboardEngine`, `LevelEngine`, etc.)
- Specialised badge engines (`SiteFirstBadgeEngine`, `TenureBadgeEngine`, `CredentialExpiryEngine`)
- Cross-cutting infra (`Privacy`, `NotificationBridge`, `WebhookDispatcher`, `RateLimiter`, `FeatureFlags`)
- Utilities (`Log`, `LogPruner`, `AsyncEvaluator`)
- Compliance / install (`Installer`, `DbUpgrader`, `Capabilities`)
- Rendering (`BlockHooks`, `ShortcodeHandler`, `BadgeSharePage`)

Onboarding pain — a new dev opening `src/Engine/` sees 40 files with no grouping. Hard to find related files.

### Issue 2 — Domain bleed between `integrations/*.php` and `src/Integrations/` + `src/BuddyPress/`

- WooCommerce: `integrations/woocommerce.php` (top-level manifest) + `src/Integrations/WooCommerce/` (PSR-4 logic)
- BuddyPress: `integrations/buddypress.php` + `src/BuddyPress/` — different parent namespace from WC
- LearnDash: `integrations/learndash.php` only — no `src/Integrations/LearnDash/`

Inconsistent: BuddyPress should arguably live under `src/Integrations/BuddyPress/` for parity with WC.

### Issue 3 — Tests are Unit-tier only

`tests/Unit/{Admin,Blocks,Engine}/` exists; no `tests/Integration/` or `tests/REST/`. As REST surface grows (51 routes), without an HTTP-tier test suite, regressions in cross-controller behaviour get caught only in browser journeys.

### Issue 4 — `src/shared/` is empty

Placeholder dirs (`shared/components`, `shared/hooks`, `shared/utils`) but no files. Either delete them or populate — empty placeholders confuse new devs.

## Aligning with the canonical Wbcom 7-layer architecture

Per the `wp-plugin-development` skill (`references/layered-architecture.md`), every Wbcom plugin should split into 7 layers: Bootstrap → Container → Repository → Services → Surface adapters → Templates → Admin UI.

Today's WB Gamification deviates in two places:

| Layer | Canonical | WB Gamification | Status |
|---|---|---|---|
| 2. Container | `Core/ServiceContainer.php` with explicit DI | Implicit via `Registry` singletons | ⚠ partial — works, but no explicit DI |
| 3. Repository | `Repository/<Domain>Repository.php` (SQL only) | DB queries inlined in `Engine/*Engine.php` | ❌ Repository layer not extracted |
| 4. Services | `Services/<Capability>Service.php` (one class per capability) | Engines mix service + repository concerns | ⚠ engines do both jobs |

**This is acceptable for v1.0** — engines are stable, tested (108 unit tests, PHPStan level 5 clean), and refactoring 40 files pre-launch is risky. **Tracked as a v1.x roadmap item** in the recommendations below.

When adding new code (e.g. the 5 v1.0 critical-gap features), **build to the canonical layers from day one**. Even though existing code is mixed, new domains should ship with a Repository + Service split — that way the v1.x extraction sprint only has to refactor old code, not new.

## Recommendations — three tiers, do them in order

### Tier 1: Document conventions (zero refactor — do this BEFORE v1.0)

Add `plan/ARCHITECTURE.md` with:
- Top-level src/ namespace map ("Admin = pages, API = REST controllers, Engine = business logic, ...")
- "Where does X go?" decision tree (e.g. "new badge variant → `src/Engine/Badges/...`; new admin page → `src/Admin/<X>Page.php`; new REST controller → `src/API/<X>Controller.php`")
- Explicit rule: domain engines END with `Engine.php`; controllers END with `Controller.php`; admin pages END with `Page.php`. Already mostly true — just lock it in CI via `bin/coding-rules-check.sh`
- Extension contract — what manifests look like, which filters are public, which are internal

Cost: 1 day. Impact: every future PR knows where to add code. **Do this regardless of when we refactor.**

### Tier 2: Subnamespace `src/Engine/` (low-risk refactor — post-v1.0)

Group the 40 files into ~6 subnamespaces. Pure namespace + folder move; no logic changes. PHPStan + tests catch any miss.

```
src/Engine/
├── Core/                # Engine, Event, Registry, ManifestLoader, Installer, DbUpgrader, Capabilities
├── Points/              # PointsEngine, RateLimiter, RuleEngine
├── Badges/              # BadgeEngine, SiteFirstBadgeEngine, TenureBadgeEngine, CredentialExpiryEngine, BadgeSharePage
├── Progression/         # LevelEngine, StreakEngine, ChallengeEngine, CommunityChallengeEngine, CohortEngine
├── Engagement/          # KudosEngine, LeaderboardEngine, NudgeEngine, LeaderboardNudge, PersonalRecordEngine, StatusRetentionEngine, RankAutomation, RecapEngine, RedemptionEngine
├── Notifications/       # NotificationBridge, Email, WeeklyEmailEngine, WebhookDispatcher
├── Compliance/          # Privacy, FeatureFlags
├── Utilities/           # Log, LogPruner, AsyncEvaluator
└── Rendering/           # BlockHooks, ShortcodeHandler
```

Cost: 1-2 days (mechanical move + namespace updates + run all tests). Impact: file-discoverability goes from "where the hell is this" to "obvious by feature".

### Tier 3: Domain-driven layout (only at v2.0 if codebase keeps growing)

For each major domain, gather every concern (engine + controller + admin page + block + integration) into one folder. This is the "feature folder" pattern (popular in larger codebases).

```
src/
├── Domain/
│   ├── Points/
│   │   ├── Engine.php (was PointsEngine)
│   │   ├── Controller.php (was API/PointsController)
│   │   ├── AdminPage.php (was Admin/ManualAwardPage)
│   │   ├── Block_MemberPoints.php
│   │   ├── Schema.php (extracted from Installer)
│   │   └── Tests/
│   ├── Badges/...
│   ├── Levels/...
│   └── ...
├── Platform/         # Cross-cutting infra (REST server, auth, DB upgrader, log)
├── Integrations/     # Third-party adapters (BP, WC, LD, ...)
└── shared/
```

Cost: 1-2 weeks. Risk: every namespace + use statement changes; every doc reference needs update; pre-existing PHPStan ignores need re-ratchet. **Only worth it at a major version where breaking changes are acceptable.**

## Other long-run recommendations

1. **Add an Integration test tier.** `tests/Integration/REST/` with `WP_REST_Request` round-trips per controller — catches schema drift, contract regressions, auth-mode bugs. Do this in parallel with v1.0 since the surface keeps growing.

2. **ADR folder.** Create `plan/decisions/`. One markdown file per significant decision: "ADR-001: why we keep custom $_POST in admin (not Settings API)", "ADR-002: why event-sourced", "ADR-003: why bundle 37 SVGs as PHP defaults". Future-you will thank present-you.

3. **Each domain exposes a public facade.** Block render PHP and integrations should not call `\WBGam\Engine\PointsEngine::award()` directly — they should call a stable facade like `\WBGam\API\Points::award()` so the engine internals can refactor without breaking dependents.

4. **PHPStan ratchet.** We're at level 5 with 0 errors. Push to level 6 in v1.0 work, level 7 in v1.1, etc. Lock the level via CI so it can't regress.

5. **Documentation generator.** OpenAPI is already published at `/openapi`. Add a CI step that converts it to Markdown for `docs/website/api-reference/` so external dev docs auto-update with the code.

6. **Drop `src/shared/` placeholders** until they have actual content. Empty dirs are noise.

7. **Move BuddyPress under `src/Integrations/BuddyPress/`** for parity with WooCommerce. Small refactor, large clarity win.

## What to do this week

- [ ] Update `plan/COMPETITIVE-ANALYSIS.md` with corrected effort estimates (XS for emails + login streak; S for campaigns + Slack/Discord)
- [ ] Update `plan/v1.0-release-plan.md` with revised effort table
- [ ] Update the 5 critical-gap basecamp cards to reflect "polish existing" vs "build new" distinction
- [ ] Write `plan/ARCHITECTURE.md` (Tier 1 above) — 1 day's work, unblocks every future PR

## References

- `plan/COMPETITIVE-ANALYSIS.md`
- `plan/v1.0-release-plan.md` / `v1.1-release-plan.md`
- `audit/manifest.json` — canonical inventory
- `audit/CLOSE-OUT-2026-05-02.md` — last big internal milestone

Updated by Varun — 2026-05-06.
