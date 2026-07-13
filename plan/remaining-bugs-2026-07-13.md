# Remaining bugs — fix plan (2026-07-13)

**Branch:** `1.6.4` · Seven open defects, every one verified against live code before it entered this
list. No card is here on a hunch; each row below carries the proof that made it real.

Four of the seven are **silent failures** — nothing errors, no log line, no red screen. The webhook
just never retries. The challenge just never awards. The member's rows just stay forever. That is the
shape of every bug on this branch, and it is why they survived a green test suite.

---

## The seven

| # | Card | Severity | The defect, in one line | Proof |
|---|---|---|---|---|
| B1 | #10087941464 | HIGH | Deleting a member leaves every gamification row behind, forever | 11,378 orphaned streak rows vs 152 real ones; no `deleted_user` hook exists anywhere |
| B2 | #10087941371 | HIGH | Challenge windows are compared against two different clocks | On Asia/Kolkata: controller says ACTIVE, engine says NOT ACTIVE, 5.5h apart |
| B3 | #10087743791 | HIGH | Webhook retry selects a `status` column that does not exist | MySQL: `Unknown column 'status'` → `$row` NULL → every retry silently drops |
| B4 | #10087744000 | HIGH | The leaderboard contradicts itself on the same page | Same member, same period: board row **660**, "your standing" strip **1160** |
| B5 | #9933306809 | MEDIUM | User status bar renders on top of the theme header | `position: fixed; top: 48px` vs BuddyX header at y=37→106; `overlaps_header: true` |
| B6 | #10087744146 | MEDIUM | Analytics "Daily Points Trend" chart is unreadable | No axis, no labels, flat uniform bars (browser-verified) |
| B7 | #10087743885 | LOW | Two dead no-op stub classes shipped and hooked at boot | `WPHooks::init` / `BPHooks::init` are empty; both `add_action`'d |

---

## B1 — Deleting a member leaves everything behind

**And it is worse than the card says: the GDPR eraser is incomplete too.**

`Privacy::erase_user_data()` hand-lists the tables it deletes from. That list was written once and has
drifted ever since — every table added after it was written is simply not in it:

| Table with `user_id` | In the eraser? | Rows on the dev site |
|---|---|---|
| `wb_gam_notifications_queue` | **NO** | 23,103 |
| `wb_gam_cohort_members` | **NO** | 11,808 |
| `wb_gam_user_intelligence` | **NO** | 2,314 |
| `wb_gam_redemptions` | **NO** | 0 |
| `wb_gam_community_challenge_contributions` | **NO** | 0 |
| `wb_gam_api_keys` | **NO** | 0 |
| `wb_gam_side_effect_failures` | **NO** | 0 |

So a member who exercises their **right to erasure** keeps rows in five tables. That is a compliance
defect, and it has the same root cause as the missing `deleted_user` hook: **there is no single purge
path.** A hand-maintained list of tables will drift again the next time someone adds one.

### The fix

1. **`MemberData::purge( int $user_id ): array`** — ONE canonical purge. It does not hand-list tables;
   it asks the schema which `wb_gam_*` tables carry a `user_id` column, and deletes from each. A table
   added tomorrow is covered on the day it is added, with nobody remembering to update a list.
2. `Privacy::erase_user_data()` calls it. The GDPR gap closes as a side effect of fixing the model.
3. `deleted_user` and `wpmu_delete_user` call it. An owner deleting a member in wp-admin gets the same
   cleanup as an erasure request — two doors, one path.
4. **Analytics denominators**: the percentages must not be able to exceed 100%. Count only rows whose
   user still exists (`JOIN wp_users`), so the dashboard is correct on a site that already has orphans.
5. **`wp wb-gamification member purge-orphans [--dry-run]`** — an explicit, owner-run cleanup for rows
   already orphaned. **NOT an upgrade migration.** Deleting data silently on upgrade is not something a
   plugin gets to do; the owner asks for it, and `--dry-run` shows them what would go first.

**Tests:** purge removes rows from EVERY user-keyed table (asserted by enumerating the schema, so the
test fails when a new table is added and not covered); the GDPR eraser covers the same set; deleting a
user through WP leaves zero rows behind.

---

## B2 — The challenge clock is split in two

`ChallengesController:506` reads the window with `current_time('mysql')` (site-local, and it carries a
comment explaining the two-clock bug it was fixed for). `ChallengeEngine:116` and `:320` compare the
SAME columns against `UTC_TIMESTAMP()` — the database clock.

`starts_at` / `ends_at` are typed by an owner as wall-clock times. The controller reads them that way.
The engine — the thing that decides whether to actually award progress — does not.

