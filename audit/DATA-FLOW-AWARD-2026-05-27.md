# Data-flow audit: Event → Award → Side-effects (2026-05-27)

## Pipeline summary

A WP hook fires → `Registry::register_action` closure resolves `user_id` + injects
`point_type` into metadata → `Engine::process_async` (when repeatable) or
`Engine::process` (sync) → rate-limit re-check inside `START TRANSACTION` →
`persist_event` (PK on UUID = replay shield) + `PointsEngine::insert_point_row`
+ atomic UPSERT into `wb_gam_user_totals` → COMMIT → `wb_gam_points_awarded`
fires → `LeaderboardEngine::invalidate_cache` (prio 5), `BadgeEngine::evaluate`
(10), `ChallengeEngine` (15), `CommunityChallengeEngine` (20), `SiteFirstBadge`
(30), `NotificationBridge` (99) → `LevelEngine::maybe_level_up`,
`StreakEngine::record_activity`, `WebhookDispatcher::dispatch`,
`wb_gam_event_processed`. Conversion + redemption use a separate atomic path in
`PointTypeConversionService::convert` / `RedemptionEngine::redeem`.

## Findings

### G1: `PointsEngine::debit` writes ledger row without matching `wb_gam_events` row
- **Severity**: critical
- **File:line**: `src/Engine/PointsEngine.php:265-303`
- **What's missing/broken**: `debit()` inserts into `wb_gam_points` (line 270) and bumps `wb_gam_user_totals` (line 299) — but never inserts into `wb_gam_events`. CLAUDE.md states "Events row = source of truth". Every debit therefore breaks the invariant: a `wb_gam_points` row exists with `event_id = ''` (or with a borrowed event id that has no parent row). Replay-from-events will under-state user balance because debits are invisible to the canonical log. Affects every callsite: `RedemptionEngine::redeem` (line 186), `JetonomyIntegration::handle_change` (line 128), and the conversion service (line 337 — partially mitigated because the conversion path inserts a synthetic `wb_gam_events` row at line 350, but ONLY for the debit side; the credit-side `wpdb->insert` at line 373 also writes to `wb_gam_points` without a `wb_gam_events` row).
- **Reproduction**: Run a redemption; `SELECT * FROM wb_gam_events WHERE id = (SELECT event_id FROM wb_gam_points WHERE action_id='redemption' ORDER BY id DESC LIMIT 1)` returns zero rows.
- **Likely fix**: Either route every debit through `Engine::process` with negative `points` metadata, or have `debit()` insert a paired `wb_gam_events` row inside the same transaction as the ledger insert.

### G2: `RedemptionEngine::redeem` records the redemption row OUTSIDE the debit transaction
- **Severity**: critical
- **File:line**: `src/Engine/RedemptionEngine.php:210-223`
- **What's missing/broken**: `COMMIT` runs at line 210; `wpdb->insert(wb_gam_redemptions)` runs at line 213. If the redemption insert fails (table missing, full disk, deadlock victim), the user has been debited but no `wb_gam_redemptions` row exists, so no fulfilment ever happens and no admin record exists. Coupon-creation + status-update calls (lines 232-251) are also OUTSIDE any transaction.
- **Reproduction**: Force a constraint failure on `wb_gam_redemptions` (e.g. drop the table) and call `RedemptionEngine::redeem()` — points disappear, no record created.
- **Likely fix**: Insert the redemption row INSIDE the same transaction as the debit; commit only after the redemption row + stock-decrement both succeed.

### G3: `JetonomyIntegration::handle_change` debit path has TOCTOU race
- **Severity**: critical
- **File:line**: `src/Integrations/Jetonomy/JetonomyIntegration.php:124-128`
- **What's missing/broken**: Reads balance with `PointsEngine::get_total()` then calls `PointsEngine::debit()`. No `START TRANSACTION`, no `FOR UPDATE`, no balance lock. Two concurrent debit requests (Jetonomy reputation downvotes from two moderators in the same second) both pass `$total < $amount` and both debit — balance can go negative.
- **Reproduction**: Two parallel POSTs to a Jetonomy endpoint that calls `award_change(user, -100)` when user has exactly 100 points. Both succeed; user ends at -100.
- **Likely fix**: Wrap in `START TRANSACTION` + `SELECT … FOR UPDATE` like `RedemptionEngine::redeem` (line 147-152) or `PointTypeConversionService::convert` (line 281-291).

