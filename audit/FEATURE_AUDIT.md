# WB Gamification — Feature Audit Report

**Generated**: 2026-05-06 (v1.0.0 release-candidate refresh)
**Version**: 1.0.0
**Source**: [`audit/manifest.json`](manifest.json)
**Counts**: 65 REST endpoints · 0 AJAX handlers · 13 admin pages · 17 blocks · 15 shortcodes · 22 DB tables · 9 cron hooks · 10 WP-CLI commands · 85 fired hooks (54 actions + 31 filters)

---

## v1.0.0 release sprint additions (2026-05-06)

Closes the four critical v1.0 gaps from `plan/v1.0-release-plan.md`. Inventory deltas vs the 2026-05-02 baseline:

| Surface | Added | Files |
|---|---|---|
| Tables | `wb_gam_point_types`, `wb_gam_point_type_conversions`, `wb_gam_user_totals`, `wb_gam_submissions` | `src/Engine/Installer.php`, `src/Engine/DbUpgrader.php` |
| REST endpoints | `/point-types*` (4), `/point-type-conversions*` (4), `/point-types/{from}/convert`, `/settings/emails` (2), `/submissions` (1), `/submissions/{id}/approve`, `/submissions/{id}/reject` | `src/API/PointTypesController.php`, `PointTypeConversionsController.php`, `EmailSettingsController.php`, `SubmissionsController.php` |
| Admin pages | Point Types, Conversions, Submissions | `src/Admin/PointTypesPage.php`, `PointTypeConversionsPage.php`, `SubmissionsPage.php` |
| Blocks | `daily-bonus`, `submit-achievement` (Wbcom Block Quality Standard compliant) | `src/Blocks/daily-bonus/`, `src/Blocks/submit-achievement/` |
| Engines | `TransactionalEmailEngine`, `LoginBonusEngine`, `ProfilePage` | `src/Engine/` |
| Services | `PointTypeService`, `PointTypeConversionService`, `SubmissionService` | `src/Services/` |
| Repositories | `PointTypeRepository`, `PointTypeConversionRepository`, `SubmissionRepository` | `src/Repository/` |
| Email templates | `level-up.php`, `badge-earned.php`, `challenge-completed.php`, `leaderboard-nudge.php` | `templates/emails/` |
| WP-CLI commands | `wb-gamification email-test`, `scale` (seed/benchmark/teardown) | `src/CLI/EmailCommand.php`, `ScaleCommand.php` |
| Public surfaces | `/u/{user_login}` profile pages with privacy gate, OG meta + Schema.org JSON-LD | `src/Engine/ProfilePage.php` |

**Scale hardening also landed** in this sprint: materialised `user_totals`, batched `LogPruner`, batch award API on `PointsEngine`, leaderboard upsert pattern. Hot-path read budgets in `composer scale:bench` all pass under target on a 1M-row dataset.

**wppqa baseline**: `audit/wppqa-baseline-2026-05-07/SUMMARY.md` — failed=0 across `plugin_dev_rules`, `rest_js_contract`, `wiring_completeness`.

---

> **Architecture in one line:** Event-sourced — events in → rules evaluate → effects out. The Engine owns three surfaces: event normalization, rule evaluation, output. Everything else (BuddyPress display, WooCommerce triggers, mobile) is a consumer.

---

## 1. Frontend features

The plugin ships **12 Gutenberg blocks** (each paired with an equivalent shortcode for classic-editor users). All blocks are server-rendered via `render.php`. None use the Interactivity API — they read the REST API directly via fetch on hydration.

