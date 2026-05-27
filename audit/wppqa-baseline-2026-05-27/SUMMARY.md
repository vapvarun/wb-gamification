# wppqa baseline — 2026-05-27

| Check | Passed | Failed | Skipped | Duration |
|---|---:|---:|---:|---:|
| `plugin_dev_rules` | 9 | **0** | 0 | 123ms |
| `rest_js_contract` | 3 | **0** | 0 | 26ms |
| `wiring_completeness` | 0 | **0** | 1 | 1ms |
| `audit_plugin` (omnibus) | 235 | 2,428 | 676 | n/a |

## RELEASE-READY: yes

The three targeted checks (`plugin_dev_rules`, `rest_js_contract`, `wiring_completeness`) report **failed = 0**, matching the prior `2026-05-07` baseline. The omnibus `audit_plugin` is a wide-net scanner that pulls in phpcs/eslint/stylelint output as "bugs" — its 2,428 "failed" count is misleading and dominated by phpcs noise from running without the project's `.phpcs.xml` config. Only a small subset is actionable (classified below).

The hard release gate (used by `bin/build-release.sh`) is the 3 targeted checks. **Verdict: release-ready.**

## Per-check pass/fail

### plugin_dev_rules (9/9 pass)
Rule 10 (no `alert()`/`confirm()`), security (no iteration over `$_POST`/`$_GET`, nonces paired with caps), lifecycle (activation/uninstall hooks), Rule 7 (no raw `-1` in number inputs), inline `onclick` ban, 3-breakpoint discipline, 40px tap targets. **All 9 rules green.**

### rest_js_contract (3/3 pass)
Catches the "envelope mismatch" silent-bug class — JS reads `data.foo` but PHP shape moved to `{data, meta}`. 55 REST routes + heavy frontend JS, all within the scanner's 50-line proximity window. **No drift detected.**

### wiring_completeness (0/0, 1 skipped)
**N/A** for WB Gamification's architecture. Scanner looks for `templates/` files reading settings saved by `includes/admin/`. WB Gamification renders through 19 Gutenberg blocks (build-time SSR via `render.php`) + 13 admin page classes (`src/Admin/`); only 6 files under `templates/` (mostly emails). Skip is expected. False negative risk is mitigated by the bespoke `bin/check-event-wiring.sh` gate (which DID catch `wb_gam_points_redeemed` orphan during v1.4.0).

### audit_plugin omnibus

| Category | Score | Pass | Fail | Skip | Real signal? |
|---|---:|---:|---:|---:|---|
| phpcs | 0 | 0 | 2,399 | 667 | Mostly cosmetic + suppressed-via-`phpcs:ignore` blocks. Real `composer phpcs` baseline is **clean** for v1.4.0. The wppqa run ignores `.phpcs.xml`. **Not actionable.** |
| phpstan | 0 | 0 | 0 | 1 | Skipped. Plugin CI runs PHPStan **level 9** clean. |
| eslint / stylelint | 0 | 0 | 0 | 1 each | Skipped. |
| php-lint | 100 | 173 | 0 | 0 | All 173 PHP files syntactically valid. |
| composer-audit | 100 | 1 | 0 | 0 | No CVEs. |
| i18n | 100 | 3 | 0 | 0 | Text domain consistent. |
| bundle-size | 100 | 1 | 0 | 0 | Within budget. |
| ux | 100 | 5 | 0 | 0 | UX foundation pass. |
| templates | 100 | 5 | 0 | 0 | Template hygiene pass. |
| ux-guidelines | 100 | 5 | 0 | 0 | Wbcom UX foundation rules pass. |
| plugin-dev-rules | 100 | 9 | 0 | 0 | Matches targeted run. |
| rest-js-contract | 100 | 3 | 0 | 0 | Matches targeted run. |
| qa-coverage | 100 | 1 | 0 | 0 | QA shelf present. |
| a11y | 0 | 0 | 10 | 0 | **15 findings — see top-5 below.** Mix of real (CSS focus rings) + duplicates (.min.css pairs). |
| admin-eval | 0 | 0 | 13 | 0 | **16 findings — mostly false positives.** SettingsPage direct-`$_POST` is inside explicit `phpcs:disable` with `check_admin_referer()` at `handle_save()`. Admin REST forms use `data-wb-gam-rest-*` not classic POST. 3 real (non-dismissible notices). |
| enum-consistency | 71 | 10 | 4 | 0 | **4 findings — all cross-domain key-name collisions.** Plugin's `bin/check-enum-drift.sh` (domain-scoped) is **green**. False positives. |
| frontend-eval | 90 | 9 | 1 | 0 | **48 sub-findings.** Block-SSR check broken (all 19 blocks DO have `render.php`). Real signal: 8 `fetch()` w/o `.catch()` in shipped JS; 4 modal a11y sites; silent-empty-state count high but mostly intentional. |
| marketing | 91 | 10 | 1 | 0 | 1 false-positive critical (version mismatch — both 1.4.0); 2 informational. |
| editor-layout-bias | 0 | 0 | 0 | 1 | Skipped. |

