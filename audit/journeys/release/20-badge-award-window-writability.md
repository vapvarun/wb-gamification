---
journey: badge-award-window-writability
plugin: wb-gamification
priority: critical
roles: [admin]
covers: [basecamp-10074180474, validity-days-unwritable, nullable-cap-clear, upsert-def-drops-columns]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one badge definition exists (e.g. active_member)"
estimated_runtime_minutes: 4
---

# Badge award-window columns are writable from all three entry points, and clearable

Locks Basecamp 10074180474. `wb_gam_badge_defs.validity_days` was **read** by every
consumer — `award_badge()` computes `expires_at = earned_at + validity_days`, the
expiry SQL filters on it, `get_badge_def()` returns it, `doctor` repairs against it —
but **written** by nobody. It was absent from `BadgeAdminPage`'s form, from
`BadgesController::save_args()` / `collect_badge_row()`, and from
`BadgeEngine::upsert_def()`. The REST API answered `200 OK` and silently discarded
the field, so the only way to set a badge expiry was hand-editing MySQL.

A second defect in the same family: `collect_badge_row()` gated nullable caps on
`null !== $request->get_param( $field )`. A blank admin number input serialises to
JSON `null`, which that check cannot distinguish from "field absent" — so **clearing**
`max_earners` was impossible. The form returned success and kept the old cap.

If this journey fails, site owners can create expiring credentials in the UI that
never expire, or find a badge cap they cannot lift.

Companion to `16-badge-expiry-visibility.md`, which locks the *read* side (a
never-expiring badge must store SQL `NULL`, not a zero-date). This one locks the
*write* side. Both must pass.

## Setup

- Site: `$SITE_URL`
- Admin user: id 1 (autologin via `?autologin=1`)
- Fixture badge: `active_member` (any existing def)
- Restore after run:
  ```sql
  UPDATE wp_wb_gam_badge_defs SET validity_days = NULL, max_earners = NULL, closes_at = NULL WHERE id = 'active_member';
  DELETE FROM wp_wb_gam_badge_defs  WHERE id LIKE 'zz_probe%';
  DELETE FROM wp_wb_gam_user_badges WHERE badge_id LIKE 'zz_probe%';
  ```

## Steps

### 1. REST accepts and persists validity_days
- **Action**: `wp eval 'wp_set_current_user(1); $r=new WP_REST_Request("POST","/wb-gamification/v1/badges/active_member"); $r->set_header("content-type","application/json"); $r->set_body(wp_json_encode(array("validity_days"=>30))); echo rest_do_request($r)->get_status();'`
- **Expect**: `200`, and `SELECT validity_days FROM wp_wb_gam_badge_defs WHERE id='active_member'` = `30`
- **On fail**: `save_args()` is missing `validity_days`, or `collect_badge_row()` never maps it — `src/API/BadgesController.php`

### 2. A JSON null clears the column (the blank-admin-field case)
- **Action**: same request with body `{"validity_days": null}`, then `{"max_earners": null}` after setting it to `50`
- **Expect**: `200`, and both columns read SQL `NULL` afterwards
- **On fail**: `collect_badge_row()` regressed to `null !== $request->get_param(...)`; it MUST gate on `$request->has_param(...)` — `src/API/BadgesController.php`

### 3. Fields not present in the request are left untouched
- **Action**: POST body `{"name":"Active Member"}` only, after setting `validity_days = 30`
- **Expect**: `200`, `validity_days` still `30` (a partial update must not null out unrelated columns)
- **On fail**: `has_param()` is matching absent params — check WP_REST_Request param order/defaults

### 4. Admin form exposes the field
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wb-gamification-badges&badge=active_member&autologin=1`
- **Expect**: an `input[name="validity_days"]` is present, `type=number`, `min=1`, with a description that says leaving it blank means the badge never expires
- **On fail**: the row was dropped from `src/Admin/BadgeAdminPage.php`

### 5. upsert_def() carries the award-window columns
- **Action**: `wp eval 'var_export( \WBGam\Engine\BadgeEngine::upsert_def(array("id"=>"zz_probe","name"=>"P","validity_days"=>7,"max_earners"=>2,"is_credential"=>true)) );'`
- **Expect**: `true`, and the stored row has `validity_days=7`, `max_earners=2`, `is_credential=1`
- **On fail**: `upsert_def()` is back to inserting only id/name/description/image_url/category — `src/Engine/BadgeEngine.php`

### 6. End-to-end: a validity_days badge actually expires
- **Action**: award `zz_probe` to user 1, then read `earned_at` + `expires_at`
- **Expect**: `expires_at - earned_at` is exactly 7 days
- **On fail**: `award_badge()` no longer reads `$def['validity_days']` — `src/Engine/BadgeEngine.php`

### 7. A def with no window still inserts clean NULLs
- **Action**: `wp eval 'var_export( \WBGam\Engine\BadgeEngine::upsert_def(array("id"=>"zz_probe2","name"=>"N")) );'`
- **Expect**: `true`, and `validity_days`/`closes_at`/`max_earners` are all SQL `NULL` (never `0`, never `0000-00-00 00:00:00`)
- **On fail**: nulls are being bound to `%d`/`%s` through `prepare()` instead of passed to `$wpdb->insert()`, which emits literal `NULL`

## Pass criteria

ALL of the following hold:
1. Step 1: `validity_days` round-trips through REST.
2. Step 2: an explicit JSON `null` clears both `validity_days` and `max_earners`.
3. Step 3: an unrelated partial update leaves both columns intact.
4. Step 4: the admin form renders the `validity_days` input.
5. Step 5: `upsert_def()` persists `validity_days`, `max_earners`, `is_credential`.
6. Step 6: the computed `expires_at` matches `earned_at + validity_days`.
7. Step 7: an unset window stores SQL `NULL`, not `0` and not a zero-date.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| REST answers 200 but column stays NULL | field missing from `save_args()` — WP strips unregistered args | `src/API/BadgesController.php::save_args()` |
| Setting works, clearing does not | `null !== get_param()` used instead of `has_param()` | `src/API/BadgesController.php::collect_badge_row()` |
| Partial update nulls unrelated columns | `has_param()` returning true for absent params | `src/API/BadgesController.php::collect_badge_row()` |
| REST 400 "must be greater than or equal to" | `minimum` on `validity_days` raised above 0 | `src/API/BadgesController.php::save_args()` |
| Imported badges never expire | `upsert_def()` dropped the columns again | `src/Engine/BadgeEngine.php::upsert_def()` |
| Fresh def has `validity_days = 0` not NULL | `empty()` → `null` mapping lost | `src/Engine/BadgeEngine.php::upsert_def()` |
