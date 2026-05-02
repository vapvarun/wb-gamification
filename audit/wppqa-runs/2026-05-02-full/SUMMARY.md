# wppqa full audit — 2026-05-02 — verified summary

Run via `wppqa_audit_plugin` MCP. Full output: [`REPORT.md`](REPORT.md) (88 KB / 969 lines).

> **Read this, not the raw report.** The raw output flags ~1,341 issues; most are heuristic false positives caused by mismatches between WPCS conventions and this plugin's PSR-4 architecture. This summary triages the real signals.

## Headline numbers

| Metric | Value |
|---|---|
| Plugin Maturity | **BASIC (56/100)** |
| Code Quality | **C+ (68/100)** |
| Total checks run | 1,462 (153 passed, 743 failed, rest skipped) |
| Critical "fix before release" items | 4 — **all 4 are false positives** (verified below) |
| Important items | 745 — bulk are auto-fixable PHPCS style |
| Recommended | 601 |

## What the audit actually ran (vs skipped)

| Category | Status | Count |
|---|---|---|
| **PHPCS** | FAIL | 731 errors, 559 warnings |
| **PHP-LINT** | PASS | 112/112 |
| **PHPCOMPAT** | PASS | clean |
| **COMPOSER-AUDIT** | PASS | clean |
| **I18N** | PASS | 3/3 |
| **PHPSTAN** | SKIPPED | (no `phpstan.neon` at audit time — we added it after; re-run will engage it) |
| **ESLint** / **Stylelint** | SKIPPED | not configured |
| **A11Y-GREP** / **SECURITY-SCAN** / **PERFORMANCE-SCAN** / **PCP-DEEP** / **BUNDLE-SIZE** | SKIPPED | tooling not present |
| **A11Y** (product-level) | FAIL | 5 errors / 2 warnings (29% pass) |
| **ADMIN-EVAL** | PASS-PARTIAL | 4 errors / 1 warning (60%) |
| **FRONTEND-EVAL** | PASS | 0 errors / 31 warnings |
| **MARKETING** | PASS-PARTIAL | 3 errors / 4 warnings (73%) |
| **UX** / **TEMPLATES** | PASS | clean |

## The 4 "Fix Before Release" critical items — verified

The audit lists 4 critical items. Each was verified against the source code. **All 4 are false positives** caused by the audit's heuristic not finding the existing implementations.

| # | Audit claim | Reality | Evidence |
|---|---|---|---|
| 1 | "Some REST routes lack proper permission callbacks" | False positive | All 39 routes have explicit `permission_callback` (see `audit/manifest.json#/rest`). The 17 `__return_true` callbacks are intentionally public (catalog reads, OG share, OpenBadges credential, etc.) and documented in `audit/ROLE_MATRIX.md`. The audit flags `__return_true` as "missing" without reading the docs. |
| 2 | "Custom tables exist but no uninstall hook" | False positive | `uninstall.php` exists at the plugin root and handles full cleanup (tables, options, transients, cron, Action Scheduler tasks, user meta). |
| 3 | "Custom tables exist but no activation hook" | False positive | `register_activation_hook( __FILE__, ... )` at `wb-gamification.php:320`. Tables are created via `Installer::activate()`. |
| 4 | "Cron jobs scheduled but no deactivation hook" | False positive | `register_deactivation_hook( __FILE__, ... )` at `wb-gamification.php:337`. |

**Net new release-blockers from this audit: zero.** The pre-existing `wb_gam_award_manual` cap drift and `manage_options` monoculture remain the only material issues from the partial baseline.

## Important — the 731 phpcs "errors" are wppqa's ruleset, not the project's

After publishing the first draft of this summary, follow-up verification surfaced an important context:

**The project's own `.phpcs.xml` is already comprehensively tuned.** It excludes every noisy rule the wppqa output complained about: `WordPress.Files.FileName.NotHyphenatedLowercase`, `WordPress.Files.FileName.InvalidClassFileName`, `Universal.Arrays.DisallowShortArraySyntax`, `Universal.Operators.DisallowShortTernary`, `WordPress.WP.GlobalVariablesOverride.Prohibited`, `WordPress.Security.EscapeOutput.OutputNotEscaped`, `Squiz.Commenting.*` (file/class/function/inline). It also `<exclude-pattern>`s `tests/`, `blocks/`, `vendor/`, `node_modules/`, `dist/`, and `integrations/contrib/`.

