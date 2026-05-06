# WB Gamification — Code Flow Maps

**Generated**: 2026-05-06 (v1.0.0 release-candidate refresh)
**Source**: [`audit/manifest.json`](manifest.json)

> **Read this before tracing a bug.** The plugin is event-sourced. The same surface (REST, admin form, BuddyPress event, WC order) all funnel into the same engine pipeline. Most bugs are not in the surface — they are in the rule evaluator, the cron snapshot, or a missing manifest entry.

---

## v1.0.0 release sprint — new flows

### Flow: Multi-currency point award (Phase 1–5 sprint)

```
event in (action_id, user_id, …)
  → Registry::resolve_action_point_type( action_id )       — single source of truth
  → PointsEngine::award( user_id, points, type, action_id )
      → INSERT wb_gam_events (point_type stamped)
      → INSERT wb_gam_points (point_type stamped)
      → UPSERT wb_gam_user_totals (user_id, point_type, total)   — materialised
      → cache invalidate by point_type
      → fire wb_gam_points_awarded
```

`PointsEngine::award_batch()` runs the same flow inside `START TRANSACTION` for bulk award paths.

### Flow: Currency conversion (`/point-types/{from}/convert`)

```
POST /point-types/{from}/convert {to, amount}
  → PointTypeConversionService::convert
      → START TRANSACTION + SELECT … FOR UPDATE on user_totals row
      → DEBIT row in wb_gam_points (negative, point_type = from)
      → CREDIT row in wb_gam_points (positive, point_type = to)
      → both share event_id (linked ledger pair)
      → UPSERT both user_totals rows
      → COMMIT or ROLLBACK
```

### Flow: Login bonus

```
wp_login fires
  → LoginBonusEngine::on_login( $user_login, $user )
      → reads user_meta wb_gam_login_streak / _last_award
      → if today_is_new_day:
           bumps streak (or resets if missed > 1 day)
           tier = LoginBonusEngine::tier_for_day( streak_day )
           PointsEngine::award( … )
           fires wb_gam_login_bonus_claimed
```

State lives in user_meta (`wb_gam_login_streak`, `wb_gam_login_streak_max`, `wb_gam_login_last_award`). No schema migration.

### Flow: Transactional emails

```
wb_gam_level_changed fires (after LevelEngine commits new level)
  → TransactionalEmailEngine::on_level_up
      → check option wb_gam_email_level_up (default false; per-event opt-in)
      → resolve old/new level via LevelEngine::get_level_for_user + iterate get_all_levels_for_user
      → render via Email::render( 'level-up', $vars )    — theme override aware
      → wp_mail with Email::from_header()
```

Same shape for `badge_earned` and `challenge_completed`.

### Flow: UGC submission queue

```
member submit (REST POST /submissions { action_id, evidence, evidence_url })
  → SubmissionsController::submit
      → SubmissionService::submit (validates action_id, rate-limits at DAILY_CAP=5)
      → INSERT wb_gam_submissions status=pending
      → fire wb_gam_submission_created
admin approve (REST POST /submissions/{id}/approve)
  → SubmissionService::approve
      → set_status approved
      → PointsEngine::award( default_points from action manifest )    — same engine
      → fire wb_gam_submission_approved
admin reject  (REST POST /submissions/{id}/reject { notes })
  → SubmissionService::reject — records notes, fires wb_gam_submission_rejected
```

Approval routes through `PointsEngine::award` so badges/levels/totals stay consistent — single source of truth.

### Flow: Public profile pages `/u/{user_login}`

```
template_redirect fires
  → ProfilePage::render_profile
      → privacy gate: option wb_gam_profile_public_enabled AND user_meta wb_gam_profile_public
      → wp_head injects OG meta + Schema.org Person JSON-LD
      → get_header() / do_shortcode( '[wb_gam_badge_showcase]' ) / do_shortcode( '[wb_gam_points_history]' ) / get_footer()
      → exit
```

---

---

