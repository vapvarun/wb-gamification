# Stability audit + v2 architecture plan — 2026-05-28

> Authored at the end of the foundation wave (commits `0c3e7c4` → `5e7f7d9`).
> Walks the five most-critical data flows at LOC level, surfaces the
> structural findings I tripped over while doing it, and proposes the
> v2 architecture trajectory. Treat the v2 section as direction, not
> commitment — every item moves through the same plan/MASTER-CHECKLIST
> + drift-gate discipline before landing.

---

## 1. Where we are

12 commits since v1.5.0 shipped. The foundation wave moved the project
from "10 unstable manifest fields + manual release ritual" to:

| Artefact | Before wave | After wave |
|---|---|---|
| Drift-gated artefacts | 0 | 6 (readme, docs_config, hooks_fired, frontend_assets, openapi, sdk types) |
| Release ritual | 10-spot hand-edit | `bash bin/cut-release.sh 1.6.0` |
| Scale 100k claim | unverified | measured + recorded (`audit/scale-baseline.md`) |
| JS SDK | 10 methods, 9/56 routes | 64 methods, 56/56 routes |
| OpenAPI artefact | absent | `audit/openapi.json` (57 paths) |
| AS-schedule guard | absent | Rule 12 in coding-rules-check.sh + 7 fire-once annotations |
| Realtime transport | heartbeat only (5s poll) | heartbeat + SSE scaffold (stages 2-4 pending) |
| Manifest hooks_fired | 43 actions + 12 filters (11 fabricated) | 57 actions + 59 filters from ground truth |
| Manifest frontend_assets | 6 CSS + 4 JS flat | 40 CSS + 42 JS structured by domain |

The plan's "pending" count went from 11 (mostly stale) to 4 (genuine
multi-day projects):

1. SSE stages 2–4 (storage + streaming loop + opt-in default)
2. GraphQL extension
3. AI intelligence layer
4. ActivityPub federation

None of those are "build infrastructure" items. They're feature
expansions over the foundation we now have. That's the right end-state
for the wave.

---

## 2. Data-flow trace — five critical paths

Walked at LOC level. Each section names the entry points, the
transitions, and the failure modes.

### Flow 1: Award (REST/integration → ledger → cache → toast)

**Entry**: any of —
- `POST /wb-gamification/v1/events` (REST, user-driven)
- `POST /wb-gamification/v1/points/award` (REST, admin)
- `do_action('publish_post', …)` (integration, auto)
- Action Scheduler tick for queued async events
- `wp wb-gamification points award` (CLI)

**Pipeline** (`src/Engine/Engine.php:240→432`):

```
1. Event constructed (action_id, user_id, metadata, event_id)
   ├─ event_id is the replay-protection key. Stable IDs from callers
   │  prevent double-award on network retry.
   └─ Caller responsibility — engine doesn't generate.

2. apply_filters('wb_gam_event_metadata')   line 271
   └─ Enrichment — listeners are documented "read-only, no side effects".
      Hooks_fired entries now show consumed_by[] grepped from add_filter sites.

3. apply_filters('wb_gam_before_evaluate')  line 303
   └─ Gate filter. Returns false → silent skip + early return.
      No event log entry. No audit trail.   ⚠ FINDING #1 below.

4. Points resolution                         lines 309-330
   ├─ get_option('wb_gam_points_' . action_id)
   ├─ apply_filters('wb_gam_points_for_action')
   └─ RuleEngine::apply_multipliers

5. ATOMIC TRANSACTION                        lines 354-392
   ├─ START TRANSACTION
   ├─ Rate-limit re-check (inside txn — TOCTOU narrowed)
   ├─ persist_event()      — INSERT into wb_gam_events; PK collision
   │                         on event_id rejects replays
   ├─ insert_point_row()   — INSERT into wb_gam_points
   ├─ UPSERT wb_gam_user_totals (materialised; PointsEngine::award_batch)
   └─ COMMIT

6. SIDE EFFECTS (AFTER COMMIT, NOT IN TXN)   lines 417-419
   ├─ LevelEngine::maybe_level_up         ⚠ FINDING #2
   ├─ StreakEngine::record_activity       ⚠ FINDING #2
   └─ WebhookDispatcher::dispatch         ⚠ FINDING #2

7. do_action('wb_gam_points_awarded')        line 406
8. do_action('wb_gam_event_processed')       line 429
   ├─ NotificationBridge listens → writes transient queue
   └─ TransactionalEmailEngine listens → enqueues AS job
```

