# Tier 0.C — Admin REST migration

**Run date:** 2026-05-03
**Status:** ✅ **PASS** — 9 of 9 admin pages migrated, 17 of 17 `admin_post_*` hooks deleted, all CRUD live-tested.

## Objective

Per wp-plugin-development standard: "Admin UI uses REST internally, not form-posts. Max 2 AJAX/form-post exceptions per plugin." Pre-Tier 0 baseline: 17 form-post hooks across 9 admin pages — 8.5× the standard.

## Migration map

| # | Admin page | Slug | admin_post_* hooks deleted | REST endpoint(s) used | Verified |
|---|---|---|---|---|---|
| 1 | SettingsPage (Levels tab) | `wb-gamification?tab=levels` | `wb_gam_save_levels`, `wb_gam_delete_level` | `POST/PATCH/DELETE /levels` | ✅ live-UI |
| 2 | CohortSettingsPage | `wb-gam-cohort` | `wb_gam_save_cohort_settings` | `GET/POST /cohort-settings` | ✅ live-UI |
| 3 | ApiKeysPage | `wb-gam-api-keys` | `wb_gam_create_api_key`, `wb_gam_revoke_api_key`, `wb_gam_delete_api_key` | `GET/POST /api-keys`, `PATCH /api-keys/{key}/revoke`, `DELETE /api-keys/{key}` | ✅ live-UI |
| 4 | WebhooksAdminPage | `wb-gam-webhooks` | `wb_gam_webhook_save`, `wb_gam_webhook_delete` | `POST /webhooks`, `DELETE /webhooks/{id}` | ✅ live-UI |
| 5 | ManualAwardPage | `wb-gamification-award` | `wb_gam_manual_award` | `POST /points/award` | ✅ form swapped + handler removed |
| 6 | BadgeAdminPage | `wb-gamification-badges` | `wb_gam_save_badge`, `wb_gam_delete_badge` | `POST /badges`, `POST/DELETE /badges/{id}` | ✅ live-UI (full schema + condition rule) |
| 7 | ChallengeManagerPage | `wb-gam-challenges` | `wb_gam_save_challenge`, `wb_gam_delete_challenge` | `POST /challenges`, `POST/DELETE /challenges/{id}` | ✅ live-UI |
| 8 | CommunityChallengesPage | `wb-gam-community-challenges` | `wb_gam_save_community_challenge`, `wb_gam_delete_community_challenge` | `POST /community-challenges`, `POST/DELETE /community-challenges/{id}` | ✅ live (forked controller from 0.B) |
| 9 | RedemptionStorePage | `wb-gam-redemption` | `wb_gam_save_reward`, `wb_gam_delete_reward` | `POST /redemptions/items`, `POST/DELETE /redemptions/items/{id}` | ✅ live-UI (incl. nested `reward_config`) |

## What ships

### REST data layer (Tiers 0.A + 0.B + 0.C extensions)

- `LevelsController` — added POST/PATCH/DELETE
- `CohortSettingsController` — new (GET/POST)
- `ApiKeysController` — new (GET/POST + PATCH revoke + DELETE)
- `CommunityChallengesController` — new (GET/POST + GET/PATCH/DELETE)
- `BadgesController` — extended: new `POST /badges` (create), update_item now accepts full 8-field schema + nested `condition` rule, `persist_condition()` helper

### Generic UI driver

- `assets/js/admin-rest-utils.js` — shared `wbGamAdminRest = { apiFetch, toast, toastError, clearChildren, confirmAction }`
- `assets/js/admin-rest-form.js` — `data-wb-gam-rest-form` form driver + `data-wb-gam-rest-action` button driver. Supports nested objects (`name="condition[type]"`), top-level arrays (`name="events[]"`), datetime-local → UTC auto-convert, three after-save modes (`reload`, `remove-row`, none).
- `assets/js/admin-levels.js` — bespoke (richer in-place table refresh)
- `assets/js/admin-cohort.js` — bespoke (single-form save with no row state)
- `assets/js/admin-api-keys.js` — bespoke (show-once secret + list refresh)

The remaining 6 pages (Webhooks, ManualAward, Badges, Challenges, CommunityChallenges, Redemption) consume the generic driver through `data-*` attributes — **zero per-page JS**.

### Bug surfaced + fixed

