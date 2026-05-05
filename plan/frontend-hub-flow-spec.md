# Frontend Hub & Connected Flow — Design Spec

> Designed 2026-04-12. Addresses the core UX gap: 11 blocks exist but are disconnected.
> Members need a single hub page with guided navigation and "what to do next" intelligence.

---

## Problem

The plugin ships 11 Gutenberg blocks and 11 shortcodes, but they are standalone widgets with no navigation between them. A member who sees the leaderboard has no path to discover challenges. A member who earns a badge has no way to find the earning guide. The blocks are powerful individually but dead as a product — there's no journey.

## Solution

A **Gamification Hub** — a single auto-created WordPress page that assembles the member's gamification experience into a connected, card-based dashboard with slide-in detail panels. It reuses all existing block renderers (zero duplication) and adds a smart nudge bar that tells members what to do next.

---

## Architecture

### New Components

| Component | Type | File |
|-----------|------|------|
| Hub Block | Gutenberg block | `blocks/hub/block.json` + `blocks/hub/render.php` |
| Hub Shortcode | Shortcode | `[wb_gam_hub]` via `ShortcodeHandler.php` |
| Hub CSS | Stylesheet | `assets/css/hub.css` |
| Hub JS | Interactivity API | `assets/interactivity/hub.js` |
| Nudge Engine | PHP class | `src/Engine/NudgeEngine.php` |
| Page Creator | Installer hook | Modify `src/Engine/Installer.php` |

### No New Components (Reused)

All 11 existing block `render.php` files are loaded inside the slide-in panel as-is. No dedicated panel renderers. When blocks improve, the hub improves automatically.

---

## Hub Page Structure

### 1. Smart Nudge Bar

A single contextual bar at the top. One message, one CTA. Changes based on user state.

**Nudge priority logic** (first matching rule wins):

| Priority | Condition | Message | CTA |
|----------|-----------|---------|-----|
| 1 | Unclaimed challenge reward | "You completed [challenge]! Claim your +[X] bonus points" | Opens challenges panel |
| 2 | Close to level-up (within 20%) | "You're [N] points from Level [X] — [suggestion to earn]" | Opens earning guide or challenge |
| 3 | Streak at risk (no activity today, streak > 3) | "Don't break your [N]-day streak! Do any activity to keep it" | Opens earning guide |
| 4 | New badges earned (unseen) | "You earned [N] new badge(s)! Check them out" | Opens badges panel |
| 5 | Active challenge with progress > 50% | "[Challenge] is [X]% done — [N] more to complete it" | Opens challenges panel |
| 6 | No challenges joined | "Try a challenge to earn bonus points" | Opens challenges panel |
| 7 | Fallback | "Keep going! You've earned [X] points this week" | Opens earning guide |

**Implementation:** `NudgeEngine::get_nudge( int $user_id ): array` returns `[ 'message' => string, 'panel' => string, 'icon' => string ]`. Called once per page load, result cached in user transient for 5 minutes.

**Markup:**
```html
<div class="gam-nudge">
  <div class="gam-nudge__icon"><i class="lucide-zap"></i></div>
  <div class="gam-nudge__body">
    <div class="gam-nudge__label">Your next move</div>
    <div class="gam-nudge__text">{message}</div>
  </div>
  <button class="gam-nudge__action" data-panel="{panel}">
    Go <i class="lucide-arrow-right"></i>
  </button>
</div>
```

### 2. Stats Row

Four stat cards showing the member's key numbers at a glance:

| Stat | Source | Sub-detail |
|------|--------|------------|
| Total Points | `wb_gam_get_user_points()` | — |
| Current Level | `wb_gam_get_user_level()` | Progress bar + "X / Y to Level N" |
| Badges Earned | Count from `wb_gam_user_badges` | — |
| Day Streak | `wb_gam_get_user_streak()` | "Best: N days" |

### 3. Card Grid

Six cards in a 3-column grid (1-column on mobile). Each card is a summary that opens a slide-in panel on click.

| Card | Icon (Lucide) | Shows | Panel Content |
|------|--------------|-------|---------------|
| My Badges | `award` | Earned count · locked count. Pill: "N new" if unseen | `badge-showcase` block (with `show_locked=1`) |
| Challenges | `target` | Active challenge name + progress bar. Pill: "N active" | `challenges` block |
| Leaderboard | `trophy` | User's rank this week. Pill: rank number | `leaderboard` block |
| How to Earn | `lightbulb` | Count of enabled actions | `earning-guide` block |
| Kudos | `heart-handshake` | Received · given counts. Pill: "N received" | `kudos-feed` block |
| Activity | `history` | Label: "Points history & streaks" | `points-history` block |

**Card data:** Each card's summary data comes from a single PHP function call. No extra queries — reuse existing engine methods.

### 4. Slide-in Panel

Right-side panel (520px max, 100vw on mobile) that loads existing block render output.

**Behavior:**
- Opens on card click or nudge CTA click
- Closes on: X button, backdrop click, ESC key
- Panel header: back button + title
- Panel body: output of `render_block()` for the corresponding block
- `body` overflow hidden while panel is open
- CSS animation: `translateX(100%)` → `translateX(0)` with cubic-bezier easing

