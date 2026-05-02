---
journey: earn-points-via-rest
plugin: wb-gamification
priority: critical
roles: [subscriber, contributor]
covers: [points-engine, event-pipeline, rest-events-controller]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged in as a non-admin test user (autologin via ?autologin=test_user)"
  - "Plugin activated, DB schema current (wb_gam_db_version matches DbUpgrader::TARGET)"
estimated_runtime_minutes: 5
---

# Earn points via the canonical event pipeline

The single most important flow in the plugin: a tracked event arrives, the engine evaluates rules, points get written, and the user's total reflects within one async cycle. If this regresses, every other engine (badges, levels, streaks, leaderboard) silently breaks because they all read off the same ledger.

## Setup

- Site: `$SITE_URL`
- Test user login: `test_user` (subscriber role) via `?autologin=test_user`
- Capture user ID from the login response (`USER_ID`).
- DB clean (optional — repeatable runs add a `wp_post_receives_comment` event each time, increasing the user's total by the action's configured points):
  ```sql
  -- Optional: delete events tagged by this journey's metadata.source
  -- (the `metadata` column is JSON; LIKE on the serialized value is the cheapest filter):
  DELETE FROM wp_wb_gam_events WHERE user_id = $USER_ID AND metadata LIKE '%journey_smoke_test%';
  DELETE FROM wp_wb_gam_points WHERE user_id = $USER_ID AND reason = 'journey_smoke_test';
  ```

## Steps

### 1. Capture starting point total
- **Action**: `GET $SITE_URL/wp-json/wb-gamification/v1/members/$USER_ID/points` (cookie auth from autologin)
- **Expect**: 200 OK, JSON `{ total: <int>, history: [...] }`
- **Capture**: `STARTING_TOTAL` ← `.total`
- **On fail**: `src/API/MembersController.php:88` (get_points handler) or schema mismatch

### 2. Ingest an event for a registered action
- **Action**: `POST $SITE_URL/wp-json/wb-gamification/v1/events` with body
  ```json
  { "action_id": "wp_post_receives_comment", "metadata": { "source": "journey_smoke_test" } }
  ```
- **Expect**: 200 OK, JSON shape `{ "processed": true, "event_id": <uuid>, "action_id": "wp_post_receives_comment", "user_id": <int> }`.
- **Capture**: `EVENT_ID` ← `.event_id`. `EXPECTED_DELTA` ← the action's `default_points` (3 for `wp_post_receives_comment`, see `GET /actions`; site owners may override via the `wb_gam_points_<action_id>` option).
- **On fail**: `src/API/EventsController.php:70` (create_item handler), or unrecognized `action_id`, or `create_item_permissions_check` rejecting the test user.
- **Note**: The endpoint requires `action_id` (a registered action). Points come from the action's configured value, not from the request body — there is no `points` field on `/events`.

### 3. Wait for async processing
- **Action**: poll up to 5× at 1s intervals: `GET /wp-json/wb-gamification/v1/members/$USER_ID/points`
- **Expect**: `.total == STARTING_TOTAL + EXPECTED_DELTA` within 5s
- **On fail**: Action Scheduler queue stuck, or `Engine::handle_async()` errored. Check:
  - WP cron is running (`wp cron event list | grep action_scheduler_run_queue`)
  - `src/Engine/Engine.php:65` (the listener for `wb_gam_process_event_async`)
  - `wb_gam_events` table: was the event recorded?
  - `wp wb-gamification doctor` (the `DoctorCommand` will report queue/schema health)

### 4. Verify event row in DB
- **Action**: `mysql_query "SELECT id, user_id, action_id, object_id FROM wp_wb_gam_events WHERE id = '$EVENT_ID'"`
- **Expect**: exactly 1 row with `user_id = $USER_ID`, `action_id = 'wp_post_receives_comment'`. (The events table has `action_id`, not `action`. Note also: there is no `points` column on `wb_gam_events` — the points layer lives in `wb_gam_points`.)

### 5. Verify points-ledger row
- **Action**: `mysql_query "SELECT id, user_id, points, event_id FROM wp_wb_gam_points WHERE event_id = '$EVENT_ID'"`
- **Expect**: exactly 1 row with `points = EXPECTED_DELTA` and `event_id = $EVENT_ID` (FK back to events).

## Pass criteria

ALL of the following hold:
1. `members/{id}/points` returns 200 in step 1 with a numeric `total`.
2. `POST /events` returns 200 with `processed:true` and `event_id` set.
3. The user's total increases by `EXPECTED_DELTA` within 5s.
4. `wb_gam_events` and `wb_gam_points` both contain the new row, properly linked.
5. The `wb_gamification_points_awarded` action fired (verifiable via a test listener if instrumented; otherwise relax this assertion).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| 401 on `/events` POST | `create_item_permissions_check` rejecting user | `src/API/EventsController.php:222` |
| 200 returned but no event row | Engine never called `record()` | `src/API/EventsController.php:70` (create_item) |
| Event row exists but no points row | Async job not running, or `PointsEngine::award` failed | `src/Engine/Engine.php:65,115` + Action Scheduler logs |
| Points row exists but `total` unchanged | `MembersController::get_points` cache stale or query wrong | `src/API/MembersController.php:88` |
| `total` jumps by more than 7 | Rule engine doubled the award; `wb_gamification_points_for_action` filter mis-firing | `src/Engine/RuleEngine.php`, `src/Engine/PointsEngine.php` |
| All steps pass but `wb_gam_events` user_id is 0 | Cookie auth lost between curl and the engine | autologin mu-plugin not active, or session-from-event missing |
