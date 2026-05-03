---
journey: tier-3-frontend-15-blocks
plugin: wb-gamification
priority: critical
roles: [visitor, member]
covers: [block-rendering, mobile-responsive, theme-conflict-baseline]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "QA pages seeded via `wp wb-gamification qa seed_pages`"
estimated_runtime_minutes: 15
---

# Tier 3 — Frontend Surface (15 blocks × 1280 + 390)

Every QA page must serve a 200, render the block (no PHP fatals, no JS errors), produce no horizontal scrolling on mobile, and pass hover/focus/visited checks on every `<a>` (theme-conflict gate). Block markup must include `wp-block-wb-gamification-<slug>` so the auto-card surface lands.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Visitor user: anonymous (no autologin)
- Fixtures: 15 seeded QA pages (`wb-gamification-qa-<slug>`)

## Steps

### 1. HTTP + fatal sweep at desktop
- **Action**: per slug, `curl -sk -L -o /dev/null -w "%{http_code}" http://wb-gamification.local/wb-gamification-qa/wb-gamification-qa-<slug>/`
- **Expect**: HTTP 200 for all 15
- **Action**: `curl -sk` body, grep -cE "Fatal error|Parse error|Uncaught"
- **Expect**: 0 matches per page
- **Action**: grep -cE "wp-block-wb-gamification-<slug>"
- **Expect**: ≥ 1 match per page (block rendered at least once)

### 2. Mobile @ 390px
- **Action**: `playwright_resize 390 844` then `playwright_navigate <qa_page_url>`
- **Action**: `playwright_evaluate`:
  ```js
  return {
    horizontalScroll: document.documentElement.scrollWidth > window.innerWidth,
    blockOverflow: !![...document.querySelectorAll('[class*="wp-block-wb-gamification-"]')]
      .find(b => b.scrollWidth > window.innerWidth)
  };
  ```
- **Expect**: `horizontalScroll: false`, `blockOverflow: false`

### 3. `<a>` hover/focus/visited contract (theme-conflict gate)
For one representative QA page (`wb-gamification-qa-leaderboard`):
- **Action**: locate every `<a>` inside the block; programmatically read `getComputedStyle(:link)`, `:hover`, `:focus`, `:visited`
- **Expect**: theme link colors do not override block link colors (anchors styled like buttons keep their explicit color across all four states)

### 4. Empty state
For one block that handles zero data well (`leaderboard`):
- **Action**: navigate as a brand new user (no point ledger entries)
- **Expect**: friendly empty state copy, no broken layout

### 5. Console errors
- **Action**: `playwright_console_messages level=error`
- **Expect**: 0 unhandled errors per page

## Pass criteria

ALL of the following hold:
1. 15/15 pages return 200, 0 fatals, ≥1 block markup hit
2. 15/15 pages have no horizontal scroll at 390px
3. Sample anchor doesn't get its color hijacked by theme link styles
4. Sample empty state renders cleanly
5. 0 JS console errors

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| 500 on a specific page | render.php fatal — bad template var, missing engine class | `src/Blocks/<slug>/render.php` |
| Horizontal scroll at 390px | A descendant exceeds viewport — usually a flex item without `flex-wrap` | The block's `style.css` — add `flex-wrap: wrap` + the standard 640/1024 media queries |
| Theme link color leaks | Anchor inside the block lacks explicit color — falls through to theme | `src/Blocks/<slug>/style.css` — explicit `color` on every `a` variant + `:hover`, `:focus`, `:visited` |
| "wp-block-wb-gamification-X" missing from HTML | Block didn't render or got the wrong wrapper | `Registrar::register_blocks()` + `src/Blocks/<slug>/render.php` `get_block_wrapper_attributes()` call |
