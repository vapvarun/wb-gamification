---
journey: activity-stream-cards
plugin: wb-gamification
priority: high
roles: [member, admin]
covers: [activity-card-redesign, activity-action-dedup, legacy-backfill-migration, challenge-converter]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "BuddyPress active with the Activity component"
  - "At least one gamification activity exists (badge_earned / kudos_given)"
estimated_runtime_minutes: 6
---

# BuddyPress activity stream — one card, generic headline, no legacy rows

When a member earns a badge / level / kudos / completes a challenge, the plugin
posts a BuddyPress activity. Three regressions this journey re-locks, all
customer-reported on 1.5.5:

1. **Rainbow / heavy cards.** Each event type used to inject a hardcoded accent
   (gold/blue/red/green) plus a 4px top strip, gradient and icon ring — loud and
   off-brand. The card must now use ONE theme accent (`--wb-gam-color-accent`)
   with a flat surface and a subtle inline-start edge.
2. **Duplicated text.** The BP action headline repeated the card title
   ("X earned the *Active Member* badge" + a card titled "Active Member"). The
   headline must now be a GENERIC verb ("X earned a badge") so it never repeats
   the card.
3. **Mixed legacy rows.** Activities created before the 1.4.0 card redesign were
   stored as bare `<img>`+`<strong>` text. The `Backfiller`, wired into the 1.5.5
   `DbUpgrader` migration, must convert ALL of them (badge, level, kudos,
   challenge) to cards on plugin update — automatically, no CLI.

## Setup

- Site: `$SITE_URL`
- Test user: `admin` (autologin via `?autologin=admin`)
- Activity page: `$SITE_URL/activity/`
- DB table: `wp_bp_activity` (component = `wb_gamification`)

## Steps

### 1. No legacy plain rows remain after migration
- **Action**: `mysql_query "SELECT COUNT(*) AS n FROM wp_bp_activity WHERE component='wb_gamification' AND type IN ('badge_earned','level_changed','kudos_given','challenge_completed') AND content NOT LIKE '%wb-gam-activity-card%'"`
- **Expect**: `n = 0` for any row whose source record still exists. (Orphaned kudos whose `wb_gam_kudos` row was deleted are the only allowed exception — verify by joining `item_id` to `wp_wb_gam_kudos`.)
- **On fail**: `src/BuddyPress/Stream/Backfiller.php` (a `fix_*_rows` converter is missing or skipping) or `src/Engine/DbUpgrader.php::upgrade_to_1_5_5()` (migration not wired / not run).

### 2. Action headlines are generic, not specific
- **Action**: `mysql_query "SELECT action FROM wp_bp_activity WHERE component='wb_gamification' AND type IN ('badge_earned','level_changed','kudos_given','challenge_completed') AND action LIKE '%<strong>%' LIMIT 5"`
- **Expect**: zero rows. A `<strong>`-wrapped specific name in the action means the headline still repeats the card.
- **On fail**: `src/BuddyPress/Stream/ActivityCard.php::action_line()` not used, or `Backfiller::genericize_actions()` did not run.

### 3. Rendered: one card per item, single accent, no duplication
- **Action**: `playwright_navigate $SITE_URL/activity/?autologin=admin`, then evaluate each `.activity-item` that contains a `.wb-gam-activity-card`.
- **Expect**:
  - The card's `--wb-gam-activity-card` left/inline-start border colour equals the theme accent (`getComputedStyle(eyebrow).color` is identical across ALL cards — no per-type colour).
  - `getComputedStyle(card, '::before').height` is `0px` / none (no top strip).
  - The activity header text (generic verb, e.g. "earned a badge", "gave kudos") does NOT contain the card title (e.g. "Active Member").
- **On fail**: `assets/css/frontend.css` `.wb-gam-activity-card` block (variant colours reintroduced / strip restored).

### 4. Icon plate stays light in dark mode
- **Action**: `browser_evaluate "document.documentElement.setAttribute('data-bx-mode','dark')"`, then read the first card's `.wb-gam-activity-card__icon` background.
- **Expect**: background is a fixed light value (white) so badge SVG line-art stays legible; the card surface itself is the dark theme surface. No horizontal scroll at 390px.
- **On fail**: `assets/css/frontend.css` `.wb-gam-activity-card__icon` (a flipping surface token used instead of `white`).

### 5. New activity uses the card + generic headline
- **Action**: award a badge to a fresh user (`wp wb-gamification ...` or trigger the event), then reload `/activity/`.
- **Expect**: the new item renders as a card; its headline is the generic verb; content contains `wb-gam-activity-card__icon`.
- **Capture**: `NEW_ID` ← the new `wp_bp_activity.id`.
- **On fail**: the relevant `src/BuddyPress/Stream/*Stream.php` (action/content wiring).

## Pass criteria

ALL of the following hold:
1. Step 1: no non-orphan legacy plain rows (`content` always contains the card markup).
2. Step 2: no gamification action headline contains `<strong>` (all generic).
3. Step 3: every card shares the one theme accent, no top strip, headline does not repeat the card title.
4. Step 4: icon plate is light in dark mode; no horizontal scroll at 390px.
5. Step 5: a freshly created activity is a card with a generic headline.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Cards show gold/blue/red/green per type | variant colour blocks reintroduced | `assets/css/frontend.css` (`.wb-gam-activity-card--*`) |
| Headline repeats the badge/recipient name | streams not using `action_line()` | `src/BuddyPress/Stream/*Stream.php`, `ActivityCard::action_line()` |
| Some activities still plain text after update | a `fix_*_rows` missing / migration not wired | `src/BuddyPress/Stream/Backfiller.php`, `src/Engine/DbUpgrader.php` |
| Challenge activity stays plain | `fix_challenge_rows()` missing from `run()` | `src/BuddyPress/Stream/Backfiller.php` |
| Badge art invisible on dark card | icon plate uses a flipping token | `assets/css/frontend.css` (`.wb-gam-activity-card__icon`) |
