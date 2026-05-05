# wppqa baseline — 2026-05-06 (pre-Sprint-1)

**Plugin:** wb-gamification 1.0.0 (pre-launch)
**Trigger:** baseline run before starting v1.0 critical-gap sprint work
**Scope:** full `mcp__wp-plugin-qa__wppqa_audit_plugin` — broader than the 2026-05-03 baseline (which covered only `plugin_dev_rules` / `rest_js_contract` / `wiring_completeness` / `a11y`)

## Verdict

**Release-clean.** Zero net-new fixes required. Every high-severity finding from the broad audit traces to either a documented architectural decision, a heuristic false positive in the audit's grep patterns, or a previously verified false positive (per `audit/CLOSE-OUT-2026-05-02.md` § 3).

The strict gate — `wppqa_check_plugin_dev_rules`, which is the canonical enforcer of `wp-plugin-development` skill rules — reports **passed=9 / failed=0** (matches 2026-05-03 baseline).

## Per-check results

| Check | Result | Notes |
|---|---|---|
| `wppqa_check_plugin_dev_rules` | ✅ passed=9 / failed=0 | The strict gate. Same as 2026-05-03 |
| `wppqa_check_a11y` | ✅ passed=11 / failed=1 | 1 false positive — see § 1 |
| `wppqa_check_rest_js_contract` | ✅ 0 issues | Same as 2026-05-03 |
| `wppqa_check_wiring_completeness` | ✅ 0 issues | Same as 2026-05-03 |
| PHP-LINT | ✅ 0 errors | |
| COMPOSER-AUDIT | ✅ 0 errors | |
| I18N | ✅ 0 errors | |
| PLUGIN-CHECK | ✅ 0 errors | Closed via 2026-05-05 fix run (was 13 errors) |
| PHPCOMPAT | ✅ 0 errors | PHP 8.1+ |
| PHPCS (audit's stricter ruleset) | ⚠ 1018 errors / 654 warnings | See § 4 — project's own `.phpcs.xml` is clean |
| ADMIN-EVAL | ⚠ 10 errors | 4 documented architectural decision + 6 heuristic false positives — see § 2 |
| FRONTEND-EVAL | ⚠ 1 error / 54 warnings | Modal-close-button heuristic mismatch — see § 3 |
| TEMPLATES | ✅ 0 errors / 2 warnings | |
| MARKETING | ✅ 0 errors / 3 warnings | |

## § 1 — A11y "1 image without alt" (false positive)

**Finding**: `src/BuddyPress/Stream/ActivityCard.php:31` — `<img>` without alt attribute.

**Reality**: Line 31 is inside a `@docblock` comment that contains the literal text `<img src>`. The audit's grep matches the substring inside the comment, not actual HTML.

The real `<img>` output is at line 50:
```php
'<img class="wb-gam-activity-card__icon" src="%2$s" alt="%3$s" width="64" height="64" />'
```
— alt attribute is populated from `$title`. ✅ Compliant.

**Action**: none. False positive.

## § 2 — Admin-eval 10 errors

### 2a — 4× "Direct $_POST to update_option (bypasses Settings API)" — documented architectural decision

Same finding as `audit/CLOSE-OUT-2026-05-02.md` § 2: the Settings page (and 3 other admin pages) handle their own form submission via direct `$_POST` reads + `update_option()` instead of the WordPress Settings API. This is a deliberate architectural choice — see `src/Admin/SettingsPage.php` and the team decision to use a custom card-based admin UI rather than the legacy `<table>` Settings API rendering. Migrating would be a substantial refactor across 4 admin pages and provides no functional benefit (same nonce/cap checks already gate every write).

**Action**: none. Acceptable.

### 2b — 6× "POST form without nonce verification" — heuristic mismatch

The strict check `wppqa_check_plugin_dev_rules` enforces the canonical `wp-plugin-development` skill rule: nonce paired with capability check. It reports **passed=9 / failed=0**.

The broader `admin-eval` heuristic flags these 6 forms because it can't trace the nonce-verify pattern through the Tier-0 `data-wb-gam-rest-*` REST-driven flow (forms submit via `assets/js/admin-rest-form.js` to authenticated REST endpoints with `permission_callback` checks; no traditional `wp_nonce_field()` in the static HTML).

**Action**: none. The strict gate confirms compliance. Heuristic is too narrow.

## § 3 — Frontend-eval "Modal/popup missing close button" (false positive)

**Finding**: 1 modal flagged as missing close button.

**Reality**: Two `role="dialog"` elements exist; both have working close affordances:

| File | Element | Close affordance | Heuristic match? |
|---|---|---|---|
| `src/Blocks/redemption-store/render.php:237` | confirm modal | "Cancel" button (`wb-gam-redemption__confirm-no` + `actions.cancelRedeem`) | ❌ — heuristic looks for `.close` / `.dismiss` / `×` |
| `src/Blocks/hub/render.php:319` | side panel | back-arrow button (`gam-panel__back` + `actions.closePanel` + `screen-reader-text` "Close panel") | ❌ — same |

Both UX patterns are valid and a11y-compliant. The heuristic doesn't recognise our specific class names / icon-based affordances.

**Action**: none for v1.0. Optional UX-polish in a later cycle: add a top-right `×` close button to both dialogs for an additional close affordance — would also satisfy the heuristic. Not blocking.

## § 4 — PHPCS 1018 errors / 654 warnings (stricter ruleset)

The audit runs PHPCS with the WordPress.org submission ruleset. The project's own `.phpcs.xml` (used by `composer phpcs`) is clean — see `audit/CLOSE-OUT-2026-05-02.md` § "Score progression". Top issue categories from the broader run:

- `WordPress.Files.FileName.InvalidClassFileName` — "expected class-X.php, got X.php". Project follows PSR-4 (matches `wb-gamification.php`'s autoloader). Intentional. WP.org submission would need a shim file but plugin is sold via store, not WP.org.
- `Squiz.Commenting.FunctionComment.Missing` — missing docblocks on small functions. Documentation debt, not a security issue.
- `Generic.Arrays.DisallowShortArraySyntax` — short array syntax allowed in modern PHP; project intentionally uses `[]`.

**Action**: none for v1.0. The strict gate (`composer phpcs`) is clean. Documentation backfill is a v1.x technical-debt item.

## § 5 — Documented "Fix Before Release" GAPs (all false positives, re-verified)

Per `audit/CLOSE-OUT-2026-05-02.md` § 3:

| Audit claim | Reality (verified) |
|---|---|
| "Some REST routes lack proper permission callbacks" | All 47 routes have explicit `permission_callback`. The 17 `__return_true` ones are documented public per `audit/ROLE_MATRIX.md` allowlist; the strict gate `bin/coding-rules-check.sh` Rule 2 enforces that allowlist |
| "Custom tables exist but no uninstall hook" | `uninstall.php` exists; full cleanup |
| "Custom tables exist but no activation hook" | `register_activation_hook` at `wb-gamification.php` |
| "Cron jobs scheduled but no deactivation hook" | Cron events cleared in `Engine\Engine::deactivate` |

## § 6 — What this means for Sprint 1

- ✅ Codebase is release-clean as a starting point for the v1.0 critical-gap sprint
- ✅ No pre-Sprint-1 cleanup work required
- ✅ The 5 critical-gap cards in **Figuring it out** can move to **In progress** immediately
- ⚠ When Sprint 1 PRs land, re-run `wppqa_audit_plugin` and confirm `plugin_dev_rules` stays `failed=0` (the gate that matters)
- 📋 The wider audit's heuristic mismatches above are NOT in the v1.0 acceptance gates — only `wppqa_check_plugin_dev_rules` and `wppqa_check_rest_js_contract` and `wppqa_check_wiring_completeness` are gating

## Artefacts

- `REPORT.md` (this folder) — full 1,256-line wppqa_audit_plugin output
- This file — interpreted summary

Updated by Varun — 2026-05-06.