**Failure modes**:
- ⚠ **FINDING #1** — `wb_gam_before_evaluate` returning false leaves
  ZERO trace. No event log entry, no debug log line. A misconfigured
  filter can silently drop awards forever; debugging requires
  guessing the filter exists. Future fix: log every skip with the
  listener's reason, gated behind a `wb_gam_log_skipped_events`
  filter.
- ⚠ **FINDING #2** — side effects fire AFTER COMMIT, OUTSIDE the
  transaction. If `LevelEngine::maybe_level_up` fails (e.g. DB
  connection drops mid-request), the user gets their points but
  doesn't level up. There's no retry. Recovery requires manual
  replay or a separate reconciliation pass. The current design
  trades atomicity-with-side-effects for write throughput; the
  trade-off should be EXPLICIT, not implicit.
- ⚠ **FINDING #3** — `persist_event()` uses INSERT (not INSERT
  IGNORE). On a duplicate event_id the DB error bubbles up to the
  catch-all logger. That's correct behaviour for the replay
  protection invariant, BUT it means an upstream caller that
  reuses event_ids (e.g. a buggy integration manifest) emits noisy
  errors instead of being short-circuited cleanly.

### Flow 2: Badge auto-award

**Entry**: `do_action('wb_gam_event_processed', $metadata, $user_id)` (Engine.php:429)

**Pipeline** (`src/Engine/BadgeEngine.php`):

```
1. on_event_processed listener fires
2. Iterates active badge definitions from wb_gam_badge_defs
3. For each: evaluate condition rule against user's history
   ├─ action_count (e.g. "10 publish_post")
   │   └─ SELECT COUNT(*) FROM wb_gam_points WHERE user_id=? AND action_id=?
   └─ point_milestone (e.g. "100 total points")
       └─ SELECT total FROM wb_gam_user_totals WHERE user_id=? AND point_type=?
4. If threshold crossed AND not in wb_gam_user_badges already:
   ├─ INSERT IGNORE wb_gam_user_badges (race-safe — added in PERF-004 commit ef8fb69)
   ├─ do_action('wb_gam_badge_awarded')
   ├─ NotificationBridge listens → toast queue
   └─ TransactionalEmailEngine listens → email
```

**Failure modes**:
- ⚠ **FINDING #4** — badge evaluation runs O(N badges × N user history
  reads) on every event. For users with thousands of events and the
  default 30 badges, that's measurable per-event latency. The
  badge-condition contract (Rule 14) now guarantees no fabricated
  conditions, but doesn't address the **read amplification**. v2
  trajectory: maintain a per-user badge-evaluation cache table that
  tracks progress for action_count conditions, so per-event work is
  O(N badges) reads + 1 write rather than N reads.

### Flow 3: Leaderboard read

**Entry**: any of —
- `GET /wb-gamification/v1/leaderboard?period=week&limit=10`
- Block render of `leaderboard` block (server-render through controller)
- Heartbeat tick (subscribers register a board sig, server includes a
  fresh slice in the response)

**Pipeline** (`src/Engine/LeaderboardEngine.php`):

```
1. Cache lookup
   ├─ wp_cache_get('leaderboard:<period>:<scope>:<limit>:<type>')
   └─ HIT → return immediately
2. Cache miss → snapshot query
   ├─ SELECT user_id, SUM(points) AS total FROM wb_gam_points
   │  WHERE created_at >= ? AND point_type = ?
   │  GROUP BY user_id ORDER BY total DESC LIMIT ?
   └─ Materialised wb_gam_leaderboard_cache for the all-time period
      (the only board where the SUM is across all rows; weekly/monthly/
       daily snapshots use bounded windows)
3. Enrich with display_name + avatar_url
   ├─ Loops users → get_user_by(ID)
   └─ N+1 alert ⚠ FINDING #5
4. wp_cache_set(...)
```

**Failure modes**:
- ⚠ **FINDING #5** — display name + avatar enrichment is N+1
  (`get_user_by()` per result). At 10 results that's 10 user lookups;
  at the default limit it's bounded but a `limit=100` query is 100
  user lookups. The 100k-row benchmark (`audit/scale-baseline.md`)
  measures this path at 1ms — but that's a cold cache, the second
  request flies. Real-world latency depends on how many distinct
  leaderboard scopes a page renders. v2: batch the user lookups into
  one query (`SELECT * FROM users WHERE ID IN (...)`).
