# WB Gamification — UX Audit

> **Per-template surface check.** Every view × every persona × every viewport × every theme mode.
> Run when a release touches UI, or at least once per minor version.

The goal: catch silent surface regressions (broken spacing, wrong color token, hover/focus/visited state stripped by the theme, dark-mode bleed, mobile overflow) before a customer notices.

The deeper admin-side counterpart is [`plan/UX-ADMIN-AUDIT-2026-05-03.md`](../../plan/UX-ADMIN-AUDIT-2026-05-03.md). This document is the standardized, recurring audit; that one was the v1.0 deep dive.

## Axes

| Axis | Values |
|------|--------|
| **Persona** | Anonymous, Member (subscriber), Editor (granular cap), Admin |
| **Viewport** | Desktop 1440px, Tablet 1024px (spot), Mobile 390px |
| **Theme mode** | OS-Light, OS-Dark (via `emulateMedia({ colorScheme: "dark" })`), site `body.wb-gam-dark` toggle |
| **Browser** | Chromium primary, Firefox + Safari iOS via `manual_required[]` |
| **Theme** | Twenty-Twenty-Five (block theme), Reign (classic), Astra, BuddyX-Pro |

Every row × every applicable axis = one audit cell. Don't re-audit identical cells across releases — audit the ones that changed or the ones flagged in the last regression guard.

---

## Block surfaces (17 blocks)

For each block under `src/Blocks/`, the editor preview, front-end render, dark mode, and mobile cells must all pass.

| Block | Slug | Editor preview | Front-end render | Dark mode | Mobile 390px |
|-------|------|----------------|------------------|-----------|--------------|
| Leaderboard | `wb-gamification/leaderboard` | ☐ | ☐ | ☐ | ☐ |
| Hub | `wb-gamification/hub` | ☐ | ☐ | ☐ | ☐ |
| Member Points | `wb-gamification/member-points` | ☐ | ☐ | ☐ | ☐ |
| Points History | `wb-gamification/points-history` | ☐ | ☐ | ☐ | ☐ |
| Badge Showcase | `wb-gamification/badge-showcase` | ☐ | ☐ | ☐ | ☐ |
| Earning Guide | `wb-gamification/earning-guide` | ☐ | ☐ | ☐ | ☐ |
| Daily Bonus | `wb-gamification/daily-bonus` | ☐ | ☐ | ☐ | ☐ |
| Streak | `wb-gamification/streak` | ☐ | ☐ | ☐ | ☐ |
| Challenges | `wb-gamification/challenges` | ☐ | ☐ | ☐ | ☐ |
| Community Challenges | `wb-gamification/community-challenges` | ☐ | ☐ | ☐ | ☐ |
| Cohort Rank | `wb-gamification/cohort-rank` | ☐ | ☐ | ☐ | ☐ |
| Top Members | `wb-gamification/top-members` | ☐ | ☐ | ☐ | ☐ |
| Kudos Feed | `wb-gamification/kudos-feed` | ☐ | ☐ | ☐ | ☐ |
| Submit Achievement | `wb-gamification/submit-achievement` | ☐ | ☐ | ☐ | ☐ |
| Level Progress | `wb-gamification/level-progress` | ☐ | ☐ | ☐ | ☐ |
| Redemption Store | `wb-gamification/redemption-store` | ☐ | ☐ | ☐ | ☐ |
| Year Recap | `wb-gamification/year-recap` | ☐ | ☐ | ☐ | ☐ |

### Per-block checks

For every block above:

#### Visual contract
- [ ] Renders at 1440px — no horizontal scrollbar
- [ ] At 390px — no horizontal scrollbar, no clipped text, no off-screen buttons
- [ ] Typography hierarchy intact (H1 > H2 > H3 > body)
- [ ] Spacing uses design tokens (`--wb-gam-space-*`) — no hardcoded `px` values
- [ ] Colors use tokens (`--wb-gam-bg`, `--wb-gam-text`, `--wb-gam-accent`, `--wb-gam-warn`) — no hardcoded `#fff` outside print CSS
- [ ] Icons load (no broken `<img>`, no 404 on SVG sprite)
- [ ] Images `loading="lazy"`, `alt` set on content images

#### Interactive states (every `<a>`, `<button>`, form input)
- [ ] **default** — visible, legible, correct color
- [ ] **hover** — discoverable change (color, bg, border, underline)
- [ ] **focus-visible** — clear focus ring; not suppressed by theme override
- [ ] **active** — visual feedback on click
- [ ] **disabled** — clearly distinguishable, cursor `not-allowed`
- [ ] **visited** (links only) — different from default where meaningful

#### Dark mode
- [ ] `body.wb-gam-dark` (or OS dark) → block remains readable
- [ ] No light-mode token bleed (no `#fff` background inside a dark container)
- [ ] Form inputs visible (borders, placeholder text)
- [ ] Focus rings visible against dark bg
- [ ] Badges, level pills, progress bars — all have dark variants