### G4: Duplicate `wb_gam_streak_milestone` fire
- **Severity**: high
- **File:line**: `src/Engine/StreakEngine.php:213,223`
- **What's missing/broken**: `fire_milestone()` calls `do_action('wb_gam_streak_milestone', ...)` twice in a row — once at line 213, once at line 223. Every listener (`NotificationBridge`, `WebhookDispatcher`, `SiteFirstBadgeEngine`) fires twice per milestone, producing duplicate toasts, duplicate webhook dispatches, and a double-evaluation of the first-streak badge.
- **Reproduction**: Reach a 7-day streak; check that `NotificationBridge::push` is invoked twice and `WebhookDispatcher::on_streak_milestone` dispatches two webhooks.
- **Likely fix**: Delete one of the two `do_action` blocks (lines 215-223 is a copy-paste duplicate of lines 204-213).

### G5: BadgeEngine point-milestone reads wrong currency on registered actions without metadata injection
- **Severity**: high
- **File:line**: `src/Engine/BadgeEngine.php:103-104`
- **What's missing/broken**: `$event_type = isset($event->metadata['point_type']) ? ... : ''` and then `PointsEngine::get_total($user_id, $event_type ?: null)`. The metadata is only stamped by `Registry::register_action` closure (line 178). Events created directly via `Engine::process()` from internal callsites (`ChallengeEngine::complete_challenge:258`, `StreakEngine::fire_milestone:228`, `PointsEngine::award`, `PointsEngine::process_action`) never set `metadata['point_type']`, so badge evaluation falls back to the primary type. A site with `coins` actions whose milestone badge is configured against `coins` will never auto-award because BadgeEngine sums the user's `points` ledger, not their `coins` ledger.
- **Reproduction**: Configure a `coins`-only action, define a `point_milestone` badge requiring 100 coins, award 100 coins via REST `events` endpoint. Badge does not award.
- **Likely fix**: In `Engine::process`, inject the resolved `$resolved_type` back into the event metadata before firing `wb_gam_points_awarded`, so listeners read the same currency that was written to.

### G6: `Event` value object has no `point_type` property but NotificationBridge reads it
- **Severity**: high
- **File:line**: `src/Engine/NotificationBridge.php:136-138` (reader); `src/Engine/Event.php:21-63` (missing property)
- **What's missing/broken**: `property_exists($event, 'point_type')` is permanently false because `Event` defines only `event_id, action_id, user_id, object_id, metadata, created_at`. The fallback path at line 140 calls `Registry::resolve_action_point_type` — which fails for synthetic actions (`challenge_completed`, `streak_milestone`, `manual`, `redemption`, `convert_X_to_Y`) and returns `''`. Toast label always defaults to primary currency for synthetic awards even when they touched a different ledger.
- **Reproduction**: Award via `PointsEngine::award($user, 'manual', 50, 0, 'coins')`. Toast renders "+50 points" instead of "+50 coins".
- **Likely fix**: Read `$event->metadata['point_type']` first; only fall back to Registry resolution. Or — better — add a `point_type` field on `Event` and stamp it once in `Engine::process` after resolution.

### G7: `LevelEngine::maybe_level_up` only considers primary currency
- **Severity**: medium
- **File:line**: `src/Engine/LevelEngine.php:178`
- **What's missing/broken**: `get_level_for_user()` calls `PointsEngine::get_total($user_id)` with no `point_type` argument, so levels are derived purely from the primary ledger. If a site uses `coins` as the canonical action currency, no level-up ever fires, even after thousands of coins. The CLAUDE.md design says "level state is derived from the points ledger" but does not specify single-currency.
- **Reproduction**: Make `coins` the only ledger with awards; user never levels up.
- **Likely fix**: Either declare levels as primary-currency-only in admin UI + docs (current behaviour) or extend `wb_gam_levels` with a `point_type` column.

