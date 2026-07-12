# Multi-condition badge rules — design

**Date:** 2026-07-12 · **Branch:** `1.6.4` · **Status:** approved, ready for implementation plan

---

## 1. Why

A badge today has **exactly one condition**, chosen from three types:

| Type | Meaning |
|---|---|
| `action_count` | performed action X, N times (all-time) |
| `point_milestone` | reached N total points |
| `admin_awarded` | manual grant only; never auto-evaluates |

`BadgeEngine::evaluate_condition()` is a `switch` on `condition_type`. There is no AND, no OR, no
level condition, no badge-chaining, no streak or tenure condition — even though `LevelEngine`,
`StreakEngine` and `TenureBadgeEngine` all exist and work.

This is the single dimension owners compare gamification plugins on, and it is where they *start*
an evaluation. Three single conditions is below the bar (`audit/OWNER-EXPECTATIONS-CHECKLIST.md`,
T-21..T-26).

**Product identity:** wb-gamification is the gamification layer for the Wbcom suite. This work is
table stakes regardless of that decision — a suite owner expects a badge to express a real rule.

---

## 2. Decisions taken

| Decision | Choice | Why |
|---|---|---|
| Rule shape | **Flat list + ALL/ANY** | Covers real badges. No nesting, no tree UI, no recursive JSON. |
| New conditions | **5**, all backed by engines we already own | Highest value per unit of work; unlocks capability already paid for. |
| Legacy rules | **Migrate once. One shape, one reader.** | Install base is tiny. This window closes permanently once there are real users. |
| Existing earners when a rule tightens | **Never revoke** | Earned is earned. Retroactively stripping a badge is the most trust-destroying thing a gamification system can do. |
| Members who already qualify when a badge is created | **Retroactive backfill, batched, in the background** | GamiPress's #2 complaint is that existing members get nothing. We already have the machinery. |
| Ship scope | **One flow** | The pieces interlock: backfill needs event-free evaluation, which needs the new evaluator, which needs the migration. |

**One-way door, accepted:** after the migration a downgrade to an older plugin version leaves badge
rules the old code cannot parse. Acceptable at this install count.

---

## 3. Data model

`wb_gam_rules.rule_config` is already `LONGTEXT` JSON. **No schema change.** Only the shape changes.

```jsonc
// BEFORE (legacy — exists on current installs)
{ "condition_type": "action_count", "action_id": "wp_publish_post", "count": 10 }

// AFTER (the only shape that exists post-migration)
{
  "match": "all",                    // "all" | "any"
  "conditions": [
    { "type": "action_count",  "action_id": "wp_publish_post", "count": 10 },
    { "type": "level_reached", "level_id": 4 }
  ]
}
```

**Migration** — `DbUpgrader::ensure_badge_rule_groups()`, flag-gated
(`wb_gam_feature_badge_rule_groups_v1`), idempotent:

1. Read every `wb_gam_rules` row with `rule_type = 'badge_condition'`.
2. If `rule_config` already has `conditions`, skip (idempotent).
3. Else rewrite `{condition_type: X, ...rest}` → `{match: "all", conditions: [{type: X, ...rest}]}`.
4. Set the flag.

There is **no read-time normalizer**. After this migration exactly one shape exists in the database
and exactly one reader parses it. Seeded badges (`Installer.php`) ship in the new shape.

---

## 4. Condition vocabulary — 8 types

3 existing + 5 new. Every new type is backed by an engine that already exists.