#### Block editor
- [ ] Inspector controls render without PHP/JS errors
- [ ] Preview matches front-end render (no "frontend-only" CSS surprises)
- [ ] Block validates on reload (no "block contains unexpected content" warning)

---

## Admin surfaces (13 pages)

For each plugin admin page (`admin.php?page=wb-gamification[-suffix]`):

- [ ] Page renders without `Notice:` / `Warning:` in debug.log
- [ ] Every tab renders — iterate `.nav-tab` and click each
- [ ] Every settings section has a label, help text, and saves
- [ ] List tables (Badges, Challenges, Submissions, Webhooks, etc.): search, filter, pagination, bulk actions
- [ ] Action buttons on rows: view, edit, delete, custom actions
- [ ] Admin responsive: WP collapses sidebar at 782px — verify plugin pages still usable
- [ ] Screen options / Help tabs (where present)

| Page | Slug |
|------|------|
| Dashboard | `wb-gamification` |
| Analytics | `wb-gamification-analytics` |
| Badges | `wb-gamification-badges` |
| Challenges | `wb-gamification-challenges` |
| Community Challenges | `wb-gamification-community-challenges` |
| Cohort Leagues | `wb-gamification-cohort` |
| Award Points | `wb-gamification-award` |
| API Keys | `wb-gamification-api-keys` |
| Redemption Store | `wb-gamification-redemption-store` |
| Webhooks | `wb-gamification-webhooks` |
| Submissions | `wb-gamification-submissions` |
| Point Types | `wb-gamification-point-types` |
| Point Type Conversions | `wb-gamification-point-type-conversions` |

---

## Email surfaces

For each transactional email registered by `WBGam\Engine\TransactionalEmailEngine`:

- [ ] Rendered HTML opens in Mailpit/Mailhog without layout break
- [ ] Dark mode email client (Gmail dark, Apple Mail dark) — text readable, buttons visible
- [ ] Merge tags resolve (`{user_name}` not literal `{user_name}`)
- [ ] Unsubscribe / manage-preferences link works
- [ ] Plain-text fallback present

Templates live under `templates/emails/`.

| Email | Trigger |
|-------|---------|
| Welcome / first-badge unlocked | first qualifying action |
| Weekly recap | `wb_gam_weekly_email` cron |
| Challenge completed | challenge completion event |
| Kudos received | kudos give |
| Level up | level threshold crossed |
| Year recap | `wb_gam_year_recap` cron |

---

## Accessibility (per surface)

- [ ] Tab order logical
- [ ] Skip links present on heavy-nav templates (Hub)
- [ ] ARIA labels on icon-only buttons (kudos give, share, expand, collapse)
- [ ] Form inputs have `<label>` (or `aria-label` / `aria-labelledby`)
- [ ] Color contrast ≥ 4.5:1 for body text, ≥ 3:1 for large text
- [ ] `prefers-reduced-motion` respected (toast slide-in disables, streak fire-emoji animation pauses)
- [ ] Modals (badge-share, redemption confirm) trap focus + close on ESC

The deeper a11y journey lives at [`audit/journeys/release/07-a11y-and-mobile.md`](../../audit/journeys/release/07-a11y-and-mobile.md). This document tracks the per-block audit; that journey runs the deterministic structural assertions.

---

## Theme matrix (tracked per release)

| Theme | Blocks render | Hub renders | Admin renders | Notes |
|-------|---------------|-------------|---------------|-------|
| Twenty-Twenty-Five (block) | ☐ | ☐ | ☐ | reference theme |
| Reign (classic + BP) | ☐ | ☐ | ☐ | requires `body.wb-gam-dark` to win against Reign customizer |
| Astra | ☐ | ☐ | ☐ | watch for hardcoded h1..h6 colors in Astra customizer |
| BuddyX-Pro | ☐ | ☐ | ☐ | BP-aware; watch profile tab integration |

Walked deterministically by [`audit/journeys/release/08-theme-matrix.md`](../../audit/journeys/release/08-theme-matrix.md).

---

## Dark mode protocol (Playwright MCP)

```javascript
// Chromium
browser_run_code({
  code: `await page.emulateMedia({ colorScheme: "dark" })`
})
browser_take_screenshot({ filename: "dark-<surface>.png" })

// Reset before exiting
browser_run_code({
  code: `await page.emulateMedia({ colorScheme: "light" })`
})
```

Every dark-mode screenshot in this audit is one snapshot to attach to the PR that changed the surface.

---

## Output

If invoked as part of an agent walk, append to `manual_required[]` anything that can only be verified on Firefox or Safari iOS. The Chromium walk covers Chrome + dark mode + viewport matrix.

If invoked as a human audit, treat each unchecked row as a blocking issue, file a Basecamp card (project `47162271`, column `9860020654`), and halt the release.

## Regression guard promotion

After two clean release cycles where a UX row passes without touching it, the row is stable and can be moved to the automated structural assertion in `AGENT_SMOKE_RUNBOOK.md`. The rest stay here as slower, human-verified surface checks.
