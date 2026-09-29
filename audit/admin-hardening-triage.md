# Admin and write-path hardening triage - wb-gamification

Card 10061741479. Run 2026-09-27 on branch `1.6.5` against buddynext.local. Every row below comes from
the live code, not from memory; re-run the two commands at the bottom to regenerate it.

## Verdict

No unguarded save path. Every classic-POST handler checks a nonce **and** a capability, and every REST
write route refuses a logged-out visitor. The wppqa admin-eval flags (30 on the 2026-09-27 baseline)
are the SettingsPage dispatcher and REST-driven forms, both false positives, annotated in
`audit/wppqa-baseline-2026-09-27/SUMMARY.md`.

Two real findings came out of this pass and went to their own card (10344274129):

- `POST /events` let any member fire any registered action for themselves (self-award). Fixed in 1.6.5.
- `MemberUploadCap` grants `upload_files` on any `upload-attachment` AJAX call, not only the
  submission editor's. Kept: WordPress still requires its media nonce, and scoping it further adds a
  token for a small risk (owner rule: no new machinery for small risks).

## 1. Classic POST / REQUEST readers (all of them)

| File | Reads | Guard | Verdict |
|---|---:|---|---|
| `src/Admin/SettingsPage.php` | 62 | `handle_save()`: `check_admin_referer( 'wb_gam_save_settings' )` (:83) + `manage_options` (:85) before dispatching to every tab's save method | dispatcher-covered |
| `src/Admin/SetupWizard.php` | 3 | submission: `check_admin_referer()` (:326) + `manage_options` (:277); notice dismissal: `wp_verify_nonce()` (:246) + `manage_options` (:250), per-user meta | guarded |
| `src/Admin/DeactivationFeedback.php` | 4 | `check_ajax_referer()` (:153) + `deactivate_plugin` (:154) | guarded |
| `src/Engine/MemberUploadCap.php` | 1 | read-only routing check on `action` (:136); writes nothing | not a save path - see finding 2 above |

Every other admin screen writes through REST (`admin-rest-form.js`, `X-WP-Nonce`).

## 2. REST write routes (all 64 non-GET handlers)

`guest` = permission callback called with no user; `member` = a subscriber (any non-admin; pass an id to choose).

