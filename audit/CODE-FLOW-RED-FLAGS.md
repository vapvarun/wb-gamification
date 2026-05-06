# WB Gamification — Code-Flow Red Flags

**Generated:** 2026-05-06
**Lens:** read-only audit of major code flows. Each flag is a deferred concern — **fixes happen in the next session**, this doc just maps them.
**Severity legend:** 🔴 critical · 🟠 high · 🟡 medium · 🟢 noted · ✅ closed in 2a730d9 (2026-05-06)

> **Update 2026-05-06:** all critical + high flags (F1–F7) **closed in commit `2a730d9`**. The 7 fixes ship as a single end-to-end change because F1+F2 are paired (hash storage requires the new table; migration replaces the legacy wp_options blob in the same step). Code below is the original audit prose; status indicator at the top of each section reflects the current state. Medium flags (F8–F14) remain deferred.

---

## 🔴 ✅ Critical (closed in 2a730d9)

### F1 · API keys stored in PLAINTEXT (security)

**Path:** `src/API/ApiKeyAuth.php` — `OPTION_KEY = 'wb_gam_api_keys'` stores keys as a PHP array where the **full key string IS the array key**.

```php
// create_key()
$key  = 'wbgam_' . wp_generate_password( 40, false );
$keys[ $key ] = array( 'label' => ..., 'user_id' => ..., 'active' => true, ... );
update_option( self::OPTION_KEY, $keys );

// authenticate()
$key_data = $keys[ $api_key ] ?? null;   // ← incoming key used as direct lookup
```

**Why this matters:**
- DB backups, `wp_options` exports, `wp option get wb_gam_api_keys` — all expose the live keys verbatim.
- Any other plugin with admin DB access (or a leaked SQL dump) gets every paired remote site's authenticated access.
- The earlier admin help panel I wrote claims keys are "hashed at rest" — **that claim is wrong and must be updated** when this is fixed (or when help text is corrected).

**Standard fix pattern:**
1. On `create_key`: generate the key, hash it (PBKDF2 with random salt, OR sha256 of `wp_salt('auth') . $key`), store the **hash** as the lookup index. Display the full key ONCE in the response (already does this).
2. On `authenticate`: hash the incoming key the same way, look up by hash.
3. Migration path: invalidate all existing keys + force regeneration on upgrade. There's no way to retrofit hashing without breaking pairings.

**Impact if shipped public:** customer sites expose remote-pairing credentials in DB backups. Real-world incidents follow.

### F2 · `update_option` on every authenticated API call (perf)

**Path:** `src/API/ApiKeyAuth.php::authenticate()` lines after `wp_set_current_user`:

```php
$keys[ $api_key ]['last_used'] = gmdate( 'Y-m-d H:i:s' );
update_option( self::OPTION_KEY, $keys );
```

**Why this matters:**
- `update_option` writes the entire serialized keys array on every authenticated REST hit.
- Default autoload: `yes` (option count >5 → autoload of all keys array on every WP request).
- High-traffic API consumers (mobile app, polling integration) hammer wp_options write contention. SQL log fills with `UPDATE wp_options SET option_value = ... WHERE option_name = 'wb_gam_api_keys'` on every API call.

**Fix sketch:** stop tracking `last_used` in the wp_options blob. Move `last_used` to a separate per-key user_meta or a small `wb_gam_api_key_usage` table indexed by key_hash. Update at most every N seconds (debounce) or on a sampling basis. Set `autoload = no` either way.

**Adjacency:** when F1 lands (hash storage), splitting last_used out of the same record is the natural pairing.

---

## 🟠 ✅ High (closed in 2a730d9 — F6 was verification-only)

### F3 · `event_id` is nullable + not UNIQUE — replay isn't enforced at the schema level

**Path:** `src/Engine/Installer.php` — `wb_gam_events` schema:

```sql
event_id   VARCHAR(36)     DEFAULT NULL,
KEY idx_event (event_id),
```

There's **no UNIQUE constraint** on `event_id`. Comment in DB: *"event_id nullable until Phase 0 Engine is built"* — Phase 0 IS built (v1.0), but the column never got tightened.

