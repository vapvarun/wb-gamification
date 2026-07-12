# Multi-condition badges — implementation plan

**Branch:** `1.6.4` · **Spec:** `docs/superpowers/specs/2026-07-12-multi-condition-badges-design.md`
**Status:** plan verified against real code AND the real admin screen. Not yet implemented.

The spec says WHAT and WHY. This says **what the admin has today, what they get after, and how we
prove it** — because an admin does not write code, and a feature they cannot reach does not exist.

---

## 1. What we have today — and why it is broken

A badge gets awarded **three different ways**, and only one of them is visible to the person who
owns the site.

| How it is awarded | Badges | What the Badge Library shows | Can the admin edit it? |
|---|---|---|---|
| A rule in `wb_gam_rules` | **35** | `AUTO-AWARD` + one condition | Yes — but only **ONE** condition, ever |
| `TenureBadgeEngine`, hardcoded `TIERS` | **4** (`tenure_1yr`, `2yr`, `5yr`, `10yr`) | **`MANUAL`** | **No** |
| `SiteFirstBadgeEngine`, hardcoded list | **3** (`first_champion`, `first_10k_points`, `first_100_day_streak`) | **`MANUAL`** | **No** |

### The UI lies to the owner

Those 7 badges have **no rule row at all**. The Badge Library derives its chip from the rule, finds
none, and prints **`MANUAL`** — which tells the owner *"you must grant this by hand."*

They are auto-awarded by an engine on a cron. The owner is told the opposite of the truth, shown no
condition, and offered nothing to change. If they want "2-Year Member" to be 18 months, their only
option is to edit PHP.

**Verified in the browser and in the database.** (`plan/` screenshots; 7 badges, `LEFT JOIN` on
`wb_gam_rules` returns NULL for all 7.)

### And the one condition is a real ceiling

The badge edit form has a single `When user…` row: one condition type, one action, one count. You
cannot express *"published 10 posts AND reached Champion"* — the thing every competitor does and the
thing owners compare on.

### There is also a live N+1

**35 active rules. 23 are `action_count`; 12 of those need `count > 1`.** `BadgeEngine.php:247`
short-circuits ONLY when `count === 1`:

```php
if ( 1 === $required && $event->action_id !== $action_id ) {
    return false;                   // <- only this case escapes
}
return PointsEngine::get_action_count( $user_id, $action_id ) >= $required;
```

So those 12 rules issue a `COUNT(*)` **on every award**, whatever action fired. **One award costs 30
queries today**, and ~12 are counts for badges the event could not possibly have advanced.

---

## 2. What the admin gets after

**One way a badge is awarded. One screen. Everything editable.**

| Before | After |
|---|---|
| 3 award mechanisms, 2 of them invisible | **1**: every badge is a rule |
| 7 badges chipped `MANUAL` that auto-award | `MANUAL` means manual. The chip tells the truth. |
| Tenure/site-first badges uneditable | Open them, see the condition, change it |
| One condition per badge | **ANY / ALL** over a list of conditions |
| 3 condition types | **8** (`level_reached`, `badge_earned`, `streak_days`, `tenure_days`, `points_in_period` added) |
| An award costs 30 queries | **Fewer.** The relevance gate deletes the N+1. |
| New badge awards nobody who already qualifies | Retroactive backfill, batched and resumable |

### The admin's flow, end to end

1. **Badges** → open any badge, including the 7 that were invisible.
2. See its condition(s) in plain language: *"Publishes a post — 10 times"*, *"Reaches level — Champion"*.
3. `[ + Add condition ]` → pick `ALL` or `ANY`.
4. Save → *"Awarding this badge to members who already qualify… (0 of 1,240)"*.
5. The chip on the card is now truthful.

**Every step of that is browser-tested as the admin. A step that only works from PHP is not done.**

---

## 3. Tasks, in dependency order

Each lands green: gates pass, tests pass, nothing half-connected.

| # | Task | Files | Done when |
|---|---|---|---|
| **T1** | **Rule shape + migration.** `ensure_badge_rule_groups()`, flag-gated, idempotent. `{condition_type: X, ...}` → `{match:"all", conditions:[{type:X, ...}]}`. **No read-time normalizer** — one shape, one reader. | `DbUpgrader.php` | All 35 rules migrate; running twice is a no-op |
| **T2** | **Evaluator + relevance gate.** `evaluate_rule($uid, $rule, ?Event)`. ALL/ANY, short-circuit, cheapest-first. Each condition type declares its signals; a badge is evaluated only if a signal it cares about actually fired. `$event` is used ONLY by the gate — so `null` works (backfill needs that). Delete the `count===1` fast path. | `BadgeEngine.php` | An award costs **fewer than 30 queries** |
| **T3** | **The 5 new condition types.** `level_reached`, `badge_earned`, `streak_days`, `tenure_days`, `points_in_period`. Every seam already exists (verified). The `default:` `apply_filters` stays — third-party types keep working. | `BadgeEngine.php` | Each has a true and a false test |
| **T4** | **Consolidation — delete both engines.** Their 7 badges become ordinary rules: tenure → `tenure_days`; site-first → `point_milestone`/`level_reached` **+ `max_earners = 1`** (already in place from this branch). Re-seeded as rules, so they become editable. | delete `TenureBadgeEngine.php`, `SiteFirstBadgeEngine.php`; `Installer.php`; `DbUpgrader.php` | The 7 badges show a real condition; the `MANUAL` chip is gone; **net LOC down** |
| **T5** | **Admin UI — the repeater.** `( ) ANY  (•) ALL` + condition rows + `[ + Add condition ]`. Progressive disclosure per type. `admin_awarded` is exclusive (disables the repeater). **A single condition must look exactly as it does today.** | `BadgeAdminPage.php`, `assets/js/admin-badge-conditions.js`, `assets/css/admin/pages/badges.css` | Admin builds a 2-condition badge in the browser, saves, and it awards |
| **T6** | **Retroactive backfill.** On save, ONE Action Scheduler job with a keyset cursor (500/page), guarded with `as_has_scheduled_action()`. Driving set `wb_gam_user_totals`. Awards via `award_badge()` so `max_earners` still holds. Progress surfaced on the Badges screen. | `BadgeEngine.php`, `BadgeAdminPage.php` | 1,200 qualifying members → 1 job in the request, all 1,200 awarded |

