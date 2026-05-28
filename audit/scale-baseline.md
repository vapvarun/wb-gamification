# Scale baseline — 100k-readiness verified

> **First-time-measured: 2026-05-28** against `wp wb-gamification scale benchmark`
> on a 100k-row synthetic dataset (10,003 users × ~10 events each).
> Local-by-Flywheel, PHP 8.2.27, MySQL 8.

The "Scalable — built for 100K+ members" claim in readme.txt is no longer
faith-based. The numbers below are the first recorded run; if the next
run hits a query budget, the regression is real.

## Procedure to reproduce

```bash
wp wb-gamification scale seed --users=10000 --events-per-user=10
wp wb-gamification scale benchmark
wp wb-gamification scale teardown
```

Seeded data uses `user_id >= 1000000` so teardown is bounded; it does
NOT touch any real user data.

## Results (2026-05-28)

| Query                    | Budget   | Actual   | Headroom |
|--------------------------|----------|----------|----------|
| `get_total_pk`           | 5.0 ms   | 1.55 ms  | 3.2x     |
| `get_totals_by_type_pk`  | 5.0 ms   | 0.07 ms  | 71x      |
| `leaderboard_snapshot`   | 20.0 ms  | 1.01 ms  | 20x      |
| `points_history_user`    | 30.0 ms  | 0.10 ms  | 300x     |
| `rate_limit_today_count` | 15.0 ms  | 0.11 ms  | 136x     |
| `convert_balance_lookup` | 5.0 ms   | 0.03 ms  | 167x     |

All 6 PASS. Verdict: **100k-ready**.

## Why the budgets aren't tighter

The headroom is huge — `points_history_user` runs 300x under budget —
but `BUDGETS_MS` in `src/CLI/ScaleCommand.php` reflects the
customer-experience thresholds, not the regression-detection
thresholds. A 5 ms primary-key lookup is the right ceiling for a UI
read path: that's roughly the limit where a page-paint pipeline can
still feel instant. The headroom exists so the budget catches *real*
problems (an unindexed scan, an N+1 leak) rather than micro-variance.

If a query starts approaching its budget over time, that's the
warning sign. The current readings tell us the indexes and snapshot
caches are doing what they should.

## What's measured

The 6 queries cover the read-side hot paths:

1. **`get_total_pk`** — single-currency total fetched from
   `wb_gam_user_totals` (materialised). Hit on every page paint that
   renders a member's points.

2. **`get_totals_by_type_pk`** — multi-currency breakdown via the
   same materialised table, GROUP BY point_type. Hit on the hub.

3. **`leaderboard_snapshot`** — top-10 week-window read from
   `wb_gam_leaderboard_cache`. Hit on every leaderboard block render.

4. **`points_history_user`** — paginated ledger read, latest first.
   Hit on the points-history block + admin manual-award screen.

5. **`rate_limit_today_count`** — `COUNT(*)` of today's events for
   one user, one action. Fires on every action attempt, so this
   query runs more often than any other.

6. **`convert_balance_lookup`** — pre-flight balance check for
   point-type conversion. Wraps the atomic FOR UPDATE path; lock
   contention isn't tested here (single-user benchmark).

## What's NOT measured

- **Award write path** (`PointsEngine::award_batch`). The benchmark
  is read-only by design; the write path is event-sourced and bounded
  by the engine's `INSERT IGNORE` + async drain.
- **Multi-tenant contention** (concurrent writes from cron + async
  evaluator). The async pipeline + circuit-breaker close that class;
  a load test belongs in a separate JMeter / k6 harness.
- **API-key auth overhead.** Per-request hashing cost isn't isolated
  here — covered by the integration suite (`tests/Integration/`).

## When to re-run

Trigger conditions:

- New table or schema change touching `wb_gam_points`,
  `wb_gam_user_totals`, `wb_gam_events`, or `wb_gam_leaderboard_cache`.
- Index changes anywhere in `src/Engine/DbUpgrader.php`.
- Significant query rewrites in `PointsEngine` / `LeaderboardEngine` /
  `PointTypeService`.
- Periodic — quarterly is enough given the headroom.

Run, paste new numbers under a dated heading below.

## Why this isn't in local-CI

Seeding 100k rows takes ~2 seconds against MySQL 8 on this hardware,
which is fine for an interactive run but adds friction to every
commit. The benchmark stays as a manual gate per-major-release
verification rather than a CI stage — the existing PHPStan + WPCS +
WP Plugin Check stages already catch the categories of regression
this would (missing indexes flag as `WordPress.DB.SlowDBQuery.*`).

`bin/build-release.sh` could plausibly run the seed+bench+teardown
as a release-blocker for major-version bumps. Not wired today; flag
as a future enhancement if we ever ship a perf-relevant version that
needs an explicit "100k re-verified" stamp.