| Type | Fields | Evaluated from | Cost |
|---|---|---|---|
| `action_count` | `action_id`, `count` | `PointsEngine::get_action_count()` | 1 indexed COUNT |
| `point_milestone` | `points` | primed total | **0** (in-memory) |
| `admin_awarded` | — | never auto-evaluates | **0** |
| **`level_reached`** *(new)* | `level_id` | `LevelEngine::get_level_for_points($total)` — **pure** | **0** (in-memory) |
| **`badge_earned`** *(new)* | `badge_id` | primed earned-badge set | **0** (already loaded) |
| **`streak_days`** *(new)* | `days` | `StreakEngine::get_row()` | 1 PK lookup, cached |
| **`tenure_days`** *(new)* | `days` | `user_registered` | **0** (no query) |
| **`points_in_period`** *(new)* | `points`, `period` (`day`/`week`/`month`) | `wb_gam_points` sum in window | 1 indexed range scan (`idx_user_type_created`) |

Six of the eight cost **zero queries** once the shared state is primed.

The existing `default:` branch of the `switch` fires an `apply_filters`, so third-party condition
types keep working. That extension point is preserved.

---

## 5. Evaluation — the relevance gate

**The problem.** Naively, multi-condition multiplies the award cost: 30 badges × 4 conditions = 120
evaluations per award, several with queries. That does not survive 100k members.

**The fix.** Each condition type declares which **signals** can change its truth:

| Condition | Signal |
|---|---|
| `action_count` | `action:{action_id}` |
| `point_milestone` | `points` |
| `points_in_period` | `points` |
| `level_reached` | `level` |
| `badge_earned` | `badge:{badge_id}` |
| `streak_days` | `streak` |
| `tenure_days` | *(none — daily cron only)* |
| `admin_awarded` | *(none — never auto-evaluates)* |

A condition type **must** declare its signal. That is the contract a new type implements, and it is
what keeps the award path cheap as the vocabulary grows.

An award emits a signal set — e.g. `{points, action:wp_publish_post}`, plus `level` if the level
changed and `badge:{id}` when a badge is granted. **A badge is evaluated only if at least one of its
conditions is relevant to a signal that actually fired.** A "publish 10 posts" badge is never touched
when a member reacts to a comment.

**This makes the award path FASTER than it is today.** The scale audit found a live N+1:
`action_count` currently issues a `COUNT(*)` per rule **even when the action does not match**, unless
`count === 1` (`BadgeEngine.php`, the `1 === $required` short-circuit). At 30 badge rules that is ~30
pointless COUNT queries on **every award**. The relevance gate removes them.

**Then:**
- **Prime shared state once per pass** — total, earned-badge set, resolved level. Lazily: streak is
  only read if a `streak_days` condition survived the gate.
- **Short-circuit** — `all` stops at the first false, `any` at the first true.
- **Cheapest-first ordering** — evaluate zero-query conditions before query-backed ones, so a failing
  in-memory condition kills the badge before any SQL runs.

**Event-free evaluation is the primary seam.** `evaluate_rule( int $user_id, array $rule, ?Event
$event = null ): bool` evaluates purely from member state. The event is used **only** by the
relevance gate, never by the condition logic itself. Backfill (§6) passes `null` and everything
works. Today's `$event`-dependent fast path inside `action_count` goes away — the gate replaces it.

---

## 6. Retroactive backfill

On rule save, enqueue an Action Scheduler job for that badge.

- **Driving set:** `wb_gam_user_totals` — one row per member who has ever earned. Bounded, indexed,
  and a member with no points cannot satisfy any auto-condition.
- **Keyset cursor:** `WHERE user_id > $cursor ORDER BY user_id LIMIT 500`, next page scheduled as its
  own AS job. Same pattern as the 1.6.4 cron fan-out work. No OFFSET.
- **Per batch:** `PointsEngine::prime_totals()` + `BadgeEngine::prime_earned_badges()`, then
  `evaluate_rule($uid, $rule, null)` per member.
- **Award via `BadgeEngine::award_badge()`** so `max_earners` and every existing guard still applies.
- **Progress:** `{done, total, awarded, started_at}` in an option, surfaced on the Badges screen.
- **Idempotent:** re-running cannot double-award (`UNIQUE (user_id, badge_id)` + the earned check).

