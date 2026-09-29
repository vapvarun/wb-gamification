# wppqa baseline — wb-gamification

Run: 2026-09-27 · branch `1.6.5` · site `http://buddynext.local`
Command: `wppqa_audit_plugin --plugin_path=<repo> --site_url=http://buddynext.local --format=json`
Raw output was 1.6 MB and is **not committed** (gitignored) — re-run the command to regenerate it.
This summary is the durable record.

Per hard rule #6, this runs BEFORE manifest generation: the manifest says what EXISTS,
wppqa says what's BROKEN. Everything below is read in that order.

**Tool caveats for this run (read before trusting any number):**

- The tool mis-detected the main plugin file. It reported `pluginName` "Redemption — LearnDash
  course unlock" / version `1.0.0`, which is an `examples/` sample, not `wb-gamification.php`.
  Anything keyed on the header (the marketing version check) is wrong for that reason.
- The tool **overwrote the tracked `audit/qa-coverage.json`** with a worse copy (version `0.0.0`,
  `manifest_at: null`). It was restored from git before this baseline was committed. The qa-coverage
  "pass" below comes from that regenerated file and is not a real fix.
- phpcs ran **without** the repo's `.phpcs.xml` (it flags `bin/`, which the ruleset excludes).
  July has no phpcs row, and this row cannot be compared with July or with `composer phpcs`.

## Per-check result

| Section | Check | Passed | Failed |
|---|---|---:|---:|
| Code quality | php-lint | 345 | **0** |
| Code quality | composer-audit | 0 | 3 (dev-only, see below) |
| Code quality | i18n | 3 | 0 |
| Code quality | bundle-size | 1 | 0 (1 warn) |
| Code quality | phpcs | 0 | 4378 (ruleset not applied, not comparable) |
| Product | ux | 5 | 0 |
| Product | templates | 5 | 0 |
| Product | api | 48 | 28 (all false positive, see below) |
| Product | database | 40 | **0** |
| Product | frontend-eval | 9 | 1 |
| Product | marketing | 10 | 1 |
| Product | a11y | 0 | **18** |
| Product | admin-eval | 0 | **30** |
| Product | browser | 0 | 0 (40 skipped) |
| Systemic | rest-js-contract | 12 | **0** |
| Systemic | ux-guidelines | 5 | 0 |
| Systemic | enum-consistency | 19 | 6 |
| Systemic | plugin-dev-rules | 4 | 5 |
| Systemic | stylelint | 623 | 5 |
| Systemic | qa-coverage | 1 | 0 (artifact, see caveats) |

phpstan, eslint, stylelint (code-quality), a11y-grep, security-scan, performance-scan, pcp-deep,
wiring and editor-layout-bias were skipped by the tool.

## Diff vs 2026-07-12

The July raw output was not kept, so this diff is by count per check. Each finding in a check
whose count went up was read at its file:line.

| Check | July → now | What changed | Verdict |
|---|---|---|---|
| api | 0 → 28 | 28 critical "write endpoint allows unauth access", all reading "GET / returned 200" | **False positive.** The tool resolved concatenated routes (`'/' . $this->rest_base`) to `/` and probed `/wp-json/`, which always returns 200. Several flagged lines are READABLE routes, not writes. Disproved directly: every one of the 64 non-GET handlers under `wb-gamification/v1` was enumerated from the live REST server and its `permission_callback` called as a logged-out visitor; 0 returned true. (Plain HTTP probes are not proof here: WordPress validates params before permissions, so a 400 hides the auth answer.) |
| composer-audit | 0 → 3 | critical advisories on `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`, `phpcsstandards/phpcsutils` | Real advisories, but **require-dev only**. `composer.lock` did not change on 1.6.5. `vendor/` never ships in the zip (`bin/build-release.sh` strips it). Bump dev tooling when convenient. |
| admin-eval | 28 → 30 | SettingsPage `$_POST`→`update_option` (18), "POST form without nonce" (11), "too many top-level menus" (1) | **False positive.** Saves run inside `handle_save()` behind `check_admin_referer()` and `manage_options`. The "nonce-less forms" are `data-wb-gam-rest-form` REST forms (wp_rest nonce). Only one `add_menu_page` is ours. The +2 includes SettingsPage:447, the new 1.6.5 `Privacy::host_decides()` guard. |
| enum-consistency | 5 → 6 | new `reason` drift: NotificationBridge:289 / PointsEngine:264 / RedemptionEngine:90 | **False positive.** Skip reasons and redemption-failure reasons are separate domains that share the key name. It was triggered by 1.6.5 adding `fulfillment_failed` to RedemptionEngine. |
| stylelint (systemic) | new | 5 high: undefined `--wb-gam-color-text-secondary`, `--wb-gam-container-width`; unstyled `.wb-gam-rank-badge`, `.wb-gam-submit-achievement__hint`, `__label` | Real but cosmetic. Both vars have fallbacks. None of these files changed on 1.6.5. |

