---
journey: tier-7-a11y-and-mobile
plugin: wb-gamification
priority: high
roles: [keyboard-user, screen-reader-user, mobile-user]
covers: [a11y, wcag-2.1-aa, focus-management, prefers-reduced-motion]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Lighthouse CLI or Lighthouse extension available for the optional final step"
estimated_runtime_minutes: 12
---

# Tier 7 — Mobile + Accessibility Deep Dive

Keyboard-only users, screen readers, and 390px mobile users must be first-class citizens. WCAG 2.1 AA contrast, ≥40px tap targets, focus indicators, escapable overlays, `prefers-reduced-motion`.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Tools: Playwright (or Chrome devtools), wppqa MCP, optional Lighthouse

## Steps

### 1. wppqa a11y MCP (gate)
- **Action**: `mcp__wp-plugin-qa__wppqa_check_a11y --plugin_path=<plugin>`
- **Expect**: `failed=0`. Warnings allowed but tracked.

### 2. wppqa plugin-dev-rules MCP (gate)
- **Action**: `mcp__wp-plugin-qa__wppqa_check_plugin_dev_rules`
- **Expect**: `failed=0`. (Catches `confirm()`/`alert()`, missing nonce caps, breakpoint proliferation.)

### 3. Confirm modal contract
- **Action**: trigger any DELETE button in admin (e.g. webhooks, levels, badges)
- **Expect**:
  - `[role="dialog"][aria-modal="true"]` rendered
  - Confirm button is auto-focused
  - `Escape` key dismisses → `false`
  - Backdrop click dismisses → `false`
  - `Tab` traps focus inside the dialog
  - On dismiss, focus returns to the triggering button

### 4. Slide-in panels (hub block)
- **Action**: load `/gamification/`, click any stat-card
- **Expect**:
  - panel slides in
  - Esc closes it (per Phase F notification overlay rule)
  - Focus restored to the originating card
  - Backdrop click closes it
  - Close button (X) closes it

### 5. `prefers-reduced-motion: reduce`
- **Action**: enable reduced-motion in OS, reload `/gamification/`
- **Expect**: hub stat-cards show no transform animations; toasts may fade but no slide. Confirm modal also stops animating.

### 6. 390px viewport sweep
- **Action**: per QA page (5 sample blocks), `playwright_resize 390 844` then navigate
- **Expect**: no horizontal scroll; tap targets visibly ≥40px (use Lighthouse target-size audit if available)

### 7. Color contrast (AA)
- **Action**: per QA page, run an axe-core scan or use Lighthouse a11y audit
- **Expect**: no AA-failing color-contrast violations. The known yellow-on-white rank-1 highlight on the leaderboard must be fixed if it lights up the warning.

### 8. Lighthouse a11y score (optional)
- **Action**: Lighthouse run on `/gamification/` and 3 sample QA pages
- **Expect**: a11y score ≥ 95 per page

## Pass criteria

ALL of the following hold:
1. wppqa_check_a11y `failed=0`
2. wppqa_check_plugin_dev_rules `failed=0`
3. Every overlay (confirm, hub panel, level-up, streak) is keyboard-accessible
4. `prefers-reduced-motion` is respected
5. No horizontal scroll on any QA page at 390px
6. (Optional) Lighthouse a11y ≥ 95

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `outline:none` on `:focus` flagged | Removed outline without `:focus-visible` replacement | The CSS file the linter names — convert to `:focus:not(:focus-visible)` + add `:focus-visible { outline: 2px solid var(--wb-gam-color-accent); outline-offset: 2px; }` |
| `confirm()` flagged | Native browser dialog used | `assets/js/admin-rest-utils.js` `confirmAction` — must be the promise-based DOM modal |
| Esc doesn't close overlay | Listener not attached to `keydown` | `src/Engine/NotificationBridge.php` (level-up overlay) + `assets/interactivity/notifications.js` |
| Focus doesn't return after modal close | Saved `previousFocus` lost reference | `assets/js/admin-rest-utils.js` `confirmAction()` `previousFocus.focus()` call |
| Reduced-motion not respected | CSS `transition` declared without `@media (prefers-reduced-motion: reduce)` override | the offending block's `style.css` — copy the pattern from `src/shared/block-card.css` |
