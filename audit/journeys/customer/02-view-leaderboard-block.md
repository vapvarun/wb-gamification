---
journey: view-leaderboard-block
plugin: wb-gamification
priority: critical
roles: [anonymous, subscriber]
covers: [leaderboard-block, leaderboard-controller, leaderboard-cache]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Plugin activated; at least one user has nonzero points"
  - "A page exists containing the wb-gamification/leaderboard block (the wb_gam_hub page works)"
estimated_runtime_minutes: 3
---

# View leaderboard block (anonymous + logged-in)

Verifies the entire leaderboard surface — block render → REST `/leaderboard` → cache table read or live-query fallback. Every blank-leaderboard support ticket regresses through this exact path.

## Setup

- Site: `$SITE_URL`
- Hub page URL: `$SITE_URL?page_id={wb_gam_hub_page_id}` (read the option from DB or use `wp option get wb_gam_hub_page_id`)
- Capture: `LEADERBOARD_PAGE_URL`

## Steps

### 1. Anonymous fetch of REST endpoint
- **Action**: `curl -s $SITE_URL/wp-json/wb-gamification/v1/leaderboard?period=all_time&limit=10`
- **Expect**:
  - 200 OK
  - JSON shape: `{ "period": "all", "scope": { "type": <str>, "id": <int> }, "rows": [ { "rank": <int>, "user_id": <int>, "display_name": <str>, "avatar_url": <str>, "points": <int> }, ... ] }`
  - `rows.length` ≤ 10
  - `rows[0].rank == 1`
- **Capture**: `TOP_USER_ID` ← `.rows[0].user_id`, `TOP_POINTS` ← `.rows[0].points`
- **On fail**: `src/API/LeaderboardController.php:66` (get_leaderboard) or schema regression in `wb_gam_leaderboard_cache`

### 2. Browser render — anonymous
- **Action**: `playwright_navigate $LEADERBOARD_PAGE_URL` (no cookies)
- **Expect**:
  - DOM contains a leaderboard container element (class includes `wp-block-wb-gamification-leaderboard` or similar — read the actual class from `blocks/leaderboard/render.php`)
  - At least one row visible with the top user's display name from step 1
  - No `console.error` entries

### 3. Logged-in fetch — `/leaderboard/me`
- **Action**: navigate to `$SITE_URL?autologin=test_user`, then `GET /wp-json/wb-gamification/v1/leaderboard/me`
- **Expect**: 200 OK, JSON `{ rank: <int>, points: <int>, user_id: <int> }`. `rank` must equal the position the test user occupies in step 1's results (or be `null` if they're outside the top N).
- **On fail**: `src/API/LeaderboardController.php:105` (`get_my_rank`) — auth detection or rank-window calculation

### 4. Verify cache vs live-query parity
- **Action**: snapshot the cache value, then force a recompute: `wp wb-gamification doctor --recompute-leaderboard` (if the doctor command supports it; otherwise `mysql_query "TRUNCATE wp_wb_gam_leaderboard_cache"` + re-fetch step 1).
- **Expect**: the post-recompute response matches the snapshot exactly (within rank ordering — points may shift by 1 if events landed during the interval).
- **On fail**: `src/Engine/LeaderboardEngine.php:write_snapshot` is producing different results to the live-query fallback path. This is the single highest-impact "leaderboard wrong" bug.

## Pass criteria

ALL of the following hold:
1. Anonymous `/leaderboard` returns 200 with a valid results array.
2. Block renders on the page with at least the top user visible.
3. `/leaderboard/me` returns the right rank for the logged-in test user.
4. Cache and live-query produce equivalent ordering.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `rows: []` but DB has points rows | Cache empty + live-query fallback broken | `src/Engine/LeaderboardEngine.php` |
| Block renders empty `<div></div>` | `render.php` returned empty due to REST call failing in render context | `blocks/leaderboard/render.php` |
| `me` returns 401 on logged-in user | `require_logged_in` rejecting cookie auth | `src/API/LeaderboardController.php:127` |
| Ranks tied but cache says rank=2 for both | Tie-break logic mismatch between cache & live-query | `src/Engine/LeaderboardEngine.php` |
| Console error `apiFetch is not defined` | Block JS expects `wp.apiFetch` but core dep not enqueued | `blocks/leaderboard/block.json` (dependencies) |
