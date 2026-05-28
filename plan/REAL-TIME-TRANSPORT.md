# Real-time transport — SSE design + rollout plan

> **Status (2026-05-28):** scaffold landed, feature-flagged OFF. Stage 1
> of the 4-stage rollout below. Heartbeat broker remains the default
> transport. Flip the flag only after stages 2–3 ship and the journey
> tests in stage 4 pass.

The current realtime path is WP Heartbeat polling at 5s intervals
(`assets/js/heartbeat.js`). That's good enough for SENDER-side latency
(sender hits ping() after an action, sees toast in <1s — see commit
`2e29187`) but the RECEIVER-side latency is still up to 5s. SSE closes
that.

This document is the implementation contract — concrete LOC-level
choices, NOT pseudo-code. It supersedes the SSE section in
`plan/TECH-STACK.md §3` which was vision-level.

## Why SSE, not WebSockets

| Constraint | SSE | WebSocket |
|---|---|---|
| Shared-hosting compatible | ✓ | ✗ (mod_proxy_wstunnel needed) |
| Survives Cloudflare | ✓ with `X-Accel-Buffering: no` | ✗ (Free tier closes idle sockets) |
| Auto-reconnect built-in | ✓ (EventSource native) | ✗ (manual) |
| Auth via cookies | ✓ (regular HTTP) | needs handshake juggling |
| Direction | server → client only | bidirectional |
| We need bidirectional? | **NO** — sender uses REST | (irrelevant) |

SSE wins outright for gamification. WebSocket is the wrong tool —
nothing flows client → server that REST + ping() doesn't already
handle. ActivityPub federation later may want pub/sub via Redis but
that's a separate layer (server-to-server), not a transport choice.

## The environment hazards (and what we do about each)

Each of these has bitten plugins before. Mitigation baked into the
design, not bolted on later.

### Hazard 1 — PHP-FPM session lock

