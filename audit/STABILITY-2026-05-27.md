# WB Gamification — Stability Audit, 2026-05-27

> Snapshot taken at commit `fd51cae` after the v1.4.0 bug-sweep waves (1–6) shipped. Audit scope: items 1–3 of the stability plan — manifest refresh, bug-pattern post-mortem, test coverage gap. Items 4–10 are proposed in [Section 4](#4-proposed-gates).

---

## 1. Manifest refresh

`audit/manifest.json` was last regenerated 2026-05-06 (21 days stale). The CLAUDE.md header counts reflect that snapshot, not current reality.

| Metric | CLAUDE.md (5/6) | Current (5/27) | Delta | Notes |
|---|---:|---:|---:|---|
| REST routes (`register_rest_route`) | 65 | 56 | **−9** | Some routes consolidated when Tier 0 admin migration finished. Needs walk-through. |
| Blocks (`src/Blocks/*/block.json`) | 17 | **19** | +2 | `user-status-bar` + 1 other added since 5/6. |
| Shortcodes | 15 | **17** | +2 | `wb_gam_give_kudos`, `wb_gam_hub` (?) added. |
| Admin pages | 13 | 13 | 0 | Stable. |
| Tables (`src/Engine/Installer.php`) | 22 | **23** | +1 | New table not reflected in CLAUDE.md. |
| WP-CLI commands | 10 | 10 | 0 | Stable. |
| `admin_post_*` handlers | 0 | 0 ✓ | 0 | **Tier 0 invariant intact** (the 11 grep hits are deprecation comments, not handlers). |
| `wp_ajax_*` handlers | 0 | 0 ✓ | 0 | **Invariant intact**. |
| Fired `do_action` events | 54 | 56 | +2 | New event hooks since 5/6. |
| Applied `apply_filters` | 31 | **44** | **+13** | Significant new filter surface. |
| Cron hooks (constants + literals) | 9 | ~10 | +1 | New cron schedule since 5/6. |
| Action Scheduler dispatches | not tracked | 13 | — | **New axis the manifest doesn't enumerate today.** |

**Action**: run `/wp-plugin-onboard --refresh` (or invoke `wp-plugin-onboard` skill) to regenerate `audit/manifest.json` + `audit/manifest.summary.json`. Update CLAUDE.md header counts in the same commit. **Add an AS-dispatch column** to the manifest so future audits don't miss async surface area.

---

## 2. Bug-pattern post-mortem

26 cards in Ready-for-Testing + the 2 closed-out in Bugs today were classified by reading each fix commit. Patterns:

| Root-cause class | Count | Cards | Why static gates miss it |
|---|---:|---|---|
| **Boot/wiring drift** (hook timing, parent_slug, plugins_loaded nesting) | 3 | 9914460166 (wizard), 9927572402 (community challenges), 9927279782 (notifications) | No invariant test that admin pages register cleanly on the request that needs them; no destructive-read detection. |
| **Enum/constant drift across layers** (controller validator vs engine handler) | 3 | 9927682021 (free shipping enum), 9927027277 (redemption error reasons), 9925589914 (WC async flag) | Each constant duplicated in 2–3 places; nothing links them. Detected only when a feature tries to use the missing case. |
| **Capability/permission drift** | 1 | 9927027149 (rules cap) | Controllers hand-roll caps; no central matrix asserted in tests. |
| **CSS class / template mismatch** | 1 | 9925205802 issue 2 (Emails switch) | PHP and CSS independently own the class name; no link. |
| **Missing engine wiring** (event fired but no listener) | 1 | 9927383947 (redemption email) | No "every fired event has at least one handler" check. |
| **Stale build artefacts** | 1 | render.php drift (commit `fd51cae`) | `npm run build` reported success but `build/Blocks/.../render.php` was stale. |
| **UX polish / mobile** | ~15 | most "UI issue" cards | Subjective; only browser smoke catches these. |
| **Environmental** (not plugin code) | 1 | 9927883656 (cron fatal — system PHP missing mysqli) | Caught by triage. |

### The repeating shape

Every recurring class above is an **inter-layer contract** — a promise that two places in the code must agree on something, where neither place enforces the agreement. The plugin's static gates (WPCS, PHPStan, coding-rules, ux-audit, plugin-dev-rules, block-standard, wppqa) all check **single-layer** invariants. The bug-fix waves keep finding contracts that have *no* gate.

### Most expensive recurring smell

The wizard activation flow (#9914460166) was reported "issue not resolved" **three times** by QA before a structural refactor finally closed it. Each round was a real fix that worked on the dev's sandbox but failed somewhere QA was testing. The cost: ~6 dev–QA round trips on a single bug.

Root cause was nested `add_action('plugins_loaded', ...)` from inside a callback already running at `plugins_loaded@0`. WP supports the pattern, but timing inside `WP_Filter::do_action` mid-iteration is fragile.

**A single boot-timing journey would have caught all three iterations on the first attempt.**

---

## 3. PHPUnit coverage gap

### Suite health

`composer test` (Run via `php -d auto_prepend_file=tests/prepend.php vendor/bin/phpunit --configuration phpunit.xml.dist`):

```
Tests: 108
Assertions: 232
Errors: 1
Failures: 2
PHPUnit Warnings: 1
Warnings: 9
PHPUnit Deprecations: 27
Skipped: 7
```

**The test suite is failing today.** This is hidden because `bin/local-ci.sh` does NOT run PHPUnit — only lint, WPCS, PHPStan, coding rules, architecture, block standard, ux-audit, plugin-dev-rules, wppqa, manifest. So every push since the last breakage has shipped with a red suite.

### The 3 failures

| # | Test | Cause | Fix |
|---|---|---|---|
| 1 | `RedemptionEngineTest::test_redeem_returns_error_when_out_of_stock` | `Mockery::wpdb()` mock doesn't stub `query()`; `RedemptionEngine.php:132` calls it for the `START TRANSACTION` lock added in v1.0.0 multi-currency work. | Stub `query()` on the mock with a `Mockery::any()` matcher returning `true`. |
| 2 | `ShortcodeHandlerTest::test_init_registers_all_shortcodes` | Test expects 17 shortcodes; actual is 18 (`wb_gam_give_kudos` added). | Add `wb_gam_give_kudos` to the expected array. |
| 3 | `ShortcodeHandlerTest::test_qa_pages_map_covers_every_block_in_src_blocks` | Test expects 17 blocks; actual is 19 (`give-kudos`, `user-status-bar` added). | Add both slugs to `QAPages::MAP`. |

All three are stale-fixture failures — code added a feature, tests weren't updated. The QAPages test is **specifically designed** to catch the kind of drift the manifest refresh found above ("every block must have a QAPages entry") — it failed, did its job, and got ignored because PHPUnit isn't gated.

### Coverage distribution

22 test files across 3 areas:

```
tests/Unit/Admin/     — admin page tests
tests/Unit/Blocks/    — block render tests
tests/Unit/Engine/    — engine logic tests
```

Source surface (rough): ~50 engine classes, 13 admin pages, 19 blocks, 17 REST controllers, 11 CLI commands. 22 test files for that surface area = thin coverage. PHPStan covers types; PHPUnit should cover behaviour.

### Coverage measurement

Xdebug coverage mode isn't enabled — `composer test` errors with `XDEBUG_MODE=coverage … has to be set`. So even when the suite is green, we don't know the line-level coverage number.

---

## 4. Proposed gates

Items 4–10 from the original plan, ranked by leverage (bugs prevented per hour of effort).

**Status update — 2026-05-27**: items A–K all shipped (commits `343b0a1`, `ab60fce`, plus the PHPStan + coverage bump). Local-CI now runs 16 stages (was 10 at audit time).

| # | Gate | Catches | Effort | Priority | Status |
|---|---|---|---:|---|---|
| **A** | **Fix the 3 failing PHPUnit tests + gate PHPUnit in local-CI** | The QAPages drift test was designed to catch the block-add bug — it works, it just wasn't gated. | 30 min | **P0** | ✅ `343b0a1` |
| **B** | **Refresh manifest + update CLAUDE.md header counts** | Grounds every future audit. | 15 min | **P0** | ✅ `343b0a1` (full Phase 2.5 deferred) |
| **C** | **Boot-timing journey** | Every admin page registers cleanly; every REST controller resolves caps. Would have caught 3 of the 26 cards. | 90 min | **P0** | ✅ `343b0a1` (journey 10) |
| **D** | **Enum-link gate** (`bin/check-enum-drift.sh`) | Diffs each `VALID_*` constant against its engine consumer. Would have caught the free-shipping 400 and the redemption-error mapping bug. | 45 min | **P0** | ✅ `343b0a1` — caught real `point_multiplier` typo on first run |
| **E** | **CSS-class orphan gate** | Greps `class="wbgam-foo"` in PHP vs `.wbgam-foo` in CSS; fails on orphans. Would have caught the Emails switch bug. | 30 min | **P1** | ✅ `ab60fce` (47 orphans baselined) |
| **F** | **Sync/async manifest invariant** | Every action manifest must declare `async` explicitly when `repeatable=true`. Default-routing is the smell. | 30 min | **P1** | ✅ `ab60fce` (59 implicit baselined) |
| **G** | **"Every fired `do_action('wb_gam_*')` has at least one `add_action`"** | Would have caught the missing redemption-email listener. | 45 min | **P1** | ✅ `ab60fce` (7 critical events gated) |
| **H** | **Force-fail local-CI when vendor/ missing** | Today WPCS + PHPStan silently skip. Remove the warn-and-skip path. | 10 min | **P0** | ✅ `343b0a1` |
| **I** | **Enable xdebug coverage + set a floor** | Coverage drift is invisible today. | 60 min | **P1** | ✅ Floor at 3.5% lines / 5.0% methods (baseline 3.79% / 5.60%) |
| **J** | **Journey-per-fix rule** (procedural) | Every Ready-for-Testing card must add a journey under `audit/journeys/release/` before close-out. CLAUDE.md says this; not enforced. | 0 dev / 100% discipline | **P0** | ✅ Codified in CLAUDE.md |
| **K** | **Bump PHPStan level 5 → 6 with baseline** | New code can't add type holes; existing covered by baseline. | 60 min | **P2** | ✅ Bumped to level 9 — codebase already passed max cleanly, no baseline needed |

**Estimated total to ship A + B + C + D + H + J**: ~4 hours, blocks 8 of the next 10 bugs of the recurring classes seen above.

---

## 5. Recommended sequencing

**This session (next 4 hours):**
1. Fix the 3 failing tests (A) + add PHPUnit to `bin/local-ci.sh` (A.2).
2. Force-fail when vendor missing (H) — drop the warn-and-skip in CI.
3. Run `/wp-plugin-onboard --refresh` to regenerate the manifest (B).
4. Build the boot-timing journey (C).
5. Build the enum-drift gate (D).
6. Land everything in one commit, push, mark each Ready-for-Testing card with the journey added under "Files touched".

**Next session:**
- E (CSS gate), F (async invariant), G (event/listener gate).
- Adopt rule J (journey-per-fix).

**Quarterly:**
- I (coverage floor), K (PHPStan bump).

---

## 6. Files this audit touched (planning only)

```
audit/STABILITY-2026-05-27.md   (this file — planning only, no code change yet)
```

The next commit, if approved, would touch:

```
tests/Unit/Engine/RedemptionEngineTest.php      [fix mock stub]
tests/Unit/Engine/ShortcodeHandlerTest.php      [update expected lists]
src/Admin/QAPages.php                            [add 2 block slugs to MAP if missing]
bin/local-ci.sh                                  [add phpunit stage + remove skip-on-no-vendor]
audit/manifest.json                              [regenerate]
audit/manifest.summary.json                      [regenerate]
CLAUDE.md                                        [refresh header counts]
audit/journeys/release/boot-timing.json          [new journey]
bin/check-enum-drift.sh                          [new gate]
```