**The 1.6.4 changelog already claims this is fixed.** It is true of the controller only. The fix was
half-applied, and the changelog is currently lying to customers.

**And my own gate let it through.** CI stage 2.15 (the clock contract, written on this branch precisely
to stop this bug class) greps for SQL `NOW()`. It never looks for `UTC_TIMESTAMP()`.

### The fix

1. Bind the window in PHP with `current_time('mysql')` in `ChallengeEngine`, exactly as the controller
   does. Same clock, same columns, one meaning.
2. **Widen the gate**: `bin/check-clock-contract.sh` must catch `UTC_TIMESTAMP()`, `CURRENT_TIMESTAMP`,
   `CURDATE()` and `CURTIME()` — not just `NOW()`. A guard that covers one spelling of the mistake is a
   guard that teaches people the other spellings.
3. Sweep `src/` for every remaining occurrence and fix or annotate each.
4. Correct the changelog line.

**Tests:** the gate goes red on an unannotated `UTC_TIMESTAMP()` (mutation-tested); a challenge scheduled
09:00–17:00 on a non-UTC site is active to the ENGINE at 09:00 local, not 14:30.

---

## B3 — Webhook retry silently no-ops

`WebhookDispatcher:388` selects `status`. The column is `is_active`. MySQL rejects the query, `$wpdb`
swallows it, `get_row()` returns NULL, and `if ( ! $row ) return;` drops the retry.

Every failed webhook delivery on every site has been silently discarded rather than retried.

### The fix

Select `is_active` and guard on it. Then prove a retry actually fires end-to-end, because the entire
point of this bug is that the failure path was invisible — a fix that only makes the query valid, with
nothing exercising the retry, would be indistinguishable from the bug.

**Test:** a failed delivery is retried; a webhook with `is_active = 0` is not.

---

## B4 — The leaderboard contradicts itself

The board rows come from `get_leaderboard()`, which serves the **snapshot**. The "your standing" strip
in the same block calls `get_user_rank()`, which sums the **ledger** live. Between cron rebuilds the two
disagree: board **660**, strip **1160**, same member, same period, same page.

**The constraint that shapes the fix:** `write_snapshot()` is `LIMIT 500`. The snapshot holds the top
500 only — so "make the strip read the snapshot" is not a whole answer, because a member outside the top
500 has no row to read.

### The fix

One resolver, used by both surfaces: **if the member has a snapshot row for this period, both the board
and the strip use it; if not, both compute live.** The page can then never show two numbers for one
metric. A leaderboard is eventually consistent by design — that is the deal a snapshot buys — but it must
be consistently stale, not stale in one corner and live in the other.

**Test:** with a stale snapshot and fresh points, board and strip return the SAME figure. (This test goes
red against today's code — that is the point.)

---

## B5 — The status bar sits on top of the theme header

`position: fixed; top: 48px` — hardcoded to the height of the WP admin bar. The block never asks what is
actually occupying the top of the page, so on any theme with a header (i.e. every real site) it lands on
top of the nav.

**This is the same bug class we already fixed for toasts on this branch**, and the fix is the same:
measure whatever currently occupies the top strip, whatever its `position` value, and offset below it —
re-measuring on scroll. BuddyX's header is `position: static`, which is exactly why the first (wrong)
toast fix — "look for a fixed or sticky header" — did nothing on the theme our customers actually run.

`assets/js/toast.js` already has that measurement. **Extract it into one shared helper and have both
call it.** Two copies of this logic guarantees the next fix lands in one of them.

**Verify:** browser, on BuddyX, at 1640px and 390px. `overlaps_header` must be false.

---

## B6 — The Daily Points Trend chart is unreadable

Bars render flat and uniform with no axis, no labels and no scale. Browser-verified.

Fix the render so the bars are scaled against the maximum in the series, with an axis and readable
labels. Empty and single-point series must not divide by zero.

---

## B7 — Dead no-op stubs

`src/Integrations/WordPress/HooksIntegration.php` and `src/BuddyPress/HooksIntegration.php` are empty
classes whose `init()` does nothing — and both are still `add_action`'d at boot. The card claims "zero
callers", which is wrong (they ARE called); they are simply called to do nothing.

Delete both classes, their two `add_action` registrations and their two `use` statements.

---

## Order of work

B1 → B2 → B3 → B4 → B7 → B5 → B6.

Data integrity first (B1–B4): those are the silent ones, and they are the ones corrupting what an owner
sees. B7 is a two-minute deletion. The two UI bugs land last because they need browser verification per
item, and B5 depends on the shared helper extraction.

Each lands its own commit: failing test first where a test can express it, then the fix, then 19/19 gates
green, then live proof on the seeded site.
