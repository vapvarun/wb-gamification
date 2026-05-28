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

## ✅ v2 work shipped on 1.5.0 HEAD

- [x] **v2.1 Decouple side effects** — `SideEffectDispatcher` + `wb_gam_side_effect_failures` table + hourly reconciler cron. Engine refactored to fan-out through dispatcher (try/catch isolation, retry up to 3 times, status='exhausted' for human triage). 5 PHPUnit tests cover success + failure-isolation + retry-success + retry-exhausted + orphan-handler paths.
- [x] **v2.2 Notifications-queue durability (write-side)** — new `wb_gam_notifications_queue` table + dual-write from `NotificationBridge::push()`. Transient flush no longer strands user_meta cursors because the durable backup survives. Daily prune cron with 24h retention. Reader-side switch deferred to a follow-up commit; existing transient reads keep working unchanged. Re-scoped Finding C in `STABILITY-AND-ARCHITECTURE-V2.md` after re-reading code (the 3-cursor design is intentional, not a band-aid).
- [x] **SSE stages 2 + 3** — Streaming loop in `SSEController::stream()` long-polls the notifications-queue table (consolidates the originally-planned `wb_gam_sse_events` table into the v2.2 queue). All 6 environment hazards handled (PHP-FPM session lock, output buffering, max_execution_time, nginx + Cloudflare buffering, REST JSON wrap, connection abort). `assets/js/sse.js` dispatches named-event types into the `wbGamRealtime` broker via new `_dispatch()` write-side API in `heartbeat.js` — existing toast/leaderboard consumers receive SSE-delivered events transparently. Still feature-flagged off by default; stage 4 (journey test + flag flip to `'auto'`) remaining.
- [x] **v2.2b Reader-side switch to durable queue** — `NotificationBridge::read_pending()` now prefers the `wb_gam_notifications_queue` table when the feature flag is set, falling back to the transient only on installs that haven't seen the migration. New `read_pending_from_table()` private method runs the cursor-aware query (`WHERE user_id = ? AND id > cursor ORDER BY id ASC LIMIT 50`), decodes payload_json, and overwrites the event's `_id` with the table's authoritative id so the toast.js dedupe key stays coherent across storage paths. Pre-existing user_meta cursors self-heal: any lingering value is at worst harmlessly low (sees a few events twice on first read) since table ids are globally monotonic. v2.2 is now durability-complete.
- [x] **v2.4 Boot order contract** — `src/Engine/BootOrder.php` introduces `SLOT_SCHEMA` / `SLOT_REGISTRY` / `SLOT_CORE` / `SLOT_INTEGRATIONS` / `SLOT_OPTIONAL` priority constants + a `register(slug, slot, depends_on[])` API + a `plugins_loaded@99` validator that logs warnings when an engine declares a dependency at a LATER slot than itself. Wired into wb-gamification.php: 13 `add_action('plugins_loaded', …, $priority)` lines migrated to use `BootOrder::SLOT_*` constants with explicit `BootOrder::register(slug, slot, depends_on)` declarations. Closes Finding A (silent-boot from wrong-priority engine registration). 5 PHPUnit tests cover register-records-state, validate-passes-on-correct-order, validate-handles-later-slot-dependency, validate-handles-unregistered-dep, and slot-constants-are-monotone.
- [x] **GraphQL extension** — `src/Integrations/GraphQL.php` registers 4 GraphQL object types (WBGamMember, WBGamBadge, WBGamLeaderboardEntry, WBGamIntelligence) + 3 root query fields (wbGamMember, wbGamLeaderboard, wbGamIntelligence) when WPGraphQL is loaded. No-op when WPGraphQL is absent (graphql_register_types hook never fires). Resolvers delegate to existing engine methods — no business-logic duplication. Same authz rules as REST (admins see all intelligence, users see their own only).
- [x] **ActivityPub federation** — `src/Integrations/ActivityPub.php` listens for `wb_gam_badge_awarded` / `wb_gam_level_changed` / `wb_gam_challenge_completed`, publishes AS2 activities to the WP ActivityPub plugin's outbox via `\Activitypub\add_to_outbox()`. Site-level + per-user opt-in (both off by default — `wb_gam_activitypub_publish` option + `wb_gam_federate_events` user_meta). Activities use AS2 + OpenBadges v3 context. points_awarded deliberately NOT federated (would saturate timelines). Failure-isolated — federation errors log + skip, never break the gamification flow.
- [x] **SSE stage 4-prep: operational documentation** — `docs/website/developer-guide/realtime-transport.md` documents the transport options, the host requirements (nginx proxy_buffering, Cloudflare Auto Minify, PHP output_buffering), how to verify SSE works via DevTools Network tab, and the heartbeat-interval filter.
- [x] **SSE stage 4: browser-verified + default flipped to 'auto'** — Playwright two-tab journey ran: alice → bob kudos via `do_action('wb_gam_kudos_given')`, bob's tab received the toast through the broker (both heartbeat path + SSE path, toast.js Set dedupe collapses the duplicate). Discovered + fixed two bugs along the way: (1) `dev-auto-login.php` mu-plugin didn't switch users when already logged in (cross-user testing failed silently); patched to log-out + re-login when target differs. (2) WordPress REST cookie-auth requires X-WP-Nonce header which EventSource can't send; added `rest_authentication_errors` filter to bypass nonce check ONLY for `/events/stream`, then direct `wp_validate_auth_cookie()` in `check_permission()` as belt + suspenders. Default flipped: `SSEController::get_transport()` returns `'auto'` for unset option, fresh installs get SSE-with-heartbeat-fallback out of the box.
- [x] **Points-toast aggregation (client-side)** — `assets/js/toast.js` now merges consecutive points-typed toasts arriving within a 2-second window into the previously-visible toast: bumps the total, increments action_count, hides the per-action detail, resets the dismiss timer. Closes the "5 clicks = 5 stacked toasts" UX noise. Server-side queue stays as individual rows for audit/SSE/GraphQL — aggregation is presentation-only.
- [x] **v2.5 + AI v1: behavioural intelligence projection** — first read-side projection on the engine's immutable event log. New `wb_gam_user_intelligence` table (one row per user, denormalised signals) populated by `src/Engine/IntelligenceProjector.php` on the daily `wb_gam_compute_intelligence` cron (BATCH_SIZE=2000 users per tick, least-recently-computed first). Heuristic v1 — no model training, pure SQL aggregates over `wb_gam_events`: engagement_score = log10(events_30d+1) × min(diversity/5, 1.0) × max(0, 1 - recency/30); churn_risk = 1 - normalised(engagement); anomaly_flag = (events_30d > 500 AND diversity < 3). Exposed via new `GET /members/{id}/intelligence` REST endpoint (admins see anyone's; users see their own only) + SDK method `getMemberIntelligence(userId)`. Validates the projection pattern for future read-side projections (GraphQL extension, multi-server projections via Redis, etc.).