- ⚠ **FINDING #6** — cache invalidation. Award flow ends without
  invalidating leaderboard caches; staleness is bounded by the cache
  TTL (`wb_gam_leaderboard_cache_ttl` filter, default 300s). For most
  installs that's fine; for "live leaderboard" UX it's a multi-minute
  lag. The realtime broker bridges that gap when heartbeat or SSE is
  active — but if a user has notifications disabled, the leaderboard
  block they're staring at shows stale data. Documented in
  `audit/CODE_FLOWS.md` as intentional; could be a config knob if
  customers ask.

### Flow 4: Point-type conversion

**Entry**: `POST /wb-gamification/v1/point-types/{from}/convert` body `{to_type, amount}`

**Pipeline** (`src/API/PointTypesController.php` → `src/Services/PointTypeConversionService.php`):

```
1. Resolve from_type + to_type via PointTypeService
2. Load conversion rate from wb_gam_point_type_conversions
3. ATOMIC TRANSACTION
   ├─ START TRANSACTION
   ├─ SELECT total FROM wb_gam_user_totals
   │  WHERE user_id=? AND point_type=? FOR UPDATE   ← row lock
   ├─ Check balance >= amount; else ROLLBACK + 400
   ├─ Generate shared event_id (the linkage key)
   ├─ INSERT events row (debit side) point_type=from
   ├─ INSERT events row (credit side) point_type=to, linked_event_id=<shared>
   ├─ INSERT points row (-amount, from)
   ├─ INSERT points row (+amount * ratio, to)
   ├─ UPDATE user_totals (from = - amount)
   ├─ UPDATE user_totals (to = + amount * ratio)
   └─ COMMIT
4. Return { debited, credited, event_id }
```

**Failure modes** (this flow is the cleanest in the codebase):
- FOR UPDATE lock prevents concurrent debit. ✓
- Shared event_id makes both ledger rows queryable as one
  transaction. ✓
- No external side effects — pure ledger movement. ✓
- The transaction does NOT fire `wb_gam_event_processed`. That's
  intentional — conversions aren't gamification events. But it
  means badges that listen for "any event" don't see conversions.
  Documented in `audit/CODE_FLOWS.md` Phase 5 trace.

### Flow 5: Redemption

**Entry**: `POST /wb-gamification/v1/redemptions` body `{item_id}`

**Pipeline** (`src/API/RedemptionController.php` → `src/Engine/RedemptionEngine.php`):

```
1. Load item from wb_gam_redemption_items (active, not out of stock)
2. ATOMIC TRANSACTION
   ├─ START TRANSACTION
   ├─ SELECT total FROM wb_gam_user_totals
   │  WHERE user_id=? AND point_type=? FOR UPDATE   ← row lock
   ├─ If balance < cost → ROLLBACK + 400
   ├─ DECREMENT stock if > 0 (UPDATE WHERE stock > 0)
   ├─ If item type='woocommerce_coupon' → mint coupon code
   │   └─ External WC API call inside transaction ⚠ FINDING #7
   ├─ INSERT wb_gam_redemption_history
   ├─ INSERT debit ledger row (events + points + user_totals -= cost)
   └─ COMMIT
3. do_action('wb_gam_points_redeemed')
   └─ TransactionalEmailEngine listens → email
```

**Failure modes**:
- ⚠ **FINDING #7** — WooCommerce coupon-mint is called INSIDE the
  transaction. WC's coupon creation hits its own DB writes. If WC
  fails (gateway timeout, plugin disabled mid-checkout, etc.) the
  whole redemption rolls back — that's correct. But the WC writes
  are slow (~100ms-1s) and they hold the row lock from step 1. Two
  users redeeming the same item concurrently serialise on the WC
  call. v2 trajectory: WC coupon mint moves OUTSIDE the transaction,
  retried via Action Scheduler if it fails. The redemption is
  committed first; the coupon is "pending" until WC mints it; the
  email goes out only after mint succeeds.

---

## 3. Cross-cutting stability findings

Beyond the per-flow findings above, three structural debts surfaced
while tracing:

### Finding A — Implicit boot ordering

`wb-gamification.php` registers engines in a specific order:
`Installer@0`, `ManifestLoader@5`, `Registry@6`, engines + APIs @8,
integrations @10. The ordering is enforced by `add_action`'s priority
argument; the contract is documented in CLAUDE.md prose.

If a new engine is added with the wrong priority, the symptom is
silent — a listener registers before the dependency exists, the
callback fires, nothing happens. The class-hoist boot bug we caught
in commit `61f62ca` is a related failure mode (same shape: silent
boot failure).

**v2 proposal**: introduce `BootOrder::register('engine-name', 8)`
that asserts no two consumers claim the same slot and errors loudly
if a dependency claims a later slot than its dependent.

