# WB Gamification — QA Release Checklist

> **The final gate before tagging a release.** Every row must pass, no exceptions.
> Backend / packaging counterpart to [`PRE_RELEASE_SMOKE.md`](PRE_RELEASE_SMOKE.md) (frontend / browser).
> Together they guarantee: code quality + feature behavior + safe packaging.

**Target time:** 45 minutes end-to-end (plus the 90-min browser smoke).

A more-detailed historical equivalent is [`plan/PRE-RELEASE-CHECKLIST.md`](../../plan/PRE-RELEASE-CHECKLIST.md). This document is the standardized, recurring gate; that one was the v1.0 release-cycle planning doc.

---

## 0 — Branch hygiene

- [ ] On a named release branch (`release/x.y.z`), NOT on `main`
- [ ] `git status` clean — no uncommitted changes
- [ ] `git pull` on the branch — up to date with origin
- [ ] `main` merged into release branch (or rebased) — no stale base
- [ ] No `.DS_Store`, `.idea/`, `.vscode/`, `node_modules/`, `vendor/` staged for commit
- [ ] `audit/journey-runs/` not committed (`.gitignore` covers it)
- [ ] `docs/qa/.last-smoke-pass.json` not committed (`.gitignore` covers it; `.example.json` IS tracked)

```bash
cd wp-content/plugins/wb-gamification
git status
git fetch origin
git log --oneline origin/main..HEAD | head -20   # what's shipping
```

## 1 — Version triangulation

Every version reference must match.

- [ ] `wb-gamification.php` header `Version:` equals the release version
- [ ] `wb-gamification.php` `define( 'WB_GAM_VERSION', 'x.y.z' );` matches
- [ ] `readme.txt` `Stable tag: x.y.z` matches
- [ ] `package.json` `version` matches
- [ ] `composer.json` `version` matches (if set; not all releases set it)
- [ ] `CHANGELOG.md` has a `## x.y.z — YYYY-MM-DD` entry with real release notes

Fast check:
```bash
grep -rE "Version:|define.*WB_GAM_VERSION|Stable tag" \
  wp-content/plugins/wb-gamification \
  | grep -v vendor | grep -v node_modules
```

Every printed line should show the same version.

## 2 — Static analysis

Run from the plugin root.

### WPCS (WordPress Coding Standards)

- [ ] `composer phpcs` (or per-file via `mcp__wpcs__wpcs_check_directory`) — 0 errors on changed files
- [ ] No new `// phpcs:ignore` suppressions added without comment

### PHPStan

- [ ] `composer phpstan` — level 5 clean, or only entries in baseline
- [ ] Baseline NOT grown this release (or the diff is documented in CHANGELOG)

### PHP lint (syntax)

