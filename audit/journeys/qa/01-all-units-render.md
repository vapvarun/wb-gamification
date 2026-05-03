---
journey: all-units-render
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [block-registration, shortcode-dispatch, render.php-contract, page-builder-compat]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged in as administrator (autologin via ?autologin=1)"
  - "QA pages seeded: wp wb-gamification qa seed_pages"
  - "Plugin activated, DB schema current"
estimated_runtime_minutes: 3
---

# Every block + shortcode renders without fatal

The single regression check that proves page-builder compatibility: every Wbcom Block Quality Standard unit is reachable at a known URL, and both its Gutenberg block and matching `[wb_gam_*]` shortcode render without a PHP fatal. Catches the class of bug we shipped (and fixed) in `[wb_gam_earning_guide]` — a shortcode handler calling a non-existent helper that PHP-fatalled the moment a customer dropped it into a page.

The journey walks `/wb-gamification-qa/` (the index seeded by `wp wb-gamification qa seed_pages`), follows every link, and asserts each unit page returns 200 OK with no fatal HTML markers (`Fatal error`, `Call to undefined`, `does not exist`).

## Setup

- Site: `$SITE_URL`
- Admin login: `?autologin=1` (admin user_id=1)
- Seed once: `wp wb-gamification qa seed_pages` (idempotent — re-run is safe)

## Steps

### 1. Visit the index, capture every unit URL
- **Action**: `GET $SITE_URL/wb-gamification-qa/?autologin=1`
- **Expect**: 200 OK, page title `WB Gamification QA`, body contains 15 `<a href>` entries pointing to per-unit children.
- **Capture**: `UNIT_URLS` ← every `<li><a href="...">` from the body.
- **On fail**:
  - 404 → QA pages not seeded; run `wp wb-gamification qa seed_pages`.
  - < 15 links → `src/CLI/QAPages.php` MAP regression.
  - 500 → check the PHP error log for the failing render, then triage to the engine of the missing block.

### 2. Walk every unit URL — assert no fatal
For each URL in `UNIT_URLS`:
- **Action**: `GET $URL?autologin=1`
- **Expect**:
  - HTTP 200.
  - Body does NOT contain any of: `Fatal error`, `Call to undefined`, `does not exist on this mock object`, `<b>Warning</b>`.
  - Body contains `<h2 class="wp-block-heading">Block —` AND `<h2 class="wp-block-heading">Shortcode —`.
  - Body contains the unit's wrapper class `wb-gam-block-` (per-instance scope from the Wbcom Block Quality Standard) at least once.
- **Capture**: nothing — fail-fast journey.
- **On fail**:
  - Fatal markers → triage to the engine called by that block's `render.php`.
  - Missing wrapper → `wb-gam-tokens` style handle didn't enqueue; check `wb-gamification.php` `enqueue_assets`.
  - Block heading present but shortcode heading missing → `ShortcodeHandler::render_*` handler broken; cross-reference `tests/Unit/Engine/ShortcodeHandlerTest::test_shortcode_dispatches_to_correct_block`.

### 3. Spot-check parity on one unit (visual regression)
- **Action**: navigate to `/wb-gamification-qa/wb-gamification-qa-leaderboard/?autologin=1`, capture full-page screenshot.
- **Expect**: screenshot diff vs `audit/journey-runs/baseline/qa-leaderboard.png` (if baseline exists). Within 1% pixel tolerance.
- **On fail**: visual regression — diff for review, do not auto-fail (theme nuances are out of scope).

## Cleanup

- Optional: `wp wb-gamification qa remove_pages --force` after the run to keep the QA test pages out of the live site index. Recommended against because re-seeding takes 1s and the pages are admin-discoverable but nav-nested under one parent.

## Why this journey is critical

- **Block-side regression detector.** Every block's `render.php` runs once.
- **Shortcode-side regression detector.** Every `[wb_gam_*]` runs once. Catches the earning-guide-fatal class of bug that unit tests miss.
- **Page-builder readiness.** Customers using Elementor / Beaver Builder / Divi reach the same code path as this journey — if the journey passes, the page-builder usage works.
- **Customer support shortcut.** When a ticket says "block X doesn't work," support visits the matching `/wb-gamification-qa/wb-gamification-qa-X/` to repro instantly.