| Block | Shortcode | Render | Purpose |
|---|---|---|---|
| `wb-gamification/badge-showcase` | `[wb_gam_badge_showcase]` | `blocks/badge-showcase/render.php` | Display a member's earned badges. |
| `wb-gamification/challenges` | `[wb_gam_challenges]` | `blocks/challenges/render.php` | Active challenges + progress. |
| `wb-gamification/earning-guide` | `[wb_gam_earning_guide]` | `blocks/earning-guide/render.php` | "How to earn points" reference table. |
| `wb-gamification/hub` | `[wb_gam_hub]` | `blocks/hub/render.php` | **Layout-owning** member hub page (full-width). Auto-installed on first activation per `wb_gam_hub_page_id`. |
| `wb-gamification/kudos-feed` | `[wb_gam_kudos_feed]` | `blocks/kudos-feed/render.php` | Recent kudos given/received. |
| `wb-gamification/leaderboard` | `[wb_gam_leaderboard]` | `blocks/leaderboard/render.php` | Ranked member list (period switchable). |
| `wb-gamification/level-progress` | `[wb_gam_level_progress]` | `blocks/level-progress/render.php` | Current member's level + progress to next. |
| `wb-gamification/member-points` | `[wb_gam_member_points]` | `blocks/member-points/render.php` | Member's total points. |
| `wb-gamification/points-history` | `[wb_gam_points_history]` | `blocks/points-history/render.php` | Member's recent point transactions. |
| `wb-gamification/streak` | `[wb_gam_streak]` | `blocks/streak/render.php` | Current streak + longest. |
| `wb-gamification/top-members` | `[wb_gam_top_members]` | `blocks/top-members/render.php` | Top-N members card. |
| `wb-gamification/year-recap` | `[wb_gam_year_recap]` | `blocks/year-recap/render.php` | Year-in-review recap. |

All shortcodes are registered in `src/Engine/ShortcodeHandler.php:43-54`.

## 2. AJAX handlers

_None._ The plugin has no `admin-ajax.php` layer. All interactive admin and frontend traffic goes through the REST API.

## 3. REST endpoints

Namespace: `wb-gamification/v1`. 39 routes across 18 controllers. See `audit/manifest.json#/rest/endpoints` for the full enumerated list with file:line, method, handler and permission for each. Headlines:

| Controller | Endpoints | Auth model |
|---|---|---|
| `MembersController` | 7 | `get_item_permissions_check` (self or admin); `me/toasts` for current user. |
| `RedemptionController` | 4 | Mixed: GET items public, write/admin via `admin_check`; `redeem` + `me` require login. |
| `WebhooksController` | 3 | All routes admin-gated (`admin_check`). |
| `LeaderboardController` | 3 | Public reads; `me` requires login. |
| `ChallengesController` | 3 | Read public; create/update/delete admin; `complete` requires login. |
| `BadgesController` | 3 | Mixed read/write, write admin-gated; `award` via `award_permissions_check`. |
| `RulesController` | 2 | All admin-gated. |
| `PointsController` | 2 | Both admin-gated; revoke also requires admin (with `wb_gam_award_manual` fallback — see § 12). |
| `KudosController` | 2 | Public read; create requires login + cooldown; `me` requires login. |
| `ActionsController` | 2 | Public reads. |
| `RecapController`, `OpenApiController`, `LevelsController`, `EventsController`, `CredentialController`, `CapabilitiesController`, `BadgeShareController`, `AbilitiesRegistration` | 1 each | Public reads + ingestion endpoints. |

Auth modes accepted: cookie + `wp_rest` nonce (default), Application Passwords (WP core), and per-key API keys (`src/API/ApiKeyAuth.php`).

## 4. Admin pages

10 pages — 1 top-level menu (`Gamification`, `manage_options`, dashicon `dashicons-awards`, position 56) and 9 submenus. The Setup Wizard is intentionally hidden from the menu (parent slug `null`).

| Page | Slug | Render | Notes |
|---|---|---|---|
| Gamification (Settings) | `wb-gamification` | `SettingsPage::render` | Top-level. |
| Award Points | `wb-gamification-award` | `ManualAwardPage::render_page` | Manual point award UI. |
| Redemption Store | `wb-gam-redemption` | `RedemptionStorePage::render_page` | Catalog management. |
| Setup Wizard | `wb-gamification-setup` | `SetupWizard::render` | **Hidden** (parent=null); reachable via redirect after activation. |
| Challenges | `wb-gam-challenges` | `ChallengeManagerPage::render_page` | Individual challenges. |
| Community Challenges | `wb-gam-community-challenges` | `CommunityChallengesPage::render_page` | Group challenges. |
| Cohort Leagues | `wb-gam-cohort` | `CohortSettingsPage::render_page` | League configuration. |
| Analytics | `wb-gamification-analytics` | `AnalyticsDashboard::render_page` | KPI dashboard. |
| API Keys | `wb-gam-api-keys` | `ApiKeysPage::render_page` | API key issuance + listing. |
| Badges | `wb-gamification-badges` | `BadgeAdminPage::render_page` | Badge library. |

All pages gated by `manage_options`. See § 12 for the permission-monoculture observation.