### G8: Engine fires both `wb_gam_points_awarded` AND `wb_gam_after_points_award` with same data
- **Severity**: low
- **File:line**: `src/Engine/Engine.php:356,368`
- **What's missing/broken**: Two hooks fire back-to-back with the same arguments (`user_id`, `event/action_id`, `points`). No internal listener subscribes to `wb_gam_after_points_award`. Either deprecate one or make them semantically distinct (e.g. "before/after side-effects").
- **Reproduction**: `grep -rn "wb_gam_after_points_award" src/` finds zero listeners.
- **Likely fix**: Deprecate `wb_gam_after_points_award` in favor of `wb_gam_points_awarded` (or move side-effect engine evaluation between the two and document the contract).

### G9: `wp_publish_post` re-awards on every post update
- **Severity**: high
- **File:line**: `integrations/wordpress.php:102-118`
- **What's missing/broken**: `publish_post` fires on every save where the post ends up `publish` — including edits to already-published posts. `repeatable: true`, no `cooldown`, no `daily_cap`. Author re-earns 25pts on every save. The Action Scheduler async path (default for `repeatable=true`) does have an internal rate-limit re-check, but no per-post idempotency.
- **Reproduction**: Publish a post, then click Update 10 times → 11 awards.
- **Likely fix**: Use the `transition_post_status` hook to scope to `*_to_publish` transitions, or add a `metadata_callback` that returns `_first_publish_only` and gate via `wb_gam_before_evaluate`.

### G10: `ChallengeEngine::complete_challenge` recursively calls `Engine::process` from inside `wb_gam_points_awarded`
- **Severity**: medium
- **File:line**: `src/Engine/ChallengeEngine.php:258`
- **What's missing/broken**: The challenge-bonus award happens synchronously inside the listener for the triggering award. If the bonus itself satisfies another challenge (or the same action triggers a chain), the listener recurses. The comment in `Engine::process` (`src/Engine/Engine.php:243-247`) explicitly warns against calling `PointsEngine::award` from listeners. `StreakEngine::fire_milestone:228` has the same shape. No guard against re-entrancy.
- **Reproduction**: Define challenge X with `bonus_points=10` and `action_id=challenge_completed`. Completing any challenge triggers an infinite loop until PHP timeout.
- **Likely fix**: Either schedule bonus awards via `Engine::process_async` so they don't run inside the parent listener, or add a static guard (`self::$is_running = true`) that prevents re-entry.

### G11: BadgeEngine's badge-rules cache never invalidates on rule changes
- **Severity**: medium
- **File:line**: `src/Engine/BadgeEngine.php:79-91`
- **What's missing/broken**: `wp_cache_set('wb_gam_badge_rules', ..., 300)` caches all active badge conditions for 5 minutes with no invalidation hook. When an admin creates or edits a badge rule in `wb_gam_rules`, evaluations keep using stale rules for up to 5 minutes — both for "I made a new badge but nobody earns it" and "I disabled a bad badge but it's still auto-awarding".
- **Reproduction**: Create a badge rule, immediately award points; rule not evaluated.
- **Likely fix**: `wp_cache_delete('wb_gam_badge_rules', 'wb_gamification')` from BadgeAdminPage / RulesController on every rule save.

### G12: `PointsEngine::bump_user_total` failure is silently swallowed
- **Severity**: medium
- **File:line**: `src/Engine/PointsEngine.php:326-355`
- **What's missing/broken**: When the UPSERT fails (`false === $ok`), the function logs and returns void. The caller (`insert_point_row` line 246) already committed the ledger row; the materialised total is now drifted. `get_total` will fall through to live SUM until the row appears, masking the drift, but for any user with the totals row pre-existing it will silently report stale values forever. No background reconciliation job exists.
- **Reproduction**: Force `wb_gam_user_totals` write to fail (mysql user permissions); subsequent reads return stale total but ledger keeps growing.
- **Likely fix**: Either roll back the parent transaction on UPSERT failure, or run a periodic reconciliation cron (`SELECT user_id, point_type, SUM(points)... GROUP BY` vs `wb_gam_user_totals`).