**Accessibility:**
- `role="dialog"` + `aria-modal="true"` on panel
- `aria-label` on close button
- Focus trapped inside panel while open
- ESC key closes panel
- `aria-live` not needed (user-initiated action)

**Implementation:** Uses WordPress Interactivity API `data-wp-on--click` directives. Panel body renders the block server-side inside a `<template>` tag per card, then clones into panel on click (no AJAX round-trip).

**Performance note:** All 6 block outputs render server-side on initial page load (hidden in `<template>` tags). This is acceptable because: (1) these blocks already use object cache for expensive queries, (2) leaderboard uses snapshot cache, (3) total additional queries is ~6 cached reads. If profiling shows this is too heavy, the fallback is AJAX-loading panel content on first click — but start with the simpler pre-rendered approach.

---

## Color System

Theme-independent. All values via CSS custom properties with `--gam-` prefix.

```css
/* Neutral */
--gam-text: #1a1a2e;
--gam-text-secondary: #555770;
--gam-text-muted: #8b8da3;
--gam-surface: #ffffff;
--gam-surface-hover: #f7f7fb;
--gam-border: #e2e3ed;

/* Accent — single hue, 3 weights */
--gam-accent: #5b4cdb;
--gam-accent-light: #ededfc;
--gam-accent-text: #4338b2;

/* Semantic — status only */
--gam-success: #0d9f6e;   --gam-success-bg: #ecfdf3;
--gam-warning: #c2770e;   --gam-warning-bg: #fef7ec;
--gam-info: #2563eb;      --gam-info-bg: #eff4ff;

/* Rank metals — muted */
--gam-gold: #b8860b;
--gam-silver: #6b7280;
--gam-bronze: #a0522d;
```

**Rationale:** These colors are chosen to not clash with any WordPress theme. The neutral gray surface sits on top of any background. The single accent hue avoids "carnival" effect. Semantic colors are used only for status pills, not decoration.

**Icon system:** Lucide icons via CSS font (`lucide-static`). Enqueued from CDN in dev, bundled locally for production. Icon names map 1:1 to Lucide's library.

---

## Page Auto-Creation

On plugin activation (in `Installer.php`):

1. Check if a page with meta `_wb_gam_hub_page` exists
2. If not, create: `wp_insert_post([ 'post_title' => 'Gamification', 'post_content' => '<!-- wp:wb-gamification/hub /-->', 'post_status' => 'publish', 'post_type' => 'page' ])`
3. Store page ID in option `wb_gam_hub_page_id`
4. Set page meta `_wb_gam_hub_page = 1`

**Admin can:** rename, move, delete, or replace with a shortcode version. The option is used for internal linking (nudge bar CTAs, toast notification links).

**Deactivation:** Page is NOT deleted. It becomes a normal page with a non-rendering block.

---

## Responsive Breakpoints

| Viewport | Stats | Cards | Panel | Nudge |
|----------|-------|-------|-------|-------|
| > 640px | 4 columns | 3 columns | 520px slide-in | Horizontal row |
| 391-640px | 2 columns | 1 column | 90vw slide-in | Stacked |
| ≤ 390px | 2 columns | 1 column | 100vw full-screen | Stacked, CTA full-width |

---

## Integration Points

The hub page is the **core** surface. Integrations create entry points to it:

| Integration | What it does |
|-------------|-------------|
| BuddyPress | Adds "Gamification" tab on member profile → links to hub page (or renders inline) |
| Toast notifications | "View details" link in toast → opens hub page with panel pre-opened via `?panel=badges` URL param |
| WooCommerce | "Your Points" link in My Account → hub page |
| LearnDash | "Your Progress" widget → hub page |
| Activity stream | Badge/level-up activity entries → link to hub page |

---

## Shortcode

`[wb_gam_hub]` — renders the full hub. No attributes needed (stats/cards always show current user). Available for admins who prefer shortcode placement over the auto-created page.

---

## What This Does NOT Include

- No admin-facing dashboard changes (admin already has AnalyticsDashboard)
- No new REST endpoints (panel content is server-rendered via `render_block()`)
- No new database tables or columns
- No real-time updates (toast polling handles that separately)
- No user settings/preferences panel (out of scope, future feature)

---

## Verification Plan

1. **Activate plugin on fresh install** → "Gamification" page auto-created
2. **Visit hub as logged-in member** → nudge bar shows, stats populated, 6 cards visible
3. **Click each card** → panel slides in with correct block content
4. **ESC / backdrop / X button** → panel closes
5. **Test at 390px viewport** → single column cards, full-screen panel
6. **Test as guest** → appropriate empty/login state
7. **Test with zero data** → nudge shows fallback, stats show 0s, cards show empty states
8. **Toast notification** → "View" link opens hub with `?panel=badges`
9. **BuddyPress tab** → links to hub page
10. **Deactivate + reactivate** → page not duplicated (meta check)

---

## Prototype

Working interactive prototype at:
`.superpowers/brainstorm/98241-1776001804/content/flow-architecture-v2.html`

Open in any browser to test card clicks, panel slides, responsive layout, and the full color system.
