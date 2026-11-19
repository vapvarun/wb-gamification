---
journey: import-run-here-second-tab
plugin: wb-gamification
priority: normal
roles: [administrator]
covers: [import-runner, run-here, lock-declined, late-click, basecamp-10458810873]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A source ledger to import (see journey 26) and the background queue stopped (DISABLE_WP_CRON true, no Action Scheduler runner) so the run shows as stalled"
estimated_runtime_minutes: 10
---

# Import: a second "Run it in this window" tab waits instead of hammering the server

When the background queue is dead, the Import screen offers "Run it in this window", which drives one
page per request. If two tabs do that, only one holds the lock; the other gets back progress with no
page run. Before 1.6.8 that tab asked again at once, in a tight loop. A click on Resume or "Run it in
this window" after the run already finished showed a red error.

## Steps

### 1. Second tab backs off
- **Action**: start an import, let it go stalled. Open the Import screen in two tabs, click "Run it in
  this window" in both. Watch the Network panel of the second tab.
- **Expect**: while the first tab holds the lock, the second sends `POST /import/{source}/resume` about
  once every 2 seconds, not continuously. The run finishes once, both tabs end on the results.
- **On fail**: `assets/js/admin-import.js` `pump()` must wait `POLL_MIN_MS` when `seq` did not move.

### 2. Late click is neutral
- **Action**: in a tab still showing the stalled panel, let the other tab finish the run, then click
  "Resume import" (or "Run it in this window").
- **Expect**: a plain note "This import has already finished." and the results; no red error text.
- **On fail**: `lateClick()` in `assets/js/admin-import.js` (codes `wb_gam_import_nothing_to_resume`,
  `wb_gam_import_running`), strings in `src/Admin/ImportPage.php`.

## Pass criteria

1. A declined tab sends at most one resume request per 2 seconds.
2. A click after completion never shows an error.