## Boot sequence

`plugins_loaded` priority order (from `wb-gamification.php` and the engine constructors):

| Priority | What loads | Why |
|---|---|---|
| 0 | `WB_Gamification::instance()` | Registers all top-level hooks (REST, admin menus, blocks, shortcodes, CLI). |
| 1 | `DbUpgrader::init()` | Schema migrations (gated by `wb_gam_db_version` option). |
| 5 | `ManifestLoader::scan()` | Scans `integrations/*.php` for action manifests. Fires `wb_gam_manifests_loaded`. |
| 6 | `Registry::init()` | Registers actions discovered by ManifestLoader. |
| 8 | `Engine::init()`, WP/BP hook bridges | Engine starts listening for events. |
| 10 | `BadgeEngine`, `ChallengeEngine`, `StreakEngine`, … | Engines wire to the Engine event stream. |
| 12 | `NotificationBridge` | Bridges plugin events to BP notifications. |
| 15 | `Privacy` | GDPR export/erase handlers. |
| 20 | `SiteFirstBadgeEngine` | Late so it sees other engines’ badge claims first. |
| `bp_loaded` | `ProfileIntegration`, `DirectoryIntegration`, `BPActivity` | Need BuddyPress to be fully booted. |

After all engines are wired, the plugin fires `wb_gam_engines_booted` then `wb_gam_free_loaded`. The latter is the documented extension hook for a future Pro plugin.

---

## Flow: Earning Points

The canonical pipeline. Every other engine extends this same pipeline.

**Entry points (any of):**
- WordPress core event (`wp_insert_comment`, `publish_post`, `wp_login`, …) wired by `integrations/wordpress.php`.
- BuddyPress event (`bp_activity_posted_update`, `bp_groups_member_after_save`, …) via `src/BuddyPress/HooksIntegration.php`.
- WooCommerce order event (`woocommerce_order_status_completed`) via `integrations/woocommerce.php`.
- LearnDash event (`ld_course_completed`, …) via `integrations/learndash.php`.
- Direct REST ingestion: `POST /wb-gamification/v1/events` (`EventsController::create_item`).
- Admin manual award: `POST /wb-gamification/v1/points/award` (`PointsController::award`) or `Admin → Award Points`.
- WP-CLI: `wp wb-gamification points award --user=42 --points=100`.

**Code path:**
1. Source event handler builds a `WBGam\Engine\Event` value object.
2. `Engine::record(Event $event)` writes to `wb_gam_events` (immutable).
3. `Engine::dispatch(Event $event)` enqueues async evaluation via Action Scheduler (`as_enqueue_async_action('wb_gam_process_event_async', [$queue], 'wb_gamification')`).
4. **Async job runs** (`Engine::handle_async`):
   - Loads listeners that subscribe to this event type.
   - For each: applies `wb_gamification_before_evaluate` filter, then `RuleEngine` matches conditions.
   - Filter `wb_gamification_points_for_action` lets integrations veto/modify the award amount.
   - `PointsEngine::award()` writes to `wb_gam_points` (with the originating `event_id` FK).
   - Fires `wb_gamification_before_points_awarded` (vetoable) → writes → fires `wb_gamification_points_awarded`.
   - `LevelEngine` evaluates new total against `wb_gam_levels`; on level change, fires `wb_gamification_level_changed`.
   - `BadgeEngine` evaluates against `wb_gam_rules` filtered by `wb_gamification_should_award_badge`; awards via `wb_gam_user_badges` and fires `wb_gamification_badge_awarded`.
   - `RateLimiter` may short-circuit at any point if the action's daily cap was hit.
5. **Output side** — listeners on the canonical events:
   - `NotificationBridge` → BP notification (if BP active and user opted in).
   - `WebhookDispatcher` → outbound webhook (async, retried).
   - `BPActivity` → BuddyPress activity stream entry.
   - Frontend toasts queued in `wb_gam_member_prefs` for next `members/me/toasts` poll.