## ⏳ Pending (1)

> See [`STABILITY-AND-ARCHITECTURE-V2.md`](STABILITY-AND-ARCHITECTURE-V2.md) for the data-flow trace + v2 architecture trajectory that contextualises these items. The remaining work is no longer "build infrastructure" — it's feature projection on top of the foundation we now have.

### Stable-foundation wave (shipped 2026-05-28, all 3 commits)
- [x] **Release-prep orchestrator + drift-impossible generators** — `bin/cut-release.sh <X.Y.Z>` bumps version in 5 spots; `bin/build-readme.php` inlines feature counts from the manifest into `readme.txt`; `bin/build-docs-config.php` keeps `docs/website/docs_config.json` in sync with on-disk `.md` files (errors on dangling entries); `bin/build-blocks.js` safety-net copies orphaned per-block `style.css` into `build/` and warns when an `import './style.css'` is missing. `bin/cut-release.sh --check` proves idempotency by exiting non-zero on any drift.
- [x] **AS-schedule guard contract** — `bin/check-as-schedule-guard.php` (PHP token-walker) flags every `as_schedule_*` / `as_enqueue_*` call without an `as_has_scheduled_action()` guard in the same method AND without an `@as-fire-once` docblock annotation. The 7 existing fire-once call sites (`AsyncEvaluator::flush_queue`, `Engine::process_async`, `WeeklyEmailEngine::dispatch_batch`, `CommunityChallengeEngine::complete_challenge`, `WebhookDispatcher::dispatch`, `WebhookDispatcher::maybe_schedule_retry`, `TransactionalEmailEngine::send`) carry the annotation with a specific reason. Wired into `bin/coding-rules-check.sh` as Rule 12 — extends the existing stage 2.1, no new local-CI stage. Originally intended as a PHPStan rule, pivoted after PHPStan was found silently broken in the Local-by-Flywheel PHP build (exits 0 with zero output regardless of input — see `audit/` if added).
- [x] **OpenAPI 3.0.3 spec artefact** — `wp wb-gamification openapi export` command (`src/CLI/OpenApiCommand.php`) writes `audit/openapi.json` (56 paths, 131 KB) by calling the new `OpenApiController::build_spec()` helper — same builder serves the runtime `/wb-gamification/v1/openapi.json` endpoint. `--check` mode is wired into `bin/cut-release.sh` as the third drift gate alongside readme.txt + docs_config.json. The committed artefact is the contract surface for the upcoming JS SDK, GraphQL extension, and any AI/headless consumer. Lives in `audit/` (canonical inventory) rather than `dist/` (gitignored build output).
- [x] **JS SDK toolchain wired from OpenAPI** — `sdk/` package now installs cleanly (`npm install`), regenerates `sdk/src/openapi.d.ts` from `audit/openapi.json` via openapi-typescript v7 (`npm run gen-types`, 3568 lines covering all 56 paths), and builds via `tsc` to a shippable `dist/` (index, client, types, openapi.d.ts all emitted). SDK version syncs to the plugin version automatically through `bin/cut-release.sh`. Added a fourth drift gate to `cut-release --check`: shasum of `sdk/src/openapi.d.ts` must equal a fresh regen against the current `audit/openapi.json`. Hand-written client coverage is still 9 / 56 endpoints — that's NOT foundation work (it's incremental method expansion) and lives as a separate pending item below. The toolchain is the foundation move; method expansion is feature work.

