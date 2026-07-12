# wppqa baseline — wb-gamification

Run: 2026-07-12 · branch `1.6.4` · site `http://buddynext-dev.local`
Command: `wppqa_audit_plugin --plugin_path=<repo> --site_url=http://buddynext-dev.local`
Raw output was 888 KB and is **not committed** (gitignored) — re-run the command to regenerate it.
This summary is the durable record.

Per hard rule #6, this runs BEFORE manifest generation: the manifest says what EXISTS,
wppqa says what's BROKEN. Everything below is read in that order.

## Per-check result

| Section | Check | Passed | Failed |
|---|---|---:|---:|
| Code quality | php-lint | 337 | **0** |
| Code quality | composer-audit | 1 | 0 |
| Code quality | i18n | 3 | 0 |
| Code quality | bundle-size | 1 | 0 (1 warn) |
| Product | ux | 5 | 0 |
| Product | templates | 5 | 0 |
| Product | api | 62 | **0** |
| Product | database | 39 | **0** |
| Product | frontend-eval | 9 | 1 |
| Product | marketing | 10 | 1 |
| Product | a11y | 0 | **18** |
| Product | admin-eval | 0 | **28** |
| Systemic | rest-js-contract | 10 | **0** |
| Systemic | ux-guidelines | 5 | 0 |
| Systemic | enum-consistency | 18 | 5 |
| Systemic | plugin-dev-rules | 4 | 5 |
| Systemic | qa-coverage | 0 | 1 |

## Ours vs vendored — the load-bearing distinction

1,500 issues in our code; 53 in bundled third-party (`libs/`, `vendor/`, `dist/`).

**All 5 `plugin-dev-rules` HIGH security failures are VENDORED, not ours:**

- `libs/woocommerce/action-scheduler/...ActionScheduler_Abstract_ListTable.php` — raw `$_POST` iteration, nonce-without-cap
- `libs/easy-digital-downloads/edd-sl-sdk/src/Licensing/License.php:371` — nonce-without-cap

Our own code has **zero** plugin-dev-rules high findings. This matches the plugin's known
security posture (own code clean; warnings are the bundled-library + custom-table pattern).
Do NOT "fix" vendored libraries — they are upstream code and the patch would be lost on
re-vendor. (The one exception we DO carry is the EDD SDK `Path.php` asset-URL patch, which is
marked `WBCOM PATCH` precisely so it survives.)

## Real, ours, worth acting on

| Count | Sev | Check | Where |
|---:|---|---|---|
| 28 | high | admin-eval | `src/Admin/SettingsPage.php` (18), BadgeAdminPage, ManualAwardPage, ChallengeManagerPage, CommunityChallengesPage |
| 25 | high | qa-coverage | `audit/manifest.json` — REST/AJAX/hook entries with no test coverage |
| 17 | high | a11y | mostly `assets/css/*.css` (give-kudos, hub, share-page + RTL/min variants) |
| 2 | high | enum-consistency | ours (3 more are vendored) |
| 1 | critical | marketing | readme/marketing copy |

## Verdict

Not release-ready by the strict gate (`failed > 0`), but **no failing gate is in our
security-critical path**: php-lint, api (62), database (39) and rest-js-contract (10) are all
clean, which covers the 1.6.4 notifications-queue work. The open work is admin-eval + a11y
polish and QA coverage — pre-existing, not introduced by 1.6.4.