**Evidence:** the `WordPress Coding Standards` GitHub Actions check on PR #2 returned `SUCCESS`. `composer phpcs` against the project ruleset is **clean**.

The wppqa `phpcs` check uses its own stricter ruleset (it does NOT read the project's `.phpcs.xml`), so its 731 errors / 559 warnings reflect what `WordPress` standard would say if applied without the project's deliberate exclusions. Three implications:

1. The earlier first-draft recommendation to "update `.phpcs.xml` to exclude `src/`, `tests/`, `blocks/*/*.asset.php` from filename rules" is **redundant** — it was already done.
2. The "WordPress global override" findings on `blocks/earning-guide/render.php:36` (`foreach ( $actions as $id => $action )`) and `blocks/year-recap/render.php` are flagged by the heuristic, but block render.php files execute inside `WP_Block::render_callback` (a method scope), so locals don't actually overwrite the WordPress globals at the global scope. The project ruleset correctly excludes this rule.
3. The "All output should be run through an escaping function" warnings on block render.php files are about `get_block_wrapper_attributes()` — a core WP function whose return value is already a sanitized attribute string. The render.php files already wrap each call with `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` (see `blocks/earning-guide/render.php:25,60,73`). The project ruleset ALSO excludes the rule globally. Defense-in-depth.

So of the ~1,341 raw findings, the project owners have already triaged ~95% as "ignore" via the existing `.phpcs.xml`, and the GitHub Actions WPCS gate confirms the remaining surface is clean.

## Real, surviving signals (not handled by .phpcs.xml or CI)

### 1. Accessibility — 5 errors / 2 warnings (real)

Product-level a11y check (separate from the heuristic phpcs noise). The raw report enumerates each — the dominant patterns are:

- Form fields without explicit `<label>` association
- `outline: none` without a paired `:focus-visible` style
- Empty links / links without discernible text

These are real for users with assistive tech. Worth a separate hardening pass.

### 2. Marketing — 3 errors / 4 warnings (now spec'd, awaiting design)

WordPress.org submission readiness:

- No SVN-style banner (1544×500, 772×250)
- No icon (256×256, 128×128)
- No screenshots in the readme.txt format

These are required for the plugin directory if you intend to publish there. Lower priority if distribution is private.

## Triage: real bug : noise ratio

Of the ~1,341 raw findings:

- **0** are net new release-blockers (the 4 "critical" are all false positives — verified above).
- **~7** are real concerns worth fixing (5 a11y errors, 3 marketing-readiness errors). Surface in separate hardening passes.
- **~1,000+** are wppqa using a stricter ruleset than the project's own `.phpcs.xml` deliberately enforces. The team has already decided to ignore these patterns; the project's `composer phpcs` (the gate that actually runs in CI) returns clean.
- **~300+** are heuristic false positives where wppqa doesn't recognize core WP behaviors (block render scope, `get_block_wrapper_attributes` returning sanitized output).

## Action items (in priority order)

1. **A11y hardening pass** for the 5 product-level a11y errors. Real impact for assistive-tech users. Separate PR.
2. **Marketing assets** — banner, icon, screenshots — if planning .org submission. Separate effort.
3. **Pre-existing findings carried over from the partial baseline** are still the most impactful:
   - `wb_gam_award_manual` cap drift (`src/API/PointsController.php:273` — enforced but unregistered).
   - 32× `manage_options` monoculture — no granular plugin caps.

**Not on the list (intentionally):** "fix" the `.phpcs.xml` (already correctly tuned), "fix" the global overrides in block render.php (heuristic false positives — block render scope is method-local), "fix" output escaping on `get_block_wrapper_attributes()` (already silenced via `// phpcs:ignore` comments and core WP guarantees the return is sanitized).

## Refresh recommendation

Re-running `wppqa_audit_plugin` periodically will keep the a11y / marketing lists fresh and pick up any newly introduced real signals. The phpcs noise will not change between runs (wppqa's ruleset is fixed and the project's deliberate exclusions are already applied at the gate that matters — the GitHub Actions WPCS check). Save each refresh as `audit/wppqa-full-{date}/` so the diff is reviewable.