| Route | Methods | Permission callback | guest | member |
|---|---|---|---|---|
| `/wb-gamification/v1/members/(?P<id>[\d]+)/streak` | POST | `MembersController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/members/(?P<id>[\d]+)/streak` | DELETE | `MembersController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/members/me/profile-visibility` | POST | `MembersController::logged_in_permissions_check` | refused | ALLOWED |
| `/wb-gamification/v1/members/(?P<id>[\d]+)/exclude` | POST | `MembersController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/members/(?P<id>[\d]+)/reset-points` | POST | `MembersController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/points/award` | POST | `PointsController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/points/bulk` | POST | `PointsController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/points/(?P<id>[\d]+)` | DELETE | `PointsController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-types` | POST | `PointTypesController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-types/(?P<slug>[a-z0-9_-]+)` | POST,PUT,PATCH | `PointTypesController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-types/(?P<slug>[a-z0-9_-]+)` | DELETE | `PointTypesController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-type-conversions` | POST | `PointTypeConversionsController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-type-conversions/(?P<id>[\d]+)` | POST,PUT,PATCH | `PointTypeConversionsController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-type-conversions/(?P<id>[\d]+)` | DELETE | `PointTypeConversionsController::admin_permission_check` | refused | refused |
| `/wb-gamification/v1/point-types/(?P<from>[a-z0-9_-]+)/convert` | POST | `PointTypeConversionsController::authenticated_permission_check` | refused | ALLOWED |
| `/wb-gamification/v1/badges` | POST | `BadgesController::admin_check` | refused | refused |
| `/wb-gamification/v1/badges/(?P<id>[a-z0-9_-]+)` | POST,PUT,PATCH | `BadgesController::admin_check` | refused | refused |
| `/wb-gamification/v1/badges/(?P<id>[a-z0-9_-]+)` | DELETE | `BadgesController::admin_check` | refused | refused |
| `/wb-gamification/v1/badges/(?P<id>[a-z0-9_-]+)/award` | POST | `BadgesController::award_permissions_check` | refused | refused |
| `/wb-gamification/v1/actions/(?P<id>[a-z0-9_]+)/overrides` | POST,PUT | `ActionsController::overrides_permissions_check` | refused | refused |
| `/wb-gamification/v1/actions/(?P<id>[a-z0-9_]+)/overrides` | DELETE | `ActionsController::overrides_permissions_check` | refused | refused |
| `/wb-gamification/v1/kudos` | POST | `KudosController::create_item_permissions_check` | refused | ALLOWED |
| `/wb-gamification/v1/kudos/(?P<id>[\d]+)` | DELETE | `KudosController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/badges/(?P<badge_id>[a-z0-9_-]+)/share` | POST | `is_user_logged_in` | refused | ALLOWED |
| `/wb-gamification/v1/badges/(?P<badge_id>[a-z0-9_-]+)/share` | DELETE | `is_user_logged_in` | refused | ALLOWED |
| `/wb-gamification/v1/challenges` | POST | `ChallengesController::admin_check` | refused | refused |
| `/wb-gamification/v1/challenges/(?P<id>[\d]+)` | POST,PUT,PATCH | `ChallengesController::admin_check` | refused | refused |
| `/wb-gamification/v1/challenges/(?P<id>[\d]+)` | DELETE | `ChallengesController::admin_check` | refused | refused |
| `/wb-gamification/v1/events` | POST | `EventsController::manage_members_permissions_check` | refused | refused |
| `/wb-gamification/v1/events/import` | POST | `EventsController::manage_members_permissions_check` | refused | refused |
| `/wb-gamification/v1/import/(?P<source>[a-z]+)` | POST | `ImportController::permissions` | refused | refused |
| `/wb-gamification/v1/webhooks` | POST | `WebhooksController::admin_check` | refused | refused |
| `/wb-gamification/v1/webhooks/(?P<id>[\d]+)` | POST,PUT,PATCH | `WebhooksController::admin_check` | refused | refused |
| `/wb-gamification/v1/webhooks/(?P<id>[\d]+)` | DELETE | `WebhooksController::admin_check` | refused | refused |
| `/wb-gamification/v1/webhooks/(?P<id>[\d]+)/log` | DELETE | `WebhooksController::admin_check` | refused | refused |
| `/wb-gamification/v1/rules` | POST | `RulesController::admin_check` | refused | refused |
| `/wb-gamification/v1/rules/(?P<id>[\d]+)` | POST,PUT,PATCH | `RulesController::admin_check` | refused | refused |
| `/wb-gamification/v1/rules/(?P<id>[\d]+)` | DELETE | `RulesController::admin_check` | refused | refused |
| `/wb-gamification/v1/redemptions/items` | POST | `RedemptionController::admin_check` | refused | refused |
| `/wb-gamification/v1/redemptions/items/(?P<id>[\d]+)` | POST,PUT,PATCH | `RedemptionController::admin_check` | refused | refused |
| `/wb-gamification/v1/redemptions/items/(?P<id>[\d]+)` | DELETE | `RedemptionController::admin_check` | refused | refused |
| `/wb-gamification/v1/redemptions` | POST | `RedemptionController::require_logged_in` | refused | ALLOWED |
| `/wb-gamification/v1/redemptions/(?P<id>[\d]+)/fulfill` | POST | `RedemptionController::admin_check` | refused | refused |
| `/wb-gamification/v1/redemptions/(?P<id>[\d]+)/refund` | POST | `RedemptionController::admin_check` | refused | refused |
| `/wb-gamification/v1/levels` | POST | `LevelsController::admin_check` | refused | refused |
| `/wb-gamification/v1/levels/(?P<id>\d+)` | POST,PUT,PATCH | `LevelsController::admin_check` | refused | refused |
| `/wb-gamification/v1/levels/(?P<id>\d+)` | DELETE | `LevelsController::admin_check` | refused | refused |
| `/wb-gamification/v1/settings/capabilities` | POST,PUT,PATCH | `CapabilitiesController::admin_check` | refused | refused |
| `/wb-gamification/v1/api-keys` | POST | `ApiKeysController::admin_check` | refused | refused |
| `/wb-gamification/v1/api-keys/(?P<id>[\d]+)/revoke` | POST,PUT,PATCH | `ApiKeysController::admin_check` | refused | refused |
| `/wb-gamification/v1/api-keys/(?P<id>[\d]+)` | DELETE | `ApiKeysController::admin_check` | refused | refused |
| `/wb-gamification/v1/cohort-settings` | POST | `CohortSettingsController::admin_check` | refused | refused |
| `/wb-gamification/v1/community-challenges` | POST | `CommunityChallengesController::admin_check` | refused | refused |
| `/wb-gamification/v1/community-challenges/(?P<id>\d+)` | POST,PUT,PATCH | `CommunityChallengesController::admin_check` | refused | refused |
| `/wb-gamification/v1/community-challenges/(?P<id>\d+)` | DELETE | `CommunityChallengesController::admin_check` | refused | refused |
| `/wb-gamification/v1/settings/emails` | POST | `EmailSettingsController::admin_check` | refused | refused |
| `/wb-gamification/v1/tools/recompute-leaderboard` | POST | `ToolsController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/tools/reset-progress` | POST | `ToolsController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/tools/retry-side-effect/(?P<id>[\d]+)` | POST | `ToolsController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/tools/import-settings` | POST | `ToolsController::admin_permissions_check` | refused | refused |
| `/wb-gamification/v1/submissions` | POST | `SubmissionsController::logged_in_check` | refused | ALLOWED |
| `/wb-gamification/v1/submissions/(?P<id>[\d]+)/approve` | POST | `SubmissionsController::admin_check` | refused | refused |
| `/wb-gamification/v1/submissions/(?P<id>[\d]+)/reject` | POST | `SubmissionsController::admin_check` | refused | refused |

63 write handlers: 0 allowed for guests. The 7 member-allowed rows are member self-actions (kudos,
redemption, badge share, submissions, own profile visibility, point conversion), all on the caller's
own account. Fixed in 1.6.5 (card 10344274129): `POST /events` now needs `wb_gam_manage_members`,
and `POST /challenges/{id}/complete` was retired.

## 3. The rest of the card

| Item | State | Evidence |
|---|---|---|
| Notices dismissible and staying dismissed | Done | Welcome notice has a real "No thanks": nonced GET + `manage_options` + per-user meta `wb_gam_dismissed_wizard_notice` (SetupWizard.php:57-64, 4d7d869). Settings save notices print once and do not replay (1.6.5, 5ba15b2 / 0ce2593). |
| Spinner affordance | Already shipped | `.wbgam-btn--loading` + `@keyframes wbgam-spin`, token `--wbgam-btn-spinner-size` (assets/css/admin/components.css); REST forms disable submit while in flight (admin-rest-form.js). |
| Regression gate | Already shipped | `bin/coding-rules-check.sh` Rule 14: raw `$_POST` into `update_option()` outside a nonce-verified handler fails the build. |
| Dead `admin-settings.js` (July follow-up) | Gone | File no longer exists. |

## Regenerate

```
grep -rln '\$_POST\|\$_REQUEST' src --include='*.php'
wp eval-file bin/audit-rest-write-permissions.php   # prints section 2's rows
```