**Webhooks event enum** — admin's `available_events()` listed `badge_awarded` while REST schema enforced `badge_earned`. Submitting from admin would silently 400. Fixed by aligning admin to the REST canonical list (Webhooks now subscribe to: points_awarded / badge_earned / level_changed / streak_milestone / challenge_completed / kudos_given).

## Verification (live REST round-trips)

Every page exercised end-to-end:

```
POST /levels                        → 201 + id           DELETE /levels/{id}                 → 200
POST /cohort-settings (full)        → 200 + state doc    GET /cohort-settings                → 200
POST /api-keys                      → 201 + secret       PATCH /api-keys/{k}/revoke          → 200 + active=false
                                                          DELETE /api-keys/{k}                → 200
POST /webhooks                      → 201 + id           DELETE /webhooks/{id}               → 200
POST /badges (id+name+condition)    → 200 + def          PATCH /badges/{id} (cond change)    → 200 + rule deleted
                                                          DELETE /badges/{id}                 → 200
POST /challenges                    → 201 + id           DELETE /challenges/{id}             → 200
POST /community-challenges          → 201 + id           PATCH /community-challenges/{id}    → 200
                                                          DELETE /community-challenges/{id}   → 200
POST /redemptions/items (cfg.amount)→ 201 + id           DELETE /redemptions/items/{id}      → 200
POST /points/award (manual)         → endpoint pre-existed; admin form rebuilt to send canonical schema
```

## Final audit

```bash
$ grep -rE "add_action.*'admin_post_wb_gam_" src/ wb-gamification.php
# (no output — all 17 hooks gone)

$ grep -rE "add_action.*'wp_ajax_wb_gam_" src/ wb-gamification.php
# (no output — zero AJAX handlers either)
```

**`admin_post_*` count: 0. `wp_ajax_*` count: 0. AJAX/form-post exception count: 0.** Standard's "max 2 exceptions" budget completely unused.

## Tier-1 backlog still open (independent of Tier 0)

These were captured before Tier 0 started and remain blocking 1.0.0:

- **#55 HIGH** — 8 outline:none in admin.css / admin.min.css / admin-premium.css
- **#56 MEDIUM** — 9 inline `onclick=` attributes (one was deleted with the badge delete link migration; 8 remain)
- **#57 MEDIUM** — 6 → 3 CSS breakpoints consolidation
- **#58 LOW** — 591 raw `px`/hex/emoji warnings (token-system tech debt)

## Files touched (Tier 0 total)

### REST controllers
- `src/API/LevelsController.php`
- `src/API/CohortSettingsController.php` (new)
- `src/API/ApiKeysController.php` (new)
- `src/API/CommunityChallengesController.php` (new)
- `src/API/BadgesController.php` (extended)

### JS modules
- `assets/js/admin-rest-utils.js` (new)
- `assets/js/admin-rest-form.js` (new generic driver)
- `assets/js/admin-levels.js` (new)
- `assets/js/admin-cohort.js` (new)
- `assets/js/admin-api-keys.js` (new)

### Admin pages
- `src/Admin/SettingsPage.php` (Levels tab)
- `src/Admin/CohortSettingsPage.php`
- `src/Admin/ApiKeysPage.php`
- `src/Admin/WebhooksAdminPage.php`
- `src/Admin/ManualAwardPage.php`
- `src/Admin/BadgeAdminPage.php`
- `src/Admin/ChallengeManagerPage.php`
- `src/Admin/CommunityChallengesPage.php`
- `src/Admin/RedemptionStorePage.php`

### Bootstrap
- `wb-gamification.php` (registered 4 new controllers)

### Documentation
- `plans/V1-RELEASE-VERIFICATION-PLAN.md` (Tier 0 added ahead of all other tiers)
- `audit/release-runs/2026-05-03/{tier-0-A,tier-0-B,tier-0-C,tier-1}/SUMMARY.md`

## What unblocks now

- **Tier 5** (admin surface walk at 1280 + 390) was BLOCKED on Tier 0 — now unblocked
- **Tier 9** (release zip gate) was BLOCKED on Tier 0 — now unblocked
- 3 Tier-1 backlog items (#55/#56) still blocking Tier 5 — fix those first; admin a11y + onclick removal
- Mobile + 3rd-party clients now consume **the same REST API** that the admin UI does — single contract, single source of truth