### G13: PersonalRecordEngine async pipeline is dead — never enqueued
- **Severity**: high
- **File:line**: `src/Engine/AsyncEvaluator.php:98` (never called); `src/Engine/PersonalRecordEngine.php:63` (subscribes)
- **What's missing/broken**: `PersonalRecordEngine::init` registers a callback with `AsyncEvaluator::register`. Nothing in the codebase calls `AsyncEvaluator::enqueue`. The personal-record detection (day/week/month best scores → BP notification + `wb_gam_personal_record` hook) never runs. CLAUDE.md lists Personal Record under "Phase 3 — Engagement Mechanics" but the wiring is broken.
- **Reproduction**: `grep -rn "AsyncEvaluator::enqueue" src/` — zero call sites.
- **Likely fix**: Either delete the dead engine or wire `AsyncEvaluator::enqueue` into `Engine::process` after COMMIT (same place `WebhookDispatcher::dispatch` is called, Engine.php:373).

### G14: `Engine::process` event-metadata filter cannot influence currency despite warning
- **Severity**: low
- **File:line**: `src/Engine/Engine.php:185-187, 221`
- **What's missing/broken**: The docblock says metadata['point_type'] mutation has no effect (line 206-211). That's true and intentional, but `Registry::register_action` closure (Registry.php:178-183) DOES inject `metadata['point_type']` from `resolve_action_point_type` before the filter sees it. So filters see the value but can't change it — confusing API surface. Multiple internal callsites (manual/REST) carry `metadata['point_type']` for non-registered actions, where it IS authoritative. Inconsistent.
- **Reproduction**: A filter that swaps `point_type` in `wb_gam_event_metadata` is silently ignored for registered actions, silently respected for manual awards.
- **Likely fix**: Stamp `$resolved_type` back into `$event->metadata['point_type']` after resolution, then make the docblock match.

### G15: `Engine::is_action_enabled` per-request cache never busts on admin toggle
- **Severity**: medium
- **File:line**: `src/Engine/Engine.php:58-94`
- **What's missing/broken**: `self::$enabled_cache` is a per-request static cache. Within the same request (CLI bulk, long-running cron), admin changes via `update_option('wb_gam_enabled_<action>')` aren't seen. Mostly a non-issue for web requests (each request starts fresh), but `wp wb-gamification` CLI bulk operations and Action Scheduler workers can see stale data across event batches.
- **Reproduction**: Open WP-CLI shell, change an action toggle, fire the action — old state respected.
- **Likely fix**: Make the cache a `wp_cache_get` with the standard incrementor pattern, or expose `Engine::flush_enabled_cache()` for CLI/admin use.

### G16: Conversion service credit-side ledger insert has no point_type validation on writer
- **Severity**: low
- **File:line**: `src/Services/PointTypeConversionService.php:373-385`
- **What's missing/broken**: The direct `wpdb->insert` into `wb_gam_points` for the credit side bypasses `PointsEngine::insert_point_row` and `PointsEngine::resolve_type`. If `$to` is somehow a deleted slug between rule-read and write, the credit lands on a non-existent currency. Mitigated by `$this->types->resolve($to)` at line 219, but no FK constraint enforces this.
- **Reproduction**: Race: admin deletes the destination point-type between conversion start and the credit insert.
- **Likely fix**: Re-validate destination type exists immediately before the credit insert, or wrap the credit through `PointsEngine::insert_point_row` (which centralises resolution + total bump + cache delete).

### G17: `wb_gam_award_skipped` has zero internal listeners despite being a published surface
- **Severity**: low
- **File:line**: `src/Engine/PointsEngine.php:88-187` (5 fires); `src/Engine/Registry.php:159`; `src/Integrations/Jetonomy/JetonomyIntegration.php:105`
- **What's missing/broken**: Fired in 6 places to communicate "we skipped this award and here's why" — no internal listener consumes it. NotificationBridge could surface the skip-toast ("you've hit your daily cap") but doesn't. Pure dead-letter unless third-parties subscribe.
- **Reproduction**: Hit daily cap; user gets no feedback.
- **Likely fix**: Wire `NotificationBridge::on_award_skipped` for `daily_cap` / `weekly_cap` / `cooldown` reasons.

