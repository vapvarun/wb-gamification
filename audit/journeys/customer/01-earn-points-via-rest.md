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
- DB clean (optional, ensures repeatable starting state):
  ```sql
  DELETE FROM wp_wb_gam_events WHERE user_id = $USER_ID AND action = 'journey_smoke_test';
  DELETE FROM wp_wb_gam_points WHERE user_id = $USER_ID AND reason = 'journey_smoke_test';
  ```

## Steps

### 1. Capture starting point total
- **Action**: `GET $SITE_URL/wp-json/wb-gamification/v1/members/$USER_ID/points` (cookie auth from autologin)
- **Expect**: 200 OK, JSON `{ total: <int>, history: [...] }`
- **Capture**: `STARTING_TOTAL` ← `.total`
- **On fail**: `src/API/MembersController.php:88` (get_points handler) or schema mismatch

### 2. Ingest a synthetic event
- **Action**: `POST $SITE_URL/wp-json/wb-gamification/v1/events` with body
  ```json
  { "action": "journey_smoke_test", "points": 7, "metadata": { "source": "journey" } }
  ```
- **Expect**: 200/201 OK, JSON contains `event_id` (UUID).
- **Capture**: `EVENT_ID` ← `.event_id`
- **On fail**: `src/API/EventsController.php:70` (create_item handler) or `create_item_permissions_check` rejecting the test user

### 3. Wait for async processing
- **Action**: poll up to 5× at 1s intervals: `GET /wp-json/wb-gamification/v1/members/$USER_ID/points`
- **Expect**: `.total == STARTING_TOTAL + 7` within 5s
- **On fail**: Action Scheduler queue stuck, or `Engine::handle_async()` errored. Check:
  - WP cron is running (`wp cron event list | grep action_scheduler_run_queue`)
  - `src/Engine/Engine.php:65` (the listener for `wb_gam_process_event_async`)
  - `wb_gam_events` table: was the event recorded?
  - `wp wb-gamification doctor` (the `DoctorCommand` will report queue/schema health)

### 4. Verify event row in DB
- **Action**: `mysql_query "SELECT id, user_id, action, points FROM wp_wb_gam_events WHERE id = '$EVENT_ID'"`
- **Expect**: exactly 1 row with `user_id = $USER_ID`, `action = 'journey_smoke_test'`, `points = 7`.

### 5. Verify points-ledger row
- **Action**: `mysql_query "SELECT id, user_id, points, event_id FROM wp_wb_gam_points WHERE event_id = '$EVENT_ID'"`
- **Expect**: exactly 1 row with `points = 7` and `event_id = $EVENT_ID` (FK back to events).

## Pass criteria

ALL of the following hold:
1. `members/{id}/points` returns 200 in step 1 with a numeric `total`.
2. `POST /events` returns 200/201 with an `event_id`.
3. The user's total increases by 7 within 5s.
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