**Why this matters:**
- A duplicate event_id (same UUID submitted twice — network retry, double-tap, replay attack) inserts twice. Two events, two awards, double points.
- The **only guard is `apply_filters('wb_gam_before_evaluate', ...)`**, which is filterable but not enforced. A naive REST consumer that retries a failed `POST /events` on timeout double-awards.

**Fix sketch:** add `UNIQUE KEY idx_event_unique (event_id)` and treat NULL as "synthetic, no idempotency guarantee." Alter table in `DbUpgrader::upgrade_to_1_1_0` (or whatever next version), gated by a feature flag so it can be rolled back.

### F4 · Synchronous `wp_mail()` in `TransactionalEmailEngine`

**Path:** `src/Engine/TransactionalEmailEngine.php::send()` calls `wp_mail()` directly during the page-load that fired the level-up / badge / challenge event.

**Why this matters:**
- Level-up emails fire from `on_level_changed`, which fires from `wb_gam_level_changed`, which fires from `LevelEngine::sync()`, which fires from `wb_gam_points_awarded`, which fires AFTER the await transaction commits inside `Engine::process`.
- **The user's POST /events response is blocked on `wp_mail()` finishing.** SMTP-with-Mailgun or a slow MTA = 2–10 second response. WP-Mail-SMTP usually buffers, but a misconfigured site with `mail()` direct delivery times out.
- Compare: `WeeklyEmailEngine` correctly uses `as_enqueue_async_action` for the same outbound email work.

**Fix sketch:** mirror `WeeklyEmailEngine` — enqueue an Action Scheduler job for the actual `wp_mail` call. The hot path returns immediately; AS worker delivers the email out-of-band. Same retry / failure logging the existing pipeline already has.

### F5 · Rate-limit checks are TOCTOU (read-then-act) under concurrency

**Path:** `src/Engine/PointsEngine.php::passes_rate_limits()` does:

```php
if ( $cooldown > 0 && self::is_on_cooldown( ... ) ) return false;
if ( $daily_cap > 0 && self::get_today_count( ... ) >= $daily_cap ) return false;
// ... then later, Engine::process inserts the row
```

The check + insert are NOT in the same transaction. Two concurrent requests for the same `(user_id, action_id, day)` both pass the cap check and both insert. Cap of 5/day → user sneaks in 6 (or more) under burst.

**Why this matters:**
- AJAX-heavy frontends (rapid like clicks, kudos spam) can defeat caps.
- Anti-spam UGC-submission cap (`SubmissionService::DAILY_CAP = 5`) has the same shape — admin sees 6+ submissions land before realizing the cap was bypassed.

**Fix sketch:**
- Move the cap check INSIDE `Engine::process`'s transaction with `SELECT ... FOR UPDATE` on a counter row OR the relevant ledger window.
- Or: enforce caps via a UNIQUE constraint where shape allows (e.g. `(user_id, action_id, day_bucket)` for once-per-day actions).
- Or: accept eventual-consistency (current behavior) and document explicitly that caps are best-effort under concurrency.

### F6 · Webhook secret travels through Action Scheduler args

**Path:** `src/Engine/WebhookDispatcher.php::deliver_async()`:

```php
as_enqueue_async_action(
    self::AS_ACTION,
    array( (int) $webhook['id'], $webhook['url'], $signature, $payload ),  // $signature already = HMAC, OK
    'wb-gamification'
);
```

The signature alone is fine, but on **retry** the dispatcher re-signs and re-enqueues — and the secret-bearing `secret` field is read fresh from DB on each retry. So far OK.

But in `as_schedule_single_action` for retries the code may pass either the recomputed signature (current path) or the raw secret. Worth a careful read on retry to ensure the secret never crosses into the AS args table (which lives in the DB and is cleaner up by retention but visible to admins).

**Action this session:** spot-check the retry path; not yet confirmed problematic. **Next session:** verify by reading `maybe_schedule_retry` end-to-end.

### F7 · Rate-limit `passes_rate_limits` reads point_type from Registry, but the writer (Engine::process) reads it from `$event->metadata`

**Path:** rate-limit reads via `Registry::resolve_action_point_type($action)`. Engine writes via `PointsEngine::insert_point_row` which reads `$event->metadata['point_type'] ?? null`. If a `wb_gam_event_metadata` filter mutates `point_type` between rate-limit check and write, the cap counts the wrong currency.

