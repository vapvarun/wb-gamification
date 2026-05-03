---
journey: tier-1-foundations
plugin: wb-gamification
priority: critical
roles: [release-engineer]
covers: [foundation-gates, regression]
prerequisites:
  - "PHP 8.2+ available"
  - "composer dependencies installed (composer install)"
  - "wp-cli on PATH (or via Local WP)"
estimated_runtime_minutes: 8
---

# Tier 1 — Automated Foundation Gates

Static + unit-level gates that must be green before any browser verification runs. If these fail, no other tier can be trusted.

## Setup

- Repo: `wp-content/plugins/wb-gamification`
- Tools: `php`, `composer`, `wp` (WP-CLI), `mcp__wp-plugin-qa__*`, `mcp__wpcs__*`
- Fixtures: none (static analysis)

## Steps

### 1. PHP syntax across the source tree
- **Action**: `find src wb-gamification.php -name '*.php' -exec php -l {} \;`
- **Expect**: every line says "No syntax errors detected"
- **On fail**: the file printing a parse error is the regression site

### 2. WordPress Coding Standards (per-file MCP)
- **Action**: `mcp__wpcs__wpcs_check_file` for any PHP touched in the PR (use `working_dir` = plugin path so `.phpcs.xml` is honoured)
- **Expect**: edits introduce zero new errors. Pre-existing legacy debt (missing docblocks, filename casing) is logged but not gating

### 3. PHPStan level 5
- **Action**: `composer phpstan` (or `php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress`)
- **Expect**: 0 errors, 0 baseline drift

### 4. PHPUnit unit suite
- **Action**: `composer test:unit`
- **Expect**: `OK` or `OK, but...` with 0 failures + 0 errors. Skips are allowed (alias-mock isolation cases)
- **Capture**: test count (currently 108 / 237 assertions)

### 5. Block bundle sizes
- **Action**: per-block gzipped wc -c on `build/Blocks/*/index.js` and `build/Blocks/*/style-index.css`
- **Expect**: every block JS ≤ 20 KB gzipped, CSS ≤ 30 KB gzipped

### 6. wppqa MCP audit (REST↔JS contract, wiring, plugin-dev-rules, a11y)
- **Action**: run each in turn:
  - `mcp__wp-plugin-qa__wppqa_check_rest_js_contract`
  - `mcp__wp-plugin-qa__wppqa_check_wiring_completeness`
  - `mcp__wp-plugin-qa__wppqa_check_plugin_dev_rules`
  - `mcp__wp-plugin-qa__wppqa_check_a11y`
- **Expect**: `failed=0` from each. Warnings allowed but tracked.

### 7. Plugin-specific coding rules
- **Action**: `bash bin/coding-rules-check.sh`
- **Expect**: "All coding rules pass."

### 8. Block standard compliance
- **Action**: `bash bin/check-block-standard.sh`
- **Expect**: "Wbcom Block Quality Standard check — green (15 block(s) compliant)"

## Pass criteria

ALL of the following hold:
1. Every `failed=0` MCP check stays at 0
2. PHPStan baseline does not grow
3. PHPUnit reports 0 failures + 0 errors
4. Every block's JS+CSS bundle is under budget
5. WPCS edits introduce zero new violations on this PR

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `wppqa_check_rest_js_contract` flags `data.foo` | JS reads a key that REST controller's response shape doesn't include | `assets/js/*.js` + matching `src/API/*Controller.php` |
| `wppqa_check_a11y` flags `outline:none` without `:focus-visible` | Removed outline on `:focus` without keyboard replacement | The CSS file the linter names — convert to `:focus:not(:focus-visible)` |
| Bundle size over budget | New unminified import landed in production bundle | `webpack.config.js` + the offending block's `view.js`/`edit.js` |
| Block standard check red | Missing standard schema attribute | `src/Blocks/<slug>/block.json` — compare against the canonical leaderboard schema |
