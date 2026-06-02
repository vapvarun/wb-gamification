---
journey: jetonomy-leaderboard-defer
plugin: wb-gamification
priority: high
roles: [member, admin]
covers: [jetonomy-display-defer, leaderboard-suppression, reputation-mirror, hub-card-gating]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wb-gamification active"
  - "Jetonomy active (JETONOMY_VERSION defined) so the defer is on by default"
  - "A Hub page is mapped (wb_gam_hub_page_id set) and reachable at /gamification/"
estimated_runtime_minutes: 4
---

# Jetonomy leaderboard deferral - wb-gam hides its duplicate ranking, keeps badges

On a Jetonomy site the reputation leaderboard (`/community/leaderboard/`, ranked
`ORDER BY reputation DESC`) is the single ranking. `JetonomyIntegration` mirrors
every Jetonomy reputation delta 1:1 into the wb-gam points ledger (the
`jetonomy_reputation_changed` action), so wb-gam's own leaderboard becomes a
genuine DUPLICATE - same members, same order. `DisplayDefer` therefore
suppresses the wb-gam leaderboard + top-members blocks/shortcodes and drops the
Hub's "Leaderboard" card, leaving Jetonomy's leaderboard as the one source of
truth. Badges are deliberately KEPT (wb-gam's badge engine is the stronger,
broader system and the badge SETS are complementary, not duplicates), so "My
Badges" must still render. If this regresses, customers see two competing
leaderboards showing identical rankings, or an empty Leaderboard card tile that
opens a blank panel.

The defer is default-on when Jetonomy is active and is controlled by the
`wb_gam_defer_leaderboard_to_jetonomy` filter; a site can force the wb-gam
leaderboard back by returning false.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Test user: `user_id=1` (admin) via `?autologin=1`
- Jetonomy must be active: `wp eval 'echo defined("JETONOMY_VERSION") ? "ok" : "MISSING";'` prints `ok`
- Hub page mapped: `wp eval 'echo (int) get_option("wb_gam_hub_page_id");'` is non-zero
- No DB cleanup - this is a display-state journey, not a ledger journey

## Steps

### 1. Confirm the defer is active by default with Jetonomy on
- **Action**: `wp eval 'echo WBGam\Integrations\Jetonomy\DisplayDefer::defers_leaderboard() ? "defers" : "NO";'`
- **Expect**: prints `defers`.
- **On fail**: `DisplayDefer::defers_leaderboard()` is not reading `JETONOMY_VERSION`, or the `wb_gam_defer_leaderboard_to_jetonomy` filter default flipped. See `src/Integrations/Jetonomy/DisplayDefer.php`.

### 2. Hub page renders "My Badges" but NOT "Leaderboard"
- **Action**: `playwright_navigate http://wb-gamification.local/gamification/?autologin=1`
- **Wait**: ~2s for the hub block to render
- **Action**: `playwright_evaluate`:
  ```js
  const titles = [...document.querySelectorAll('.gam-card__title')].map(n => n.textContent.trim());
  return {
    hasBadges: titles.includes('My Badges'),
    hasLeaderboard: titles.includes('Leaderboard'),
    titles
  };
  ```
- **Expect**: `hasBadges: true`, `hasLeaderboard: false`. There must be NO `.gam-card` whose `.gam-card__title` reads "Leaderboard".
- **On fail**: `src/Blocks/hub/render.php` did not `unset( $wb_gam_cards['leaderboard'] )` (line ~274), or the built artefact `build/Blocks/hub/render.php` is stale. Rebuild and re-verify both source and build.

### 3. The wb-gam leaderboard SHORTCODE output is empty
- **Action**:
  ```bash
  wp eval 'echo "[" . trim( do_shortcode("[wb_gam_leaderboard]") ) . "]";'
  ```
- **Expect**: prints `[]` - the `do_shortcode_tag` filter blanks the output. Same for `[wb_gam_top_members]`.
- **On fail**: `DisplayDefer::maybe_suppress_shortcode()` is not hooked on `do_shortcode_tag`, or `SHORTCODES` no longer lists `wb_gam_leaderboard` / `wb_gam_top_members`. See `src/Integrations/Jetonomy/DisplayDefer.php`.

### 4. The wb-gam leaderboard BLOCK output is empty
- **Action**:
  ```bash
  wp eval 'echo "[" . trim( do_blocks("<!-- wp:wb-gamification/leaderboard /-->") ) . "]";'
  ```
