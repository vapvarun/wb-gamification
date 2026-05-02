# wppqa baseline — 2026-05-02

Run before manifest generation per `wp-plugin-onboard` hard rule #6.

## Per-check results

| Check | Passed | Failed | Skipped | Duration |
|---|---|---|---|---|
| `plugin-dev-rules` | 8 | **1** | 0 | 42ms |
| `rest-js-contract` | 2 | 0 | 0 | 12ms |
| `wiring-completeness` | 0 | 0 | 1 | 0ms |

`wiring-completeness` skipped: no `templates/` directory matching its read-pattern (this plugin renders admin UI from `src/Admin/*Page.php` directly and frontend from `blocks/*/render.php`). The check is heuristic; absence here is not a clean bill.

## High-severity findings (1)

| Severity | Finding | Location | Fix direction |
|---|---|---|---|
| high | Nonce check without capability check | `src/Admin/BadgeAdminPage.php:526` | Pair `wp_verify_nonce()` with `current_user_can()` — nonces prevent CSRF but do NOT authorize. |

## Medium-severity findings (9)

| Code | Count | Files |
|---|---|---|
| `inline-onclick` | 8 | `blocks/year-recap/render.php:208`, `src/Admin/ApiKeysPage.php:153`, `src/Admin/BadgeAdminPage.php:360`, `src/Admin/ChallengeManagerPage.php:244`, `src/Admin/CommunityChallengesPage.php:270`, `src/Admin/RedemptionStorePage.php:249`, `src/Admin/SettingsPage.php:818`, `src/Admin/SettingsPage.php:944` |
| `breakpoint-proliferation` | 1 | 6 distinct breakpoints found (390/480/640/782/900/1024px) — frontend-responsive Rule 1 wants ≤3 (640/1024/1440 typical) |

## Low-severity findings (2)

| Code | Count | Files |
|---|---|---|
| `tap-target-small` | 2 | `assets/css/admin-premium.css:766`, `assets/css/admin-premium.min.css:1` (16px button height; minimum 40px per a11y) |

## Verdict

Plugin is **NOT release-ready** until the 1 high finding is addressed (per Phase 0 release-readiness gate in the skill). Medium/low findings are quality debt, not release blockers.

The manifest generated below documents WHAT exists; this baseline documents WHAT'S BROKEN. Cross-reference both when answering "what does this plugin do?"
