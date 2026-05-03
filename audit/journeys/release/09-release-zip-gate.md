---
journey: tier-9-release-zip-gate
plugin: wb-gamification
priority: critical
roles: [release-engineer]
covers: [packaging, plugin-check, dist-exclusions]
prerequisites:
  - "Repo at wp-content/plugins/wb-gamification"
  - "composer + npm + zip available"
  - "wp-cli + Plugin Check plugin installed for the optional final probe"
estimated_runtime_minutes: 8
---

# Tier 9 — Release Engineering Gate

The release zip must NOT contain dev artefacts (src/, tests/, audit/, plans/, node_modules/, composer*, package*, phpunit*, phpstan*, .git*, CLAUDE.md). Plugin Check must run on the BUILT zip — never on the source tree (per Part 17.7.3 of wp-plugin-development).

## Setup

- Repo: `wp-content/plugins/wb-gamification`
- Tools: `bash`, `composer`, `npm`, `zip`, optional `wp plugin check`

## Steps

### 1. Bump version (manual, before this journey runs)
- **Expect**: `wb-gamification.php` `Version:` header, `readme.txt` `Stable tag`, and `CHANGELOG.md` head all reference the new version

### 2. Build the release zip
- **Action**: `bash bin/build-release.sh`
- **Expect**:
  - script prints `→ Building wb-gamification v<X.Y.Z>`
  - npm run build completes (webpack reports "compiled successfully")
  - `dist/wb-gamification-<X.Y.Z>.zip` created (size 2-5 MB typical)
  - composer dev deps restored at end of script

### 3. Inspect zip — exclusions enforced
- **Action**: `unzip -l dist/wb-gamification-<X.Y.Z>.zip | grep -E "wb-gamification/(src|tests|docs|plans|audit|node_modules|composer\\.|package\\.|\\.git|CLAUDE\\.md|phpunit|phpstan|phpcs)"`
- **Expect**: 0 matches (every dev artefact excluded)

### 4. Inspect zip — required content present
- **Action**: extract to temp dir + `ls`
- **Expect**: at minimum `wb-gamification.php`, `readme.txt`, `uninstall.php`, `vendor/`, `assets/`, `build/`, `templates/`, `languages/`, `integrations/`, `blocks/` (legacy compat)

### 5. PHP lint on the built zip
- **Action**: `find <extracted> -name '*.php' -exec php -l {} \;`
- **Expect**: 0 lines saying anything other than "No syntax errors detected"

### 6. (Optional) Plugin Check on the built zip
- **Action**: `wp plugin check <extracted-path> --severity=error`
- **Expect**: 0 errors. (Pro plugin might emit `plugin_updater_detected` — that's an intentional Pro warning per Part 17.7.5 and is allowed.)

### 7. (Optional) Smoke install on a clean WP
- **Action**: spin up a clean WP container, drop the zip into `wp-content/plugins/`, activate, run `wp wb-gamification setup` if a wizard exists
- **Expect**: activation succeeds, wizard renders, first action awards points to the wizard's test user, first badge unlocks within 60s

## Pass criteria

ALL of the following hold:
1. `bin/build-release.sh` exits 0
2. zip excludes all dev artefacts
3. zip includes all release-required content
4. PHP lint clean across every file in the zip
5. (Optional) Plugin Check on the BUILT zip = 0 errors
6. (Optional) Clean smoke install completes the earning loop in <60s

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `bin/build-release.sh` not found | Release script not committed | `bin/build-release.sh` (this file ships with 1.0.0 — see git log) |
| Zip contains `src/` or `tests/` | rsync exclude list out of sync with the code layout | `bin/build-release.sh` rsync `--exclude` arguments |
| Zip missing `vendor/` | `composer install --no-dev` step skipped | `bin/build-release.sh` first composer call |
| `wp plugin check` flags `compressed_files` | Compressed assets shipped in unrelated dirs | rsync `--exclude='*.map'` + check for `.zip`/`.tar.gz` artefacts |
| `wp plugin check` flags `plugin_updater_detected` on the Free plugin | EDD/Updater code in a Free release zip — should only be in Pro | `includes/class-updater.php` and bootstrap registration |
