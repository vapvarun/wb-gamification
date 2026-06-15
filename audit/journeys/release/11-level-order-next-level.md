---
journey: level-order-next-level
plugin: wb-gamification
priority: high
roles: [member]
covers: [level-engine, next-level, nudge-widget, sort-order]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli or mysql access"
  - "Default level seed present (Newcomer/Member/Contributor/Regular/Champion)"
estimated_runtime_minutes: 4
---

# Tier 11 — Level ordering drives the next level (not min_points)

Basecamp 9995220498. Levels have two ordering signals: `sort_order` (the admin's
intended hierarchy) and `min_points` (the points gate). They usually agree, but an
admin can edit a threshold so a numerically-lower level ends up with a HIGHER
`min_points` than the level above it. When that happens the "next level" shown in
the dashboard nudge / level-progress card must still follow `sort_order` — otherwise
a Contributor gets told they are "15 points from Member", a level that actually sits
below them in the ladder.

`LevelEngine::get_all_levels()` must order by `sort_order ASC` (not `min_points ASC`),
`get_level_for_points()` must not break early, and `get_next_level()` must walk the
ladder by `sort_order`, not by the first threshold above the user's total.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Test user: any member whose total puts them mid-ladder (default install: `user_id=1`, ~932 pts → Contributor)
- Fixtures: default level seed. Note Member's real `min_points` (100) so it can be restored.

## Steps

### 1. Scramble Member's threshold above the user's total but below Regular
- **Action**: `mysql_write`:
  ```sql
  UPDATE wp_wb_gam_levels SET min_points = 1000 WHERE id = 2; -- Member, sort_order stays 2
  ```
  (Pick a value > the test user's total and < Regular's 1500 so the user's current
  level stays Contributor while a naive min_points walk would mis-name "next".)

### 2. Load the Hub and read the level card
- **Action**: `playwright_navigate $SITE_URL/gamification/` (autologin), read the
  level-progress card text and any `Next:` label.
- **Expect**: `Current Level: Contributor`, `Next: Regular`.
- **On fail (shows `Next: Member`)**: `src/Engine/LevelEngine.php` — `get_all_levels()`
  is still `ORDER BY min_points`, or `get_next_level()` still compares thresholds
  instead of walking `sort_order`.

### 3. Restore the seed
- **Action**: `mysql_write`:
  ```sql
  UPDATE wp_wb_gam_levels SET min_points = 100 WHERE id = 2;
  ```

## Pass criteria

ALL of the following hold:
1. With Member scrambled to 1000, the level card reads `Next: Regular` (sort_order successor), never `Next: Member`.
2. The current level still resolves to the highest threshold actually reached (`Contributor`).
3. `LevelEngineTest::next_level_respects_sort_order_when_thresholds_cross` is green.
4. The healthy default seed (sort_order monotonic with min_points) is unchanged: `Next: Regular` for a Contributor either way.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `Next: Member` after scramble | `get_next_level` walks min_points, not sort_order | `src/Engine/LevelEngine.php` (`get_next_level_for_points`) |
| Current level wrong at high totals | `get_level_for_points` still breaks early | `src/Engine/LevelEngine.php` (`get_level_for_points`) |
| Edits to levels don't take effect immediately | Write path didn't call `LevelEngine::invalidate_cache()` | `src/API/LevelsController.php` (`create_item` / `update_item` / `delete_item`) |
