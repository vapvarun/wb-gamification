---
journey: tier-8-theme-matrix
plugin: wb-gamification
priority: high
roles: [visitor]
covers: [theme-conflict, link-color-leak]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli available"
  - "Themes installed: BuddyX (current), Twenty Twenty-Five (default), Astra (recommended)"
estimated_runtime_minutes: 10
---

# Tier 8 — Theme Conflict Matrix

Every Wbcom support ticket starts with "but it works on my dev site". The plugin must render correctly under the 3 most common themes our customers pick. The most common breakage class is theme link color overrides — anchors styled like buttons turning blue/pink/purple under different themes.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Themes to test: `buddyx` (current), `twentytwentyfive` (WP default), `astra` (commercial — install if missing)
- Sample blocks: `leaderboard`, `hub`, `level-progress`, `redemption-store`

## Steps

For each theme:

### 1. Activate
- **Action**: `wp theme activate <theme-slug>`
- **Expect**: `Success: Switched to '...' theme.`

### 2. Frontend QA pages render
- **Action**: per sample block, `curl -sk -L http://wb-gamification.local/wb-gamification-qa/wb-gamification-qa-<slug>/`
- **Expect**: HTTP 200, body contains `wp-block-wb-gamification-<slug>`, no PHP fatal markers

### 3. Anchor colors don't get hijacked
- **Action**: navigate to QA page → `playwright_evaluate`:
  ```js
  const a = document.querySelector('.wp-block-wb-gamification-leaderboard a');
  if (!a) return { skip: true };
  const cs = getComputedStyle(a);
  const hover = getComputedStyle(a, ':hover');
  const visited = getComputedStyle(a, ':visited');
  const focus = getComputedStyle(a, ':focus');
  return { color: cs.color, hover: hover.color, visited: visited.color, focus: focus.color };
  ```
- **Expect**: color stays close to `var(--wb-gam-color-accent)` resolution (#5b4cdb-ish). Theme link tints not bleeding through.

### 4. Editor renders the block (Gutenberg theme.json compatibility)
- **Action**: edit any QA page in the block editor
- **Expect**: 0 "Your site doesn't include support" messages, block visual matches the live frontend

### 5. Re-activate the original theme at the end
- **Action**: `wp theme activate <original>` (from the captured starting theme name)
- **Expect**: `Success: Switched...`

## Pass criteria

ALL of the following hold:
1. 3/3 themes serve all sample QA pages with HTTP 200, 0 fatals
2. Anchor colors do not regress to theme defaults under any theme
3. Editor canvas works under all 3 themes' theme.json
4. Original theme restored at journey end

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Block link color changes under theme X | The anchor inherits `color` from the theme's link styles | The block's `style.css` — explicit `color` on every anchor variant + `:hover`, `:focus`, `:visited` |
| Block invisible/zero-height under theme X | Theme's `.wp-block` reset clobbers our `.wb-gam-card` baseline | `src/shared/block-card.css` — increase specificity, scope rules to `.wp-block-wb-gamification-<slug>` |
| 500 only under one theme | Theme calls a function that triggers a PHP fatal in our render path | check `wp-content/debug.log` while reproducing |
| Editor "no support" only under theme X | Theme's editor stylesheet missing or interfering | Twenty Twenty-Five compatibility check — `block.json` `editorStyle` declaration |
