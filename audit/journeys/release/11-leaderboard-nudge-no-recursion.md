---
journey: leaderboard-nudge-no-recursion
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [PERF-001, hook-collision, action-scheduler-recursion]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli on PATH (Local Site Shell satisfies this)"
  - "Action Scheduler (woocommerce/action-scheduler) loaded"
estimated_runtime_minutes: 2
---

# Leaderboard nudge dispatch must not recursively re-enqueue itself

The original `LeaderboardNudge::send_nudge()` ended with
`do_action( 'wb_gam_weekly_nudge', ... )` — but that same string was
ALSO the cron hook the engine listened on, so every completed nudge
re-ran `dispatch_batch()` and re-enqueued an Action Scheduler job
for every active user. With 5 active users on a stalled dev site
the cascade reached 3.6M rows / 4.5 GB in 40 hours, starved PHP-FPM
workers, and took the site down with a 96%-AS-runner 502 storm.

The fix is a hook rename (`wb_gam_weekly_nudge_sent`) plus two
defence-in-depth layers (a per-user re-entrancy guard inside
`send_nudge`, and a 1-hour transient lock around `dispatch_batch`).
This journey is the regression sentinel — any future commit that
re-introduces the collision class of bug fails this gate, not QA.

See:
- `git-history snapshot of audit/PERF-DIAG-2026-05-27.yaml (PERF-001 fix shipped in f33a6b5)`
- Basecamp card 9932683754

## Setup

- Site: `$SITE_URL` = `http://wb-gamification.local`
- Plugin path: `wp-content/plugins/wb-gamification/`
- Tools: `grep`, `wp eval`, MySQL via Local-WP MCP

## Steps

### 1. Static check — hook name collision is gone

```bash
# CRON_HOOK is the action listener wired to dispatch_batch.
# The post-send extension hook must NOT share that string.
grep -n "do_action( 'wb_gam_weekly_nudge'" src/Engine/LeaderboardNudge.php
# Expected: no matches.

grep -n "do_action( 'wb_gam_weekly_nudge_sent'" src/Engine/LeaderboardNudge.php
# Expected: exactly one match (the post-send extension point).
```

Fail criteria: `wb_gam_weekly_nudge` re-appears as a `do_action`
target inside the engine.

### 2. Static check — recursion guard is in place

```bash
grep -n 'static \$in_progress = array' src/Engine/LeaderboardNudge.php
grep -n 'private const DISPATCH_LOCK_KEY' src/Engine/LeaderboardNudge.php
```

Fail criteria: either guard is removed.

### 3. Runtime — back-to-back send_nudge calls do not cascade

The recursion guard is per-user, scoped to a PHP request, and uses
`try / finally` so legitimate sequential nudges still complete. Two
calls for the same user in the same request must both return without
spawning AS jobs that target the same user.

```bash
wp eval '
\WBGam\Engine\LeaderboardNudge::send_nudge( 7 );
\WBGam\Engine\LeaderboardNudge::send_nudge( 7 );
echo "two-call ok\n";
'
```

Then check Action Scheduler:

```sql
SELECT COUNT(*) FROM wp_actionscheduler_actions
 WHERE hook = "wb_gam_nudge_single_user" AND args = "[7]"
   AND scheduled_date_gmt > NOW() - INTERVAL 1 MINUTE;
```

Fail criteria: > 0 newly-scheduled rows for user 7 (the engine should
NOT enqueue from inside `send_nudge` itself — only `dispatch_batch`
enqueues).

### 4. Runtime — dispatch_batch is single-fire-per-hour

```bash
wp eval '
\WBGam\Engine\LeaderboardNudge::dispatch_batch();
\WBGam\Engine\LeaderboardNudge::dispatch_batch();
echo "two-batch ok\n";
'
```

Then check the lock transient:

```bash
wp transient get wb_gam_nudge_dispatch_lock
# Expected: 1 (transient was set on first call, second call short-circuited).
```

Fail criteria: transient missing OR second call enqueues a fresh
round of AS jobs (compare row counts before / after — should be flat).

### 5. AS table size stays bounded

After 5 minutes of normal operation, the AS table should have no
unexpected growth from wb_gam_* hooks:

```sql
SELECT hook, COUNT(*)
  FROM wp_actionscheduler_actions
 WHERE hook LIKE "wb_gam_%"
 GROUP BY hook
 ORDER BY 2 DESC;
```

Fail criteria: any single `wb_gam_*` hook over 1000 pending rows.

## Pass criteria

All five steps pass without modification. The journey itself can be
re-run safely (idempotent — only reads / single-shot enqueues).

## Failure-mode coverage

This journey would have caught:
- The original PERF-001 recursion (step 1 fails on the unrenamed hook).
- Any future re-introduction of a collision between a cron hook and a
  do_action fired from inside a callback bound to that hook.
- A regression where the guards (re-entrancy or transient lock) are
  removed in a refactor.

## Cleanup

```bash
wp transient delete wb_gam_nudge_dispatch_lock
```