- **Expect**: prints `[]` - the `render_block` filter blanks the block. Same for `wb-gamification/top-members`.
- **On fail**: `DisplayDefer::maybe_suppress_block()` not hooked on `render_block`, or `BLOCKS` no longer lists the two block names. Same file.

### 5. Jetonomy's own leaderboard still renders
- **Action**: `curl -sk -L -o /dev/null -w "%{http_code}" http://wb-gamification.local/community/leaderboard/`
- **Expect**: HTTP 200, and the body still shows Jetonomy's ranked members (the defer must only touch wb-gam surfaces, never Jetonomy's).
- **On fail**: the suppression filters are matching too broadly (e.g. blanking by content instead of by block name / shortcode tag). Confirm `maybe_suppress_block` keys on `$block['blockName']` and `maybe_suppress_shortcode` keys on `$tag`.

### 6. Reputation is mirrored 1:1 into points (why the wb-gam leaderboard is a duplicate)
- **Action**: confirm the mirror hook is wired:
  ```bash
  wp eval 'echo has_action("jetonomy_reputation_changed", ["WBGam\\Integrations\\Jetonomy\\JetonomyIntegration","on_reputation_changed"]) ? "wired" : "MISSING";'
  ```
- **Expect**: prints a priority (e.g. `20`), not `MISSING`. This is the reason a separate wb-gam leaderboard would just restate Jetonomy's ranking.
- **On fail**: `src/Integrations/Jetonomy/JetonomyIntegration.php` no longer adds the `jetonomy_reputation_changed` action; the deferral premise (duplicate ranking) is broken.

### 7. Override filter brings the wb-gam leaderboard back
- **Action**:
  ```bash
  wp eval '
    add_filter("wb_gam_defer_leaderboard_to_jetonomy", "__return_false");
    echo "defers=" . ( WBGam\Integrations\Jetonomy\DisplayDefer::defers_leaderboard() ? "yes" : "no" ) . "\n";
  '
  ```
- **Expect**: prints `defers=no`. With the filter false, `defers_leaderboard()` returns false, so the Hub card is NOT unset, the `render_block` / `do_shortcode_tag` filters are NOT wired (they only attach in `init()` when deferring), and the wb-gam leaderboard renders again.
- **On fail**: `defers_leaderboard()` ignores the filter or hardcodes `JETONOMY_VERSION` without `apply_filters`. See `DisplayDefer::defers_leaderboard()`.

## Pass criteria

ALL of the following hold:

1. With Jetonomy active, `defers_leaderboard()` returns true by default.
2. The Hub page renders a "My Badges" card and renders NO "Leaderboard" card.
3. `[wb_gam_leaderboard]` and `[wb_gam_top_members]` shortcodes output empty strings.
4. `wb-gamification/leaderboard` and `wb-gamification/top-members` blocks render empty.
5. Jetonomy's `/community/leaderboard/` still serves 200 with its own ranking intact.
6. The `jetonomy_reputation_changed` -> `on_reputation_changed` mirror hook is wired.
7. Setting `wb_gam_defer_leaderboard_to_jetonomy` to false makes `defers_leaderboard()` return false.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Hub still shows a "Leaderboard" card | `unset( $wb_gam_cards['leaderboard'] )` removed, or build artefact stale | `src/Blocks/hub/render.php:~274` + `build/Blocks/hub/render.php` |
| Shortcode still outputs a leaderboard | `do_shortcode_tag` filter not wired, or `SHORTCODES` list changed | `src/Integrations/Jetonomy/DisplayDefer.php` `maybe_suppress_shortcode()` |
| Block still outputs a leaderboard | `render_block` filter not wired, or `BLOCKS` list changed | `src/Integrations/Jetonomy/DisplayDefer.php` `maybe_suppress_block()` |
| My Badges card also disappears | suppression matching too broad (blanking badges blocks/shortcodes) | `DisplayDefer` `BLOCKS` / `SHORTCODES` must list ONLY leaderboard + top-members |
| Jetonomy's own leaderboard goes blank | filter keys on content, not on block name / shortcode tag | `DisplayDefer::maybe_suppress_block` / `maybe_suppress_shortcode` |
| Override filter has no effect | `defers_leaderboard()` not wrapped in `apply_filters` | `DisplayDefer::defers_leaderboard()` |
| Defer never engages even with Jetonomy on | `DisplayDefer::init()` not hooked at `plugins_loaded` | `wb-gamification.php` `add_action( 'plugins_loaded', [JetonomyDisplayDefer::class,'init'], SLOT_INTEGRATIONS )` |
