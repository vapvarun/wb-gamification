---
journey: manual-award-points
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [award-points-rest, points-controller, admin-permission-check, manual-award-page]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged in as administrator (autologin via ?autologin=admin)"
  - "Target test user exists (display name 'test_user', subscriber role)"
estimated_runtime_minutes: 3
---

# Manual point award (admin → REST → user reflects)

The admin-only fast path for moderator awards. Cap drift on `wb_gam_award_manual` makes this the single most important permission journey: today only `manage_options` opens this gate. If a regression silently flips it to allow lower roles, every subscriber can mint points.

## Setup

- Site: `$SITE_URL`
- Admin login: `?autologin=admin`
- Target user: `test_user` — capture `USER_ID` via `GET /wp-json/wp/v2/users?search=test_user`
- Capture starting total: `STARTING_TOTAL` ← `.total` from `GET /wb-gamification/v1/members/$USER_ID/points` (admin can read others)

## Steps

### 1. Verify admin can award via REST
- **Action**: `POST $SITE_URL/wp-json/wb-gamification/v1/points/award` (admin cookie + `wp_rest` nonce) with body
  ```json
  { "user_id": $USER_ID, "points": 25, "reason": "manual_award", "note": "journey smoke test" }
  ```
- **Expect**: 200 OK, JSON contains `event_id` (the synthetic event written by the controller).
- **Capture**: `EVENT_ID` ← `.event_id`
- **On fail**: `src/API/PointsController.php:60` (award handler) or `admin_permission_check` rejecting the admin (cookie auth or nonce missing)

### 2. Reflect on user's points endpoint
- **Action**: `GET /wb-gamification/v1/members/$USER_ID/points`
- **Expect**: `.total == STARTING_TOTAL + 25` within 5s.
- **On fail**: same root causes as customer/01 (event recorded but async failed) — OR the REST handler does NOT write through the same engine pipeline (architectural drift).

### 3. Visit the Manual Award admin page
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wb-gamification-award`
- **Expect**:
  - 200 OK
  - DOM contains a form with fields for user picker and points
  - Page title contains "Award Points"
- **On fail**: `src/Admin/ManualAwardPage.php:49` (page registration) or `manage_options` cap not held (autologin landed wrong user)

### 4. Reject anonymous attempt
- **Action**: clear cookies, then `POST /wb-gamification/v1/points/award` with the same body
- **Expect**: 401 Unauthorized OR 403 with `code: rest_forbidden`.
- **On fail**: `admin_permission_check` is incorrectly returning true for non-admins — security regression. `src/API/PointsController.php:267-282`.

### 5. Reject subscriber attempt (regression sentinel for cap drift)
- **Action**: `?autologin=test_user` (subscriber), then `POST /wb-gamification/v1/points/award` with same body.
- **Expect**: 403 with `code: rest_forbidden`.
- **Important**: this verifies the `wb_gam_award_manual` fallback at line 273 doesn't silently grant access. Today the cap is unregistered, so the fallback returns false. If a future change registers the cap and grants it to subscribers (or any non-admin), this journey FAILS — that's the desired regression sentinel.

## Pass criteria

ALL of the following hold:
1. Admin POST to `/points/award` returns 200 with `event_id`.
2. Target user's `total` reflects the +25 within 5s.
3. The Manual Award admin page loads for admins.
4. Anonymous and subscriber POSTs both return 401/403.
5. The DB shows exactly one new row in `wb_gam_events` (action=`manual_award`) and one matching row in `wb_gam_points`.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| 200 returned to subscriber | Cap drift: someone registered `wb_gam_award_manual` and granted it broadly | search for `add_cap.*wb_gam_award_manual` |
| 200 returned but no event_id | Controller stubbed/short-circuited | `src/API/PointsController.php:60` |
| `total` unchanged | Manual award path bypasses the engine pipeline | check whether `Engine::record()` is called in `PointsController::award` |
| 500 on POST | Schema issue in `wb_gam_events` (UUID generation, NOT NULL constraint) | `src/Engine/Installer.php` migrations |
| Admin page 404s | `add_submenu_page` capability mismatch or wrong parent slug | `src/Admin/ManualAwardPage.php:49` |
