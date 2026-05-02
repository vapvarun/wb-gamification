---
journey: rest-public-allowlist
plugin: wb-gamification
priority: high
roles: [anonymous]
covers: [rest-permissions, __return_true-allowlist, rule-2-coding-rule]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "No active session (clear cookies)"
estimated_runtime_minutes: 2
---

# Public REST allowlist — verify only the documented endpoints are anonymous-readable

The plugin intentionally ships ~17 `__return_true` permission callbacks across 12 controllers (read-only catalogs, OG share, OpenBadges credential, leaderboard, etc.). Every other route must require auth. This journey is the live counterpart to `bin/coding-rules-check.sh` Rule 2 — that rule blocks new violations at commit time; this rule verifies the running site doesn't quietly expose them.

## Setup

- Site: `$SITE_URL`
- All requests anonymous (`-H "Cookie:"` or fresh curl jar)

## Steps

### 1. Allowlisted public reads (must succeed anonymously)

Each `GET` must return 200 with a JSON body. List taken from `audit/ROLE_MATRIX.md` "REST API permission map".

| Route | Expected |
|---|---|
| `/wb-gamification/v1/abilities` | 200 — discovery list |
| `/wb-gamification/v1/actions` | 200 — registered action catalog |
| `/wb-gamification/v1/badges` | 200 — badge defs |
| `/wb-gamification/v1/capabilities` | 200 — capability list |
| `/wb-gamification/v1/challenges` | 200 — challenge list |
| `/wb-gamification/v1/leaderboard` | 200 — public leaderboard |
| `/wb-gamification/v1/levels` | 200 — level defs |
| `/wb-gamification/v1/openapi.json` | 200 — OpenAPI 3.0 spec, valid JSON |
| `/wb-gamification/v1/redemptions/items` | 200 — redemption catalog |
| `/wb-gamification/v1/kudos` | 200 — public kudos feed |

For each: `curl -fsS $SITE_URL/wp-json{route}` returns 200 and parseable JSON.

### 2. Auth-required routes (must reject anonymous)

| Route | Method | Expected |
|---|---|---|
| `/wb-gamification/v1/kudos` | POST | 401 — give kudos requires login |
| `/wb-gamification/v1/kudos/me` | GET | 401 — own stats require login |
| `/wb-gamification/v1/leaderboard/me` | GET | 401 — own rank requires login |
| `/wb-gamification/v1/members/me/toasts` | GET | 401 — own toasts require login |
| `/wb-gamification/v1/redemptions` | POST | 401 — redeem requires login |
| `/wb-gamification/v1/redemptions/me` | GET | 401 — own history requires login |
| `/wb-gamification/v1/challenges/1/complete` | POST | 401 — complete challenge requires login |

For each: response status is 401 OR 403 with `code` of `rest_not_logged_in` / `rest_forbidden`.

### 3. Admin-required routes (must reject anonymous + subscriber)

Run each twice — once anonymous, once as `?autologin=test_user` (subscriber). Both must reject.

| Route | Method | Expected |
|---|---|---|
| `/wb-gamification/v1/points/award` | POST | 401/403 |
| `/wb-gamification/v1/points/1` | DELETE | 401/403 |
| `/wb-gamification/v1/badges/test/award` | POST | 401/403 |
| `/wb-gamification/v1/rules` | POST | 401/403 |
| `/wb-gamification/v1/webhooks` | POST | 401/403 |
| `/wb-gamification/v1/redemptions/items` | POST | 401/403 |

### 4. Cross-user member reads (must reject other-user)

Self vs other gating is in `MembersController::get_item_permissions_check`. As `test_user` (subscriber, USER_ID=X), attempt to read another user's data:

- `GET /wb-gamification/v1/members/{Y}/points` (where Y ≠ X) — expect 401/403.
- `GET /wb-gamification/v1/members/X/points` — expect 200.

### 5. OpenAPI spec sanity

- **Action**: `GET /wb-gamification/v1/openapi.json`
- **Expect**: 200, valid JSON, `info.title` contains "WB Gamification" or similar, `paths` count ≥ 30.
- **On fail**: `src/API/OpenApiController.php` schema generator or static spec stale.

## Pass criteria

ALL of the following hold:
1. All 10 allowlisted public routes return 200.
2. All 7 auth-required routes return 401/403 anonymously.
3. All 6 admin-required routes return 401/403 for both anonymous AND subscriber.
4. Cross-user member reads are rejected for non-self/non-admin.
5. OpenAPI spec parses and lists ≥ 30 paths.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Allowlisted route returns 401 | The controller's `__return_true` was changed to a real check that's failing | the specific controller in `src/API/` |
| Auth-required route returns 200 | `__return_true` was added where it shouldn't be (run `composer coding-rules`) | the specific controller |
| Subscriber gets 200 on admin route | `admin_check`/`admin_permission_check` regression OR cap drift on `wb_gam_award_manual` | `src/API/{Points,Badges,Rules,Webhooks,Redemption}Controller.php` |
| Cross-user 200 | `get_item_permissions_check` regression — self check not enforced | `src/API/MembersController.php:230` |
| OpenAPI returns 404 or empty | Spec not regenerated after route additions | `src/API/OpenApiController.php` |