**Fix sketch:** lock in the resolved `point_type` BEFORE rate-limit check, pass it through to `insert_point_row`. Make it a method parameter, not a metadata back-channel.

---

## 🟡 Medium

### F8 · `wb_gam_before_evaluate` filter fires BEFORE the transaction starts

A listener on this filter could call `PointsEngine::award` or other DB-mutating code OUTSIDE the transaction. If the main transaction rolls back, the listener's mutations are not rolled back. Documented behavior, but worth flagging — listeners aren't aware of the transaction boundary.

**Fix sketch:** rename to `wb_gam_pre_event_validate` and document in the filter docblock that listeners must not perform side effects. Or: introduce a separate `wb_gam_inside_transaction` filter that fires inside the txn for listeners that want atomicity.

### F9 · Cache key `wb_gamification` group is invalidated per-user, not by event-type

**Path:** `PointsEngine::insert_point_row()` does `wp_cache_delete( cache_key_total( $user_id, $type ), 'wb_gamification' )`. If a different cache layer (e.g. leaderboard rank cache) is keyed differently, it doesn't invalidate.

**Status:** likely fine — leaderboard cache is keyed `wb_gam_lb_{period}_{limit}_{scope}_{type}` (saw earlier), invalidated on a different schedule (5-min cron snapshot). Mild stale-window potential — admin awards points, leaderboard rank takes ≤5 min to reflect.

**Action:** document the eventual-consistency window in admin help text on the Award Points page so admins don't think "I awarded but the leaderboard didn't update" is a bug.

### F10 · `swallowed-Exception` catches in `StreakEngine` + `TenureBadgeEngine`

```php
try { /* timezone parse */ } catch ( \Exception $e ) { return 'UTC'; }
try { /* date parse */ }    catch ( \Exception $e ) { return 999; }
```

**Why this matters:** the catches are reasonable fallbacks, but they swallow the Exception silently (no `Log::error`). Bad timezone / corrupt date on a user → silent fallback to UTC / "trigger streak reset" — admin debugging "why did everyone's streak reset overnight?" has nothing to grep.

**Fix sketch:** keep the fallbacks but log via `Log::error` first. One-line addition each.

### F11 · `update_option` writes happen during REST authentication (timing side-effect on auth)

Beyond F2 — even on an INVALID API key, the early-return is correct, but on valid+inactive the path returns `WP_Error` BEFORE the last_used write. Result: only valid+active keys update their last_used. That's correct, but means "last_used" never gets updated for keys that were valid and recently revoked — admins can't see "this revoked key was hit at 3am, who's still using it?"

**Fix sketch:** consider logging revoked-key hits to a separate access-log table or a `wb_gam_api_key_revoked_hits` option. Helpful for incident response.

### F12 · No deduplication on `wb_gam_user_badges` (badge double-grant race)

**Path:** `BadgeEngine::evaluate_on_award` (line ~10 hooks at priority 10 on `wb_gam_points_awarded`). If two concurrent point awards push the user past the same threshold simultaneously, both could insert a `user_badges` row for the same `(user_id, badge_id)`.

**Fix sketch:** UNIQUE constraint on `(user_id, badge_id)` in `wb_gam_user_badges`. Verify schema; if missing, add via DbUpgrader.

### F13 · `Privacy::get_user_meta` calls don't differentiate scalar vs array meta

**Path:** `Privacy::export_user_data` lines for `$meta_groups`:

```php
'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
```

If a meta value is a stringified-int that should be an int (e.g. `wb_gam_login_streak = "5"`), the export shows "5" as string. Cosmetic — exported file becomes slightly less readable but data is intact. No fix needed unless a customer complains.

### F14 · `Engine::process` `apply_filters('wb_gam_event_metadata')` allows listeners to mutate event identity

The filter passes `$event->metadata` (array). A listener could mutate `point_type`, `points`, `object_id` via metadata side-channel before rate-limit check (F7 is one consequence; F14 is the broader concern). The Event object is reconstructed if metadata changed — but the reconstruction only refreshes metadata, not other fields.

