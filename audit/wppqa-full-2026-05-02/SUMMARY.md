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

## Real signals the audit added (worth fixing)

### 1. Output-escaping warnings on block render.php files

The audit flagged ~10 instances of "All output should be run through an escaping function" in `blocks/*/render.php`. Verified — these are real PHPCS findings on patterns like:

```php
$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => '...' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
```

`get_block_wrapper_attributes()` returns a sanitized HTML-attribute string (this is core WP), so functionally this is safe — but PHPCS doesn't recognize the function as sanitizing. The fix is either:

- Add a `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns sanitized output` comment at each site, OR
- Add `get_block_wrapper_attributes` to the `customAutoEscapedFunctions` list in `.phpcs.xml`.

Other variants flagged: `echo $display_year`, `echo $action['event_count']`, `echo $recap['badges_earned']['count']`. These ones are real concerns where data comes from `$_GET` / DB — verify each on its own merits.

### 2. WordPress global override at `blocks/earning-guide/render.php:36`

```php
foreach ( $actions as $id => $action ) {
```

Both `$id` and `$action` are WordPress globals (used by admin-screen rendering). Shadowing them in a render context can produce subtle bugs when block render runs inside an admin-screen template-part flow. **Fix**: rename to `$action_id`, `$action_config`. Same pattern at `blocks/year-recap/render.php` for `$year` and another `$action`.

### 3. PHPCS rule mismatch with PSR-4

The plugin uses PSR-4 (`src/`, `WBGam\` namespace, `MyClass.php` style). `.phpcs.xml` enforces the legacy `class-my-class.php` filename convention. Result: ~hundreds of "Filenames should be lowercase with hyphens" + "Class file names should be based on..." findings on every `src/*.php` file.

These are **noise**, not bugs. The fix is to update `.phpcs.xml` to exclude `src/` from the filename-convention rules:

```xml
<rule ref="WordPress.Files.FileName">
    <exclude-pattern>src/*</exclude-pattern>
    <exclude-pattern>tests/*</exclude-pattern>
</rule>
```

Until then, every contributor sees ~hundreds of red lines that aren't actionable.

### 4. Auto-generated build artifacts flagged

`blocks/*/edit.asset.php` files are emitted by `@wordpress/scripts` build — they're not source. PHPCS shouldn't scan them. Add to `.phpcs.xml`:

```xml
<exclude-pattern>blocks/*/edit.asset.php</exclude-pattern>
<exclude-pattern>blocks/*/index.asset.php</exclude-pattern>
<exclude-pattern>blocks/*/view.asset.php</exclude-pattern>
```

That alone removes ~100 of the 731 errors.

### 5. Accessibility — 5 errors / 2 warnings (real)

Product-level a11y check. The raw report enumerates each — the dominant patterns are:

- Form fields without explicit `<label>` association
- `outline: none` without a paired `:focus-visible` style
- Empty links / links without discernible text

These are real for users with assistive tech. Worth a separate hardening pass.

### 6. Marketing — 3 errors / 4 warnings

WordPress.org submission readiness:

- No SVN-style banner (1544×500, 772×250)
- No icon (256×256, 128×128)
- No screenshots in the readme.txt format

These are required for the plugin directory if you intend to publish there. Lower priority if distribution is private.

## Triage: real bug : noise ratio

Of the ~1,341 raw findings:

- **0** are net new release-blockers (the 4 "critical" are all false positives — verified above).
- **~5–10** are real concerns worth fixing (escaping audits on `$action['event_count']`-style outputs, the 3–4 global overrides in block renders, the a11y errors).
- **~700+** are PHPCS-style findings auto-fixable via `phpcbf` (array syntax, indentation, docblocks, line length).
- **~300+** are heuristic false positives caused by PSR-4 vs WPCS filename mismatch + auto-generated `*.asset.php` files being scanned.

## Action items (in priority order)

1. **Update `.phpcs.xml`** to exclude `src/`, `tests/`, and `blocks/*/*.asset.php` from filename rules. Drops noise by ~50%.
2. **Whitelist `get_block_wrapper_attributes`** in PHPCS auto-escape list. Drops another ~10 false positives.
3. **Fix the global overrides** in `blocks/earning-guide/render.php:36` and `blocks/year-recap/render.php`. Real bugs.
4. **Run `composer phpcs:fix`** against the rest. Auto-fixes ~600 of the remaining findings (array syntax, indentation, etc.).
5. **A11y hardening pass** for the 5 product-level a11y errors. Separate PR.
6. **Marketing assets** — banner, icon, screenshots — if planning .org submission. Separate effort.

The pre-existing findings from the partial baseline (cap drift on `wb_gam_award_manual`, `manage_options` monoculture) are still the most impactful issues, not anything in this expanded audit.

## Refresh recommendation

Re-run `wppqa_audit_plugin` after #1 + #2 above land — the noise reduction will make subsequent audits actionable instead of overwhelming. Save the next run as `audit/wppqa-full-{date}/` so the diff is reviewable.