```bash
find wp-content/plugins/wb-gamification \
  -name "*.php" -not -path "*/vendor/*" -not -path "*/node_modules/*" \
  -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

- [ ] No output (zero syntax errors)

### Plugin-specific coding rules

- [ ] `bash bin/coding-rules-check.sh` — "All coding rules pass"

## 3 — Tests

### PHPUnit

- [ ] `composer test:unit` — all tests pass, 0 failures, 0 errors
- [ ] PHP matrix covers floor (`Requires PHP: 8.1`) AND current stable (8.3 / 8.4)
- [ ] WP matrix covers `Requires at least: 6.4` AND current stable AND `latest`

```bash
composer test:unit
```

### Block bundle sizes

- [ ] Every block JS ≤ 20 KB gzipped (`build/Blocks/*/index.js`)
- [ ] Every block CSS ≤ 30 KB gzipped (`build/Blocks/*/style-index.css`)

### wppqa MCP audit

- [ ] `mcp__wp-plugin-qa__wppqa_check_rest_js_contract` — failed=0
- [ ] `mcp__wp-plugin-qa__wppqa_check_wiring_completeness` — failed=0
- [ ] `mcp__wp-plugin-qa__wppqa_check_plugin_dev_rules` — failed=0
- [ ] `mcp__wp-plugin-qa__wppqa_check_a11y` — failed=0

### Journeys

- [ ] `bash bin/run-journeys.sh --priority critical` — all critical journeys pass against the local test site
- [ ] `bash bin/run-journeys.sh` — full suite runs cleanly

## 4 — Security sweep

### Nonce + capability hot-check

- [ ] Every new REST route registered this release has a `permission_callback` that calls `current_user_can()` (or returns `__return_true` only if listed in `audit/ROLE_MATRIX.md` allowlist)
- [ ] Every new admin form calls `wp_verify_nonce()` + `current_user_can()` on POST handler
- [ ] Every new form output includes `wp_nonce_field()`

```bash
git diff origin/main...HEAD -- '*.php' | grep -E "^\+.*register_rest_route" | head -20
```

### Escape on output

- [ ] Every echoed variable passes through `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`
- [ ] Translations via `esc_html__` / `esc_attr__` / `esc_html_e` (not bare `__` in output context)

```bash
git diff origin/main...HEAD -- '*.php' | grep -E "^\+.*echo \\\$" | grep -v "esc_"
```

### SQL

- [ ] No string concatenation in `$wpdb` queries — all use `$wpdb->prepare()`
- [ ] Table names via `$wpdb->prefix . 'wb_gam_*'` — no hardcoded `wp_wb_gam_`

### Public REST allowlist

- [ ] `__return_true` permission callbacks remain limited to the documented public allowlist (`audit/ROLE_MATRIX.md` § "REST API permission map")
- [ ] Live verification via `audit/journeys/security/01-rest-public-allowlist.md` passes

## 5 — Translations (i18n)

- [ ] `.pot` regenerated and matches current strings
- [ ] No em-dashes (`—`) inside any `__()` / `_e()` / `_x()` / `_n()` / `esc_html__()` (reads as AI-generated)
- [ ] Text domain consistent across all files (`wb-gamification`)
- [ ] `_n()` used for pluralizable strings (not runtime `if ($count === 1)`)

```bash
# Em-dash check
grep -rE "(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\([^)]*—" \
  wp-content/plugins/wb-gamification | grep -v vendor

# Regenerate pot
wp i18n make-pot wp-content/plugins/wb-gamification \
  wp-content/plugins/wb-gamification/languages/wb-gamification.pot
