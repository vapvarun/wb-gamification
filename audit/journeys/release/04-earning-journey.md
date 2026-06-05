---
journey: tier-4-earning-journey
plugin: wb-gamification
priority: critical
roles: [member, admin]
covers: [points-engine, manual-award, debit-path, leaderboard-cache]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli available"
  - "At least one user (default: user_id=1)"
estimated_runtime_minutes: 5
---

# Tier 4 — Earning Journey End-to-End

The "customer earns first badge in <60s" promise. If this breaks, nothing else matters. Tier 4 surfaced 2 real regressions during the 1.0.0 verification run — must stay as a perpetual gate.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Test user: `user_id=1` (admin) — substitute any other id when running for a fresh signup
- Fixtures: none — exercises live REST + DB

## Steps

### 1. Capture baseline
- **Action**: `wp eval` snippet:
  ```php
  global $wpdb;
  $baseline = (int) $wpdb->get_var( "SELECT COALESCE(SUM(points),0) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = 1" );
  echo "baseline=$baseline";
  ```
- **Capture**: `BASELINE`

### 2. POST /points/award (positive)
- **Action**: `rest_do_request` POST `/wb-gamification/v1/points/award` body `{ user_id: 1, points: 50, reason: "tier4_test" }`
- **Expect**: HTTP 201, response body `{ awarded: true, debited: false, points: 50, ... }`

### 3. Ledger reflects the award
- **Action**: SUM(points) on `wb_gam_points` for user_id=1
- **Expect**: `BASELINE + 50`

### 4. Event log row exists
- **Action**: COUNT(*) on `wb_gam_events` for user_id=1, action_id='manual_award'
- **Expect**: ≥ 1 row added since baseline

### 5. /members/{id}/points returns updated total
- **Action**: GET `/wb-gamification/v1/members/1/points`
- **Expect**: HTTP 200, `total = BASELINE + 50`

### 6. Negative input → debit path
- **Action**: POST `/points/award` body `{ user_id: 1, points: -50 }`
- **Expect**: HTTP 201, `{ awarded: false, debited: true, points: -50 }`. Ledger now back at `BASELINE`.

### 7. Zero rejected
- **Action**: POST `/points/award` body `{ user_id: 1, points: 0 }`
- **Expect**: HTTP 400 with code `rest_points_zero`

### 8. Leaderboard reflects user (allow caching)
- **Action**: GET `/wb-gamification/v1/leaderboard?period=all&limit=20`
- **Expect**: response includes the user_id under whatever rank is appropriate (this is order-dependent, so we just verify presence in top 20)

## Pass criteria

ALL of the following hold:
1. POST /points/award with positive value → 201 + ledger increment
2. Negative value routes to debit, ledger returns to baseline
3. Zero rejected with 400
4. /members/{id}/points read endpoint reflects the live ledger
5. Event log row created per award

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Negative value adds points | `absint()` on the `points` arg stripped sign | `src/API/PointsController.php` register_routes args — must be signed integer, not `absint` |
| Ledger doesn't update | Engine::process not firing the points side-effect | `src/Engine/Engine.php` + `src/Engine/PointsEngine.php` |
| 500 on POST | Member resolution or rate-limit guard fatal | check `wp-content/debug.log` |
| Leaderboard stale | Object cache holding old snapshot — incrementor pattern not bumped on award | `src/Engine/LeaderboardEngine.php` `wp_cache_set_last_changed` call on `wb_gam_leaderboard` group |

## Step 9 — Core WordPress badges fire on a BuddyPress site (perpetual gate)

Publishing a blog post and commenting on a post are core WordPress events with NO BuddyPress equivalent (`bp_activity_update` / `bp_activity_comment` are activity-stream events, a different class). The triggers `wp_publish_post` and `wp_leave_comment` are therefore always-on (`standalone_only: false`); the default badges `first_post`, `prolific_writer`, `content_creator`, `first_comment`, `engaged_reader` count them. A prior `standalone_only: true` on those triggers left every one of those badges un-earnable on every BuddyPress site.

### 9a. Triggers registered with BuddyPress active
- **Action**: with BuddyPress active, `wp wb-gamification doctor`
- **Expect**: the "Core WordPress Triggers" section reports PASS — `wp_publish_post` and `wp_leave_comment` are registered. A FAIL here means they were re-flagged `standalone_only` and the WP badges are dead.

### 9b. Publish a post → exactly one publish award (no double-award)
- **Action**: on a BuddyPress site, publish a new `post` as a member, then COUNT rows in `wb_gam_points` for that user across `action_id IN ('wp_publish_post','bp_publish_post')`
- **Expect**: exactly ONE new row. `bp_publish_post` is superseded by the canonical `wp_publish_post` (see `integrations/wordpress.php` `supersedes` + `ManifestLoader::flush_buffer()`), so the same publish never awards twice.

### 9c. First post / first comment badges auto-award
- **Action**: as a member with no prior posts on a BuddyPress site, publish their first `post`
- **Expect**: a `first_post` badge row appears in `wb_gam_user_badges`. Likewise a member's first approved comment earns `first_comment`.

## Pass criteria (additions)

6. On a BuddyPress site, `wp_publish_post` and `wp_leave_comment` are registered (doctor "Core WordPress Triggers" = PASS).
7. Publishing one post awards exactly once across `wp_publish_post` + `bp_publish_post` (supersession holds — no double-award).
8. The core WordPress badges (first_post, prolific_writer, content_creator, first_comment, engaged_reader) can be earned on a BuddyPress site.
