# Handoff 2026-09-29: the three Ready-for-Development cards are in Ready for Testing

Branch `1.6.5` only. No 1.6.6 branch, and no release steps (changelog, benchmark run, merge, tag,
zip, testbed reseed) until the owner says. `git log --oneline -15` shows every commit below.

## Cards now in Ready for Testing (Gamification board 47162271, RFT column 9860021091)

| Card | What shipped | Journey |
|---|---|---|
| 10304072907 Leaderboard paging | keyset cursor paging, `SNAPSHOT_DEPTH` 500, REST + SDK + docs | release/25 |
| 10062823272 Importer batching + undo | chained background pages, checkpoint/resume, reconcile, undo by key prefix | release/26 |
| 10343975676 Admin menu, permissions, labels | submenu order, Levels page, Submissions cap, nudge guard, lazy labels, WP Sell Services, empty states | release/27 |

Each card carries its own "what I ran / not verified" comment; read those before QA bounces anything.

## Known limits (stated on the cards, repeat here so nobody re-derives them)

- Importer: GamiPress and BadgeOS readers verified by code and contract tests only (plugins not installed);
  WP-Cron fallback path not exercised; largest real run is 50,000 rows (22 s, zero mismatches), the
  100k-member / 1M-row ceiling is extrapolated. Undo leaves badges and bonus points earned because of the
  imported history.
- Leaderboard: the 500-rank snapshot cap was not exercised with more than 500 real rows.
- WP Sell Services: reviews held for moderation earn nothing (needs per-review idempotency on
  `wpss_review_moderated`).
- Email cap stays in the staff matrix (REST-only); hiding it would strip REST grants on save.

## Standing traps

- The Plugin Check gate needs a temporary install: `wp plugin install plugin-check --activate`, run
  `WBGAM_WP_PATH=<site> composer ci:no-journeys`, then deactivate and delete it. Baseline is 319 warnings.
- The Playwright MCP writes `.playwright-mcp/` and screenshots into the WP root; delete them.
- `wp eval` on this Mac hits the buddynext.local database; check `siteurl` first.

## Next, when the owner asks

1. Answer QA bounces on the three cards above (reproduce at the exact viewport first).
2. BuddyNext popup design-system cards (10345772171, 10345835986) are in RFT on this board; the shared
   popup shell work is theirs to confirm.
3. Release steps stay on hold.
