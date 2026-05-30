# Scale Verification — 100k members / 1M rows (2026-05-30)

Verifies the read hot-paths stay within budget at the target scale of
**100,000 members per site**. Run with the bundled benchmark:

```bash
wp wb-gamification scale seed --users=100000 --events-per-user=10   # ~1M ledger rows
wp wb-gamification scale benchmark
wp wb-gamification scale teardown
```

## Result — PASS

Dataset: **1,000,155 ledger rows across 100,009 users** (200,009 materialized
`user_totals` rows). All six hot-path queries well within budget:

| Query | Time | Budget | Status |
|-------|------|--------|--------|
| `get_total_pk` (materialized total, most-called read) | 1.96 ms | 5 ms | PASS |
| `get_totals_by_type_pk` (multi-currency hub) | 0.06 ms | 5 ms | PASS |
| `leaderboard_snapshot` (top 10, weekly) | 2.37 ms | 20 ms | PASS |
| `points_history_user` (20 rows, paginated) | 0.17 ms | 30 ms | PASS |
| `rate_limit_today_count` (per-action hot check) | 0.21 ms | 15 ms | PASS |
| `convert_balance_lookup` (FOR UPDATE pre-flight) | 0.03 ms | 5 ms | PASS |

> "All queries within budget — 100k-ready against this dataset."

## Why it holds

The reads never aggregate the ledger live:

- **`get_total`** is a primary-key lookup on `wb_gam_user_totals` (the
  materialized per-user/per-type total), not a `SUM()` over `wb_gam_points`.
  1.96 ms over 1M rows because it touches one row by PK.
- **Leaderboard** reads a precomputed snapshot (`wb_gam_leaderboard_cache`)
  refreshed by cron, not a live `RANK() OVER (...)` on every request.
- **History / rate-limit** ride the composite index
  `(user_id, action_id, created_at)` — sargable, bounded by `LIMIT`.

## Host prerequisites (required above ~10k members)

These are not optional at scale (see CLAUDE.md "Production hosting"):

1. **Persistent object cache** (Redis/Memcached) — every `get_total` /
   `get_totals_by_type` / `get_leaderboard` reads through `wp_cache_*`.
2. **Action Scheduler** — async badge/level/streak evaluation; keeps the hot
   request path sub-100 ms during award bursts.
3. **MySQL 8.0+** — the leaderboard snapshot uses `RANK() OVER (...)`.

## Scope notes

- This verifies a **single install at 100k members** — the code-relevant axis.
  "100k sites" is a hosting / multi-tenancy concern; each site has its own
  tables, so per-install scale is what the plugin controls.
- Benchmark covers the **read** hot-paths (the most-called surfaces). The write
  path (event append + `bump_user_total` UPSERT, wrapped in `Transaction::run`)
  is O(1) per award by construction; a future addition could add a concurrent
  write-burst benchmark. Re-run this benchmark before any release that touches
  read paths (`composer scale:bench`).
