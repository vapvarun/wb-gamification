# Importer Architecture Contract (all sources)

Owner directive 2026-07-03: importers READ the source plugin's database via SQL and
WRITE exclusively through our API/engine — never direct inserts into `wb_gam_*`
tables. **Gaps are not allowed**: every source storage location is either mapped or
explicitly reported as skipped-with-reason. Basecamp epic: card 10061744564.

Per-source maps: `gamipress-data-map.md` · `mycred-data-map.md` · `badgeos-data-map.md`
(each analyzed 2026-07-03 from pristine wp.org builds: GamiPress 7.9.5, myCred 3.1.2,
BadgeOS 3.7.1.6; sources extracted next to this plugin for study — never activate on
customer sites).

---

## 1. Receiving-side PREREQUISITES (build before any importer)

Verified against our current code — these are hard blockers:

1. **`occurred_at` backdating.** `POST /events` args are only
   action_id/user_id/object_id/metadata (`src/API/EventsController.php:70-110`);
   `PointsEngine` stamps `created_at = current_time('mysql')`
   (`src/Engine/PointsEngine.php:331`). Every source carries real historical
   timestamps (GamiPress earnings.date, myCred log.time, BadgeOS date_earned) —
   without backdating, 3 years of history collapses into import day. Add an
   import-privileged `occurred_at` param through the engine write path (events +
   points + badge award + level set).
2. **Import mode (side-effect suppression).** Historical writes must NOT fire emails,
   toasts, webhooks, BP notifications, or realtime queues — importing 1M events would
   otherwise notify 100k members. One flag through the engine (e.g. event metadata
   `import=true`) consumed by every side-effect listener.
3. **Bypass earning governance.** Import-mode writes skip rate limits, daily caps,
   cooldowns, and exclusion of historical actors (but RESPECT current exclusion for
   balance display). Precedence documented per import run.
4. **Idempotency / provenance.** Every imported record carries
   `{source, source_id, source_ref}` in event metadata; the importer checks
   existence before write (or the engine enforces uniqueness on
   source+source_id) so a crashed/resumed run NEVER double-awards.
5. **Badge/level backdating.** Badge award and level set must accept an awarded-at
   timestamp (BadgeOS/myCred `issued_on`/`date_earned`, GamiPress `rank_earned_time`).

## 2. Pipeline (uniform across sources)

```
Detect source + version/storage-path  →  Dry-run (counts + mapping coverage report)
→ Owner confirms  →  Batched import (Action Scheduler, id-cursor pagination,
resumable)  →  Reconciliation (computed balances vs source balances; drift report)
→  Completeness audit (distinct-key queries from each map's guard section)
→  Final report (imported / skipped-with-reason / gaps=0)
```

- **Dry-run is mandatory** and produces: per-concept counts, mapping coverage
  (every distinct ref/meta-key → mapped | skip-with-reason), estimated batches,
  and the rules-engine report (earning rules are never auto-imported — owners
  re-author in our Settings; the report shows them exactly what to recreate).
- **Batching:** Action Scheduler jobs, page by primary-key cursor
  (`WHERE id > ? ORDER BY id LIMIT n`) — never OFFSET; WP-CLI parallel path
  (`wp wb-gamification import --source=X --dry-run`).
- **Rollback:** all imported rows are provenance-tagged → one command deletes by
  tag (compensating events), restoring pre-import state.
- **Mode choice per run:** `balance-snapshot` (fast: one opening-balance event per
  user/type; default for GamiPress where user_meta balance is authoritative) vs
  `ledger-replay` (full history; default for myCred/BadgeOS where the ledger is
  authoritative). NEVER both. Replay always ends with reconciliation vs the
  source's own balance value.

## 3. Source quirks cheat-sheet (details in per-source maps)

| | GamiPress 7.9.5 | myCred 3.1.2 | BadgeOS 3.7.1.6 |
|---|---|---|---|
| Balance authority | user_meta `_gamipress_{pt}_points` | user_meta `{type}` (ledger-derivable) | DERIVED from ledger only (meta total drifts) |
| History | CT tables (earnings + logs) | `myCRED_log` (case-variant names!) | tables AND/OR legacy serialized meta (dual path) |
| Multi-currency | points-type CPT slug | `mycred_types` + ctype | point-type CPT post ID |
| Levels | rank CPT menu_order | rank CPT min/max per ctype | rank CPT menu_order=priority; user meta stores ENTRY id |
| Badges | achievement CPTs (dynamic slugs) | badge CPT + `badge_prefs` LEVELS array | badge CPTs (dynamic, 20-char truncated slugs) |
| Timestamps | datetime | unix SITE-LOCAL → normalize UTC | datetime + legacy unix epoch mix |
| Poison pitfalls | never replay `event_trigger` logs (double-award) | `data` col = PHP serialize, `entry` = template | migrated sites hold the SAME earn in meta AND table (`rec_type='normal_old'`) |
| Their own importer to crib | tools/import-export-*.php | importers/mycred-cubepoints.php | includes/meta-to-db.php |

## 4. What is never imported (uniform skip list)

Rules-engine state/counters (triggered_triggers, ref_counts, comment limits),
caches, secrets (mycred_key), widget instances, pending-payment workflow state,
real-money gateway configs, third-party credential re-issuance (Credly/Open Badges —
we can issue FRESH OpenBadges 3.0 credentials post-import instead: marketing angle).
Each appears in the dry-run report with its skip reason — silent omission is a bug.