## Top 5 most-impactful findings (real, after FP-filter)

1. **`outline:none` without `:focus-visible` replacement** — 10 CSS rules across `assets/css/admin/pages/submissions*.{css,min.css}`, `assets/css/admin/pages/conversions*.{css,min.css}`, `assets/css/admin.css:1`, `assets/css/frontend.css:1`, `src/Blocks/submit-achievement/style.css:103`. WCAG 2.4.7 risk. Unique selector count ~5 once min/non-min de-duplicated. Severity: **high (a11y)**.
2. **2 form inputs without labels** at `src/Admin/PointTypeConversionsPage.php:247, 259` (Conversions create/edit form). WCAG 3.3.2. Severity: **high (a11y)**.
3. **3 `<img>` without `alt`** at `src/Admin/SubmissionsPage.php:170` + 2 siblings. WCAG 1.1.1. Severity: **high (a11y)**.
4. **`fetch()` without `.catch()`** in 8 shipped JS files (`admin-action-overrides.js:32`, `admin-rest-utils.js:86`, `admin-test-event.js:49`, `give-kudos.js:46`, `redemption-store/view.js:87`, `submit-achievement/view.js:67`, plus 2 more). Silent failure → UI stuck on network error. Severity: **medium (correctness)**.
5. **Non-dismissible admin notices** at `src/API/ApiKeyAuth.php:95` and `src/Admin/SetupWizard.php:86` (the `wb-gamification.php:40` notice is the intentional vendor-missing fatal preventer — not actionable). WP UX guideline. Severity: **medium (a11y/UX)**.

## Classification of likely false positives

| Finding pattern | Why FP |
|---|---|
| BUG-3181 critical version-mismatch | Both readme + header are `1.4.0`; scanner reads `1.0.0` from a stale `audit/manifest.json` `.plugin.version`. |
| 13× "POST form without nonce verification" | Either uses `data-wb-gam-rest-*` REST-form pattern (no classic POST) or has `check_admin_referer()` at the `handle_save()` entry. Scanner heuristic only looks at the immediate function body. |
| 4× "Direct $_POST to update_option" in SettingsPage | Inside explicit `phpcs:disable WordPress.Security.NonceVerification.Missing` block with comment `Nonce verified by check_admin_referer() in handle_save()`. Genuine pattern, properly suppressed. |
| 4× enum-consistency | Cross-domain key-name collisions (`status` for challenges + community challenges; `type` for activity/automation/rules; `event` for webhook + email; `reason` for cooldown + redemption error). Plugin's domain-scoped `bin/check-enum-drift.sh` is green. |
| 18× "Block may lack SSR" | All 19 blocks DO have `render.php`. Scanner heuristic broken. |
| 12× gapAnalysis "no activation/deactivation/uninstall/requires-wp/requires-php" | All present (`wb-gamification.php` lines 636, 662; `uninstall.php` exists; `Requires at least: 6.4`, `Requires PHP: 8.1`). Scanner doesn't parse plugin header correctly. |
| 2,399 phpcs failures | Scanner ignores `.phpcs.xml`. The real CI (`composer phpcs` + `mcp__wpcs__*`) is clean. |

## What's NEW since 2026-05-07 baseline

| Change | Effect on baseline |
|---|---|
| v1.4.0 wave-1..wave-6 bug sweeps | No new wppqa failures introduced — engine refactor + admin REST migration kept targeted checks at 0. |
| Engine refactor (Transaction helper, unified ledger write path, multi-currency event stamping, webhook hardening, email rate-limit) | No new wppqa failures. |
| 6 new local-CI gates (P0–P2) | Catches regressions earlier than wppqa; complementary. |
| Plugin Check warning burndown | Reduces noise in `plugin-check` category (still skipped in this run). |
| CSS architecture refactor (admin.css split into per-page sheets) | The 10 `outline:none` findings appear in new per-page CSS files — these existed in the old `admin.css` too. Net real findings unchanged. |

## File map

- `plugin_dev_rules.json` — full raw output
- `rest_js_contract.json` — full raw output
- `wiring_completeness.json` — full raw output
- `audit_plugin.json` — condensed omnibus run (543KB; raw 5.2MB JSON omitted)
- This file (`SUMMARY.md`) — read by `bin/wppqa-baseline-check.sh` for release-gate verdict
