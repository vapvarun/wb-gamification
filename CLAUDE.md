# WB Gamification — CLAUDE.md

> **READ FIRST:** [`audit/manifest.json`](audit/manifest.json) is the canonical inventory — **112 REST handlers** (75 unique routes), **26 tables**, **19 blocks**, **17 shortcodes**, **142 hooks fired** (66 actions / 76 filters), **16 cron hooks** (incl. Action Scheduler), **10 WP-CLI commands**, **15 admin pages**, **49 services**, **24 integrations / 126 triggers**, **0 admin_post_* handlers** (Tier 0 REST migration intact), **0 wp_ajax_* handlers**. Quick index: [`audit/manifest.summary.json`](audit/manifest.summary.json) (≤3 KB). Buyer-level roll-up: [`CAPABILITIES.md`](CAPABILITIES.md). Refresh via `/wp-plugin-onboard --refresh` after non-trivial changes.
>
> **Trust order:** `audit/manifest.summary.json` (code-verified) > [`CAPABILITIES.md`](CAPABILITIES.md) > code > everything else. Dated snapshots ([`audit/STABILITY-2026-05-27.md`](audit/STABILITY-2026-05-27.md), `audit/wppqa-baseline-*`) are **history — verify against the trail before trusting**.
>
> ⚠️ **`manifest_refresh: agent-enumeration-only`.** `header`, `rest` and `shortcodes` come from the LIVE registries: `wp eval-file bin/refresh-manifest-live.php`. Do **NOT** run `bin/write-manifest.mjs` on this plugin. Its tracer only sees direct calls, and this codebase registers through wrappers — it zeroes **shortcodes** (0 vs 17 real, registered via `src/Engine/ShortcodeHandler.php`), collapses **cron** (1 vs 16 — Action Scheduler + `self::CONST` hooks), and undercounts **REST** (62 vs 76 — routes built by concatenation, not literals). It will silently overwrite a good manifest with a worse one. If it is ever run: `git checkout -- audit/manifest.json` and enumerate by hand.
>
> **Latest QA baseline:** [`audit/wppqa-baseline-2026-09-27/SUMMARY.md`](audit/wppqa-baseline-2026-09-27/SUMMARY.md) — no new real finding in our code; its api/admin-eval "criticals" are scanner false positives (routes built by concatenation, the SettingsPage dispatcher), disproved in [`audit/admin-hardening-triage.md`](audit/admin-hardening-triage.md). **All 5 HIGH security findings are in bundled third-party libs** (`libs/woocommerce/action-scheduler`, `libs/easy-digital-downloads`) — our own code has zero. Do not "fix" vendored libs; the patch is lost on re-vendor.
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
| **Version** | 1.6.5 (in development, branch `1.6.5`) |
| **Path** | `wp-content/plugins/wb-gamification/` |
| **Namespace** | `WBGam\` (PSR-4, maps to `src/`) |
| **PHP** | 8.1+ (matches the `Requires PHP` header and composer.json) |
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

## A Basecamp card is a HYPOTHESIS, not a work order

**Every card is a half-cooked idea until you have proved otherwise against the code and the browser.**
QA's job is to surface a symptom. Their reading of the symptom is an input, not a verdict, and a
meaningful share of cards are wrong: the bug is somewhere else, the bug is in another plugin, the
behaviour is correct and the expectation was not, or the thing they are looking at is stale test data.

Before a card becomes work:

1. **Reproduce it.** Same URL, same theme, same viewport, same role. If you cannot make it happen, you
   do not know what it is.
2. **Find the mechanism, not the symptom.** "The activity card looks wrong" turned out to be "the card
   is never rendered at all on this stack, because it is emitted only from the BuddyPress stream
   integration and BuddyPress is not active here". Those are different problems with different fixes,
   and three rounds of CSS were shipped against the wrong one.
3. **Ask whether it is even ours.** A real defect in a different repo is not a bug in this one. Say so
   on the card and move it; do not fix someone else's plugin from inside this one.
4. **Then decide.** Real and ours and worth doing now -> fix it. Real but not ours, or not now -> say
   what you found, with evidence, and move the card out of Bugs. Not real -> comment with what you
   actually observed and close it. **A card that is closed with evidence is worth more than a card
   that is quietly fixed.**

What a comment must contain: what you ran, what you saw, and the file:line that explains it. "Fixed"
is not a comment. "Cannot reproduce" is not a comment either -- say what you tried.

**And check the card against what we JUST shipped.** Chasing card #9914967346 is how we discovered that
making badges private broke every badge announcement in BuddyNext's activity feed -- the card said
nothing about that, and no gate caught it. The card was a lead, and the lead was worth more than the
complaint.

## The standards shelf (READ THE STANDARD BEFORE YOU TOUCH THE AREA IT GOVERNS)

The portfolio's written standards live in the **private Pro repo**, by policy — developer guidelines
stay in Pro. Entry point (the only one; do not maintain a second list):

    buddynext-pro/free-internal/docs/standards/INDEX.md

They are portable by design and that index names **WB Gamification** as a consumer. Do not re-derive
these rules, and do not copy them here — read them there. Which ones govern this plugin:

| Touching… | Read | Enforced here by |
|---|---|---|
| A list, a query, a table at scale | `DATA-AT-SCALE.md` | `wp wb-gamification scale benchmark` (CI 5.1) |
| A cached read, or adding a cache | `CACHING.md` | *(no gate — over-caching is a finding too)* |
| Deleting data, retention, GDPR, uninstall | `DATA-LIFECYCLE.md` | `coding-rules-check.sh` Rule 11 |
| A REST endpoint | `REST-API-BOUNDARY.md` | `coding-rules-check.sh` Rules 2 + **2b** |
| A cron job or background task | `BACKGROUND-JOBS.md` | `coding-rules-check.sh` Rule 12 |
| A member-facing control (toggle, setting, button) | `public-surface-integrity.md` | `check-wiring`/`wppqa` (partial) |
| User-facing strings | `i18n.md` | *(no gate)* |
| Frontend JS, client-side nav | `frontend-interactivity.md` | *(no gate)* |
| A wp-admin screen | `admin-ui-uniformity.md` · `admin-settings-registry.md` | `ux-audit.sh` (principle only — this plugin has its own design system, NOT BuddyNext's tokens) |

**Not applicable:** `FREE-PRO-SEAM.md` (no Pro pair). `MONEY.md` governs real currency; this plugin
spends a points balance, so only its ledger rules apply.

**The rule that outranks the rest: nothing becomes a task until someone has tried to REFUTE it.** In
the 2026-07-13 audit, two findings that looked obviously right were wrong, and implementing either
would have SHIPPED A BUG:

- "Add a nightly reconcile for `wb_gam_user_totals`" — a reconcile recomputing `total = SUM(ledger)`
  would re-introduce the legacy bug where every `LogPruner` run silently shrank members' balances.
  The pruner deliberately does not decrement totals.
- "Page the GDPR eraser, it will OOM like the exporter did" — measured: 50,001-row member purges in
  261 ms with zero PHP memory growth. A DELETE streams nothing into PHP. Paging it would trade real
  atomicity for an imaginary problem.

**And the one this plugin keeps relearning: a check that cannot fail is not a check.** Five separate
times we shipped a green gate over a real bug — Rule 11 grepping for string literals while five meta
keys were spelled `self::CONST`; Rule 2 allowlisting a *file* as "public catalog" while its
`?user_id=` parameter leaked private progress to anonymous callers; a CI stage guarded by `if [ -x ]`
pointing at a script that never existed. When you write a gate, **mutation-test it**: break the thing
it guards and watch it fail by name. `bin/local-ci.sh` asserts its own gate manifest (stage 0.1) for
this reason.

## Time: store UTC, read in the site calendar (1.6.5)

Every stored moment is UTC, written from PHP with `current_time( 'mysql', true )` — never a column
DEFAULT or SQL `NOW()` (the database clock differs per host). Windows people mean in the site's
calendar (today, this week, last N days) come from `WBGam\Engine\Clock`: `site_cutoff()` /
`site_day_start()` return the UTC instant, `site_date()` / `site_week()` return calendar keys (for DATE
columns only), `sql_utc_to_local()` groups by site day (DST-exact). Display with `wp_date()` /
`get_date_from_gmt()`, never `date_i18n()`. Pre-1.6.5 rows are converted once by
`UtcStorageMigration`. Stage 2.15 (`bin/check-clock-contract.sh`) enforces all of this.

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

### Contract-first development (owner directive, 2026-07-03)

**No code is written for any task until its Basecamp card carries a complete
micro-level contract.** The card table has a "Ready for Development" column that
enforces this: a card may only enter it with ALL five sections filled in.

| Contract section | Must answer |
|---|---|
| **Expectation** | Owner/member-visible outcome, phrased as what they can DO |
| **Code/data flow** | Files, classes, endpoints, tables, hooks touched; event-sourced writes only (compensating events, never silent mutation); manifest + openapi + SDK updated in the same PR |
| **Scale contract** | Query budgets at 100k members / 1M+ event rows; LIMIT+COUNT pagination; index proof against schema KEYs; no N+1; no unbounded scans. Target reality is ~10,000 sites, not this dev install |
| **Combination contract** | Behavior with/without BuddyPress, WooCommerce, LMS, membership plugins — any plugin mix a real owner may run; module-toggle behavior |
| **Acceptance criteria** | Browser-verifiable (incl. 390px, dark, keyboard), API-verifiable, DB-verifiable; journey added |

Epics without a complete contract stay in "Figuring it out" with an explicit
list of contract questions; answering them is the work that promotes the card.
Every card also states **Out of scope / no-dup** referencing adjacent cards so
two cards never claim the same change.

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
# FIRST-TIME SETUP after a fresh clone or branch checkout (REQUIRED — see "Registered Blocks").
# build/ is gitignored; without this step the block registrar finds nothing and registers 0 blocks.
npm install && npm run build      # compiles src/Blocks/<slug>/ -> build/Blocks/<slug>/
composer install                  # PHP deps + dev tooling (phpunit, etc.)

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
WB_GAM_VERSION   // current version, e.g. '1.6.5' (matches the plugin header)
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

[`plan/MASTER-CHECKLIST.md`](plan/MASTER-CHECKLIST.md) is the single source of truth — read it for the current shipped-vs-pending view. As of 2026-05-28 the foundation + v2 wave is complete; the only explicitly-deferred item is the toast wrapper duplication refactor (multi-commit, no functional impact today). Items historically listed here (scale-benchmark, SSE, hooks_fired re-enum, frontend_assets re-enum, capabilities manifest, GraphQL, AI intelligence, JS SDK, ActivityPub) all shipped — see the checklist for commit pointers. `src/Integrations/GraphQL.php` and `src/Integrations/ActivityPub.php` are present in the tree; SSE shipped via `src/API/SSEController.php` but is opt-in (`wb_gam_sse_allowed`, default false) with WP Heartbeat as the shipped default (see `assets/js/heartbeat.js`).

> **No Pro tier.** wb-gamification is shipped as a single free plugin — every engine boots in this codebase. Any historical references to a "Pro plugin", "Pro engines", `WB_GAM_PRO_VERSION`, or `wb-gamification-pro` left in `plan/`, `audit/`, or older docs are stale.

## 🟣 Genuinely deferred (multi-week or external-dep)

- **React Native SDK** — `@wbcom/wb-gamification-rn-sdk`. No customer ask yet; would consume the same OpenAPI spec the JS SDK does.
- **PHPStan in GitHub Actions** — `composer phpstan` works locally (level 9 clean) but PHPStan silently no-ops in the Local-by-Flywheel PHP build. CI wiring needs the GitHub Action runner to confirm output.
- **WebSocket transport** — SSE shipped, but in 1.5.2 the realtime default flipped to WP Heartbeat (15s steady / 5s post-action burst / near-suspend on hidden tabs); SSE is now opt-in behind the `wb_gam_sse_allowed` filter because the PHP long-poll pins an FPM worker per connection and does not scale on a standard pool. WS would only matter if we needed bidirectional client→server streaming, which we don't today.

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

Namespace: `/wp-json/wb-gamification/v1/`. The authoritative list (route, methods, permission callback,
file:line) is `audit/manifest.json` → `rest.endpoints`, regenerated from the live registry by
`bin/refresh-manifest-live.php`; the write-route permission table is `audit/admin-hardening-triage.md`.
Do not hand-maintain a route list here — it drifts.

---

## Registered Blocks

> ⚠️ **AFTER EVERY CLONE / BRANCH CHECKOUT: run `npm install && npm run build` or NO blocks register.**
> `build/` is gitignored (`.gitignore:7`), so a fresh checkout has only `src/Blocks/` — never the compiled `build/Blocks/`. The registrar (`src/Blocks/Registrar.php`) scans **`build/Blocks/*/block.json`** on `init@20`; with no `build/` dir its `is_dir()` guard bails **silently** and registers zero blocks (no error, no notice). This bit us on the 1.6.1 dev-branch install (2026-06-23) — block editor showed none of our blocks until the build ran. The released **dist zip** does NOT have this problem (`bin/build-release.sh` runs the build before packaging). So: dev-branch checkout = build required; dist zip = ready to use. Do NOT "fix" this by committing `build/` — that creates a stale-artifact trap where QA tests compiled output that no longer matches `src/`.

`leaderboard`, `member-points`, `badge-showcase`, `level-progress`, `challenges`, `streak`, `top-members`, `kudos-feed`, `year-recap`, `points-history`, `earning-guide`, `redemption-store`, `community-challenges`, `cohort-rank`, `hub`, `daily-bonus`, `give-kudos`, `submit-achievement`, `user-status-bar` — **19 blocks**, all Wbcom Block Quality Standard compliant (apiVersion 3, standard attribute schema, `--wb-gam-*` design tokens, per-instance scoped CSS).

Source: `src/Blocks/<slug>/{block.json, index.js, edit.js, render.php, editor.css}` (plus `view.js` + `style.css` for IA-driven blocks).

Build: `npm run build` (uses `@wordpress/scripts`, sets `WP_EXPERIMENTAL_MODULES=true` for Interactivity API view modules) → `build/Blocks/<slug>/`.

Discovery: `WBGam\Blocks\Registrar` scans `build/Blocks/*/block.json` on `init@20` and calls `register_block_type` per slug. Each block also has a matching shortcode via `ShortcodeHandler`.

CI gate: `bin/check-block-standard.sh` (wired into `composer ci` as stage 2.3) fails the build if any `block.json` is missing the standard attribute schema.

Documentation: see [`docs/website/developer-guide/block-attributes.md`](docs/website/developer-guide/block-attributes.md). The Wbcom Block Quality Standard migration shipped in 1.0.0 — `bin/check-block-standard.sh` (CI stage 2.3) keeps it enforced.

---

## WPCS Notes

Run WPCS via the `mcp__wpcs__*` MCP tools — **do not run directly** from the CLI on this plugin. The MCP tool picks up the `.phpcs.xml` config correctly. Direct `phpcs` CLI from outside the plugin directory ignores it.
