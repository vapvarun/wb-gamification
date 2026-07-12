# Large-site readiness — the WB Gamification standard

**Every rule here was bought with a bug we actually shipped.** None is theoretical. Each cites the
defect that taught it, because a rule without a scar is a rule people argue with.

The target: **100,000 members, millions of ledger rows, on a host where WP-Cron never fires and
there is no Redis.** That is not the exotic case. That is a successful customer.

---

## R1 — One clock. Declare it, and never compare across clocks.

WordPress hands you four clocks and no default:

| Expression | Returns |
|---|---|
| `current_time( 'mysql' )` | the SITE's local time |
| `current_time( 'mysql', true )` | UTC — the second arg is the GMT flag |
| `gmdate()`, `time()`, `new DateTimeImmutable()` | UTC (WP pins PHP's default tz to UTC) |
| SQL `NOW()` | the DATABASE SERVER's clock — independent of both, usually UTC |

**A column written in one clock and compared against another is broken on every site that is not
UTC — and correct on the developer's box, which is why it survives.**

This single defect produced **five** of the seven bugs in 1.6.4:

| Bug | What the member saw |
|---|---|
| `LeaderboardEngine::write_snapshot()` stamped rows with `current_time()` and pruned with `NOW()` | On any site ahead of UTC the rebuild **deleted the rows it had just written**. The board was permanently empty and every request fell back to a full ledger aggregate. |
| `KudosEngine` cooldown boundary | The per-receiver cooldown **never fired at all** on any site behind UTC. Spam protection simply absent across a hemisphere. |
| `WeeklyEmailEngine` digest window | The email covered the wrong seven days. |
| `WeeklyEmailEngine` recipient query | The email went to **the wrong people**. A missing email leaves no trace, so nobody reports it. |
| `ChallengesController` window | A challenge scheduled for 09:00 opened at 09:00 **UTC**. Members could not join a challenge that was live. |

**The rule:**
1. Every timestamp column has **one** documented clock. Write it down in the schema.
2. A comparison boundary is computed in **the same clock the column is written in**. Never `NOW()`
   against a `current_time()` column; never `gmdate()` against a local one.
3. Prefer binding a PHP-computed value as a prepared parameter over SQL `NOW()`, so the database
   server's own timezone stops being a variable you don't control.

**Current state: 24 local writes vs 3 UTC writes.** Zero cross-clock *comparisons* (all fixed in
1.6.4) — but the mixed convention is a loaded gun. The next person who adds a `gmdate()` boundary
against one of those 24 columns reintroduces the bug, and it will pass every test on a UTC box.
**Converging on one convention is R1's outstanding work.**

---

## R2 — A lock must be atomic. Check-then-act is not a lock.

```php
if ( get_transient( $lock ) ) { return; }   // both racers see nothing here
set_transient( $lock, 1, 60 );              // ...and both set it here
```

That is two operations. Two concurrent workers both pass. **It is not a lock; it is a comment that
looks like one.**

The same trap wearing a different hat:

```php
if ( ! wp_cache_add( $key, 1, '', 60 ) ) { return; }
```

`wp_cache_add()` **is** atomic — *when a persistent object cache is installed.* On a default
WordPress install there is none, so it is a process-local array and provides **zero** exclusion
between workers. `KudosEngine` shipped exactly this, with a comment asserting it was "atomic across
Redis/Memcached" — true, and irrelevant on the most common configuration there is. Two concurrent
kudos both landed.

**The rule:** locks go through **one** shared primitive, backed by the database — the only thing
every PHP worker demonstrably shares. `SELECT GET_LOCK()` / `RELEASE_LOCK()`, acquired with timeout
0, released in a `finally` so it cannot leak.

**Current state: 1 atomic lock, 4 check-then-act.** Outstanding:

| Site | What a race costs |
|---|---|
| `SiteFirstBadgeEngine:149` | **Two members both awarded "first to reach Champion."** The badge's entire promise. Its comment says "Race-safe" — it is not. |
| `WeeklyEmailEngine:150` | A duplicate weekly email to every member. (Mitigated by a per-user Action Scheduler dedupe — belt, not braces.) |
| `LeaderboardNudge:112` | Duplicate nudge jobs. (Same mitigation.) |
| `DbUpgrader:64` | Concurrent migration runs. (Mostly inert — `dbDelta` is idempotent.) |

---

## R3 — Uniqueness is the database's job, not a `SELECT COUNT`.

```php
$n = SELECT COUNT(*) ... WHERE badge_id = X;
if ( $n >= $max ) { return false; }
INSERT ...
```

Check-then-act again, and `max_earners` is enforced exactly this way. Under concurrency, two workers
both read `$n = 0` and both insert. Wrapping it in an R2-violating lock does not help — **that is
three non-atomic layers stacked, and `SiteFirstBadgeEngine` stacks all three.**

**The rule:** a uniqueness or scarcity invariant is enforced by a **UNIQUE index**, an atomic
`UPDATE ... WHERE stock > 0`, or an R2 lock. Never by counting first.

We already do this correctly in one place, and it is the proof the pattern works — redemption stock:

```sql
UPDATE ... SET stock = stock - 1 WHERE id = %d AND stock > 0
```

One statement. The loser gets 0 affected rows and rolls back. Two members cannot both take the last
unit. **Copy this shape.**

---

## R4 — `0` never means two things.

Stock read as *"NULL or 0 = unlimited"*, and the atomic decrement walks finite stock down to exactly
0. So a reward with one unit sold it, landed on `0`, and **became infinitely redeemable.** An owner
offering one laptop gave away laptops without limit.

**The rule:** a sentinel value means one thing. Three states get three representations
(`NULL` = unlimited, `0` = sold out, `n` = finite). If a value can be *reached by arithmetic*, it
cannot also be a flag.

---

## R5 — Cron fans out with a keyset cursor. Never "the whole site in one tick."

`get_users()` with no limit, then a loop, is an OOM at 100k members — and the job then **never
completes, so the emails never send and nobody finds out.** A silent failure is worse than a crash.

**The rule:** `WHERE user_id > :cursor ORDER BY user_id LIMIT :n`, next page handed to Action
Scheduler. No `OFFSET` (deep offsets scan everything they skip). Every self-scheduling handler is
guarded with `as_has_scheduled_action()` on hook + args, or it will re-enter itself and double-send.

---

## R6 — Never age out another plugin's work.

`ActionSchedulerCleaner` deleted **every** pending Action Scheduler job older than the retention
window, with no ownership check. On a WooCommerce site that is orders and subscription renewals.

**The rule:** any DELETE against shared infrastructure carries an ownership predicate
(`AND hook LIKE 'wb_gam_%'`). **Pending work is never routine housekeeping** — only completed and
failed rows age out.

---

## R7 — Every hot read is bounded, indexed, and measured.

- **Bounded:** a `SELECT` with no `LIMIT` is fine on a config table and fatal on an event table.
  Know which one you are touching.
- **Indexed:** every `WHERE` / `ORDER BY` / `JOIN` column has an index. Verify with `EXPLAIN`, not
  by eye — `wb_gam_user_badges` had a composite `UNIQUE(user_id, badge_id)` that **cannot** serve a
  `badge_id`-only predicate, so badge rarity full-scanned an event table on every badge render.
- **Measured:** hot paths carry a budget in `ScaleCommand::BUDGETS_MS`, benchmarked against a
  **seeded 100k dataset**. A release cannot be packaged without a green, seeded run
  (`build-release.sh` exit 32).

The proof this is not theatre: `idx_badge_id` is load-bearing — `badge_rarity_map` runs **27.8ms
with it and 52.4ms without**, over its 50ms budget.

---

## R8 — Aggregates are materialised, and the snapshot must not fight itself.

The leaderboard was "materialised" and yet aggregated the full ledger on nearly every request,
because an `invalidated_at` option was bumped on **every award** — so on any site with traffic the
snapshot was always considered stale and always bypassed. The plugin advertised a snapshot and
shipped a live `GROUP BY`.

**The rule:** a materialised read is **eventually consistent, on purpose.** Rebuild on a schedule.
Do **not** invalidate on write. A board that has not moved yet is correct behaviour, not a bug —
and the regression guard must say so, or the next engineer will "fix" it back into an outage.

---

## R9 — An integration owns no tables.

A plugin is a data type. An integration is an **adapter**: it maps a foreign event onto that data
type and writes through the owning service. It stores nothing of its own.

**Current state: 17 integration manifests, zero `CREATE TABLE`.** The one historical violation
proves the rule — `wb_gam_partners` survives today only as a `DROP TABLE IF EXISTS` line in
`DbUpgrader`, commented *"drop unused."* It was created, never earned its keep, and was removed.

---

## R10 — If it renders, it is real. If it is a guard, prove it fires.

A toggle that saves and is never read is worse than no toggle — the owner believes they are
protected. Grep every key you *render* for a **reader**.

And the sharper version, learned the hard way: the setup wizard's unregistered-action guard called
`Log::warning()` without importing `Log` — so the guard would **fatal at the exact moment it caught
something.** The error path was the crash path.

**The rule:** every guard has a test that makes it fire. Reintroduce the bug; the test must fail.
A guard nobody has watched trip is a guess.

---

## The bar

| | |
|---|---|
| Members | 100,000 |
| Object cache | none |
| WP-Cron | never fires |
| Database timezone | not the site's |
| Concurrency | two workers hitting the same row |

Every rule above is a thing that only breaks under one of those five conditions — which is why they
all shipped, and why none of them showed up on a developer's laptop.
