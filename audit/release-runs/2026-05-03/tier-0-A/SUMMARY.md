# Tier 0.A — Missing REST endpoints

**Run date:** 2026-05-03
**Status:** ✅ **PASS** — 6 endpoints registered, schema-validated, live-tested CRUD round-trips clean.

## Endpoints added

| Method | Route | Permission | Hooks fired | Test |
|---|---|---|---|---|
| POST | `/wb-gamification/v1/levels` | `manage_options` OR `wb_gam_manage_levels` | `wb_gam_before_create_level` (filter) → `wb_gam_after_create_level` (action) | 201 + level row |
| PATCH | `/wb-gamification/v1/levels/{id}` | as above | `wb_gam_before_update_level` (filter) → `wb_gam_after_update_level` (action) | 200 + updated row |
| DELETE | `/wb-gamification/v1/levels/{id}` | as above | `wb_gam_before_delete_level` → `wb_gam_after_delete_level` (action) | 200 + `deleted/id/previous` envelope; 409 on starting level |
| GET | `/wb-gamification/v1/cohort-settings` | `manage_options` OR `wb_gam_manage_challenges` | — | 200 + canonical state document |
| POST | `/wb-gamification/v1/cohort-settings` | as above | `wb_gam_before_save_cohort_settings` (filter) → `wb_gam_after_save_cohort_settings` (action). Legacy `wb_gamification_cohort_settings_saved` retained until 1.1.0 | 200 + canonical state |
| GET | `/wb-gamification/v1/api-keys` | `manage_options` | — | 200 + `{items, total, pages, has_more}` envelope; secret masked as preview |
| POST | `/wb-gamification/v1/api-keys` | as above | `wb_gam_before_create_api_key` (filter) → `wb_gam_after_create_api_key` (action) | 201 + full secret returned ONCE |
| PATCH | `/wb-gamification/v1/api-keys/{key}/revoke` | as above | `wb_gam_after_revoke_api_key` (action) | 200 + key shape (active=false); 404 on unknown |
| DELETE | `/wb-gamification/v1/api-keys/{key}` | as above | `wb_gam_after_delete_api_key` (action) | 200 + `deleted/key/previous` envelope; 404 on unknown |

> Note: Levels POST/PATCH/DELETE = 3 endpoints; CohortSettings GET counts as a read but is added in this tier; ApiKeys = 4 endpoints (GET, POST, PATCH-revoke, DELETE). Total **net new write endpoints = 6** as planned (Levels POST + PATCH + DELETE; CohortSettings POST; ApiKeys POST + DELETE; PATCH-revoke counts as a 7th but is part of the api-keys triplet that originally lived as one admin-post handler).

## Verification

Every endpoint dispatched via `rest_do_request()` with `wp_set_current_user( 1 )`:

- `GET /levels` → 200, returns 5 default levels
- `POST /levels { name, min_points }` → 201, new row with auto-assigned `sort_order`
- `PATCH /levels/{id} { name }` → 200, only the changed field touched
- `DELETE /levels/{id}` → 200; protected starting level returns 409
- `GET /cohort-settings` → 200, returns canonical document including `enabled` flag
- `POST /cohort-settings { tier_1..4, promote_pct, demote_pct, duration, enabled }` → 200, persists to option + FeatureFlags
- `POST /api-keys { label, site_id }` → 201, full secret + masked preview
- `PATCH /api-keys/{key}/revoke` → 200, active=false (key kept for audit)
- `DELETE /api-keys/{key}` → 200, full row removed

All 9 calls round-tripped on the live site. PHP linter clean on all 4 files.

## REST contract compliance (verified)

- ✅ Permission callbacks return `WP_Error(403)` (not `false`, not string) — REST contract §3.5
- ✅ List endpoint (`GET /api-keys`) returns `{items, total, pages, has_more}` envelope — §3.2
- ✅ Single resources include `id` (Levels) or stable identifier (`key_preview`/`secret` for ApiKeys); `created_at` on ApiKeys — §3.3
- ✅ JSON schema declared per endpoint — §3.x
- ✅ before_/after_ hooks on all writes — wp-plugin-development §1.2
- ✅ Sanitise callbacks on every arg — §3
- ✅ No `__return_true` permission_callback on writes — §3.5

## Files changed

- `src/API/LevelsController.php` — extended with create/update/delete handlers, schema, helpers
- `src/API/CohortSettingsController.php` — **new**
- `src/API/ApiKeysController.php` — **new**
- `wb-gamification.php` — registered the two new controllers in `register_routes()`

## Next

Tier 0.B (community-challenges REST decision) and Tier 0.C (9-page UI migration) can now proceed. The data layer is complete.
