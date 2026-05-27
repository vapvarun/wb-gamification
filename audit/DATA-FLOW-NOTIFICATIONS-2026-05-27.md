# Data-flow audit: Notifications / Email / Webhook async (2026-05-27)

## Pipeline summary

Five async surfaces share one event bus (`wb_gam_*` actions). NotificationBridge buffers events in a per-user transient with monotonic `_id`; three readers (footer render, heartbeat, REST poll) maintain independent cursors in user_meta, so the post-5928f95 "lost messages" fix is intact. Transactional + weekly email + webhook dispatchers each subscribe to those same hooks and either send inline (sync fallback) or enqueue an Action Scheduler job (`wb_gam_send_transactional_email`, `wb_gam_send_webhook`, `wb_gam_weekly_email_user`). Member preferences live in `wb_gam_member_prefs.notification_mode`; only the weekly engine and the unsubscribe handler honour it.

## Findings

### G1: Badge webhooks never deliver — event-name regression

- **Severity**: critical
- **File:line**: `src/Engine/BadgeEngine.php:286` vs `src/API/WebhooksController.php:77`
- **What's missing/broken**: `BadgeEngine` dispatches `WebhookDispatcher::dispatch( 'badge_awarded', … )` (line 286), but the controller's `VALID_EVENTS` allowlist exposes only `'badge_earned'` (line 77). Admin UI (`src/Admin/WebhooksAdminPage.php:323`), settings (`src/Admin/SettingsPage.php:1796`), and the dispatcher's per-row filter `in_array( $event_type, $subscribed, true )` at `WebhookDispatcher.php:202` all key on the registered string. Subscribers can only register for `badge_earned`, so `'badge_awarded'` from the dispatch site never matches and badge webhooks silently drop. The CLAUDE.md changelog claims this exact bug was fixed in v1.2.0 / PR #47 — it has regressed at the dispatch site.
- **Reproduction**: Register a webhook subscribed to `badge_earned`. Award any badge. `wb_gam_webhook_log_<id>` option stays empty; no AS job is queued; no POST hits the receiver.
- **Likely fix**: Either change line 286 to `'badge_earned'` (preferred — it's the documented enum) or add `'badge_awarded'` as a back-compat alias to `VALID_EVENTS`. Add a regression journey under `audit/journeys/release/` so this can't quietly come back a third time.

### G2: `wb_gam_points_redeemed` not exposed as a webhook event

- **Severity**: high
- **File:line**: `src/Engine/RedemptionEngine.php:264` (fires) — no webhook subscriber
- **What's missing/broken**: `RedemptionEngine::redeem` fires `wb_gam_points_redeemed`. `TransactionalEmailEngine::on_redemption` was added (the fix called out in `ab7e79e`), but `WebhookDispatcher::init` (lines 52-57) wires only `level_changed`, `streak_milestone`, `challenge_completed`, `kudos_given`. There is no `on_redemption` listener and `'redemption'` isn't in `VALID_EVENTS`. Integrators using Zapier/Make/n8n cannot react to store redemptions.
- **Reproduction**: Subscribe a webhook to any event. Trigger a redemption via `RedemptionController`. No outbound POST.
- **Likely fix**: Add `'redemption'` to `WebhooksController::VALID_EVENTS` (line 75-82) and add `WebhookDispatcher::on_redemption()` registered on `wb_gam_points_redeemed`. Mirrors the on_kudos_given pattern.

### G3: TransactionalEmailEngine bypasses `notification_mode = 'none'`

- **Severity**: critical
- **File:line**: `src/Engine/TransactionalEmailEngine.php:348-359` (`is_enabled` only reads admin option, not user prefs)
- **What's missing/broken**: When a user clicks the weekly recap's unsubscribe link, `wb-gamification.php:323-350` sets `notification_mode = 'none'`. `WeeklyEmailEngine::dispatch_batch` honours that flag (line 116), but `TransactionalEmailEngine::is_enabled` checks only `get_option( 'wb_gam_email_' . $slug )` — never reads `wb_gam_member_prefs.notification_mode`. Result: a user who explicitly unsubscribed still receives every level-up, badge-earned, challenge-completed, and redemption email. GDPR + CAN-SPAM expose risk.
- **Reproduction**: Click unsubscribe in any weekly recap. Earn a badge. Badge-earned email still arrives.
- **Likely fix**: Add a per-user check in `is_enabled`, or in each `on_*` listener before the `self::send()` call: `if ( 'none' === $mode ) return;`. The `MembersController::get_member_prefs` SQL at line 768-787 is the canonical read.

### G4: No email rate limiting / coalescing

- **Severity**: high
- **File:line**: `src/Engine/TransactionalEmailEngine.php` (entire file)
- **What's missing/broken**: A backfill that awards 50 badges to one user (`wp wb-gamification replay`, manual award batch, BadgeEngine `evaluate_all`) enqueues 50 separate `wb_gam_send_transactional_email` jobs for that user — one email per badge, ~50 mails over a few seconds. No coalesce / dedup / per-user burst cap. SMTP providers (SES, Mailgun) will rate-limit or sandbox the sender.
- **Reproduction**: `wp wb-gamification points award --user=X --points=10000` against a multi-threshold badge ladder. Inbox floods.
- **Likely fix**: Add a per-user / per-event burst cap option (e.g. ≤ 5 of the same event_type / 10 min) using a short-lived transient guard inside `send()`. Or coalesce within a single page-load by buffering payloads and emitting one digest at `shutdown`.

### G5: Webhook retry payload not refreshed on secret rotation

- **Severity**: high
- **File:line**: `src/Engine/WebhookDispatcher.php:327-363` (`maybe_schedule_retry`)
- **What's missing/broken**: The signature is computed once at dispatch (line 206) and carried verbatim through every retry — both as a serialised AS arg and inside the retry-meta array (lines 354-357). If an admin rotates the webhook's `secret` between attempt 1 and attempt 4, the receiver — which has already updated its expected secret — will reject every retry with 401. Worse, `WebhooksController` (line 214) generates a new secret on create only; if a rotate endpoint is added, retries silently fail.
- **Reproduction**: Cause attempt 1 to fail (timeout). Rotate the secret in the DB or via REST. Attempt 2's HMAC still uses the old secret.
- **Likely fix**: Pass `webhook_id` only to the retry job. Inside `handle_retry`, re-fetch `(url, secret)` from `wb_gam_webhooks` and re-sign the payload. Or document "do not rotate during retry window" and reduce `MAX_RETRIES`.

### G6: Webhook retry omits payload-version checking

- **Severity**: medium
- **File:line**: `src/Engine/WebhookDispatcher.php:233-264`, `285-312`
- **What's missing/broken**: Both `deliver` and `handle_retry` treat ANY `status_code >= 400` as retryable (lines 257, 305). A `401`/`403`/`410` indicates permanent failure (bad secret, revoked endpoint) and should stop retrying — yet it consumes all 3 retries on a 2/4/8-minute schedule, then logs permanent failure. Wastes worker capacity and pings a failing receiver four times.
- **Reproduction**: Configure a webhook URL that returns 410 Gone. Trigger any event. Four POSTs over 14 minutes; receiver complains.
- **Likely fix**: Treat 4xx (except 408, 425, 429) as terminal; only retry 5xx + network errors. Honour `Retry-After` for 429.

### G7: AS-queued events miss `wb_gam_points_redeemed` member-pref check

- **Severity**: medium
- **File:line**: `src/Engine/TransactionalEmailEngine.php:295-339`
- **What's missing/broken**: `on_redemption` pre-renders the email in the sync request and enqueues a stringified payload. If the user toggles `notification_mode` to `none` after the listener fires but before the AS worker runs, the email still ships. Same shape as G3 but at a different layer: even after G3 is patched, the check must run inside `send_async` (worker time) rather than only at enqueue time.
- **Reproduction**: Tight test only — race window is the AS queue delay (~1 min default).
- **Likely fix**: Move the `is_enabled()` + member-pref check into `send_async` (line 101) so it re-validates at delivery time. Trade-off: payload must carry `user_id` and `slug` so the worker can re-check.

### G8: Notification cursor user_meta leaks past GDPR erasure

- **Severity**: low
- **File:line**: `src/Engine/Privacy.php:526-539` (meta key list) vs `src/Engine/NotificationBridge.php:63` (`wb_gam_notif_cursor_*`)
- **What's missing/broken**: Privacy erasure deletes a whitelist of user_meta keys but doesn't include the three `wb_gam_notif_cursor_<consumer>` keys (`footer`, `heartbeat`, `rest`) written by `NotificationBridge::read_pending`. After erase, three small integers persist in user_meta keyed on the deleted user's id. Not PII, but violates the "no orphans after erase" gate.
- **Reproduction**: Erase a user via WP Tools → Erase Personal Data. Query `usermeta` for `wb_gam_notif_cursor_%`. Rows survive.
- **Likely fix**: Add `$meta_key LIKE 'wb_gam_notif_cursor_%'` cleanup to `Privacy::erase_user_data` (the existing whitelist can be extended or a `delete_metadata` LIKE query added under the `$wpdb->query( 'COMMIT' )` block at line 544).

### G9: Heartbeat `$pending_boards` static cross-request leak guarded but fragile

- **Severity**: low
- **File:line**: `src/Engine/HeartbeatChannel.php:101-148`
- **What's missing/broken**: `self::$pending_boards` is a static array filled by `on_heartbeat_received` and consumed by `on_heartbeat_send`. The author resets it at line 148 to defend against PHP-FPM worker reuse, but only AFTER the send filter runs. If a filter callback throws between read (line 145) and reset (line 148), the next heartbeat request served by the same worker inherits the previous client's board descriptors. Defence-in-depth opportunity: reset before consumption, capture into a local, then process.
- **Reproduction**: Force a fatal in a `wb_gam_heartbeat_payload` filter callback registered by a third-party plugin. Next heartbeat from a different user returns stale leaderboard rows.
- **Likely fix**: `$boards = self::$pending_boards; self::$pending_boards = array(); /* then use $boards */`.

### G10: `levelName` field shipped but JS reads `event.message` instead

- **Severity**: low (cosmetic / dead field)
- **File:line**: `src/Engine/NotificationBridge.php:232` (push) vs `assets/interactivity/notifications.js:99` (read)
- **What's missing/broken**: `on_level_changed` queues an event with key `levelName` (camelCase). The IA store at `notifications.js:99` sets `levelName: event.message || event.detail || 'Level up!'` — it never reads `event.levelName`. The REST normaliser at `MembersController.php:732` DOES read `$toast['levelName']`. Two consumers disagree on the wire format. Today the overlay still shows because PHP also fills `message`, but the level-up overlay never renders the canonical level name from `levelName`.
- **Reproduction**: Trigger a level up. Overlay shows the translated "You reached X!" string instead of the bare level name design specified at line 232.
- **Likely fix**: Either standardise on `level_name` (snake_case, matches every other field) and update all three sites; or have `notifications.js:99` read `event.levelName` first.

### G11: WeeklyEmailEngine worker re-runs eligibility but skips inline send

- **Severity**: medium
- **File:line**: `src/Engine/WeeklyEmailEngine.php:140-194`
- **What's missing/broken**: `send_to_user` reads `gather_data` and skips when nothing happened this week, but doesn't re-check `notification_mode`. The batch SQL filters at enqueue time (line 116), but if a user opts out between enqueue and worker execution they still receive the recap. Race window is "AS pending → AS run" — minutes on busy sites. Same shape as G7 for transactional.
- **Reproduction**: User opts out at 08:31 UTC; weekly recap worker fires at 08:35 UTC; email arrives.
- **Likely fix**: Re-check the `notification_mode != 'none'` query inside `send_to_user`. Tiny SELECT — won't matter to per-user cost.

### G12: WebhookDispatcher captures kudos message but drops kudos_id

- **Severity**: low
- **File:line**: `src/Engine/WebhookDispatcher.php:56` (3 args) vs `src/Engine/KudosEngine.php:215` (fires 4)
- **What's missing/broken**: `add_action( 'wb_gam_kudos_given', ..., 50, 3 )` and `on_kudos_given( int $giver_id, int $receiver_id, string $message )` — `kudos_id` (the DB row id) is dropped. Downstream webhooks therefore can't deduplicate retries against a kudos record id, and can't fetch the full row from `/wp-json/wb-gamification/v1/kudos/{id}` because no id ships. Compare with `NotificationBridge::on_kudos_given` (line 285) which DOES accept all 4.
- **Reproduction**: Register a kudos webhook receiver. Payload has `receiver_id` + `message` but no `kudos_id`.
- **Likely fix**: Bump accepted_args to 4, add `int $kudos_id = 0` to the signature, include in `extra_data`.

### G13: Heartbeat REST/Footer/Heartbeat cursors monotonic-only — never reset

- **Severity**: low
- **File:line**: `src/Engine/NotificationBridge.php:495-510`
- **What's missing/broken**: The cursors only grow. If `set_transient` is cleared (TTL or `wp_cache_flush`) and a fresh queue starts, the queue's first `_id` is `last_id + 1` of CURRENT contents (line 449-454). So the cursor at, say, `1000` from a long-lived user stays `>= 1000` forever, but new events restart at `_id=1`. Result: every event after the transient flips will have `_id <= cursor`, so `read_pending` returns an empty array indefinitely. The transient TTL is 5 min (line 46), so this corner is rare but real — restart the cache, deliver no toasts to that user for the rest of the user's account lifetime.
- **Reproduction**: Force `delete_transient( 'wb_gam_notif_' . $uid )` for a user with cursor metas already set. Push a new event. `read_pending` returns `[]` even though the queue has a new event.
- **Likely fix**: When `push()` writes to a freshly-created queue (empty `get_transient`), set `_id` to `max( $existing_cursors ) + 1` by reading the three user_meta cursor keys for that user. Or stamp a queue-creation epoch and rewrite cursors when the epoch changes.

### G14: WebhookDispatcher payload `metadata` shape non-deterministic

- **Severity**: low
- **File:line**: `src/Engine/WebhookDispatcher.php:172-194`
- **What's missing/broken**: `array_filter` strips empty-ish values before `wp_json_encode`. If `metadata` is `[]` or `0` or `''`, it's dropped — receivers see different field sets per event. Worse for HMAC reproducibility: same logical event under two different metadata states yields two different signatures over two different payloads, so signature replay protection on the receiver side has nothing to dedupe against. Document or remove the array_filter.
- **Reproduction**: Trigger the same action twice; compare two POST bodies. Field set differs across calls with the same event_type.
- **Likely fix**: Preserve the schema. Send `null`s explicitly and document the schema in `examples/05-webhook-payload.json` (already referenced in CLAUDE.md).

## Sub-areas with no gaps found

- **Toast destructive-read bug from 5928f95**: per-consumer cursor pattern at `NotificationBridge::read_pending` (line 480-511) holds. Three consumers (footer/heartbeat/rest) each get an independent slice; transient stays intact until TTL. Verified.
- **HeartbeatChannel guest support**: nopriv hooks (lines 91, 93) wire correctly; build_user_snapshot returns empty array for guests so the bar suppresses itself client-side.
- **AS fallback paths**: `TransactionalEmailEngine::send`, `WeeklyEmailEngine::dispatch_batch`, and `Engine::process_async` all fall back to sync on hosts without Action Scheduler. Correct.
- **WebhookDispatcher exponential backoff**: 2/4/8 min sequence at line 347 is correctly doubling, MAX_RETRIES=3 caps it.
- **NotificationBridge level_changed null-resilience**: line 205-219 falls back to DB lookup when the listener gets nulls — good defense against filter-based re-fires.
