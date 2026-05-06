---
journey: rest-member-private-allowlist
plugin: wb-gamification
priority: critical
roles: [anonymous, subscriber, self, admin]
covers: [privacy-model, t1-t2-t3-tiers, MembersController-permission-checks, basecamp-9863460807, basecamp-9863594052]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Two test users on the site — one with profile public toggle ON, one with toggle OFF"
  - "Site option `wb_gam_profile_public_enabled` is ON (default)"
  - "Dev-auto-login mu-plugin installed for `?autologin=<login>` quick-switch"
estimated_runtime_minutes: 5
---

# Member-private REST allowlist — verify the T1/T2 tier rules hold across roles

The privacy model (`plan/PRIVACY-MODEL.md`) classifies member data into three tiers:
**T1 (achievements)** — public when both site + member switches are ON; **T2 (behavioral history)** — always private to self + admin only; **T3 (PII)** — admin only.

`MembersController` had a high-severity bug (Basecamp `9863460807`) where every endpoint's `permission_callback` returned `true` for any caller. This journey is the regression sentinel — if a future PR breaks the T1/T2 split, this journey catches it before customers do.

The same model is applied in 8 server-rendered blocks (`points-history`, `streak`, `badge-showcase`, `level-progress`, `member-points`, `year-recap`, `challenges`, `cohort-rank`). Block coverage is below in step 5.

## Setup

- Site: `$SITE_URL`
- `$ALICE_ID` — user with `wb_gam_profile_public = 1` (e.g. `2`)
- `$BOB_ID` — user with `wb_gam_profile_public = '' or 0` (e.g. `3`)
- `$ADMIN_ID` — administrator (e.g. `1`)

```sql
-- Seed the privacy state for the test users.
UPDATE wp_usermeta SET meta_value = '1' WHERE user_id = 2 AND meta_key = 'wb_gam_profile_public';
DELETE FROM wp_usermeta WHERE user_id = 3 AND meta_key = 'wb_gam_profile_public';
UPDATE wp_options SET option_value = '1' WHERE option_name = 'wb_gam_profile_public_enabled';
```

Or via WP-CLI:
```bash
wp user meta update 2 wb_gam_profile_public 1
wp user meta delete 3 wb_gam_profile_public
wp option update wb_gam_profile_public_enabled 1
```

Each `curl` below is anonymous unless preceded by `?autologin=<login>` for that step. To run authenticated tests via REST instead, use a real WP nonce or Application Password — `?autologin=` is for browser sessions.

## Steps

### 1. Anonymous viewing public-toggle-ON member (Alice)

| Endpoint | Expected status | Expected body shape |
|---|---|---|
| `GET /members/$ALICE_ID` | 200 | T1 only — `id, display_name, avatar_url, points, level, badges_count`. **Must NOT contain** `points_by_type` or `preferences`. |
| `GET /members/$ALICE_ID/level` | 200 | Level object |
| `GET /members/$ALICE_ID/badges` | 200 | Badge array |
| `GET /members/$ALICE_ID/points` | **403** | `{"code":"rest_forbidden","message":"You do not have permission to view this member's activity history.","data":{"status":403}}` |
| `GET /members/$ALICE_ID/events` | **403** | Same shape |
| `GET /members/$ALICE_ID/streak` | 200 | T1 only — `current_streak, longest_streak, milestones`. **Must NOT contain** `last_active`, `grace_used`, or `heatmap`. |
| `GET /members/$ALICE_ID/streak?heatmap_days=30` | 200 | Same — heatmap parameter ignored for non-owner. |

```bash
for path in "" "/level" "/badges" "/streak" "/streak?heatmap_days=30"; do
  echo "GET /members/$ALICE_ID$path"
  curl -sS -o /tmp/r.json -w "  HTTP=%{http_code}\n" "$SITE_URL/wp-json/wb-gamification/v1/members/$ALICE_ID$path"
  jq 'keys' /tmp/r.json
done
for path in "/points" "/events"; do
  echo "GET /members/$ALICE_ID$path (must 403)"
  curl -sS -o /tmp/r.json -w "  HTTP=%{http_code}\n" "$SITE_URL/wp-json/wb-gamification/v1/members/$ALICE_ID$path"
done
```

### 2. Anonymous viewing public-toggle-OFF member (Bob)

| Endpoint | Expected status |
|---|---|
| `GET /members/$BOB_ID` | **403** "This member's profile is not public." |
| `GET /members/$BOB_ID/level` | **403** |
| `GET /members/$BOB_ID/badges` | **403** |
| `GET /members/$BOB_ID/streak` | **403** |
| `GET /members/$BOB_ID/points` | **403** "permission to view activity history" |
| `GET /members/$BOB_ID/events` | **403** |

All six must return 403 even though Bob exists. The error message differs by tier — T1 returns "profile is not public"; T2 returns "permission to view activity history". Both are 403.

### 3. Site kill-switch OFF (admin disables public profiles globally)

