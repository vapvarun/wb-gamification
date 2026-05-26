---
journey: design-tokens-resolve
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [design-tokens, block-css-architecture, theme-override-compat, dark-mode-readiness]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged in as administrator (autologin via ?autologin=1)"
  - "QA pages seeded: wp wb-gamification qa seed_pages"
estimated_runtime_minutes: 4
---

# Every block CSS variable resolves at render time

Every per-block stylesheet reads from `--wb-gam-*` tokens defined in `src/shared/design-tokens.css`. If the `wb-gam-tokens` style handle fails to enqueue before a block's stylesheet, the `var(--wb-gam-color-text)` references resolve to nothing and the block renders without color, border, or background. This journey is the regression lock: if a block ships a `var(--wb-gam-X)` reference that the token system doesn't define, OR if the dep chain breaks so tokens load AFTER the block CSS, this journey catches it.

The check walks every member-facing block, samples key styled elements, and asserts `getComputedStyle()` returns concrete `rgb(...)` / `rgba(...)` values — not the empty-string fallback that browsers return for unresolved `var(...)` references.

## Setup

- Site: `$SITE_URL`
- Admin login: `?autologin=1`
- Seed once: `wp wb-gamification qa seed_pages`

## Steps

### 1. Confirm wb-gam-tokens handle registered + enqueued
- **Action**: `playwright_navigate $SITE_URL/wb-gamification-qa-leaderboard/?autologin=1` then evaluate:
  ```js
  Array.from(document.styleSheets)
    .map(s => s.href || '<inline>')
    .filter(h => h.includes('design-tokens.css') || h.includes('wb-gam-tokens'))
    .length;
  ```
- **Expect**: ≥ 1 — the design-tokens.css stylesheet is loaded.
- **On fail**: `wb-gam-tokens` style not registered or not enqueued. Check `wb-gamification.php` `enqueue_assets()` for the `wp_register_style( 'wb-gam-tokens', ... )` call.

### 2. Sample computed styles on every block — assert concrete colors
For each block slug in
`[leaderboard, top-members, cohort-rank, earning-guide, badge-showcase, kudos-feed, points-history, challenges, member-points, level-progress, streak, redemption-store, submit-achievement]`:

- **Action**: navigate to `$SITE_URL/wb-gamification-qa-{slug}/?autologin=1`. Then for each selector below that exists on the page:
  ```
  [class*="__name"], [class*="__title"], [class*="__points"],
  [class*="__badge"], [class*="__pill"], [class*="__pip"]
  ```
  read `getComputedStyle(el)` for `color`, `backgroundColor`, `borderColor`.
- **Expect** — every sampled value is either:
  - a concrete `rgb(r, g, b)` / `rgba(r, g, b, a)` / `oklab(...)` / `oklch(...)`, OR
  - exactly `"rgba(0, 0, 0, 0)"` (transparent — valid).
  Never the empty string, never `"initial"`, never `var(...)` (browsers don't return unresolved vars but absent values come back as empty string).
- **On fail**:
  - Empty string returned → a `var(--wb-gam-X)` reference with no fallback hit an undefined token. Grep `src/Blocks/{slug}/style.css` for the unresolved token + add it to `src/shared/design-tokens.css`.
  - `rgb(255, 255, 255)` everywhere on a block that should be themed → `wb-gam-tokens` stylesheet loaded AFTER the block style — dep chain reversed. Check the block's `wp_register_style` call and confirm `'wb-gam-tokens'` is in its deps array (or in Registrar.php's shared-asset injection).

### 3. Force a theme.json accent override — verify cascade
- **Action**: snapshot the current leaderboard rank-1 background color. Then inject a runtime CSS override:
  ```js
  document.documentElement.style.setProperty('--wb-gam-color-accent', 'rgb(255, 0, 0)');
  ```
  re-read `getComputedStyle(rank1Row).backgroundColor`.
- **Expect**: the rank-1 background reflects the new accent (or any element that ultimately reads from `--wb-gam-color-accent`). At minimum, the leaderboard `__points` color goes from purple to red.
- **On fail**: a block has hardcoded the accent hex instead of reading from the token. Grep the affected block's style.css for raw hex matching the original accent (`#5b4cdb`) — replace with `var(--wb-gam-primary)` or `var(--wb-gam-color-accent)`.

### 4. Hardcoded-color scan against block CSS sources
- **Action**: `grep -rEn '#[0-9a-fA-F]{3,6}([^a-zA-Z0-9]|$)' src/Blocks/*/style.css | grep -v 'var(' | grep -v '^[^:]*:[[:space:]]*\*' | grep -v '^[^:]*:[[:space:]]*//'`
- **Expect**: zero hits, OR every hit lives inside a documented exception block (year-recap dark hero gradient, streak heatmap palette in design-tokens.css). The journey runner can allowlist `src/Blocks/year-recap/style.css` since its dark-hero brand colors are intentional.
- **On fail**: a hardcoded hex slipped in. Move the value to `src/shared/design-tokens.css` (give it a semantic token name), then reference it from the block.

## Pass criteria

ALL of the following hold:
1. `design-tokens.css` loads on every block page.
2. Sampled styled elements on every block resolve to concrete color values (no empty strings).
3. A runtime accent override propagates to at least one element on the leaderboard block — proving the cascade is intact.
4. No raw hex values outside the allowlisted exceptions (year-recap brand hero, streak heatmap defined in tokens).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `design-tokens.css` not loaded | Handle not registered or not enqueued | `wb-gamification.php` `enqueue_assets` |
| Empty `color` value on a block element | Block CSS references a token not defined in design-tokens.css | `src/shared/design-tokens.css` + the block's style.css |
| Runtime accent change has no effect | Block hardcodes the accent hex | Grep the block's style.css for `#5b4cdb` |
| Raw hex grep returns hits | Token sweep regression | `src/Blocks/{slug}/style.css` — replace with `var(--wb-gam-*)` |