### Finding B — Side effects after COMMIT *(closed in this wave)*

Award flow's `LevelEngine::maybe_level_up`, `StreakEngine::record_activity`,
`WebhookDispatcher::dispatch` used to fire after the points commit
with no failure-handling beyond the global logger.

**Resolved**: the three calls were extracted to a
`SideEffectDispatcher::dispatch(Event, points)` fan-out. Each handler
is registered at Engine boot via `register_default_side_effects()`.
`dispatch()` wraps every handler in try/catch; failures are persisted
to `wb_gam_side_effect_failures` (new table) with retry counters. An
hourly cron `wb_gam_reconcile_side_effects` replays pending failures
up to `MAX_RETRIES` (3) times before marking the row `'exhausted'`
for human triage. 5 PHPUnit tests cover the dispatch-success,
dispatch-failure-isolation, retry-success, retry-exhausted, and
orphaned-handler paths.

Handler contract: each registered handler MUST be idempotent (the
reconciler re-fires from the original Event payload). The three
defaults already are — `maybe_level_up` derives current level from
`wb_gam_user_totals` and writes only on diff; `record_activity`
dedupes by date; `WebhookDispatcher::dispatch` enqueues at-least-once
delivery (documented public contract).

### Finding C — NotificationBridge transient durability *(partially closed)*

Re-scoped after reading the code: the "3-cursor dedupe" framing in
the initial walk was wrong. The cursors ARE per-consumer (footer,
heartbeat, rest) and ARE in user_meta already. Each consumer reading
the same toast is by design — three independent surfaces, each
delivering once. The Set-based dedupe in `assets/js/toast.js` is
the correct collapse for a user who has all three surfaces active
at once. NOT a band-aid.

The REAL debt was **durability**: the queue lived in a transient
which gets flushed on `wp_cache_flush`. When that happens, new event
ids restart at 1 but user_meta cursors still reflect the old high-
water mark — every `read_pending` returns empty for the lifetime of
the user. The code had a defensive workaround (walk cursors to bump
new ids past them) but the root cause was transient-only storage.

**Resolved (additive)**: new `wb_gam_notifications_queue` MySQL
table, dual-write from `NotificationBridge::push()`. Existing
consumers still read from the transient; durable backup survives
cache flushes. Daily prune cron (`wb_gam_notifications_queue_prune`)
keeps the table bounded with a 24-hour retention window. This
storage backend is also what SSE stage 2 needs — the streaming
controller polls the table by `WHERE user_id=? AND id > ?` which
the new `idx_user_id` covers.

**Still pending** (future commit): switch readers to table-first.
That requires verifying every existing read site, ensuring the
fallback to transient is clean. Currently the table is write-only
from consumers' perspective.

`NotificationBridge` writes toasts to a transient queue. Three
consumers read it: page-paint footer (cursor=footer), Heartbeat tick
(cursor=heartbeat), REST poll (cursor=rest). The cursors aren't
coordinated; same toast can be emitted three times. Current fix is a
Set-based dedupe in `assets/js/toast.js` keyed on the toast `_id`.

The dedupe works but is a band-aid. The structural fix is monotonic
event IDs at the broker level, with each consumer storing only the
last cursor it consumed — same problem the SSE storage table will
need to solve. The fact that SSE is being introduced makes this the
right time to consolidate.

**v2 proposal**: rebuild NotificationBridge around a single
`wb_gam_notifications_queue` table (not transients) with monotonic
auto-increment IDs. Consumers store `last_id_consumed` in user_meta.
The dedupe becomes obsolete because each event has exactly one ID
and each consumer fetches `id > last_id`. SSE writer becomes one of
the consumers.

---

## 4. v2 architecture proposal

> Direction, not commitment. Each item below moves through plan/MASTER-CHECKLIST
> with explicit drift gates before landing. Version numbers were intentionally
> dropped from this list — every item is in-scope for v1.5.0 (the only
> version while the plugin is pre-release).

### v2.1 — Decouple side effects *(shipped)*

`SideEffectDispatcher` (`src/Engine/SideEffectDispatcher.php`) +
`wb_gam_side_effect_failures` table + `wb_gam_reconcile_side_effects`
hourly cron. Engine refactor replaces the three inline calls with
`SideEffectDispatcher::dispatch(Event, points)`. Default handlers
registered for `level_up` / `streak` / `webhook`. 5 PHPUnit tests
cover the failure-and-replay paths.

### v2.2 — Unified notification queue (closes Finding C + the 3-cursor dedupe debt)