**T5 is not last-and-optional. T4 without T5 leaves the 7 badges visible but uneditable — which is
the same disconnected flow with a different shape.** T4 and T5 ship together.

---

## 4. Test cases

Written BEFORE the task. Each must fail first.

### Migration (T1)
| # | Given | When | Then |
|---|---|---|---|
| M1 | `{"condition_type":"point_milestone","points":100}` | migrate | `{"match":"all","conditions":[{"type":"point_milestone","points":100}]}` |
| M2 | Already-new shape | migrate | untouched (idempotent) |
| M3 | The 35 live rules | migrate twice | second run no-ops; 35 valid; none lost; **no badge changes hands** |
| M4 | Malformed JSON in one row | migrate | skipped + logged; batch completes |

### Evaluator (T2)
| # | Given | When | Then |
|---|---|---|---|
| E1 | `all`, both true | evaluate | true |
| E2 | `all`, first false | evaluate | false, **second condition never evaluated** |
| E3 | `any`, first true | evaluate | true, **stops there** |
| E4 | `any`, all false | evaluate | false |
| E5 | Empty conditions | evaluate | false — never award on an empty rule |
| E6 | Unknown type | evaluate | falls to `apply_filters` — third-party types survive |
| E7 | `$event = null` | evaluate | works from pure member state (backfill depends on this) |

### Relevance gate (T2) — the performance contract
| # | Given | When | Then |
|---|---|---|---|
| G1 | "Publish 10 posts" badge | member reacts to a comment | **NOT evaluated. No `COUNT(*)`.** Mutation-tested: remove the gate → this test must go red |
| G2 | Same badge | member publishes a post | evaluated |
| G3 | `tenure_days` badge | any award | **not** evaluated — tenure changes on cron, not on award |
| G4 | **35 real rules, one award** | measure | **< 30 queries.** The acceptance number. |

### Conditions (T3) — one true, one false each
`level_reached` · `badge_earned` · `streak_days` · `tenure_days` · `points_in_period`

**C6 (clock):** `points_in_period` reads `wb_gam_points.created_at`, written `current_time('mysql')`
— SITE-LOCAL. Its window MUST be computed in the same clock. Using `gmdate()`/`NOW()` reintroduces
the bug fixed **five times** on this branch. CI stage 2.15 fails the build if it does.

### Consolidation (T4)
| # | Given | When | Then |
|---|---|---|---|
| S1 | Tenure engine deleted | member hits 1 year | `1-Year Member` still awards |
| S2 | Site-first engine deleted | two members race the threshold | exactly ONE gets it (`max_earners`) |
| S3 | Existing holders of those 7 badges | migration | **keep them.** Earned is earned. |

### Admin flow (T5) — browser, as the admin
| # | Given | When | Then |
|---|---|---|---|
| A1 | Badge Library | open `1-Year Member` | shows a real, editable condition. **Today: nothing.** |
| A2 | The card | look at the chip | says `AUTO-AWARD`, not `MANUAL`. **Today it lies.** |
| A3 | Badge edit | click `+ Add condition` | a second row appears; ALL/ANY selectable |
| A4 | 2 conditions, `ALL` | save | persists; `GET` returns both |
| A5 | A member matching only one | award fires | **not** awarded |
| A6 | A member matching both | award fires | awarded |
| A7 | A one-condition badge | open it | looks exactly as it does today — the simple case is not heavier |
| A8 | `admin_awarded` selected | — | repeater disabled |

### Backfill (T6)
| # | Given | When | Then |
|---|---|---|---|
| B1 | 1,200 qualifying members | rule saved | **1** job enqueued in the admin's request, not 1,200 |
| B2 | Same | queue drains | all 1,200 awarded, none dropped |
| B3 | `max_earners = 1`, 1,200 qualify | backfill | exactly ONE gets it |
| B4 | Run twice | — | no double-award |

---

## 5. Acceptance criteria

1. **An award costs fewer than the 30 queries it costs today.** If it costs more, the feature is wrong.
2. All 35 rules survive migration; **no badge changes hands**.
3. The 7 engine badges are editable in the admin, chipped truthfully, and still award.
4. G1 is mutation-tested: delete the gate → red.
5. Both engines deleted. **Net LOC down.**
6. A single-condition badge is unchanged for the admin.
7. Every admin-flow case (A1–A8) verified **in the browser**, not from PHP.
8. 19/19 gates green, including stage 2.15 (clock contract).

---

## 6. Risks

| Risk | Mitigation |
|---|---|
| Award path gets slower | The gate deletes an existing N+1. Locked by acceptance #1 + a scale budget. |
| Backfill hammers a large site | Keyset, 500/page, AS, resumable, guarded — the same shape as the community-challenge fix on this branch. |
| Migration corrupts a live rule | Idempotent, flag-gated, additive. Tested both directions. Existing earners never lose a badge. |
| `points_in_period` reintroduces the clock bug | Window computed in the column's clock; CI 2.15 enforces it. |
| Downgrade breaks rules | **Accepted one-way door**, agreed in the spec. Install base is tiny. |
