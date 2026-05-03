# Tier 0.B — Community-challenges REST decision

**Run date:** 2026-05-03
**Decision:** Forked into a dedicated `CommunityChallengesController`. Reasoning: separate DB table (`wb_gam_community_challenges`), separate cascade target (`wb_gam_community_challenge_contributions`), distinct shape (`global_progress` + `target_count` % vs individual challenge `progress` + per-user log).

## Investigation findings

- Existing `ChallengesController` only serves `wb_gam_challenges` (individual challenges).
- Routes that existed before this tier: `GET/PATCH/DELETE /challenges/{id}`, `POST /challenges/{id}/complete`. No community variant.
- Admin handler `CommunityChallengesPage::handle_save` writes to `wb_gam_community_challenges`.
- Admin handler `CommunityChallengesPage::handle_delete` cascades to `wb_gam_community_challenge_contributions`.

## What shipped

| Method | Route | Permission |
|---|---|---|
| GET | `/wb-gamification/v1/community-challenges` | public; `?status=all` requires admin |
| POST | `/wb-gamification/v1/community-challenges` | `manage_options` OR `wb_gam_manage_challenges` |
| GET | `/wb-gamification/v1/community-challenges/{id}` | public |
| PATCH | `/wb-gamification/v1/community-challenges/{id}` | admin |
| DELETE | `/wb-gamification/v1/community-challenges/{id}` | admin (cascades contributions) |

Hooks fired:
- Filter `wb_gam_before_create_community_challenge` / `wb_gam_before_update_community_challenge` — abort by returning WP_Error
- Action `wb_gam_after_create_community_challenge` / `wb_gam_after_update_community_challenge` / `wb_gam_after_delete_community_challenge`
- Backwards-compatible legacy hooks retained until 1.1.0: `wb_gamification_community_challenge_created/updated/deleted`

## Verification

```
GET    /community-challenges            → 200 + envelope (items=0 on fresh DB)
POST   /community-challenges            → 201 + new row id=1
PATCH  /community-challenges/1          → 200 + patched title
DELETE /community-challenges/1          → 200 + cascade to contributions
```

All four CRUD operations round-tripped on the live site.

## Files changed

- `src/API/CommunityChallengesController.php` — **new**
- `wb-gamification.php` — registered the new controller in `register_routes()`