`session_start()` locks `wp_user_*` session files for the duration of
the request. A 30-second SSE stream blocks every other request from
the same user (including the REST writes that drive the events
they're waiting for).

**Mitigation:** call `session_write_close()` immediately after auth
+ user resolution and BEFORE the streaming loop. Document the
ordering in the controller header. Add an assert in tests.

### Hazard 2 — Output buffering

WordPress + plugins routinely add output buffers. SSE needs `flush()`
to actually push bytes; if a buffer's in the way, the client sits idle
until the buffer fills or the response ends.

**Mitigation:** at the top of the streaming response, drain every
buffer and disable implicit buffering:

```php
while ( ob_get_level() ) {
    ob_end_flush();
}
ob_implicit_flush( true );
```

Send a 4 KB padding comment as the first bytes after headers so any
proxy that buffers under a threshold trips immediately.

### Hazard 3 — `max_execution_time`

Default 30s on most hosts. Our stream is 30s by design; cutting it
off mid-stream produces a confused client.

**Mitigation:** `@set_time_limit( 0 )` before the loop (suppressing
the warning if disabled). Add an internal soft-deadline at 28s that
exits cleanly with `event: close` so the client reconnects.

### Hazard 4 — nginx + Cloudflare buffering

Both can hold response bytes until the connection closes. Two
header signals defeat both:

```php
header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-cache' );
header( 'X-Accel-Buffering: no' );           // nginx
header( 'Connection: keep-alive' );
```

Document that Cloudflare users must NOT enable "Auto Minify" on
HTML for the stream URL (it eats the events). The stream path is
distinct (`/wp-json/wb-gamification/v1/events/stream`) so the user
can rule it out.

### Hazard 5 — REST framework wrapping

`WP_REST_Server` normally serialises the controller's return value
into JSON. SSE responses must NOT be serialised — they're raw byte
streams that the controller writes itself, then `exit`.

**Mitigation:** don't use `WP_REST_Controller`. Register the route
with `register_rest_route()` and a custom `callback` that handles
headers + streaming + exit by hand. Permission still goes through
`permission_callback` so auth works the WP way.

### Hazard 6 — Floating connections after browser close

A user closes the tab; PHP keeps looping for the full 30s. Wasted CPU.

**Mitigation:** call `connection_aborted()` inside the loop. Exit on
true. Test: open EventSource, close window, watch the process count.

## The data path

```
┌──────────────────────┐
│ Engine::process_event│  (existing)
│  └─ writes wb_gam_events
└──────────┬───────────┘
           │
           ▼
┌────────────────────────────────────┐
│ NotificationBridge::on_event_processed │  (existing — already writes transients)
│  + on_event_processed_sse  (NEW)       │
│     └─ writes wb_gam_sse_events        │
│        (NEW lightweight realtime table)│
└──────────┬─────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────┐
│ SSE controller — long poll on        │
│ wb_gam_sse_events WHERE              │
│   user_id = ? AND id > last_id       │
│ Returns event lines, sleep(2s), loop │
└──────────┬──────────────────────────┘
           │
           ▼
┌─────────────────────────────────────┐
│ Browser EventSource                  │
│  ▶ dispatches to wbGamRealtime        │
│    subscribers (existing channels)   │
└─────────────────────────────────────┘
```

The new `wb_gam_sse_events` table is small + ephemeral — fire-and-forget
event lines with `user_id`, `event_type`, `payload_json`, `created_at`.
Auto-pruned at 5 minutes via cron (events that didn't get streamed in 5
minutes are stale; the heartbeat poll catches anything missed).

Schema:

```sql
CREATE TABLE wp_wb_gam_sse_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  event_type   VARCHAR(64) NOT NULL,
  payload_json TEXT NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id, id)
);
```

The PK + composite index hits the per-user-since-last-id query in O(log n).
At even 1000 events per minute (well above expected) the table holds
~5000 rows live with the 5-minute prune — small enough to live in
memory for any tuned MySQL.

## Why not just stream wb_gam_events?

Two reasons:

1. **`wb_gam_events` is the immutable audit log** — we don't want
   query pressure from realtime poll competing with award writes.
   Separate table = separate index = no contention.

2. **Not every event is realtime-relevant.** Bulk imports, replay
   commands, system actions don't need to ping the user's browser.
   Writing only realtime-relevant events to the SSE table is the
   filter; `wb_gam_events` stays the source of truth.

## Stage rollout

### Stage 1 — Scaffold (shipped 2026-05-28)

- [x] `src/API/SSEController.php` — registered, returns 503 when the
      feature flag is off (default). Real handler stubbed.
- [x] `assets/js/sse.js` — EventSource client adapter; probes feature
      flag from localised data and no-ops if disabled.
- [x] `assets/js/sse.js` registered alongside `heartbeat.js`; never
      replaces it.
- [x] Feature flag: `wb_gam_realtime_transport` option, values
      `'heartbeat'` (default) or `'sse'`. Single switch.
- [x] This design doc.

### Stage 2 — Storage *(shipped — consolidated into v2.2)*

- [x] Storage backend is `wb_gam_notifications_queue` (shipped in v2.2 via
      `src/Engine/DbUpgrader::ensure_notifications_queue_table()`). The
      original design called for a separate `wb_gam_sse_events` table, but
      since the notifications queue already carries everything SSE needs
      (user_id, monotonic id, event_type, payload_json, created_at) and
      its `idx_user_id (user_id, id)` covers the SSE polling query
      verbatim, the two tables collapsed into one.
- [x] Writer: `NotificationBridge::persist_to_queue_table()` runs on
      every `push()` call, dual-writing the durable backup alongside
      the existing transient.
- [x] Daily prune: `NotificationBridge::PRUNE_CRON` removes rows older
      than 24h (`RETENTION_SECONDS`). Bounded delete LIMIT 5000/run.

### Stage 3 — Streaming loop *(shipped)*

- [x] `SSEController::stream()` long-polls `wb_gam_notifications_queue`
      by `WHERE user_id=? AND id > ?` with a 50-row LIMIT per iteration,
      a 28-second soft deadline, a 25-second idle exit, 2-second poll
      interval, and a final `event: close` so the EventSource client
      knows the deadline ended cleanly (vs. an error).
- [x] All 6 environment hazards from §2 of this doc handled:
      session_write_close before the loop; drain + ob_implicit_flush(true);
      set_time_limit(0); X-Accel-Buffering: no; bare register_rest_route
      callback (not WP_REST_Controller — bypasses JSON wrap); connection
      _aborted() check each iteration.
- [x] 4 KB padding comment as the first bytes so buffering proxies
      flush on first byte instead of waiting to fill.
- [x] `assets/js/sse.js` listens on named-event types (`points`, `badge`,
      `level_up`, `streak_milestone`, `challenge_completed`, `kudos`,
      `skip`, `unknown`) and dispatches each payload into the
      `wbGamRealtime` broker's `toasts` channel via the new `_dispatch()`
      write-side API in `heartbeat.js`. Existing subscribers
      (toast.js etc.) consume the events without knowing SSE is the
      source.
- [x] `_dispatch()` exposed in `heartbeat.js` so alternative transports
      can write into the same broker subscriber set.

### Stage 4 — Hardening + opt-in default

- [ ] Journey test under `audit/journeys/customer/` — open two browser
      tabs (sender + receiver), trigger kudos, assert receiver toast
      appears within 1s.
- [ ] Settings UI under Realtime tab: dropdown to choose
      `heartbeat` / `sse` / `auto`. Auto = SSE with heartbeat fallback
      on connection error.
- [ ] Default value bumped from `heartbeat` to `auto` once the
      journey passes on the dev box and a friendly LAN deploy.
- [ ] Document the X-Accel-Buffering: no requirement for nginx in
      `docs/website/developer-guide/`.

## What's explicitly OUT of scope for v1

- **Multi-server pub/sub via Redis.** The TECH-STACK design described
  it; we'll add when we hit a real multi-server install. For now the
  DB-polling design works fine for single-app-server (which covers
  ~95% of installs).
- **Cross-user channels.** Each user sees only their own events.
  Future "live leaderboard for all users in this group" is a
  cross-user broadcast that wants Redis.
- **Compression.** SSE events are small (~500 bytes each); gzip
  saves trivial bandwidth and complicates flushing.
- **Authentication via query param.** Cookie auth is the only path —
  EventSource passes cookies for same-origin, and `withCredentials: true`
  for cross-origin. API-key auth would mean exposing the key in the URL,
  which we refuse.

## Rollback plan

If stage 4 ships and a host can't sustain SSE, individual sites flip
the option back to `'heartbeat'` from the Realtime settings tab and
SSE consumers drop out cleanly. No data loss — the heartbeat path
still polls the existing transient queue every 5s.

## References

- Design predecessor: `plan/TECH-STACK.md §3 Real-Time Layer`
- Existing transport: `assets/js/heartbeat.js`, `assets/js/toast.js`
- Sender-side ping: `assets/js/give-kudos.js`,
  `src/Blocks/submit-achievement/view.js`,
  `src/Blocks/redemption-store/view.js`
- NotificationBridge: `src/Engine/NotificationBridge.php`