**Fix sketch:** document the contract in the filter docblock that ONLY metadata fields may be mutated. Optionally: pass a clone, then validate that core identity fields (user_id, action_id, created_at, event_id) are unchanged after the filter.

---

## 🟢 Noted (no action needed)

### N1 · Toast IA template fix landed this session
`data-wp-each--toast` rename + `data-wp-init--set-id` removal. Verified end-to-end. Mentioned for completeness in the flow trace.

### N2 · GDPR erase atomicity
Wrapped in `START TRANSACTION` ... `COMMIT` per Privacy.php. Cache busted after commit. Already correct.

### N3 · Multi-currency conversion atomicity
`PointTypeConversionService::convert` uses `START TRANSACTION` + `SELECT ... FOR UPDATE` on user_totals. Already correct.

### N4 · Webhook delivery + retry
Async via Action Scheduler. Retries with delay. Good.

### N5 · Cron registration is idempotent
`wp_next_scheduled` guard before every `wp_schedule_event`. Reactivation safe.

### N6 · Engine::process side-effect ordering
`do_action('wb_gam_points_awarded')` fires AFTER `COMMIT`. Listeners (BadgeEngine, LevelEngine, NotificationBridge) see persisted data. Correct.

### N7 · Privacy helper enforcement (this session)
`MembersController` + 8 blocks now consult `Privacy::can_view_*` helpers. Coding-rule (Rule 11) prevents future drift.

---

## Suggested fix order for the next session

| # | Severity | Effort | Pair-with |
|---|---|---|---|
| F1 — API key plaintext storage | 🔴 critical | M (hash + migrate) | F2 |
| F2 — update_option on every API call | 🔴 critical | S | F1 |
| F3 — event_id UNIQUE | 🟠 high | XS (schema migration) | — |
| F4 — async transactional email | 🟠 high | S (mirror WeeklyEmailEngine pattern) | — |
| F5 — TOCTOU rate limits | 🟠 high | M (transaction lift) | F12 (similar shape) |
| F6 — webhook retry secret leak audit | 🟠 high | XS (read 50 lines, confirm) | — |
| F7 — point_type resolution drift | 🟠 high | S (parameterize) | — |
| F8 — pre-evaluate filter docs | 🟡 medium | XS | — |
| F9 — leaderboard staleness explainer | 🟡 medium | XS | docs page |
| F10 — log silent catches | 🟡 medium | XS (2 one-liners) | — |
| F11 — revoked-key access log | 🟡 medium | S | F1 |
| F12 — `(user_id, badge_id)` UNIQUE | 🟡 medium | XS (schema migration) | F3 |
| F13 — export value formatting | 🟡 medium | XS | none |
| F14 — event_metadata filter contract | 🟡 medium | XS (docblock) | — |

**Total:** 4 critical/high items that are real shippable concerns (F1, F2, F3, F4 + F5). The rest are polish.

**Pre-public-release blockers:** F1 (security), F2 (perf at scale), F3 (replay safety), F4 (perceived perf for email-on-event paths).

**Inhouse-only release tolerable as-is** — none of the flags are exploitable on a controlled inhouse network. Public marketplace release should fix at minimum F1+F2+F3+F4 first.

---

## Method (for reproducing)

This audit ran:
1. `grep TODO|FIXME|HACK|XXX` — clean (no carryover technical debt)
2. `grep error_log` — single funnel via `Log::php` (good)
3. `grep '} catch.*Exception'` — found F10
4. `grep \$wpdb->(insert|update|delete)` — mapped which writers bypass services (most are REST controllers, expected)
5. `grep PointsEngine::get_total` — identified all readers (verified Privacy gates apply)
6. `grep wp_schedule_event` — verified cron idempotency
7. Read full `Engine::process` flow — identified F8, F14
8. Read `ApiKeyAuth::authenticate` — found F1, F2, F11
9. Read `passes_rate_limits` — found F5, F7
10. Read `WebhookDispatcher` — found F6 (needs follow-up)

Re-running this audit:
```bash
bash audit/scripts/code-flow-flags.sh   # TODO: build this script in next session
```

Until the script exists, the grep patterns above plus the file list `src/Engine/*.php src/API/*.php src/Services/*.php` are the surface to re-walk.
