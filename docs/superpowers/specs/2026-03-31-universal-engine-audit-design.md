# WB Gamification — Universal Engine Audit & Improvement Design

> Comprehensive audit from 5 parallel deep-dive agents covering architecture, code quality, performance, integration inventory, and platform readiness.

## Current State Summary

| Metric | Count |
|--------|-------|
| PHP source files | 66 |
| Custom DB tables | 20 |
| REST API endpoints | 37 |
| Integration manifests | 9 (53 total actions) |
| Hooks (do_action + apply_filters) | 25+ |
| Gutenberg blocks | 10 |
| Shortcodes | 10 |
| Admin pages | 5 |
| WP-CLI commands | 5 |
| Cron/scheduled jobs | 13+ |

**Architecture:** Event-sourced core with manifest auto-discovery. Engine.php is the sole entry point. Clean separation between integration layer (hooks + manifests), processing layer (Engine + PointsEngine + RuleEngine), and output layer (badges, levels, streaks, notifications). PSR-4 autoloaded under `WBGam\` namespace.

---

## Part 1: Critical Bugs (Fix Before Release)

### Bug 1: RedemptionEngine Race Condition — Negative Balance (CRITICAL)
**File:** `src/Engine/RedemptionEngine.php:113-158,192`

Two concurrent redemption requests can both pass the balance check and both debit, causing negative points. Additionally, the cache bust after debit uses the wrong key (`wb_gam_points_` vs `wb_gam_total_`), so the stale balance stays cached for 300 seconds.

**Fix:** Use atomic SQL: `UPDATE wb_gam_points SET balance = balance - cost WHERE user_id = ? AND balance >= cost` — returns 0 affected rows if insufficient balance. Fix cache key to `wb_gam_total_{$user_id}`.

### Bug 2: RedemptionEngine Stock Decrement Before Debit (CRITICAL)
**File:** `src/Engine/RedemptionEngine.php:129-158`

Stock is decremented before points are debited. If debit fails (DB error), stock is permanently reduced without a corresponding transaction. No rollback.

**Fix:** Debit points first (atomically), then decrement stock. Wrap in DB transaction if possible.

### Bug 3: BuddyPress Friendship Hook — Wrong Arg Order (IMPORTANT)
**File:** `integrations/buddypress.php:72-85`

`friends_friendship_accepted` fires `($friendship_id, $initiator_id, $friend_id)` but callback expects `($initiator_id, $friend_id, $friendship_id, $friendship)`. Awards wrong user.

**Fix:** Already partially fixed in latest commit. Verify arg order matches BP source.

### Bug 4: EventsController — Engine::process() Returns Bool, Treated as Array (IMPORTANT)
**File:** `src/API/EventsController.php:170-183`

REST response accesses `$result['points']` and `$result['skipped']` on a bool return value. Always returns `points: 0` regardless of actual outcome.

**Fix:** Check bool return properly or change Engine::process() to return a result DTO.

### Bug 5: ChallengeEngine — process_team() REPLACE Clears completed_at (IMPORTANT)
**File:** `src/Engine/ChallengeEngine.php:193,334-347`

`upsert_log()` uses REPLACE INTO which deletes + re-inserts, erasing `completed_at`. The completion guard in `process_team()` runs after `upsert_log()`, not before.

**Fix:** Move completion guard before `upsert_log()` call.

### Bug 6: The Events Calendar — tec_ticket_purchased on Wrong Hook (IMPORTANT)
**File:** `integrations/the-events-calendar.php:49-63`

Both `tec_ticket_purchased` and `tec_event_checked_in` fire on the same `event_tickets_checkin` hook. Ticket purchase never triggers, check-in awards double.

**Fix:** Change `tec_ticket_purchased` to `event_tickets_attendee_ticket_purchased`.

### Bug 7: Badge Triggers Use get_current_user_id() — Wrong for Cron/CLI (IMPORTANT)
**File:** `src/Engine/Registry.php:163-173`

Badge triggers always use `get_current_user_id()` instead of deriving user from hook args like actions do via `user_callback`. Returns 0 in cron/CLI context.

**Fix:** Require `user_callback` in badge trigger args.

### Bug 8: Cooldown Timezone Skew (IMPORTANT)
**File:** `src/Engine/PointsEngine.php:272-290`

`created_at` stored as `current_time('mysql')` (site timezone), compared against `time()` (UTC epoch). Cooldown window miscalculated by timezone offset.

**Fix:** Store `created_at` as UTC everywhere, or use `current_time('timestamp')` for comparison.

---

## Part 2: Performance Issues

### Perf 1: Leaderboard — Zero Cache (CRITICAL)
**File:** `src/Engine/LeaderboardEngine.php:43-127`

Full `SUM(points) GROUP BY user_id ORDER BY total_points DESC` on every request. `wb_gam_leaderboard_cache` table exists but is dead code.

**Fix:** Object cache with 2-min TTL for reads. Write snapshots to cache table on 5-min cron for large sites.

**Impact:** 2-5 second query eliminated per leaderboard view.

### Perf 2: N+1 Avatar in Leaderboard Loop (CRITICAL)
**File:** `src/Engine/LeaderboardEngine.php:121`

`get_avatar_url()` called per row (up to 100).

**Fix:** `cache_users( $user_ids )` before loop.

### Perf 3: PersonalRecordEngine — 3 Queries Per Award (HIGH)
**File:** `src/Engine/PersonalRecordEngine.php:58-61`

Three separate `SUM(points)` queries (day/week/month) on every single point award.

**Fix:** Combine into one query with CASE WHEN, or move async.

### Perf 4: Badge Rules Full Table Scan Per Award (HIGH)
**File:** `src/Engine/BadgeEngine.php:63-68`

All badge condition rules loaded from DB on every award. No object cache.

**Fix:** Cache with 5-min TTL, invalidate on admin save.

### Perf 5: Level Thresholds Not Cached (HIGH)
**File:** `src/Engine/LevelEngine.php:82-96`

DB query every time `get_level_for_points()` is called. Levels rarely change.

**Fix:** Static + object cache.

### Perf 6: Rule Multipliers Queried Per Event (HIGH)
**File:** `src/Engine/RuleEngine.php:50-61`

`apply_multipliers()` hits DB on every event.

**Fix:** Object cache with 5-min TTL.

### Perf 7: Frontend CSS Loaded Globally (MEDIUM)
**File:** `wb-gamification.php:213`

26KB unminified CSS on every page.

**Fix:** Conditional enqueue when gamification blocks present. Add minification.

### Perf 8: TenureBadgeEngine — 4 Queries on Every Boot (MEDIUM)
**File:** `src/Engine/TenureBadgeEngine.php:108-133`

`ensure_badges_exist()` runs 4 SELECT queries on every `plugins_loaded`.

**Fix:** Option flag after first seed.

### Perf 9: Events Table Unbounded Growth (HIGH)
**File:** `src/Engine/LogPruner.php:62-76`

Only `wb_gam_points` pruned. `wb_gam_events` grows forever (~73M rows/year at scale).

**Fix:** Add events pruning with separate retention option.

**Expected Impact After All Fixes:**

| Metric | Before | After |
|--------|--------|-------|
| Queries per point award | 12-15 | 5-7 |
| Leaderboard TTFB (10K users) | 800ms-2s | 50-100ms |
| Frontend CSS (non-gamification pages) | 26KB | 0KB |
| Events table (1 year) | Unbounded | ~150K rows |

---

## Part 3: Platform Gaps (Universal Engine Blockers)

### Gap 1: Action ID Collision — Silent Overwrites
**File:** `src/Engine/Registry.php:109`

`Registry::$actions[$action['id']] = $action` silently overwrites duplicates. Two plugins registering `photo_upload` — last one wins.

**Fix:** Add duplicate detection with `_doing_it_wrong()`. Enforce `{vendor}/{action}` naming convention. Document in SDK.

### Gap 2: Missing Public API Functions
**File:** `src/Extensions/functions.php`

`wb_gam_has_badge( int $user_id, int $badge_id ): bool` — missing.
`wb_gam_get_user_streak( int $user_id, string $type ): ?array` — missing.
`wb_gam_get_user_badges( int $user_id ): array` — missing.

**Fix:** Add to functions.php wrapping existing class methods.

### Gap 3: No Admin UI Extension Points
**File:** `src/Admin/SettingsPage.php`

No filter for third-party plugins to add tabs/sections. Tabs are hardcoded.

**Fix:** Add `wb_gamification_settings_tabs` filter and `wb_gamification_settings_section_{$tab}` action.

### Gap 4: Manifest Schema Versioning Ignored
**File:** `src/Engine/ManifestLoader.php`

Manifests have a `version` key but it's never checked. No backward compatibility handling.

**Fix:** Document minimum required manifest version. Add version-gated feature checks.

### Gap 5: No Multi-Site Support
No `is_multisite()`, `switch_to_blog()`, or network-level settings anywhere.

**Fix:** Phase 2 concern — add network admin page, per-site vs network-level point pools.

### Gap 6: No Configuration Import/Export
`ExportCommand` exports user data only, not plugin settings/rules/badges.

**Fix:** Add `wp wb-gamification config export` and `config import` CLI commands.

### Gap 7: Members API Exposes All Profiles
**File:** `src/API/MembersController.php:212-226`

Any authenticated user can read any other user's full gamification profile.

**Fix:** Self + admin only for private data. Public endpoint for public-facing data (points, level, badges).

---

## Part 4: Improvement Plan (Prioritized)

### Phase A: Bug Fixes (Week 1)
1. Fix RedemptionEngine race condition + cache key
2. Fix RedemptionEngine stock/debit order
3. Fix EventsController return type
4. Fix ChallengeEngine process_team() guard order
5. Fix Registry badge trigger user resolution
6. Fix The Events Calendar wrong hook
7. Fix PointsEngine cooldown timezone
8. Fix BuddyPress friendship arg order (verify latest fix)

### Phase B: Performance (Week 2)
1. Leaderboard object cache + snapshot cron
2. cache_users() for avatar N+1
3. PersonalRecordEngine — single query
4. Badge rules, level thresholds, rule multipliers — object cache
5. TenureBadgeEngine option flag
6. Frontend CSS conditional loading
7. Events table pruning

### Phase C: Platform SDK (Week 3)
1. Action ID collision detection + naming convention
2. Add missing public functions (has_badge, get_streak, get_badges)
3. Admin UI extension points (tabs filter, section actions)
4. Members API access control fix
5. Document manifest schema with examples

### Phase D: Operational (Week 4)
1. Configuration export/import CLI
2. Manifest version checking
3. Minify CSS/JS + Grunt build pipeline
4. Spread Monday cron jobs across the week
5. CohortEngine batch processing (5K chunks)

### Phase E: Future (Backlog)
- Multi-site support
- GraphQL API
- A/B testing for point values
- AI anti-gaming detection
- WebSocket real-time updates

---

## Integration Inventory

### Current Manifests (9 plugins, 53 actions)

| Plugin | Actions | Category |
|--------|---------|----------|
| WordPress Core | 10 | wordpress |
| BuddyPress | 9 | buddypress |
| WooCommerce | 4 | commerce |
| LearnDash | 5 | learning |
| bbPress | 3 | social |
| MemberPress | 3 | commerce |
| LifterLMS | 5 | learning |
| The Events Calendar | 3 | social |
| GiveWP | 4 | social |

### External Integration (WPMediaVerse Pro)
14 actions via `wb-gamification.php` manifest in the WPMediaVerse Pro plugin directory.

### Hooks Available for External Plugins
- `wb_gamification_register` (action) — register actions programmatically
- `wb_gamification_before_evaluate` (filter) — gate events
- `wb_gamification_points_for_action` (filter) — modify point awards
- `wb_gamification_event_metadata` (filter) — enrich event data
- `wb_gamification_points_awarded` (action) — post-award processing
- `wb_gamification_badge_awarded` (action) — post-badge processing
- `wb_gamification_level_changed` (action) — level transition
- `wb_gamification_recap_data` (filter) — customize year-in-review

---

## Decision Points for User

1. **Events table retention:** Keep immutable (current) or add configurable pruning? Recommendation: configurable pruning with a separate "archive" option for sites that need full history.

2. **Multi-site priority:** Is network-level gamification needed for the Wbcom ecosystem, or is per-site sufficient? Recommendation: per-site for now, network-admin in Phase E.

3. **Admin extensibility scope:** Should third-party plugins inject into existing settings tabs, or only add their own submenu pages? Recommendation: both — filter for tabs, action for section content.

4. **Action ID format:** Enforce `vendor/action` format (breaking change for existing manifests) or add it as a best-practice recommendation? Recommendation: recommend but don't enforce yet; add `_doing_it_wrong()` for duplicates only.
