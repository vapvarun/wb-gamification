# Tier 1 — Automated foundations summary

**Run date:** 2026-05-03
**Status:** **PARTIAL PASS** — 9 gates green, 1 red (security: nonce-without-cap)

## Pass/Fail per row

| Row | Tool | Result | Notes |
|---|---|---|---|
| PHP lint (8.2) | `php -l` × 200+ files | ✅ 0 errors | All source PHP parses |
| Coding rules | `bin/coding-rules-check.sh` | ✅ Pass | Rules 1+2 green |
| Block standard | `bin/check-block-standard.sh` | ✅ 15/15 compliant | All blocks have standard schema |
| WPCS (PHP files I touched) | `mcp__wpcs__wpcs_check_file` | ✅ Clean on session edits | Pre-existing legacy debt only (missing docblocks, filename casing) |
| PHPStan level 5 | `composer phpstan` | ✅ 0 errors | Full source tree analysed clean |
| PHPUnit (Unit suite) | `composer test:unit` | ✅ 108 tests, 237 assertions | 0 failures, 0 errors, 7 skipped (alias-mock isolation) |
| Bundle size — JS | gzip wc -c | ✅ 15/15 ≤ 20KB | Worst case 4.88KB (well under 20KB budget) |
| Bundle size — CSS | gzip wc -c | ✅ 15/15 ≤ 30KB | Worst case 1.58KB (well under 30KB budget) |
| REST↔JS contract | `wppqa_check_rest_js_contract` | ✅ 0 issues | |
| Wiring completeness | `wppqa_check_wiring_completeness` | ✅ 0 issues | |
| Plugin dev rules | `wppqa_check_plugin_dev_rules` | ❌ **12 high** | Nonce-without-capability across 8 admin files. **MUST FIX before 1.0.0.** |
| UX guidelines | `wppqa_check_ux_guidelines` | ⚠️ 0 high, 591 warnings | Raw px/hex outside tokens — token-system tech debt, not blocker |
| A11y | `wppqa_check_a11y` | ❌ **8 high** (pre-existing) | outline:none in admin.css/admin-premium.css. **Should fix before 1.0.0.** Session edits introduced 4 — fixed in same run. |

## Session-introduced regressions

**0** regressions remain. The 4 a11y high-severity violations introduced by `src/shared/block-card.css` (`outline: none` on `:focus-visible` without proper replacement) were caught and fixed in the same session — re-running `wppqa_check_a11y` confirms my edits are now clean.

## Pre-existing issues blocking 1.0.0

These were not introduced this session but ARE standards violations that must be resolved before customer release:

### BLOCKER — security (must fix before 1.0.0)

12 admin handlers verify a nonce but skip `current_user_can()`. Nonce ≠ authorization. Any logged-in user with a stolen nonce can submit. Files:

- `src/Admin/BadgeAdminPage.php` lines 434, 526
- `src/Admin/ChallengeManagerPage.php` lines 272, 334
- `src/Admin/CohortSettingsPage.php` line 244
- `src/Admin/CommunityChallengesPage.php` lines 298, 363
- `src/Admin/ManualAwardPage.php` line 239
- `src/Admin/RedemptionStorePage.php` lines 391, 470
- `src/Admin/WebhooksAdminPage.php` lines 190, 242

**Fix pattern:**

```php
if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), '...' ) ) {
    wp_die( esc_html__( 'Security check failed.', 'wb-gamification' ) );
}
if ( ! current_user_can( 'manage_options' ) ) {  // ← THIS LINE MISSING
    wp_die( esc_html__( 'You do not have permission to perform this action.', 'wb-gamification' ) );
}
```

### HIGH — accessibility (should fix before 1.0.0)

8 admin stylesheets remove the focus indicator without replacement:

- `assets/css/admin.css` lines 431, 451
- `assets/css/admin.min.css` (compiled output of admin.css)
- `assets/css/admin-premium.css` lines 529, 575, 603, 688

**Fix pattern:** replace `outline: none;` on `:focus` with `:focus-visible { outline: 2px solid var(--wb-gam-color-accent); outline-offset: 2px; }`. Re-minify admin.css → admin.min.css after the source edit.

### MEDIUM — code quality (clean up before 1.0.0)

9 inline `onclick=` attributes (CSP-incompatible, fight Interactivity API + event delegation):

- `blocks/year-recap/render.php` line 213
- `src/Admin/{ApiKeysPage,BadgeAdminPage,ChallengeManagerPage,CommunityChallengesPage,RedemptionStorePage,SettingsPage,WebhooksAdminPage}.php` various lines

`SettingsPage.php` has 2 occurrences. Replace with delegated `addEventListener` or `data-wp-on--click` for IA-aware blocks.

### MEDIUM — responsive discipline

6 distinct CSS breakpoints found across the codebase: 390, 480, 640, 782, 900, 1024. Standard says 3 (640/1024/1440 typical). Consolidation pass needed — likely component-local fixes drifting away from the design system.

### LOW — UX tech debt

- 427 raw `px` values outside `var(--wb-gam-space-*)` tokens
- 159 raw hex colors outside `var(--wb-gam-color-*)` tokens
- 5 emojis in user-facing strings (replace with Lucide icons)
- 2 button heights at 16px (must be ≥40px tap target)

These are tech debt — slow burndown rather than 1.0.0 blocker. Convert to a `/wp-plugin-onboard --refresh` follow-up sprint.

## Decision

Tier 1 is **NOT GREEN** for 1.0.0. The 12 nonce-without-cap security holes are blockers. Tier 2 cannot proceed until:

1. Security holes patched (12 admin handlers).
2. A11y outline:none fixed in admin stylesheets.
3. Inline onclick handlers replaced.

Tier 2/3+ work continues in parallel for all surfaces NOT touched by these issues (block frontends, REST endpoints, Tier 4 earning journey, Tier 7 frontend a11y on the 15 blocks). Admin-surface tiers (Tier 5) wait until admin fixes land.

## Run artefacts

- `php-lint.txt` — empty (no errors)
- `coding-rules.txt` — green
- `block-standard.txt` — green
- `phpcs.txt` — empty (per-file MCP runs documented inline in this summary)
- `phpstan.txt` — empty (no errors at level 5)
- `phpunit-unit.txt` — 108 tests pass
- `sizes.txt` — bundle audit
- `wppqa-plugin-dev-rules.txt` — full 12 high + 9 medium + 1 medium + 2 low listing
