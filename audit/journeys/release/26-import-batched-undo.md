---
journey: import-batched-undo
plugin: wb-gamification
priority: high
roles: [administrator, staff-without-manage-options]
covers: [import-runner, chained-jobs, checkpoint-resume, reconcile, idempotency, undo, pruner-keeps-imported, no-timeout]
prerequisites:
  - "Site reachable at $SITE_URL, WP-CLI available"
  - "A source ledger to import: a scratch myCred log table (wp_myCRED_log) with 1,000+ rows across 50+ members"
estimated_runtime_minutes: 20
---

# Import: paged, resumable, reconciled, undoable

A site migrating from myCred, GamiPress or BadgeOS moves years of history. If this regresses, the import
dies on a PHP time limit halfway with nothing saved, a re-run double-counts, a crash loses the run, the
screen claims success while balances differ, or "undo" leaves members with wrong totals.

## Setup

- Site: `$SITE_URL`, admin `?autologin=1`.
- Temporary data (delete afterwards): the scratch `wp_myCRED_log` table, users `qa_imp_*`, levels named
  `QA *`, option `wb_gam_import_run_mycred`.

## Steps

### 1. A run is queued, paged and finishes on its own
- **Action**: `POST /import/mycred` with `dry_run=false`, then poll `GET /import/mycred/progress`.
- **Expect**: 202 at once with `status: queued`; `phase` moves levels, points, awards, recompute,
  reconcile; `percent` never decreases; ends `complete`. On a site without Action Scheduler running in
  admin the first page waits for the next WP-Cron tick (up to about a minute).
- **On fail**: `src/Engine/ImportRunner.php` `tick()`, `schedule()`, `has_pending_job()`. A run stuck at
  2% means the job args lost their `seq` (pinned by `ImportRunnerTest`).

### 2. Nothing is counted twice
- **Action**: start the same import again after it completed; run `import-status`.
- **Expect**: `skipped_duplicate` equals the earlier imported count, `imported` is 0, every member's
  total is unchanged.
- **On fail**: `ImportService::ingest()`, `uniq_source_key`, `LogPruner` (must skip rows with a `source_key`).

### 3. Reconciliation is the verdict
- **Action**: read `mismatches` from progress after step 1.
- **Expect**: points, badges and ranks all 0. Change one member's source balance by hand and re-run: the
  mismatch is reported with `user_id`, `ours` and `source`, and appears in the admin screen.
- **On fail**: `ImportRunner::reconcile_member()`.

### 4. A crash mid-page loses nothing
- **Action**: kill the process during the points phase (or throw from a source read), then
  `POST /import/mycred/resume`.
- **Expect**: the run continues from its last committed page; final totals equal an uninterrupted run;
  a run with no checkpoint for 120 s and no pending job reports `stalled: true` and the admin screen
  offers Resume.
- **On fail**: `ImportRunner::resume()`, `is_stalled()`, the per-page transaction.

### 5. Retention does not undo the import
- **Action**: run `wp wb-gamification logs prune` with a horizon older than the imported rows.
- **Expect**: imported events (non-null `source_key`) survive; organic events older than the horizon go.
- **On fail**: `LogPruner::prune_table()` `$extra_where`.

### 6. Undo restores exactly what the import added
- **Action**: `POST /import/mycred/undo` without `confirm` (400 `wb_gam_confirm_required`), with
  `dry_run=true` (counts), then with `dry_run=false&confirm=true`; poll progress. Repeat as a staff user
  without `manage_options` (403).
- **Expect**: imported events, badge awards and the levels the import created are gone; every member's
  `total` and `earned` equal what their remaining ledger implies, including after step 5 removed old
  points rows; a member who spent imported points is reported in `negative`; a re-import afterwards
  reconciles with zero mismatches and re-awards the badges (no stale per-member badge cache).
- **On fail**: `src/Engine/ImportUndo.php` `step()`, `remove_events()`, `after_totals_changed()`.

### 7. Admin screen (Settings > Import), 1440 and 390
- **Action**: run an import and an undo from the screen.
- **Expect**: native progress bar with a live phase line, no fixed timeout, Resume on stall or failure,
  the red "Remove imported data" button opens a confirm with focus on Cancel, no horizontal scroll at
  390px, dark mode legible.
- **On fail**: `assets/js/admin-import.js`, `assets/css/admin/pages/import.css`.
