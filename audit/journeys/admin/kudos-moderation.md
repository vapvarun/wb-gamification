---
journey: kudos-moderation
plugin: wb-gamification
priority: high
roles: [administrator]
covers: [BC-10061737374, kudos, three-entry-point, audit-log, anti-abuse]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Kudos module enabled"
  - "At least one active kudos exists (a member gave another member kudos)"
estimated_runtime_minutes: 5
---

# Kudos moderation — admin can browse and revoke kudos

Closes the kudos three-entry-point gap (frontend give-kudos/kudos-feed blocks +
THIS admin page + DELETE /kudos/{id} REST). An owner must be able to revoke an
abusive or mistaken kudos, and the revoke must be a COMPOUND reversal: both the
giver and the receiver lose the exact points that kudos awarded them, the row is
kept (soft-revoked) for audit, and it disappears from the public feed
(BC 10061737374). Revoke lives in KudosEngine::revoke().

## Setup

- Page: `$SITE_URL/wp-admin/admin.php?page=wb-gamification-kudos-moderation`
- Admin autologin: append `&autologin=1`
- Note an active kudos row id `$KID`, its giver `$GID`, receiver `$RID`, and the
  giver/receiver point balances before the test.

## Steps

### 1. Roster renders with status filter
- **Action**: navigate to the page.
- **Expect**: `.wb-gam-kudos-table` with columns Giver / Receiver / Message / Date / Status / Actions; filter tabs All / Active / Revoked; active rows have a `.wb-gam-kudos-revoke` button; revoked rows do not.
- **On fail**: `src/Admin/KudosModerationPage.php`, or the `revoked_at` column missing (migration) makes `KudosEngine::admin_list` error.

### 2. Revoke via the inline reason editor (no native dialog)
- **Action**: click Revoke on `$KID` → inline `.wb-gam-kudos-editor` opens (a reason field + Confirm/Cancel; no window.confirm). Enter a reason, Confirm.
- **Expect**: DELETE /kudos/{KID} returns 200; the row's Status cell flips to a "Revoked" badge and the Revoke button is removed, without reload.
- **On fail**: `assets/js/admin-kudos.js`, or `KudosController::revoke_item`.

### 3. Compound point reversal (the core invariant)
- **Action**: read `$GID` and `$RID` balances after the revoke.
- **Expect**: giver balance dropped by exactly the giver_points that kudos awarded; receiver balance dropped by exactly the receiver_points. Amounts come from wb_gam_points (object_id = KID), NOT the current option value.
- **On fail**: `KudosEngine::revoke` point-lookup or `PointsEngine::debit`.

### 4. Both reversals audited
- **Action**: GET /members/{GID}/events and /members/{RID}/events.
- **Expect**: each has a `kudos_revoked` event with `metadata.reason`, `metadata.role` (giver|receiver), and `metadata.points_cost` (negative). `wb_gam_kudos_revoked` action fired.
- **On fail**: `KudosEngine::revoke_event` / `PointsEngine::debit` audit path.

### 5. Revoked kudos leaves the public feed
- **Action**: GET /kudos (public feed).
- **Expect**: `$KID` is NOT in the feed (get_recent filters `revoked_at IS NULL`).
- **On fail**: `KudosEngine::get_recent` WHERE clause.

### 6. Filters + idempotency + permission
- **Action**: Active tab excludes `$KID`; Revoked tab includes it. Re-issue DELETE /kudos/{KID}. Issue DELETE with no nonce.
- **Expect**: filters correct; a second revoke returns 409 (already revoked); no-nonce → 401/403.
- **On fail**: `KudosEngine::status_where`, the `revoked_at` guard, or `KudosController::admin_permissions_check`.

## Pass criteria

1. Roster + status filters render; revoked rows have no revoke action.
2. Revoke via accessible inline editor updates the row in place (no native dialog).
3. Giver AND receiver each debited by the exact points that kudos awarded.
4. Both debits are audited `kudos_revoked` events with reason + role.
5. Revoked kudos is hidden from the public feed.
6. Re-revoke → 409; no-nonce → 401/403.

## Fail diagnostics

| Symptom | Likely cause | File |
|---|---|---|
| admin_list SQL error | `revoked_at` column missing | `DbUpgrader::ensure_kudos_moderation_schema` |
| Only one side debited | point-lookup wrong action_id/object_id | `KudosEngine::revoke` |
| Wrong debit amount | used option value not actual award | `KudosEngine::revoke` (must query wb_gam_points) |
| Revoked kudos still in feed | feed not filtering | `KudosEngine::get_recent` |
| Native confirm appears | regressed from inline editor | `assets/js/admin-kudos.js` |
| Second revoke double-debits | missing revoked_at guard | `KudosEngine::revoke` (409 on already-revoked) |