```

## 6 — Readme + Docs

### WordPress.org readme

- [ ] `readme.txt` validates at https://wordpress.org/plugins/developers/readme-validator/
- [ ] `Requires at least`, `Tested up to`, `Requires PHP` current
- [ ] `Stable tag` matches release version
- [ ] Changelog entry written with customer-facing release notes
- [ ] Upgrade notice written if behavior changes

### Internal docs

- [ ] `CHANGELOG.md` updated (human-readable, customer-facing)
- [ ] `CLAUDE.md` "Recent Changes" updated (per the CLAUDE.md house rule)
- [ ] `docs/qa/AGENT_SMOKE_RUNBOOK.md` Section D updated with new regression guards from this release
- [ ] `docs/website/` updated for any customer-visible feature change (sync via `mcp__wbcom-docs__publish_product_docs`)
- [ ] `audit/manifest.json` regenerated if REST routes / blocks / hooks changed (`/wp-plugin-onboard --refresh`)

## 7 — Browser smoke gate (external dependency)

- [ ] `docs/qa/.last-smoke-pass.json` exists
- [ ] Report `release_version` equals the release version
- [ ] Report `ran_at` within the last 24 hours
- [ ] `failures[]` (any with `origin: from`) is empty
- [ ] `debug_log_issues[]` (any with `origin: from`) is empty
- [ ] `manual_required[]` reviewed — Firefox / Safari iOS items verified separately by a human

If the report is missing or stale, run the generic `wp-plugin-smoke` skill (`/wp-plugin-smoke` from anywhere — auto-detects this plugin from CWD or accepts an explicit path). The skill reads `docs/qa/qa.config.json` for plugin variables and dispatches Sonnet via `Agent({ model: "sonnet" })`. Skill source: `~/.claude/skills/wp-plugin-smoke/SKILL.md`.

## 8 — Packaging dry-run

- [ ] `bash bin/build-release.sh` succeeds (the smoke gate fires before packaging — it's the ground truth that section 7 passed)
- [ ] Resulting zip has NO: `.git/`, `node_modules/`, `tests/`, `.github/`, `bin/`, `phpunit.xml.dist`, `phpcs.xml.dist`, `composer.json` / `composer.lock`, `package.json` / `package-lock.json`, `CLAUDE.md`, `audit/`, `plan/`, `docs/`, `.DS_Store`
- [ ] Resulting zip HAS: `wb-gamification.php`, `readme.txt`, `uninstall.php`, `vendor/` (runtime deps), `assets/`, `build/`, `templates/`, `languages/`, `integrations/`, `blocks/` (legacy compat)
- [ ] Zip extracts to a folder named exactly `wb-gamification/` (not `wb-gamification-x.y.z/`)
- [ ] Zip size reasonable (flag if >2× previous release)

```bash
bash bin/build-release.sh
unzip -l dist/wb-gamification-x.y.z.zip | head -50
ls -lh dist/wb-gamification-x.y.z.zip
```

The detailed packaging journey is [`audit/journeys/release/09-release-zip-gate.md`](../../audit/journeys/release/09-release-zip-gate.md).

## 9 — Install-in-anger

On a **second clean** Local site:

- [ ] Install the generated zip via `wp plugin install /tmp/wb-gamification-x.y.z.zip --activate`
- [ ] Activation succeeds — no fatal, no PHP warning in debug.log
- [ ] First REST request after activation returns 200 (regression guard against `D.activation-rewrite`)
- [ ] All 22 DB tables created
- [ ] Setup wizard renders; first earned points lands within 60s of completing it

## 10 — Upgrade-in-anger

On a **third clean** site with the previous stable version + real data:

- [ ] Upload the new zip via WP admin update flow
- [ ] Upgrade succeeds — no fatal during the upgrade HTTP request
- [ ] DB version option updates: `wp option get wb_gam_db_version`
- [ ] Pre-existing data still renders on every surface (don't just check one page)
- [ ] Settings preserved (no defaults overwritten)
- [ ] Eight `wb_gam_*` granular caps registered for administrator
- [ ] Cosmetic tables dropped (on v1.0 → v1.1)
- [ ] Cron events re-registered cleanly: `wp cron event list | grep wb_gam_`
- [ ] No new `debug.log` entries during the upgrade request

## 11 — Release metadata

- [ ] Git tag created: `v<version>` (annotated: `git tag -a v<version> -m "..."`)
- [ ] Tag points at the release-branch commit (not `main` yet)
- [ ] GitHub Release drafted with changelog from `CHANGELOG.md`
- [ ] Release zip attached to GitHub Release
- [ ] Branch protection rules on `main` intact

## 12 — Post-tag checks (first push)

- [ ] CI on the tag is green (PHPUnit matrix, PHPStan, WPCS, Lint)
- [ ] Release branch merged back to `main` (per repo convention)
- [ ] Smoke gate stays green on `main` HEAD

## 13 — Customer-facing publish

Only once sections 0–12 are ticked:

- [ ] Wbcom store product page updated with new version + changelog
- [ ] Docs website synced — `mcp__wbcom-docs__publish_product_docs({ product_slug: "wb-gamification", product_path: "<plugin path>", product_type: "plugin", sync_to_live: true })`
- [ ] Internal Slack post to `#releases` with zip link + changelog link + smoke report link
- [ ] Customer update email drafted (with the real changelog, not marketing fluff) — optional per release

## 14 — Post-release monitor (first 24h)

- [ ] `wp-content/debug.log` on the production reference site clean of new warnings / notices / fatals
- [ ] Zoho Desk / Crisp — no "broke after update" tickets in first 24h
- [ ] Basecamp Bugs column (project `47162271`, ID `9860020654`) — no new cards matching the release
- [ ] Daily new-event signal ≥ 70% of trailing-7-day baseline

If any post-release signal is red → open a `<version>.1` patch cycle immediately.

---

## Failure protocol

If ANY row in sections 0–11 fails:

1. **Stop.** Do not tag or publish.
2. Fix in the release branch.
3. Re-run from Section 0 — a fix can regress earlier sections.
4. Only tag after the entire checklist is green in one continuous run.

## Emergency patch

For a genuinely emergency patch (security CVE, dataloss bug reaching production):

- The `--skip-browser-smoke` flag on `bin/build-release.sh` is allowed.
- Sections 0–6 and 8–11 are still non-negotiable.
- Document the skipped browser smoke in the release notes with a reason.

## Version-specific additions

Append a section below per release with the specific extra checks added that cycle. After 2 clean releases of a row, graduate it into the main checklist above.

### v1.0.0 release
- (initial baseline; row-by-row history begins with v1.0.1)