Single `wb_gam_notifications_queue` table, monotonic IDs, per-consumer
cursor in user_meta. Footer paint, Heartbeat tick, REST poll, and SSE
writer all become regular consumers. Toast.js dedupe code deletes.

**Sizing**: 2 commits. Migration commit (table + dual-write from
NotificationBridge), then consumer-switch commit (cursors move to
user_meta, dedupe removed).

### v2.3 — SSE stages 2–4 (closes the realtime perf gap)

Per `plan/REAL-TIME-TRANSPORT.md` § Stage rollout. Reuses the
notification queue from v2.2 as the storage backend — no new SSE
table needed.

### v2.4 — Boot order contract (closes Finding A)

`BootOrder::register(slug, slot)` enforces unique slots and
dependency ordering. Boot failures become loud at registration
time, not silent at runtime.

**Sizing**: 1 commit. New service class. Migrate all 27 existing
register calls in wb-gamification.php to declare via the new API.

### v2.5 — Read-side projection layer

Once side-effects are decoupled (v2.1) and notifications are queued
(v2.2), the engine becomes a pure write-path that emits an immutable
event log. Read-side projections (leaderboards, badge progress, hub
stats) become independent consumers that hydrate from the log + their
own materialised views.

This is the foundation for:
- **GraphQL** — a read-side projection that exposes the materialised
  views as a typed schema.
- **AI intelligence v1** — another read-side projection that computes
  churn-risk + diversity scores from the event log on a daily cron.
- **ActivityPub** — a write-side fanout that consumes the event log
  and publishes selected events as ActivityPub Outbox entries.

**Sizing**: this is the architectural shift, not a single commit.
Each projection is a separate commit; the foundation is the
"event-log consumer" pattern, which v2.1 + v2.2 establish.

### v2.6+ — Multi-server, federation, AI model integration

Out of immediate scope. Each becomes an additional projection or
consumer on the v2.5 foundation:
- **Multi-server SSE via Redis pub/sub** — replaces the DB-polling
  in SSE controller. Same writer, different transport.
- **ActivityPub Outbox** — projection that publishes events as
  AS2 activities. Requires the WP ActivityPub plugin as a
  dependency; we don't own the federation surface.
- **AI model integration** — heuristic v1 (v2.5) becomes
  pluggable model v2 via a `WBGam\AI\PredictorInterface`. Local
  ONNX or cloud-API providers as alternative implementations.

---

## 5. Build sequence

Pre-release: everything below lands as additive work on 1.5.0 HEAD.
Version stays at 1.5.0 until the first customer release. Order is
informed by dependency, not by release-tier.

```
Foundation wave (shipped — commits 0c3e7c4 → b160c81, 12 commits)
├── Generators (readme, docs_config, hooks_fired, frontend_assets)
├── OpenAPI artefact + SDK toolchain + 64 methods
├── AS-schedule guard
├── 100k scale baseline
├── ping() wiring
├── SSE scaffold (stage 1)
└── This stability + arch document

v2.1 Decouple side effects                  shipped  ✓
v2.2 Notifications-queue durability         shipped (write-side)  ✓
v2.2b Switch readers to table-first         1 commit (follow-up)
v2.3 SSE stage 2 (storage + writer)         1 commit
v2.3 SSE stage 3 (streaming loop)           1 commit
v2.4 Boot order contract                    1 commit
v2.3 SSE stage 4 (journey + default flip)   1 commit
v2.5 Read-side projection scaffold          1 commit
AI intelligence v1 (heuristic projection)   2 commits
GraphQL extension (WPGraphQL bridge)        2 commits
JS SDK method-coverage refinements          1 commit
Redis pub/sub for SSE                       TBD (multi-server)
ActivityPub Outbox projection               TBD (depends on ActivityPub plugin)
Pluggable AI provider interface             TBD (after AI v1 lands)
```

---

## 6. What this document is not

- Not a release plan. Releases go through `bin/cut-release.sh` with
  the customer-facing changelog format in `readme.txt`.
- Not a feature roadmap. `plan/PRODUCT-VISION.md` owns the strategic
  positioning + competitive context.
- Not a debugging guide. `audit/CODE_FLOWS.md` (the existing one)
  is the per-flow trace for active debugging.

This document is **direction-level architectural intent**, written
at the end of a stabilisation wave so the next set of work has a
coherent baseline to plan against.

Update this doc when v2.x items ship — flip the [ ] to [x] and add
the commit reference. Treat it as a living plan, not a snapshot.