```bash
wp option update wb_gam_profile_public_enabled 0
```

| Endpoint | Anonymous | Self | Admin |
|---|---|---|---|
| `GET /members/$ALICE_ID` | **403** | 200 (full T1+T2) | 200 (full T1+T2) |
| `GET /members/$ALICE_ID/badges` | **403** | 200 | 200 |
| `GET /members/$ALICE_ID/points` | **403** | 200 | 200 |
| `GET /members/$ALICE_ID/streak` | **403** | 200 (full incl. heatmap) | 200 (full) |
| `GET /members/me/toasts` | 401 | 200 | 200 |

After verifying, restore: `wp option update wb_gam_profile_public_enabled 1`.

### 4. Authenticated peer (subscriber) viewing another member

Login as a subscriber via `?autologin=test_subscriber` then run:

| Endpoint | Expected |
|---|---|
| `GET /members/$ALICE_ID` (Alice toggle ON) | 200, T1 only — same shape as anonymous step 1 |
| `GET /members/$BOB_ID` (Bob toggle OFF) | **403** |
| `GET /members/$ALICE_ID/points` | **403** — peer is not the owner |
| `GET /members/$ALICE_ID/events` | **403** |
| `GET /members/$ALICE_ID/streak?heatmap_days=30` | 200, T1 only (no heatmap) |

### 5. Block-render coverage (server-side render parity with REST)

Place these blocks on a public page with a foreign `user_id` argument. Each block MUST hide its data when the same gate would 403 the matching REST endpoint.

| Block + attribute | Anon viewing toggle-ON Alice | Anon viewing toggle-OFF Bob |
|---|---|---|
| `[wb_gam_member_points user_id=$ALICE_ID]` | renders points | empty / hidden |
| `[wb_gam_level_progress user_id=$ALICE_ID]` | renders level | empty / hidden |
| `[wb_gam_badge_showcase user_id=$ALICE_ID]` | renders badges | empty / hidden |
| `[wb_gam_streak user_id=$ALICE_ID]` | T1 only — current/longest, **no heatmap even if `show_heatmap=1`** | empty / hidden |
| `[wb_gam_points_history user_id=$ALICE_ID]` | empty / "log in" — T2 always private | empty |
| `[wb_gam_year_recap user_id=$ALICE_ID year=2025]` | renders T1 recap | empty / hidden |
| `[wb_gam_challenges user_id=$ALICE_ID]` | renders challenges | empty / hidden |
| `[wb_gam_cohort_rank user_id=$ALICE_ID]` | renders rank | empty / hidden |

When self-viewing (logged in as Alice), every block above renders fully — including heatmap and points-history.

Verification per block:
```bash
playwright_navigate "$SITE_URL/qa-test-page-with-foreign-blocks/"
# Inspect rendered HTML for the leaked fields above. None should be present.
```

### 6. GDPR pipeline coverage

Verify export and erase are exhaustive — they MUST cover every user-scoped surface introduced since the last passing run of this journey.

```bash
# Snapshot what should be present.
wp eval '
  $email = wp_get_current_user()->user_email;
  $r = WBGam\Engine\Privacy::export_user_data($email);
  $groups = array_unique(array_column($r["data"], "group_id"));
  sort($groups);
  print_r($groups);
'
```

Expected groups (when the user has data in each surface):
- `wb-gam-points` — single summary row
- `wb-gam-points-history` — paginated rows
- `wb-gam-badges`
- `wb-gam-streak`
- `wb-gam-prefs`
- `wb-gam-user-meta` (login_streak, profile_public, etc.)
- `wb-gam-submissions` (UGC achievement submissions)
- `wb-gam-events` (full immutable event log)

Erase test (use a synthetic test user — never run on real accounts):

```bash
# Seed + erase + verify counts go to zero. See the wp eval block in
# the project's smoke-test scripts.
```

## Pass criteria

All assertions in steps 1–6 pass. If any fail:
- Step 1/2/4 fail → MembersController T1/T2 split has regressed → re-read `plan/PRIVACY-MODEL.md` § Per-surface policy.
- Step 3 fails → site kill-switch is being ignored → check `Privacy::can_view_public_profile`.
- Step 5 fails → a block is rendering data without consulting `Privacy` helpers → grep `src/Blocks/*/render.php` for `user_id` resolution and ensure the gate is present.
- Step 6 fails → a new table or user_meta key was added without wiring `Privacy::export/erase` (the exact debt this journey was added to prevent).

## Why this journey exists

Before the privacy model landed (2026-05-06), the plugin shipped 6 of 7 `MembersController` endpoints with broken permission checks (`return true` for anyone) — anyone could harvest a member's full points history and event log via anonymous curl. The fix applied `plan/PRIVACY-MODEL.md` end-to-end. This journey codifies the role/tier matrix so the next contributor doesn't quietly re-introduce the leak by deferring permission to a callback that never fires.
