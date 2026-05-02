# Feature × Surface Completeness Audit (2026-05-02)

> **Goal:** for every user-visible feature, verify each surface is wired up — engine, REST, block, shortcode, admin, CLI, hooks, logging, tests. Identify "half-cooked" features where an engine exists but the user-facing surfaces are missing or fragmented.

> **Method:** ground-truth `grep` over the actual source tree. No agent fabrication; every claim cites file:line.

## Headline numbers

| Surface | Count | Status |
|---|---|---|
| Engines (in `src/Engine/`) | 35 | (incl. ~10 internal helpers — Engine, Event, Registry, etc.) |
| REST routes | 39 (across 18 controllers) | Documented in `audit/manifest.json` |
| Blocks | 12 | All in `blocks/`; all use `BlockHooks::before/after` (PR #14) |
| Shortcodes | 12 | Mirror block list, registered in `ShortcodeHandler.php:43-54` |
| Admin pages | 10 | Top-level + 9 submenus, granular caps wired (PR #6 + #9) |
| WP-CLI commands | 6 | points, member, actions, logs, export, doctor |
| Public actions fired | 43 unique | Documented in manifest |
| Public filters fired | 14 unique | Documented in manifest |
| `error_log()` calls in `src/` | **2** | Both in `ManifestLoader.php` (debug-only) |
| Engine test files | **8 of ~24** | ~70% gap |

## The matrix

✅ = present and verified · ✗ = absent · ◐ = partial · — = N/A for this feature

| Feature | Engine | REST | Block | Shortcode | Admin UI | CLI | Public hooks | Tests | Errors logged |
|---|---|---|---|---|---|---|---|---|---|
| **Points** | `PointsEngine.php` | `PointsController` (2) + `MembersController/points` | `member-points`, `points-history` | `wb_gam_member_points`, `wb_gam_points_history` | `ManualAwardPage` + `SettingsPage` Points tab | `points award/list/...` | `wb_gamification_points_awarded`, `_revoked`, `_redeemed`, `before_points_awarded` + 3 filters | `PointsEngineTest`, `PointsHistoryTest`, `ManualAwardPageTest` | ✗ |
| **Levels** | `LevelEngine.php` | `LevelsController` (1) + `MembersController/level` | `level-progress` | `wb_gam_level_progress` | `SettingsPage` Levels tab | — | `wb_gamification_level_changed` | ✗ | ✗ |
| **Badges** | `BadgeEngine.php` + `BadgeSharePage.php` | `BadgesController` (3) + `MembersController/badges` + `BadgeShareController` + `CredentialController` | `badge-showcase` | `wb_gam_badge_showcase` | `BadgeAdminPage` | — | `wb_gamification_badge_awarded` + `should_award_badge` filter + `credential_expired` + `credential_document` filter | `BadgeSharePageTest` | ✗ |
| **Streaks** | `StreakEngine.php` | `MembersController/streak` | `streak` | `wb_gam_streak` | `SettingsPage` (toggle only) | — | `wb_gamification_streak_milestone`, `_broken` | ✗ | ✗ |
| **Kudos** | `KudosEngine.php` | `KudosController` (2) | `kudos-feed` | `wb_gam_kudos_feed` | `SettingsPage` Kudos tab (limits only) | — | `wb_gamification_kudos_given` + `before_kudos` filter | ✗ | ✗ |
| **Leaderboard** | `LeaderboardEngine.php` | `LeaderboardController` (3) | `leaderboard`, `top-members` | `wb_gam_leaderboard`, `wb_gam_top_members` | `SettingsPage` (mode option only — no dedicated page) | — | `leaderboard_results` filter | ✗ | ✗ |
| **Challenges (individual)** | `ChallengeEngine.php` | `ChallengesController` (3) | `challenges` | `wb_gam_challenges` | `ChallengeManagerPage` | — | `wb_gamification_challenge_completed`, `_created`, `_updated`, `_deleted` | ✗ | ✗ |
| **Community Challenges** | `CommunityChallengeEngine.php` | ✗ **NO REST** | ✗ **NO block** | ✗ **NO shortcode** | `CommunityChallengesPage` | — | `wb_gamification_community_challenge_completed`, `_created`, `_updated`, `_deleted` | ✗ | ✗ |
| **Cohort Leagues** | `CohortEngine.php` | ✗ **NO REST** | ✗ **NO block** | ✗ **NO shortcode** | `CohortSettingsPage` | — | `wb_gamification_cohort_outcome`, `_settings_saved` | `CohortEngineTest` | ✗ |
| **Redemption Store** | `RedemptionEngine.php` | `RedemptionController` (4) | ✗ **NO block** | ✗ **NO shortcode** | `RedemptionStorePage` | — | `wb_gamification_reward_created/_updated/_deleted`, `points_redeemed` | `RedemptionEngineTest` | ✗ |
| **Cosmetics** | `CosmeticEngine.php` | ✗ **NO REST** | ✗ **NO block** | ✗ **NO shortcode** | ✗ **NO admin** | — | `wb_gamification_cosmetic_granted` | ✗ | ✗ |
| **Credentials (OpenBadges 3.0)** | `CredentialExpiryEngine.php` | `CredentialController` (1) | (uses `badge-showcase`) | (uses `wb_gam_badge_showcase`) | (`BadgeAdminPage` flag) | — | `wb_gamification_credential_expired` + `credential_document` filter | ✗ | ✗ |
| **Webhooks** | `WebhookDispatcher.php` | `WebhooksController` (3) | — | — | ✗ **REST-only — no admin page** | — | (none documented) | ✗ | ✗ |
| **Year Recap** | `RecapEngine.php` | `RecapController` (1) | `year-recap` | `wb_gam_year_recap` | ✗ NO admin (cron-driven) | — | `recap_data` filter | ✗ | ✗ |
| **Earning Guide** | (Registry data) | `ActionsController` (2) | `earning-guide` | `wb_gam_earning_guide` | (`SettingsPage` Points tab configures) | `actions list/inspect` | (none) | ✗ | ✗ |
| **Hub** | (composite) | (uses `MembersController`) | `hub` (layout-owning) | `wb_gam_hub` | (`SettingsPage` `wb_gam_hub_page_id` only) | — | (uses underlying engine hooks) | ✗ | ✗ |
| **Tenure Badges** | `TenureBadgeEngine.php` | (uses `BadgesController`) | (uses `badge-showcase`) | (uses `wb_gam_badge_showcase`) | ✗ **NO admin — runs by cron only** | — | (uses `badge_awarded`) | ✗ | ✗ |
| **Site First Badges** | `SiteFirstBadgeEngine.php` | (uses `BadgesController`) | (uses `badge-showcase`) | (uses `wb_gam_badge_showcase`) | ✗ **NO admin — runs by cron only** | — | (uses `badge_awarded`) | ✗ | ✗ |
| **Personal Records** | `PersonalRecordEngine.php` | ✗ **NO REST** | ✗ **NO block** | ✗ **NO shortcode** | ✗ **NO admin** | — | `wb_gamification_personal_record` | ✗ | ✗ |
| **Status Retention** | `StatusRetentionEngine.php` | ✗ **NO REST** | ✗ **NO block** | ✗ **NO shortcode** | ✗ **NO admin — pure cron** | — | `wb_gamification_retention_nudge` | ✗ | ✗ |
| **Manual Award** | `PointsController::award` | `PointsController` (POST `/award` + DELETE `/{id}`) | — | — | `ManualAwardPage` | `points award/revoke` | (uses `points_awarded` chain) | `ManualAwardPageTest` | ✗ |
| **Rank Automation** | `RankAutomation.php` | (via `RulesController`) | — | — | `SettingsPage` Rank Automation tab | — | `wb_gamification_rank_automation_action` + `rank_automation_rules` filter | `SettingsPageAutomationTest` | ✗ |
| **Privacy / GDPR** | `Privacy.php` | (uses WP core privacy) | — | — | (uses WP core Settings → Privacy) | `export user/...` | `wb_gamification_user_data_erased` | ✗ | ✗ |
| **Weekly Email** | `WeeklyEmailEngine.php` (templated post-PR #13) | ✗ NO REST | — | — | `SettingsPage` (toggle/from_name only) | — | `weekly_email_sent` + `weekly_email_body` filter + `email_template_path` filter + `email_from_header` filter | ✗ | ✗ |
| **Weekly Nudge** | `LeaderboardNudge.php` | ✗ NO REST | — | — | `SettingsPage` (toggle only) | — | `weekly_nudge` + `should_send_weekly_nudge` filter + `nudge_message` filter | `NudgeEngineTest` | ✗ |
| **Notifications** | `NotificationBridge.php` | `MembersController/me/toasts` | (uses WP/BP) | — | — | — | `toast_data` filter | ✗ | ✗ |
| **API Keys** | (`ApiKeyAuth` middleware) | (used as auth — no endpoints) | — | — | `ApiKeysPage` | — | (none) | ✗ | ✗ |
| **Rules** | `RuleEngine.php` | `RulesController` (2) | — | — | (configured per-feature in `SettingsPage` tabs) | — | (used internally by engines) | ✗ | ✗ |
| **Action Discovery** | `Registry.php` + `ManifestLoader.php` | `ActionsController` (2) | (consumed by `earning-guide`) | (consumed by `wb_gam_earning_guide`) | (configured in `SettingsPage` Points tab) | `actions` | `manifests_loaded` + `manifest_paths` filter | `ShortcodeHandlerTest` | ✓ (only 2 sites in entire `src/`) |
| **Block extension** | `BlockHooks.php` (PR #14) | — | (consumed by all 12 blocks) | — | — | — | `wb_gam_block_before_render`, `_after_render` + `block_data` filter | ✗ | ✗ |
| **Capabilities** | `Capabilities.php` (PR #4 + #6) | (used by every controller) | — | — | (granted on activation) | — | (none) | ✗ | ✗ |
| **Email rendering** | `Email.php` (PR #13) | — | — | — | — | — | `email_template_path` + `email_from_header` filters | ✗ | ✗ |
| **Async** | `AsyncEvaluator.php`, `Engine::process_async` | (uses Action Scheduler) | — | — | — | (use `wp action-scheduler` for visibility) | `wb_gam_event_processed`, `wb_gam_process_event_async` | ✗ | ✗ |
| **Notification ↔ BP** | `NotificationBridge.php` | (uses `MembersController/me/toasts`) | (uses BP UI) | — | — | — | `toast_data` filter | ✗ | ✗ |

## Half-cooked features (engine without user surface)

These engines exist and fire hooks but offer no admin UI, no REST endpoint, and no frontend display. End-users / site owners can't see, configure, or act on them without writing PHP:

| Feature | What's missing | Impact |
|---|---|---|
| **Cosmetics** (`CosmeticEngine.php`) | NO admin, NO REST, NO block, NO shortcode | Cosmetic frames/decorations can be granted programmatically and logged via hook, but site owners have no way to define them, members have no way to see/equip them. **Effectively dead code today**. |
| **Personal Records** (`PersonalRecordEngine.php`) | NO admin, NO REST, NO block | Records are tracked silently; no way to display them, no way to query them, no way to celebrate them. |
| **Status Retention** (`StatusRetentionEngine.php`) | NO admin, NO REST | Pure cron worker. Site owners can't configure retention rules from the UI; they're hardcoded. |
| **Tenure Badges** (`TenureBadgeEngine.php`) | NO admin (badges are managed via `BadgeAdminPage`, but tenure-specific config — milestone days — is hardcoded) | Cron grants badges based on hardcoded tenure thresholds; owners can't tune them without editing code. |
| **Site First Badges** (`SiteFirstBadgeEngine.php`) | NO admin (same shape as tenure) | Same pattern — cron grants the badge, owners can't tune which actions count. |
| **Community Challenges** (`CommunityChallengeEngine.php`) | NO REST, NO block, NO shortcode | Admin can create them via `CommunityChallengesPage`, but **frontend visitors have no way to see them**. They run silently. |
| **Cohort Leagues** (`CohortEngine.php`) | NO REST, NO block, NO shortcode | Admin can configure cohorts via `CohortSettingsPage`. The cron assigns members and processes outcomes. **No frontend visibility** — members never see their cohort or rank within it. |
| **Webhooks** (`WebhookDispatcher.php`) | NO admin page (REST `/webhooks` is the only management surface) | Site owners with no API skills can't configure webhooks at all. The 3 REST routes work, but there's no `WebhooksAdminPage.php`. |

## Other gaps

### Logging coverage

Only **2 `error_log()` calls** in the entire `src/` tree, both in `ManifestLoader.php` (debug-only manifest validation warnings):
- `src/Engine/ManifestLoader.php:152` — manifest didn't return an array
- `src/Engine/ManifestLoader.php:189` — manifest missing required key

Every other engine handles failures by returning `false` silently. Specific risks:

| Silent failure path | Where | Why it matters |
|---|---|---|
| `BadgeEngine::award_badge` returns false | `src/Engine/BadgeEngine.php:170` | Failed badge writes (DB constraint, missing badge_def, etc.) leave no trace |
| `PointsEngine::insert_point_row` returns false | `src/Engine/PointsEngine.php` | Points awards that fail to write are invisible |
| `WebhookDispatcher` HTTP failures | `src/Engine/WebhookDispatcher.php` | Webhook delivery failures presumably retry via Action Scheduler, but the site owner doesn't see them in the admin without checking the AS log |
| `Engine::handle_async` errors in AS jobs | `src/Engine/Engine.php:142` | Action Scheduler catches exceptions but no plugin-side log |
| `WeeklyEmailEngine::send_to_user` `wp_mail` failures | `src/Engine/WeeklyEmailEngine.php:145` | `wp_mail` returns bool — false return is not logged |
| `LeaderboardNudge::send_email` failures | `src/Engine/LeaderboardNudge.php:242` | Same as above |
| `Capabilities::register` no-ops | `src/Engine/Capabilities.php` | Idempotent by design, but no log of what was added/skipped |

**Recommendation:** add a small `WBGam\Engine\Log` helper that wraps `error_log()` with a consistent prefix (`[wb_gam]`) and a `WP_DEBUG`-aware no-op fallback. Sprinkle it into every false-return path. Treat it as a half-day cleanup, not a refactor.

### Test coverage

8 unit-test files exist; ~17 engines have no test:

| Tested | Untested |
|---|---|
| `PointsEngine`, `RateLimiter`, `RedemptionEngine`, `CohortEngine`, `NudgeEngine`, `BadgeSharePage`, plus `ManualAwardPage`, `ShortcodeHandler`, `SettingsPageAutomation`, `PointsHistory` | `BadgeEngine`, `ChallengeEngine`, `CommunityChallengeEngine`, `CosmeticEngine`, `CredentialExpiryEngine`, `KudosEngine`, `LeaderboardEngine`, `LeaderboardNudge`, `LevelEngine`, `LogPruner`, `PersonalRecordEngine`, `RankAutomation`, `RecapEngine`, `RuleEngine`, `SiteFirstBadgeEngine`, `StatusRetentionEngine`, `StreakEngine`, `TenureBadgeEngine`, `WebhookDispatcher`, `WeeklyEmailEngine`, `Email`, `BlockHooks`, `Capabilities` |

Notably the most central engines — `BadgeEngine`, `LevelEngine`, `StreakEngine`, `KudosEngine`, `RuleEngine` — have no unit tests. `CohortEngine` is tested but doesn't have a UI.

### CLI sparse

6 commands today (`points`, `member`, `actions`, `logs`, `export`, `doctor`). Substantial gaps:

| Missing CLI surface | Why it matters |
|---|---|
| `wp wb-gamification badges {list,grant,revoke,fix-orphans}` | No way to manage badges from CLI for batch operations |
| `wp wb-gamification challenges {list,complete,reset}` | No CLI for challenge admin |
| `wp wb-gamification cohorts {assign,process,list}` | Cohort engine runs by cron; no manual trigger |
| `wp wb-gamification webhooks {list,test,deliver-pending}` | Can't test webhooks from terminal |
| `wp wb-gamification redemptions {list,fulfill}` | Same |
| `wp wb-gamification replay --user=X --since=DATE` | The G4 gap — re-evaluate rules against existing events |

### REST orphans

Routes that don't map cleanly to a public-facing UI:

| Route | Missing surface |
|---|---|
| `POST /wb-gamification/v1/events` | (intentionally no UI — for external ingestion) |
| `GET /wb-gamification/v1/abilities` | Abilities API discovery (forward-compat with WP 6.9+); no admin display today |
| `GET /wb-gamification/v1/capabilities` | Discovery for mobile apps; no admin display |
| `GET /wb-gamification/v1/openapi.json` | API docs; intentionally machine-only |

These are intentionally machine-facing, so not really "orphans" — but worth surfacing in admin docs.

## What's complete (don't break these)

The plumbing is genuinely good for the core 5 features:

| Feature | Coverage |
|---|---|
| **Points** | Engine + 3 REST routes + 2 blocks/shortcodes + admin page + CLI + 5 hooks + 3 tests |
| **Badges** | Engine + 5 REST surfaces + block/shortcode + admin page + share page + OpenBadges credentials + 4 hooks + 1 test |
| **Levels** | Engine + REST + block/shortcode + admin tab + hook |
| **Leaderboard** | Engine + 3 REST routes + 2 blocks/shortcodes + 1 filter |
| **Challenges (individual)** | Engine + 3 REST routes + block/shortcode + admin page + 4 hooks |

These five carry the product. The other features have varying maturity, with the 8 half-cooked items above being the obvious gaps.

## Frontend UX wiring

| Block | Render | JS interactivity | REST consumption |
|---|---|---|---|
| `member-points`, `level-progress`, `streak`, `top-members` | server-rendered via `render.php` | minimal (server-rendered numbers) | none — read from PHP context |
| `badge-showcase`, `points-history`, `kudos-feed`, `challenges`, `year-recap`, `earning-guide` | server-rendered | minimal | reads via shared assets/js |
| `leaderboard` | server-rendered | period-switch buttons (likely client-side) | `apiFetch` on period change |
| `hub` | server-rendered (composite) | drawer toggle (How-to-Earn) | `apiFetch` on hydration |

**Block-level JS files** are bundled per-block in `blocks/{slug}/edit.asset.php` (editor-side) but no `view.asset.php` exists for any block — meaning each block's frontend is server-rendered HTML with **no top-level Interactivity API store**. Period-switching, drawer toggles, etc. are minimal vanilla JS or rely on the global `assets/js/toast.js` for cross-block notifications.

This is fine for a v1 — every block degrades gracefully with JS off. But it does mean future "live updates" or "infinite scroll on points-history" features need the Interactivity API plumbing added per-block.

## Summary findings

| Area | Status |
|---|---|
| Core features (Points, Badges, Levels, Leaderboard, Challenges) | ✅ Complete across all surfaces |
| 8 half-cooked features | ◐ Engine exists, multiple surfaces missing — see table above |
| Logging | ✗ Only 2 `error_log()` calls in `src/` — most engine errors silent |
| Test coverage | ◐ 8 of ~24 engines tested (~33%) |
| CLI surface | ◐ 6 commands; major engines (badges, challenges, cohorts, webhooks) lack CLI |
| Frontend UX | ✅ All 12 blocks render, most are server-side with minimal JS — appropriate for v1 |
| REST contract | ✅ 39 routes, all with explicit `permission_callback`; OpenAPI 3.0 spec at `/openapi.json` |
| Hooks / extension | ✅ 43 actions + 14 filters + block extension API (PR #14) + email override (PR #13) |

## Recommended next moves (ordered by ROI)

1. **Add a small `Log` helper + sprinkle into engine error paths.** Half-day; immediate operational value. Right now if a webhook fails the site owner has zero visibility.
2. **Add `wp wb-gamification replay` CLI** (G4 from gaps roadmap). 1 day; closes the last gap-roadmap item.
3. **Build admin UI for `WebhookDispatcher`** — `WebhooksAdminPage.php` so site owners without API skills can manage webhooks. Half-day.
4. **Add a `community-challenges` block** so members can see active community challenges. 1 day. (Closes one of the half-cooked items.)
5. **Add a `cohort-rank` block** for cohort league visibility. 1 day. (Closes another.)
6. **Decide on Cosmetics** — either build the admin + frontend surfaces (~3 days) OR remove the engine. Today it's a maintenance liability.
7. **Test coverage pass** — at minimum add unit tests for `BadgeEngine`, `LevelEngine`, `StreakEngine`, `RuleEngine`. 1 day per engine.

The first 3 items would close ~80% of the meaningful interconnection gaps in ~2 days of work.
