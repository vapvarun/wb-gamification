# WB Gamification v1.0.0 — Pre-Release Checklist

## Before Release

- [ ] All tests pass: `composer run test:unit`
- [ ] WPCS clean: `mcp__wpcs__wpcs_check_directory`
- [ ] PHPStan: no new errors
- [ ] .pot file generated: `grunt pot` or `wp i18n make-pot`
- [ ] .min.css and .min.js files up to date: `grunt build`
- [ ] readme.txt stable tag = 1.0.0
- [ ] All version references = 1.0.0
- [ ] CLAUDE.md recent changes table updated
- [ ] Hub page renders correctly (Playwright verify)
- [ ] All 12 blocks render without PHP errors
- [ ] Toast notifications work
- [ ] Setup wizard works on fresh install
- [ ] WP-CLI `wp wb-gamification doctor` passes

## Build Steps

1. `cd /Users/varundubey/Local Sites/wb-gamification/app/public/wp-content/plugins/wb-gamification`
2. `npm install` (if not done)
3. `grunt build` (minify + pot + rtl)
4. `grunt dist` (create zip)
5. Test zip install on clean WordPress

## Release

- [ ] Git tag: `git tag v1.0.0`
- [ ] Push tag: `git push origin v1.0.0`
- [ ] Upload zip to wbcomdesigns.com EDD product
- [ ] Update documentation site
