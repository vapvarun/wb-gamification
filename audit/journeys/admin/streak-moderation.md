---
journey: streak-moderation
plugin: wb-gamification
priority: high
roles: [administrator]
covers: [BC-10061736437, streaks, three-entry-point, audit-log]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Streaks module enabled (Settings > Modules)"
  - "At least one real member has a streak row in wb_gam_streaks"
estimated_runtime_minutes: 5
---

# Streak moderation — admin can view, adjust, and reset a member's streak

Closes the streaks three-entry-point gap (frontend `streak` block + THIS admin
page + POST/DELETE REST on /members/{id}/streak). A site owner must be able to
fix a member's broken or wrong streak from wp-admin, and every change must be
audited — never a silent mutation (BC 10061736437). The write path lives in
StreakEngine::admin_set() / admin_reset(); each records a points-free row to
wb_gam_events (surfaced by GET /members/{id}/events).

## Setup

- Streaks page: `$SITE_URL/wp-admin/admin.php?page=wb-gamification-streaks`
- Admin autologin: append `&autologin=1`
- Pick a REAL member (exists in wp_users) with a streak row — call it `$UID`.

## Steps

### 1. Roster renders, sorted, paginated
- **Action**: navigate to the Streaks page.
- **Expect**: `.wb-gam-streaks-table` present; columns Member / Current / Longest / Last active / Grace / Actions; the Current header shows the active-sort arrow; each row has `.wb-gam-streak-adjust` + `.wb-gam-streak-reset`. Orphaned rows (user no longer in wp_users) render "User #N" but still list.
- **On fail**: `src/Admin/StreaksPage.php` render, or `StreakEngine::admin_list()` sort/pagination.

### 2. Sort by streak uses an index (scale)
- **Action**: confirm `SHOW INDEX FROM {prefix}wb_gam_streaks` includes `idx_current_streak` and `idx_longest_streak`.
- **Expect**: both present (added by the 1.6.2 idempotent migration `ensure_streak_sort_indexes`).
- **On fail**: `src/Engine/DbUpgrader.php::ensure_streak_sort_indexes` didn't run — check the `wb_gam_feature_streak_sort_idx_v1` option.

### 3. Adjust via the inline editor (no native dialog)
- **Action**: click Adjust on `$UID`'s row → inline editor opens (a `.wb-gam-streak-editor` with a number field + reason field + Save/Cancel; the action buttons hide). Set current = 42, reason = "test", Save.
- **Expect**: no `window.prompt`/`confirm` used; the row's current cell updates to 42 without reload; POST /members/{id}/streak returns 200.
- **On fail**: `assets/js/admin-streaks.js` (editor build/submit), or `MembersController::set_streak`.

### 4. Adjust is audited
- **Action**: GET /members/{$UID}/events.
- **Expect**: a `streak_adjusted` event exists with `metadata.reason`, `metadata.admin_id`, and `before`/`after` current+longest values. `wb_gam_streak_adjusted` action fired.
- **On fail**: `StreakEngine::admin_set` → `record_admin_event` → `Engine::persist_event`.

### 5. Reset keeps the longest record + audits
- **Action**: click Reset on `$UID`'s row → inline confirm editor → reason "test" → Confirm reset.
- **Expect**: current cell → 0; `GET /members/{$UID}/streak` shows `current_streak: 0` and **`longest_streak` unchanged** (all-time record preserved); a `streak_reset` event exists (before → after).
- **On fail**: `StreakEngine::admin_reset` (must pass `$before['longest_streak']`, not 0).

### 6. Permission gate
- **Action**: POST /members/{$UID}/streak with NO `X-WP-Nonce` (or as a subscriber).
- **Expect**: 401 (logged-out) / 403 (subscriber). Member-not-found (non-existent user id) → 404.
- **On fail**: `MembersController::admin_permissions_check`.

## Pass criteria

1. Roster renders sorted + paginated; indexed sort columns exist.
2. Adjust updates the row without reload, via an accessible inline editor (no native prompt/confirm).
3. Adjust + Reset each write an audited event (reason + admin_id + before/after) to wb_gam_events.
4. Reset zeroes current_streak but preserves longest_streak.
5. Writes are admin-gated (401/403) and 404 on a missing member.

## Fail diagnostics

| Symptom | Likely cause | File |
|---|---|---|
| Adjust/Reset does nothing, 404 | member id has no wp_users row (orphaned streak) — expected for seed rows | `MembersController::set_streak` guard |
| current cell doesn't update | JS submit path or response shape | `assets/js/admin-streaks.js` |
| No streak_adjusted/reset event | audit not written | `StreakEngine::record_admin_event` |
| Reset wiped longest_streak | reset passed 0 for longest | `StreakEngine::admin_reset` |
| Sort slow at scale | missing index | `DbUpgrader::ensure_streak_sort_indexes` |
| Native prompt appears | regressed to prompt/confirm | `assets/js/admin-streaks.js` (must use inline editor) |