Backfilling a broad badge on a large site is real work. It is batched and resumable, and the progress
indicator is part of the feature, not a nicety.

---

## 7. Consolidation — delete two engines

Once conditions exist, two engines are duplicate implementations of the badge model:

| Engine | What it does today | Becomes |
|---|---|---|
| **`TenureBadgeEngine`** | hardcoded `TIERS` (1yr/2yr/…) calling `BadgeEngine::award_badge()` from its own daily cron | badges with a **`tenure_days`** condition. Cron evaluates tenure conditions instead of a private list. |
| **`SiteFirstBadgeEngine`** | hardcoded "first member to reach Champion", "first to 10,000 points" | a condition (**`level_reached`** / **`point_milestone`**) **+ `max_earners = 1`** — both features already exist |

Both are **deleted**, not deprecated. Their seeded badges are re-seeded as ordinary rules, so owners
can finally *edit* them — today they cannot.

**`StreakEngine` is NOT a duplicate** and stays untouched: it fires a milestone *event* (awarding
points), it never awards badges. It simply gains a `streak_days` condition so owners can build streak
badges themselves.

Net: this feature **removes** more code than it adds.

---

## 8. Admin UI

A repeater on the Badge edit screen:

```
Award this badge when the member matches:
  ( ) ANY of these     (•) ALL of these

  [ Publishes a post       ▾ ] [ 10 ] times          [×]
  [ Reaches level          ▾ ] [ Champion ▾ ]        [×]
  [ Maintains a streak of  ▾ ] [ 7 ] days            [×]

                                   [ + Add condition ]
```

- Posted as `condition[0][type]`, `condition[0][count]`, … plus `match`.
- Each type renders only its own fields (progressive disclosure, same pattern as the current
  `wbGamToggleConditionFields`).
- `admin_awarded` is exclusive: selecting it disables the repeater (a manual badge has no conditions).
- A single condition renders identically to today — the UI does not get heavier for the simple case.
- **Backfill notice on save:** "Awarding this badge to members who already qualify… (N of M)".

---

## 9. Testing

| Area | Test |
|---|---|
| Migration | legacy blob → new shape; **idempotent** (running twice is a no-op); a rule already in the new shape is untouched |
| Evaluator | ALL (all true / one false), ANY (one true / all false), empty condition list, unknown type falls through to the filter |
| Short-circuit | `all` stops at first false; `any` stops at first true — assert no further conditions evaluated |
| **Relevance gate** | **a badge is NOT evaluated for an irrelevant event.** This is the performance contract — mutation-tested (removing the gate must fail the test) |
| Each new condition | one test per type, both true and false |
| Backfill | batches with a cursor; `max_earners` respected; idempotent (no double-award) |
| Consolidation | tenure + site-first badges still award after the engines are deleted |
| Scale | new `badge_eval_per_award` budget in `ScaleCommand::BUDGETS_MS`, so the award path cannot regress. Benchmarked against a seeded dataset with many badge rules. |

---

## 10. Risks

| Risk | Mitigation |
|---|---|
| Award hot path gets slower | The relevance gate makes it *faster* (kills the existing N+1). Locked by a scale budget + a mutation-tested gate test. |
| Backfill hammers a large site | Batched (500/tick), keyset cursor, Action Scheduler, resumable, progress-reported. |
| Migration corrupts a live rule | Idempotent, flag-gated, additive rewrite; unit-tested both directions. Install base is tiny. |
| Downgrade breaks rules | **Accepted one-way door.** Explicitly agreed. |
| Deleting two engines regresses their badges | Their badges are re-seeded as ordinary rules; tests assert tenure + site-first still award. |

---

## 11. Out of scope

Nested boolean groups; sequential/ordered steps within a badge; `action_in_period`, `role_is`,
`kudos_received` and other conditions (the vocabulary is deliberately extensible — the `apply_filters`
escape hatch stays); and the other Tier-1 items (CSV export, editable emails), which are separate
specs.
