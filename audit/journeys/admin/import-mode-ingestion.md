# Journey — Import-mode ingestion (occurred_at + suppression + idempotency)

**Surface:** REST `POST /wb-gamification/v1/events/import`
**Card:** BC 10061794594
**Priority:** high

## Contract

A site manager can bulk-import historical gamification events (competitor
migration from GamiPress/myCred/BadgeOS, or a backfill) with NO data gaps:
each row keeps its real `occurred_at` and its exact point value, per-event
side-effects are suppressed, and re-running the same batch is idempotent.

## Steps (verified via `wp eval` dispatch, 2026-07-04)

1. As an admin, POST `{ "events": [ {action_id, user_id, points, occurred_at,
   source_key}, ... ] }` to `/events/import`.
   - EXPECT: `{received, imported, skipped_duplicate, failed, badges_awarded}`.
     Fresh rows → `imported == received`, `skipped_duplicate == 0`.
2. Inspect the ledger for the imported rows.
   - EXPECT: BOTH `wb_gam_events.created_at` AND `wb_gam_points.created_at`
     equal the row's `occurred_at` (UTC), not "now" — history keeps its
     timeline. Verified: 2024-01-15 10:00:00 and 2024-02-20 09:30:00.
   - EXPECT: `wb_gam_points.points` equals the row's explicit `points`
     (25, 40) even if the action's current admin value differs — no data gaps.
   - EXPECT: `wb_gam_events.source_key` stores the de-dup key.
3. Re-run the identical batch.
   - EXPECT: `imported == 0`, `skipped_duplicate == received` — the UNIQUE
     index on `source_key` + the pre-check make it a no-op, NOT a double award.
4. Confirm suppression: no per-event badge/level/email/notification/webhook
   fired during the import (Engine::process returns right after COMMIT in
   import mode); derived badge state is rebuilt ONCE via
   `Engine::recompute_users()` and reported as `badges_awarded`.

## Governance / scale

- `POST /events/import` requires the `wb_gam_manage_members` capability
  (`import_permissions_check`); anonymous → 401, non-manager → 403.
- Batch capped at 500 rows/request (importers page through); larger → 400.

## Regression notes

- `Engine::persist_event` + `PointsEngine::insert_point_row` MUST derive the
  timestamp from `$event->created_at` under import mode. If either reverts to
  `gmdate('now')` / `current_time('mysql')`, imported history collapses onto
  the import date and "points this month" / streak windows are wrong.
- Import mode is signalled by `metadata['_import']`; it also skips rate limits
  (a year of history would trip every daily cap) and the post-commit
  side-effect block.
- Idempotency depends on the `uniq_source_key` UNIQUE index
  (DbUpgrader::ensure_events_source_key). Organic events keep a NULL
  source_key and are exempt (MySQL treats NULLs as distinct in UNIQUE).