## 5. Settings inventory

No `register_setting()` calls — settings are stored as discrete `wp_options` rows with `wb_gam_*` prefix and read directly via `get_option()`. Key options (full list in `audit/manifest.json#/settings`):

| Key | Type | Purpose |
|---|---|---|
| `wb_gam_db_version` | string | Tracks DB schema version for `DbUpgrader` migrations. |
| `wb_gam_wizard_complete` | bool | Setup wizard state. |
| `wb_gam_kudos_daily_limit` | int | Per-user daily kudos cap. |
| `wb_gam_kudos_giver_points` / `_receiver_points` | int | Points awarded on each side of a kudos transaction. |
| `wb_gam_log_retention_months` / `wb_gam_events_retention_months` | int | Pruning windows. |
| `wb_gam_rank_automation_rules` | array | Rules mapping point thresholds → roles. |
| `wb_gam_leaderboard_mode` | string | Default period (daily/weekly/monthly/all-time). |
| `wb_gam_hub_page_id` | int | Auto-created Hub page. |
| `wb_gam_template` | string | Active starter template (community/learning/marketplace/blank). |
| `wb_gam_nudge_email`, `wb_gam_weekly_email_from_name` | mixed | Weekly email config. |
| `wb_gam_enabled_*` | bool | Per-feature kill switch (challenges, kudos, streaks, etc.). |
| `wb_gam_points_*` | int | Per-action point values. |
| `wb_gam_bp_stream_*` | bool | BuddyPress activity-stream toggles. |
| `wb_gam_webhook_log_*` | array | Per-webhook delivery log. |

## 6. Database tables

20 distinct tables, all `{prefix}wb_gam_*`. Core surfaces:

- **Event source of truth**: `wb_gam_events` (immutable, UUID PK)
- **Derived ledger**: `wb_gam_points` (event_id FK)
- **Rules + thresholds**: `wb_gam_rules`, `wb_gam_levels`
- **Badge layer**: `wb_gam_badge_defs`, `wb_gam_user_badges` (with `expires_at` for time-bound credentials)
- **Engagement**: `wb_gam_streaks`, `wb_gam_kudos`, `wb_gam_challenges`, `wb_gam_challenge_log`, `wb_gam_community_challenges`, `wb_gam_community_challenge_contributions`
- **Cohort league**: `wb_gam_cohort_members`
- **Redemption**: `wb_gam_redemptions`, `wb_gam_redemption_items`
- **Cosmetics**: `wb_gam_cosmetics`, `wb_gam_user_cosmetics`
- **Caches**: `wb_gam_leaderboard_cache`
- **Outbound**: `wb_gam_webhooks`
- **User prefs**: `wb_gam_member_prefs`

Schema migrations live in `src/Engine/DbUpgrader.php`; each version gets its own `upgrade_to_X_Y_Z()` method, gated by the `wb_gam_db_version` option.

## 7. Content types (CPTs / taxonomies)

_None._ The plugin uses custom tables exclusively — no CPTs or taxonomies registered.

## 8. JavaScript modules

| File | Purpose |
|---|---|
| `assets/js/admin-badge.js` | Badge admin UI (image picker, criteria builder). |
| `assets/js/admin-settings.js` | Settings page interactivity. |
| `assets/js/settings-nav.js` | Tabbed nav inside Settings. |
| `assets/js/toast.js` | Frontend toast notification dispatcher (consumes `members/me/toasts`). |

Block-level JS lives under each block's directory in `blocks/{slug}/` (typical block bundle). No top-level Interactivity API store; blocks render server-side and re-fetch via `apiFetch` on hydration where needed.

## 9. Email templates

| Engine | Template | Trigger |
|---|---|---|
| `WeeklyEmailEngine` | (inline HTML in engine) | Weekly cron (`wb_gam_weekly_email`). |
| `LeaderboardNudge` | (inline HTML in engine) | Weekly cron (`wb_gam_weekly_nudge`). |

`From-name` configurable via `wb_gam_weekly_email_from_name`. Send-veto via `wb_gamification_should_send_weekly_nudge` filter.

## 10. Cron jobs

9 distinct hooks. Some are scheduled twice in defensive code paths (counted once below — full mapping in manifest):