### Key files

| File | Lines | Role |
|---|---|---|
| `src/Engine/Engine.php` | 65, 115-120 | Event dispatch + async wiring. |
| `src/Engine/AsyncEvaluator.php` | 125-126 | Action-Scheduler enqueue. |
| `src/Engine/PointsEngine.php` | — | Write to `wb_gam_points`, fire `wb_gamification_points_awarded`. |
| `src/Engine/LevelEngine.php` | — | Threshold lookup + `wb_gamification_level_changed`. |
| `src/Engine/BadgeEngine.php` | — | Rule match + `wb_gamification_badge_awarded`. |
| `src/Engine/RuleEngine.php` | — | Generic condition evaluator. |
| `src/Engine/RateLimiter.php` | — | Per-action daily cap. |

### Permissions

| Surface | Cap | Notes |
|---|---|---|
| REST `POST /events` | `create_item_permissions_check` | Logged-in users; can be opened up via `permission_callback` override for headless. |
| REST `POST /points/award` | `manage_options` (with `wb_gam_award_manual` fallback) | Admin-only. |
| WP-CLI | shell access | Anyone with `wp` command. |
| Source events (WP/BP/WC/LD) | inherit from origin | The originating action’s own gate. |

---

## Flow: Leaderboard Read

**Entry point:** `[wb_gam_leaderboard]` shortcode, `wb-gamification/leaderboard` block, or `GET /wb-gamification/v1/leaderboard`.

**Code path:**
1. Block render or shortcode → `LeaderboardController::get_leaderboard($request)`.
2. Reads from `wb_gam_leaderboard_cache` (refreshed every 5 min by cron `wb_gam_leaderboard_snapshot`).
3. If the cache row for the requested period+scope is missing, fall through to a live query against `wb_gam_points` (sargable; composite index on `user_id, action_id, created_at`).
4. Apply `wb_gamification_leaderboard_results` filter.
5. Return JSON.

### Key files

| File | Role |
|---|---|
| `src/API/LeaderboardController.php` | Routes + READ permission_callback. |
| `src/Engine/LeaderboardEngine.php` | Snapshot writer (cron) + live-query fallback. |
| `blocks/leaderboard/render.php` | Server render of the block. |
| `assets/css/frontend.css` | Leaderboard styling. |

### Cron pipeline for the cache

`wb_gam_leaderboard_snapshot` (every 5 min, `LeaderboardEngine::write_snapshot`) writes a fresh row per (period, scope) tuple into `wb_gam_leaderboard_cache`. Defensive scheduling: the hook is registered in two paths (lines 53 and 86) so a missed activation hook still self-heals.

---

## Flow: Manual Badge Award (admin)

**Entry point:** `Admin → Badges → [Award]` button, or `POST /wb-gamification/v1/badges/{id}/award`.

**Code path:**
1. Admin clicks Award → JS posts to `/badges/{id}/award` with the badge ID + user ID.
2. `BadgesController::award_badge($request)` checks `award_permissions_check` (defaults to `manage_options`).
3. Builds a synthetic event (`badge_manual_award`) and calls `Engine::record()` → goes through the async pipeline.
4. `BadgeEngine` writes to `wb_gam_user_badges` (with `expires_at` if `validity_days` is set on the badge def).
5. `wb_gamification_badge_awarded` fires; `NotificationBridge` and `WebhookDispatcher` listen.
6. Optional: `CredentialController` issues an OpenBadges 3.0 verifiable credential signed with the site's private key.

### Key files

| File | Role |
|---|---|
| `src/API/BadgesController.php` | REST handler + permission checks. |
| `src/Admin/BadgeAdminPage.php` | Admin UI (list + create/edit/delete). |
| `src/Engine/BadgeEngine.php` | Award logic. |
| `src/API/CredentialController.php` | OpenBadges 3.0 issuance. |
| `src/Engine/CredentialExpiryEngine.php` | Expires credentials via cron. |

---

