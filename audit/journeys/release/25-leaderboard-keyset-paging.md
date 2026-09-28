---
journey: leaderboard-keyset-paging
plugin: wb-gamification
priority: high
roles: [anonymous, member, administrator]
covers: [leaderboard-paging, leaderboard-total, cursor-gate, rank-continuity, orphan-skip, no-offset]
prerequisites:
  - "Site reachable at $SITE_URL with at least 30 members who have points"
  - "WP-CLI and the REST API reachable"
estimated_runtime_minutes: 10
---

# Leaderboard: browse the whole board by cursor

Members and apps can page through a board. If this regresses, a member is served twice or skipped
at a page boundary, ranks restart at 1 on page two, a deleted member's leftover totals row shortens
a page, or an anonymous visitor can read every member's name and points in a loop.

## Setup

- Site: `$SITE_URL`. Member: `?autologin=<member login>`.
- Temporary data (delete afterwards): 7 real users tied on `earned = 5000` in `wb_gam_user_totals`
  (point_type `points`), and 30 orphan totals rows `user_id` 900000-900029 with `earned = 9999`
  (a member deleted, the row left behind).

## Steps

### 1. A full walk serves every member exactly once
- **Action**: `wp eval` a loop over `wb_gam_get_leaderboard_page( 'all', 20, $cursor )` following
  `next_cursor` until `has_more` is false. Repeat with page sizes 3, 4, 5 and 20.
- **Expect**: the walked count equals `wb_gam_get_leaderboard_total( 'all' )` and `X-WP-Total`; no
  `user_id` repeats; ranks never decrease; the members from an all-in-one page of 100 are the same and
  in the same order.
- **On fail**: `src/Engine/LeaderboardEngine.php` `get_leaderboard_page()`, `totals_board()`.

### 2. Ties and orphans do not disturb paging
- **Action**: with the temporary data above, repeat step 1 at page size 3.
- **Expect**: none of the 30 orphans is served and the total is unchanged by them; the 7 tied members
  are each served once, all with rank 1; the next distinct score has rank 8 (not 2); the rest of the
  board is identical to the walk without the temporary data.
- **On fail**: `totals_board()` keyset advance, `hydrate_rows()` seed, `rank_state()`.

### 3. No OFFSET, and deep pages stay flat
- **Action**: `EXPLAIN` the keyset totals query for a cursor deep in a large board (or run
  `wp wb-gamification scale benchmark`).
- **Expect**: `idx_type_earned`, `Backward index scan; Using index`, no filesort; the
  `leaderboard_page_deep` budget passes; `LeaderboardEngine::build_totals_query()` contains no `OFFSET`.
  Measured on a 300,000-row copy: a page at depth 150,000 took 0.11 ms against 26 ms for `OFFSET`.
- **On fail**: `build_totals_query()`, `tests/Unit/Engine/LeaderboardPagingTest.php`.

### 4. REST: envelope, headers, gate
- **Action**: `GET /wb-gamification/v1/leaderboard?limit=20` anonymously, then again with the returned
  `cursor` anonymously, as a member, with `cursor=garbage`, with a cursor from another `period`, and
  with the `wb_gam_leaderboard_anon_paging` filter returning true.
- **Expect**: first page 200 with `rows`, `total`, `has_more`, `next_cursor`, `offset` and the
  `X-WP-Total` / `X-WP-TotalPages` headers; anonymous with a cursor 401 `rest_forbidden`; member with the
  cursor 200 with `offset = 20` and the first rank 21; garbage or another board's cursor 400
  `wb_gam_invalid_cursor`; anonymous with a cursor 200 only when the filter opens it.
- **On fail**: `src/API/LeaderboardController.php` `page_response()`.

### 5. Day, week and month boards end at 500
- **Action**: walk `week` with `wp wb-gamification doctor`, or with a synthetic snapshot of more than
  500 rows.
- **Expect**: never more than `LeaderboardEngine::SNAPSHOT_DEPTH` ranks; `has_more` is false at the
  depth; the total is capped at the depth.
- **On fail**: `get_leaderboard_page()` (the `room` calculation), `write_snapshot()`.

### 6. Doctor and BuddyNext
- **Action**: `wp wb-gamification doctor`; on a BuddyNext page, the leaderboard page (BuddyNext side).
- **Expect**: "Leaderboard paging" passes (a failure names duplicates, a skipped member, or a total
  that disagrees with the walk). BuddyNext's pager, once it adopts the API, shows continuous ranks,
  drops the cursor when the period tab changes, and works at 390px and in dark mode.
- **On fail**: `src/CLI/DoctorCommand.php` `check_leaderboard_paging()`.

## Pass criteria

ALL of the following hold:
1. Every full walk serves each eligible member once and the count equals the total.
2. Ties across a boundary share one rank; orphans are never served or counted.
3. The keyset query has no OFFSET and rides `idx_type_earned` without a filesort.
4. REST returns the envelope and headers; anonymous cursors are refused unless the filter opens them;
   bad or foreign cursors are 400.
5. Day, week and month boards stop at 500 ranks.
6. The doctor's paging check passes.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A member appears on two pages | page one and two read different sources (snapshot vs totals) | `fetch_raw()` `$paged` handling |
| Page two restarts at rank 1 | cursor rank state not seeded into `hydrate_rows()` | `get_leaderboard_page()` |
| A page is short before the last | orphans consumed slots without the keyset advance | `totals_board()` |
| Page N gets slower as N grows | an OFFSET crept back into a query | `build_totals_query()`, the ledger `HAVING` |
| Anonymous visitor walks the roster | the cursor gate was bypassed | `LeaderboardController::page_response()` |