### G18: `Engine::process` rate-limit re-check inside transaction lacks corresponding lock
- **Severity**: medium
- **File:line**: `src/Engine/Engine.php:315`; `src/Engine/PointsEngine.php:803-820`
- **What's missing/broken**: The in-transaction `passes_rate_limits` re-check (Engine.php:315) calls `get_today_count` which runs `SELECT COUNT(*) ... WHERE user_id AND action_id AND point_type AND created_at >= %s`. That `COUNT(*)` does not take row locks. Two parallel requests racing to insert the Nth+1 event both see N rows and proceed. The comment at lines 306-314 acknowledges this residual race but the suggested fix ("UNIQUE constraint on key shape") is deferred to v1.1.
- **Reproduction**: Bombard the same `daily_cap=5` action with 10 parallel calls in the same millisecond; ledger ends with 6+ rows.
- **Likely fix**: Either add a `SELECT … FOR UPDATE` on the cap-window rows, or compose a `(user_id, action_id, point_type, YYYYMMDD)` unique key with a counter row that you `INSERT … ON DUPLICATE KEY UPDATE counter = counter + 1` and reject when counter > cap.

### G19: `wp_cache_delete` for per-type totals not wrapped by transaction COMMIT
- **Severity**: low
- **File:line**: `src/Engine/PointsEngine.php:247, 300`
- **What's missing/broken**: `wp_cache_delete` runs inside `insert_point_row`, which is called from `Engine::process` BEFORE the parent `COMMIT` (line 342). If the transaction rolls back AFTER `insert_point_row` returns (it doesn't currently, but the code shape is fragile), the cache has been busted with the assumption that the new total is written, leaving the cache hole filled by a stale live-SUM. The conversion path correctly busts cache only AFTER COMMIT (PointTypeConversionService.php:405-406). Inconsistent pattern.
- **Reproduction**: Hard to reproduce today; relies on a future code change to insert another DB statement after `insert_point_row` that can fail.
- **Likely fix**: Move the `wp_cache_delete` call from inside `insert_point_row` to `Engine::process` right after `COMMIT` (Engine.php:342).

### G20: `wb_gam_points_awarded_batch` fires once but Engine never invokes the side-effect listeners with batch context
- **Severity**: medium
- **File:line**: `src/Engine/PointsEngine.php:580`
- **What's missing/broken**: `award_batch` fires `wb_gam_points_awarded_batch` once but does NOT fire `wb_gam_points_awarded` per row (intentional, per docblock line 440). The only internal listener for the batch hook is `LeaderboardEngine::invalidate_cache` (LeaderboardEngine.php:76). BadgeEngine, ChallengeEngine, CommunityChallengeEngine, NotificationBridge, SiteFirstBadge — none subscribe to the batch hook. A 5000-user CSV import skips every badge / challenge / notification evaluation. No async reconciliation cron exists to pick up the slack.
- **Reproduction**: `wp wb-gamification scale seed --users=5000 --points=200`; nobody earns the "100pts" badge.
- **Likely fix**: After `wb_gam_points_awarded_batch`, schedule async badge/level/streak evaluation for each affected user via Action Scheduler (a single batch job that walks the affected user IDs).

## Areas with no gaps found

- **Manifest discovery** (`ManifestLoader.php`): scan + validation logic is clean; BP-conditional handling is correct.
- **Replay protection on `wb_gam_events` PK**: `persist_event` correctly rolls back on duplicate event_id (Engine.php:324-335).
- **Conversion service transaction**: full atomic flow with `FOR UPDATE` lock on both currencies (PointTypeConversionService.php:281-400) — paired ledger rows tied via shared `event_id`. Cache-bust correctly runs after `COMMIT`.
- **Leaderboard last-changed invalidation**: incrementor pattern is correctly used (LeaderboardEngine.php:140-167); snapshot freshness is correctly compared against `wb_gam_leaderboard_invalidated_at`.
- **Privacy gates on REST**: T1/T2 permission_callback splits are present on MembersController; opt-out IDs respected in leaderboard queries.