### Performance / scale (1 — SSE in 4 stages, stage 1 shipped)
- [x] **Scale benchmark VERIFIED at 100k rows (2026-05-28)** — plan was stale: `src/CLI/ScaleCommand.php` was already implemented (not stubs), just never executed. Ran `seed --users=10000 --events-per-user=10` (100,015 rows / 10,003 users), then `benchmark` — all 6 hot-path queries PASS with 3x–300x headroom against `BUDGETS_MS`. The "Scalable — built for 100K+ members" claim in `readme.txt` is no longer faith-based. Baseline + procedure recorded in `audit/scale-baseline.md`. Not wired into local-CI (seeding costs ~2s per run; the existing PHPStan / WPCS / WP Plugin Check stages catch the regression categories this would). Re-run on schema changes touching `wb_gam_points` / `wb_gam_user_totals` / `wb_gam_leaderboard_cache`.
- [ ] **SSE / WebSocket transport** — Stage 1 (scaffold) shipped 2026-05-28. `src/API/SSEController.php` registers `/events/stream` (returns 503 with `Retry-After` when feature flag is off — default). `assets/js/sse.js` probes the flag and no-ops if disabled. Full design in [`REAL-TIME-TRANSPORT.md`](REAL-TIME-TRANSPORT.md) covers 6 environment hazards (PHP-FPM session lock, output buffering, max_execution_time, nginx + Cloudflare buffering, REST framework JSON wrapping, floating connections). Stages 2–4 remaining: storage table + writer; streaming loop; journey test + opt-in default flip. WebSocket rejected as wrong tool (no bidirectional traffic; SSE wins on shared hosting + Cloudflare + auto-reconnect).

### Already closed (no action)
- [x] **`wbGamRealtime.ping()` API surface** — was listed pending, actually shipped. `window.wbGamRealtime.ping()` exists in `assets/js/heartbeat.js` (calls `wp.heartbeat.connectNow()`). What WAS missing was the wiring: no user-action handler called it. Wired on 2026-05-28 in 3 success paths — give-kudos POST, submit-achievement POST, redemption-store redeem. Median visible-toast latency on those flows drops from up-to-5s (waiting for next heartbeat tick) to <1s. hub-convert reloads the page on success so a ping is moot there; admin-test-event awards to the admin themselves and the toast is on the Hub (separate page), so the success message already directs the user there.

### Architecture / hygiene (0 — closed by generators)
- [x] **Hooks_fired** rebuilt by `bin/build-hooks-fired.php`. Was 43 actions + 12 filters (and 11 of the 12 filters referenced `wb_gamification_*` hooks that don't exist in code — outright fabrication). Now 57 actions + 59 filters covering every `do_action()` / `apply_filters()` call site under `src/` + `integrations/`. Each entry carries `fired_at[]` (the `file:line` of every call site) and `consumed_by[]` (matching `add_action()` / `add_filter()` registrations parsed from grep). Curated `purpose` strings are preserved across regen. Wired as the 5th drift gate in `bin/cut-release.sh --check`.
- [x] **frontend_assets** rebuilt by `bin/build-frontend-assets.php`. Was 6 CSS / 4 JS in a flat list. Now structured as `css.{global,admin,blocks}` + `js.{global,blocks}` covering 40 CSS + 41 JS canonical sources (drops `*.min.*` + `*-rtl.*` variants generated by `npm run build:min` / `build:rtl`). 6th drift gate in `cut-release --check`.

### Already closed (no action)
- [x] **capabilities[] manifest coverage** (was listed pending, actually shipped) — manifest declares all 11 caps including the 4 supposedly missing (`wb_gam_manage_rules`, `wb_gam_manage_levels`, `wb_gam_manage_submissions`, `wb_gam_manage_email_settings`). Verified by `jq '.capabilities | length, [.[].cap]' audit/manifest.json` on 2026-05-28.

### Feature next-ups (all shipped — see "v2 work shipped" above)
- [x] **GraphQL API** — `src/Integrations/GraphQL.php`. 4 types + 3 root queries when WPGraphQL is loaded; no-op otherwise.
- [x] **AI intelligence layer** — `src/Engine/IntelligenceProjector.php` heuristic v1 (engagement / churn / diversity / anomaly), daily cron, REST endpoint, SDK helper, GraphQL type.
- [x] **JS SDK method coverage** — `sdk/src/client.ts` 10 → 65 typed methods covering all 58 REST routes.
- [x] **ActivityPub federation** — `src/Integrations/ActivityPub.php` Outbox publisher. Site + per-user opt-in; no-op without WP ActivityPub plugin.

---

## How to use this file

- After a wave of work, add the new shipped items here (one bullet per logical unit, not one per commit).
- Move pending → shipped when verified end-to-end.
- Don't keep multiple parallel checklists — this is the single source. Older release-plan / bug-sweep / migration markdown files were folded in and removed.
- For the per-commit story: `git log --oneline`. For the per-feature story: `audit/FEATURE_AUDIT.md`. For the per-route shape: `audit/manifest.json`. This file is the **executive view**.
