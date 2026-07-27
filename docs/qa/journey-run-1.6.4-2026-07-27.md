# Journey run — 1.6.4 @ ccfc6db — 2026-07-27

Card: [Journey pack F-02/F-07/F-09/F-14/15/F-21](https://app.basecamp.com/5798509/buckets/47162271/card_tables/cards/10136213020)

**Environment:** `http://buddynext-dev.local` (BuddyNext + BuddyX + Jetonomy + Learnomy +
WPMediaVerse + WP Career Board; classic BuddyPress NOT active). Repo symlinked in, so this is the
exact release code. Driven through plugin APIs and REST, not raw SQL, except where noted.

**Not covered by this run:** smoke sections A (fresh install), B (upgrade) and D (regression
guards) — see card 10134329473. Those need a clean 1.6.3 install to upgrade from and are being run
separately in Docker; this site is already 1.6.4 with migrated data, so an upgrade test here would
prove nothing.

## Results

| ID | Flow | Result |
|----|------|--------|
| F-02 | Cooldown / daily / weekly cap | **PASS** |
| F-07 | Multi-condition badge rules (AND / OR) | **PASS** |
| F-09 | Badge share / privacy | **PASS** (card's 404 expectation is wrong — see below) |
| F-14/15 | Leaderboard render + snapshot + role exclusion | **PASS** |
| F-21 | Redemption stock semantics | **PASS** |
| — | Card 10087941371 challenge two clocks | **CONFIRMED FIXED** |
| — | Card 10086765461 leaderboard role exclusion | **CONFIRMED FIXED** |

### F-02 — caps are enforced and silent

`bn_comment_created` with `weekly_cap = 2`:

```
attempt 1 -> awarded   total 10143
attempt 2 -> awarded   total 10148
attempt 3 -> skipped   (wb_gam_award_skipped fired, reason = weekly_cap)
delta = 10   ledger_rows = 2   member_notifications = 2
```

Totals stop at 2. Two member notifications for the two awards, **none for the skip** — the silent
member UX holds, while the owner still gets the skip through `wb_gam_award_skipped`.

### F-07 — AND / OR

`match: all` over `action_count(bn_comment_created) >= 2` + `level_reached >= 3`:

- level met, action count 1/2 → **not awarded**
- both met → **awarded**

`match: any` over `action_count >= 999` (not met) + `level_reached >= 3` (met) → **awarded**.

### F-09 — share privacy

| State | Share page | Credential |
|---|---|---|
| Unshared | **302 → member profile** | 404 |
| Shared | 200, full OG + twitter card | 200, valid OpenBadges v3 JSON-LD |
| Unearned badge (user 999) | 404 | — |

**The card's acceptance says the unshared page should 404. It 302s to the member profile, and that
is correct** — documented at `BadgeSharePage.php:110-127`. BuddyNext's GamificationBridge links
badge-award activity announcements at this URL, so a 404 would dead-end every announcement; and the
redirect is deliberately uniform for "not earned" and "not shared" alike, so the response cannot be
used to answer "does this member hold this badge?" — which is the enumeration question the whole
feature exists to close. The card's criterion should be amended, not the code.

### F-14/15 — leaderboard, snapshot, role exclusion

Role exclusion (card 10086765461) with `subscriber` excluded, 58 members holding that role:

```
board returned 10 rows in 7.2ms, no fatal
excluded-role members leaked into board: 0
exclusion SQL: AND NOT EXISTS (SELECT 1 FROM wp_usermeta um WHERE um.user_id = p.user_id
               AND um.meta_key = %s AND (um.meta_value LIKE %s))
placeholders: 2   (pre-fix: one per excluded USER — the 100k-placeholder blowup)
```

Snapshot consistency was verified via the repair path: after a drift was introduced, `doctor --fix`
detected it by name, reported the exact delta, recomputed from the ledger and rebuilt the snapshot.

```
✗ 1 member total(s) disagree with the ledger.
  user 21 (points): totals says 60173, ledger says 138  (+60035)
Success: Recomputed 1 total(s) from the ledger and rebuilt the snapshot.
→ after: ledger 138 = materialised 138, board consistent
```

Note: that drift was **introduced by this test run** deleting ledger rows with raw SQL, not by the
product. It is recorded because it exercised the guard and its repair end to end.

### F-21 — redemption stock

```
stock empty (NULL) -> redeemed twice, stock stays NULL     (unlimited)
stock 0            -> BLOCKED[out_of_stock] both attempts   (sold out)
stock 1            -> redeemed once -> stock 0 -> BLOCKED   (one left)
```

All three entry points present: frontend block renders "Out of stock" / "N left" behind a
`showStock` attribute; admin has the field, three-state help text and an Unlimited / Sold out / N
column; REST accepts and exposes stock with an `out_of_stock` error code.

### Challenge clocks (card 10087941371)

Site set to `Asia/Kolkata` (UTC+5:30); site now 22:57, UTC now 17:27:

| Window (site wall clock) | Engine | Expected |
|---|---|---|
| 20:57 → 23:57 (open) | ACTIVE | ACTIVE |
| 17:57 → 21:57 (closed on the wall clock, still open if misread as UTC) | closed | closed |
| 23:57 → 01:57 (not started) | closed | closed |

`CommunityChallengeEngine::get_active()` compares `current_time('mysql')` on both sides — one clock,
the wall clock, on the page and in the engine.

## Harness notes (for whoever repeats this)

Four false starts in this run were the harness, not the product, and each looked like a failure:

1. `comment_posted` is not a registered action id — the real ones are `bn_*`.
2. `redemption_items` columns are `title` / `points_cost` / `reward_type`, not `name` / `cost`;
   `$wpdb->insert()` returned false and every stock case silently read NULL.
3. `Engine::process()` takes an `Event` object; `PointsEngine::process_action()` is the id-based entry.
4. A `probe()` helper declared inside `wp eval` could not see `$t` via `global` — eval scope is not
   global scope — so all three challenge inserts failed and two cases "passed" by returning the
   expected negative. **A negative result from a failed fixture is indistinguishable from a real
   pass.** Assert the fixture landed before asserting on behaviour.

## Fixtures

All test badges, rules, challenges, reward items and point rows created by this run were removed,
and `doctor --fix` was run afterwards to restore totals consistency.