**Unchanged:** a11y 18 (still css focus-outline rules + 1 vendored; new high sample StreaksPage:184 is
`get_avatar()`, which emits `alt` — false positive), plugin-dev-rules 5 (all vendored), frontend-eval 1,
marketing 1.

**Went down / improved:** php-lint 337 → 345 passing, database 39 → 40, rest-js-contract 10 → 12,
enum-consistency passing 18 → 19. qa-coverage 1 → 0 failed is an artifact of the overwritten file
(see caveats), not a fix.

## Ours vs vendored — the load-bearing distinction

**All 5 `plugin-dev-rules` HIGH security failures are still VENDORED, not ours:**

- `libs/woocommerce/action-scheduler/...ActionScheduler_Abstract_ListTable.php:184,189,632` — raw `$_POST` iteration, nonce-without-cap
- `libs/woocommerce/action-scheduler/lib/WP_Async_Request.php:172` — nonce-without-cap
- `libs/easy-digital-downloads/edd-sl-sdk/src/Licensing/License.php:371` — nonce-without-cap

Our own code has **zero** plugin-dev-rules high findings. The 28 "critical" api findings are a
tool route-resolution error, and live probes disprove them. Do NOT "fix" vendored libraries —
the patch is lost on re-vendor (the marked `WBCOM PATCH` in the EDD SDK `Path.php` is the only
exception).

## Real, ours, worth acting on

| Count | Sev | Check | Where |
|---:|---|---|---|
| 25 | high | qa-coverage | `audit/manifest.json` — REST/AJAX/hook entries with no test coverage (unchanged from July) |
| 16 | high | a11y | **Fixed in this release.** `give-kudos.css` (3 rules) and `share-page.css` (1) used `outline: none` with only a box-shadow ring, so forced-colors / Windows High Contrast users saw no focus at all (forced colors drop box-shadow). They now use `outline: 2px solid transparent`, invisible normally and repainted in forced colors (verified with Chromium forced-colors emulation). `hub.css:875` is the correct `:focus:not(:focus-visible)` pattern. The tool still counts the built/RTL/min copies. |
| 5 | high | stylelint | undefined tokens / unstyled classes listed above (cosmetic, fallbacks present) |
| 4 | low | plugin-dev-rules | `assets/css/admin/components*.css:527` — 34px button < 40px tap target |
| 1 | critical | marketing | `readme.txt` `Stable tag: 1.6.4` while the header is `1.6.5` — bump at release (the tool's "1.0.0" is the mis-detected example file) |
| 3 | critical | composer-audit | dev-only tooling advisories — bump phpcs/wpcs/phpcsutils |

## Verdict

Not release-ready by the strict gate (`failed > 0`), but **no failing gate is in our
security-critical path and 1.6.5 introduced no real regression**. php-lint, database (40) and
rest-js-contract (12) are clean. The api jump from 0 to 28 is a tool false positive, disproven
with live unauthenticated probes. The findings that rose on 1.6.5-changed files (SettingsPage:447,
the RedemptionEngine `reason` enum) are both false positives. The open work is the same
admin-eval and QA-coverage polish as July, plus bumping dev tooling. The one real a11y finding
(focus hidden in forced-colors mode) is fixed in 1.6.5. `audit/qa-coverage.json`, which the tool
overwrote, was restored from git.