| Hook | Interval | Handler | Engine |
|---|---|---|---|
| `wb_gam_leaderboard_snapshot` | every 5 min (custom) | `LeaderboardEngine::write_snapshot` | LeaderboardEngine |
| `wb_gam_weekly_nudge` | weekly | `LeaderboardNudge::run` | LeaderboardNudge |
| `wb_gam_weekly_email` | weekly | `WeeklyEmailEngine::run` | WeeklyEmailEngine |
| `wb_gam_cohort_assign` | weekly | `CohortEngine::assign_cohorts` | CohortEngine |
| `wb_gam_cohort_process` | weekly | `CohortEngine::process_outcomes` | CohortEngine |
| `wb_gam_tenure_check` | daily | `TenureBadgeEngine::check_tenure` | TenureBadgeEngine |
| `wb_gam_status_retention_check` | weekly | `StatusRetentionEngine::check` | StatusRetentionEngine |
| `wb_gam_credential_expiry_check` | weekly | `CredentialExpiryEngine::check_expirations` | CredentialExpiryEngine |
| `wb_gam_prune_logs` | daily | `LogPruner::run` | LogPruner |

In addition the plugin runs **async** work via Action Scheduler (bundled in `vendor/woocommerce/action-scheduler/`):
- `wb_gam_process_event_async` — async per-event evaluation (`Engine::handle_async`).
- Webhook delivery (`WebhookDispatcher` enqueues async + scheduled-single actions).
- Community challenge contribution accumulation.

## 11. Integrations

The plugin ships first-party integrations under `integrations/`. Each is a thin manifest of action declarations the engine auto-registers via `ManifestLoader`.

| File | Integration |
|---|---|
| `integrations/wordpress.php` | Core WP events (post publish, comment approve, login). |
| `integrations/buddypress.php` | BP profile/group/activity events. |
| `integrations/woocommerce.php` | Order completion, review left, etc. |
| `integrations/learndash.php` | Course complete, lesson complete, quiz pass. |
| `integrations/bbpress.php` | Topic/reply/forum events. |
| `integrations/contrib/` | Community-contributed manifests (LMS-extras, ACF, Elementor, etc.). |

BuddyPress sub-classes under `src/BuddyPress/` (`ActivityIntegration`, `DirectoryIntegration`, `ProfileIntegration`, `HooksIntegration`) handle render-time UI bridging.

## 12. Custom capabilities

This is the single most important observation in this audit and the chief candidate for a future hardening pass.

| Cap | Status | Details |
|---|---|---|
| `manage_options` (core) | **Used 32 times** as the sole admin gate across all admin pages, REST controllers, and admin-post handlers. | Permission monoculture — only full admins can operate the plugin. No granular plugin caps registered. Future granular caps (e.g. `wb_gam_manage_badges`, `wb_gam_award_points`, `wb_gam_view_analytics`) would let staff/community-managers operate without an admin role. |
| `wb_gam_award_manual` | **Enforced at `src/API/PointsController.php:273` but never declared.** Cap drift. | Used as a secondary gate after `manage_options`. No `add_cap()` / `add_role()` registers it — site owners cannot grant it without an external role-manager plugin or the WP Abilities API. As-is, it's effectively dormant. **Recommendation:** either drop it (rely on `manage_options`) or register it via `add_cap()` so it becomes grantable. |

Capabilities granted by `RankAutomation`: only built-in WordPress roles (`add_role` adds *role assignments* via `$user->add_role(...)`, not new caps).

## Known issues surfaced by audit

These are facts about the current code state, not opinions:

1. **Cap drift** — see § 12. `wb_gam_award_manual` is enforced but unregistered.
2. **Permission monoculture** — 32 `manage_options` gates with no granular plugin caps.
3. **wppqa medium findings** — 8 inline `onclick` attributes in admin pages + `blocks/year-recap/render.php`. Conflicts with the Interactivity API + CSP.
4. **wppqa breakpoint proliferation** — CSS uses 6 distinct breakpoints (390/480/640/782/900/1024px); the project guideline (and `frontend-responsive` Rule 1) is ≤3.
5. **wppqa false positive** — `BadgeAdminPage.php:526` flagged for nonce-without-cap, but `current_user_can('manage_options')` IS called at line 521. Heuristic missed it.
6. **Tap targets** — 16px button height in `assets/css/admin-premium.css:766` and `.min.css:1` (minimum 40px per a11y).

See `audit/wppqa-runs/2026-05-02-baseline/SUMMARY.md` for the full quality baseline.