## Flow: Kudos Given

**Entry point:** `[wb_gam_kudos_feed]` block UI, or `POST /wb-gamification/v1/kudos`.

**Code path:**
1. JS posts `{recipient_id, message}` to `/kudos`.
2. `KudosController::create_item` checks `create_item_permissions_check` (logged-in + own profile).
3. Applies `wb_gamification_before_kudos` filter (vetoable).
4. `KudosEngine` checks daily-budget (`wb_gam_kudos_daily_limit`); rejects if exhausted.
5. Inserts to `wb_gam_kudos`.
6. Awards two side-effects via `Engine::record()`:
   - Giver gets `wb_gam_kudos_giver_points`.
   - Receiver gets `wb_gam_kudos_receiver_points`.
7. Fires `wb_gamification_kudos_given`.

### Key files

| File | Role |
|---|---|
| `src/API/KudosController.php` | REST handler. |
| `src/Engine/KudosEngine.php` | Cooldown + budget enforcement. |
| `blocks/kudos-feed/render.php` | Block render. |

---

## Flow: Setup Wizard (first-run)

**Entry point:** Activation hook redirects to `wp-admin/admin.php?page=wb-gamification-setup` if `wb_gam_wizard_complete` is false.

**Code path:**
1. `Installer` (activation): creates all 20 tables, seeds defaults, sets `wb_gam_db_version`.
2. Admin redirected to `SetupWizard::render`.
3. User picks a starter template (community / learning / marketplace / blank) → option `wb_gam_template` set.
4. Wizard creates the Hub page (containing `[wb_gam_hub]` shortcode) → option `wb_gam_hub_page_id` set.
5. Wizard sets `wb_gam_wizard_complete = true`.
6. Subsequent admin loads no longer redirect.

The wizard's submenu page is registered with parent slug `null` so it does not appear in the menu — it’s only reachable via the activation redirect or a manual link.

### Key files

| File | Role |
|---|---|
| `src/Engine/Installer.php` | Activation hook — table creation + seeding. |
| `src/Engine/DbUpgrader.php` | Schema migrations on subsequent versions. |
| `src/Admin/SetupWizard.php` | Wizard UI + flow. |

---

## Flow: GDPR Export / Erase

**Entry point:** WordPress core privacy export/erase request.

**Code path:**
1. WP fires `wp_privacy_personal_data_exporters` filter — `Privacy::register_exporter` adds an entry.
2. WP fires `wp_privacy_personal_data_erasers` filter — `Privacy::register_eraser` adds an entry.
3. On export: dump rows from `wb_gam_events`, `wb_gam_points`, `wb_gam_user_badges`, `wb_gam_kudos`, `wb_gam_streaks`, `wb_gam_redemptions`, `wb_gam_user_cosmetics`, `wb_gam_member_prefs` for the user.
4. On erase: anonymize or delete the same rows (immutable `wb_gam_events` is anonymized — `user_id` set to 0).
5. Fires `wb_gamification_user_data_erased` so listeners can clean their own caches.

### Key files

| File | Role |
|---|---|
| `src/Engine/Privacy.php` | Exporter + eraser registration. |
| `uninstall.php` | Last-mile cleanup on plugin uninstall. |

---

## Cross-cutting: how to extend

1. **Add a new tracked action** — drop a manifest file under `integrations/` (or `integrations/contrib/`). Filter `wb_gam_manifest_paths` if your file lives outside that directory. `ManifestLoader` picks it up at `plugins_loaded@5`.
2. **Modify points awarded** — hook the `wb_gamification_points_for_action` filter.
3. **Veto a badge award** — return false from `wb_gamification_should_award_badge`.
4. **Receive an outbound webhook** — register via the Webhooks admin page or `POST /webhooks` (admin-gated).
5. **Issue verifiable credentials** — set `validity_days` on the badge def; `CredentialController` signs OpenBadges 3.0 credentials. Customize the document via `wb_gamification_credential_document`.
