# Scale & Stability Register — wb-gamification

**Purpose.** One numbered entry per flow, so we can audit and fix them **one at a time** and know
what is left. This is the working queue for stabilising the plugin for **large sites and large
databases**.

**Target scale:** 100,000 members · millions of rows in `wb_gam_points` / `wb_gam_events`.

**Generated:** 2026-07-12 · branch `1.6.4` · verified against code + the live schema.

---

## 0. How to read this (the thing the old audit got wrong)

The previous audit organised everything by *structure* — "76 REST endpoints, 26 tables". That
tells you nothing about whether the plugin survives a big database. **What decides that is which
table a query touches, and whether it is bounded.**

Every table falls into exactly one growth class. This is the lens for the whole register:

| Class | Rows at 100k members | Meaning | Tables |
|---|---|---|---|
| **CONFIG** | tens | Bounded by what an admin creates. A `SELECT *` here is **safe forever**. | `levels`, `rules`, `badge_defs`, `point_types`, `point_type_conversions`, `webhooks`, `redemption_items`, `challenges`, `community_challenges`, `api_keys` |
| **MEMBER** | ~100,000 | One row per member. Unbounded read = 100k rows into PHP. | `user_totals`, `streaks`, `member_prefs`, `user_intelligence`, `cohort_members`, `leaderboard_cache` |
| **EVENT** | millions | Grows with **activity**, forever. Unbounded read = OOM / timeout. | `points`, `events`, `user_badges`, `challenge_log`, `kudos`, `redemptions`, `notifications_queue`, `submissions`, `community_challenge_contributions`, `side_effect_failures` |

A finding is only real if it is **unbounded on a MEMBER or EVENT table**. An unbounded read of
`wb_gam_levels` (5 rows) is not a bug and never will be. Scoring by table class is what stops
this register being 56 false alarms.

Second distinction, which changes the *fix*:

- **Big result** — N rows fetched into PHP. Fix = `LIMIT` / batch / cursor.
- **Big scan, small result** — an aggregation (`COUNT(DISTINCT …) GROUP BY`). The result is tiny;
  the *scan* is the cost. Fix = cache or materialise. A `LIMIT` does nothing here.

---

## 1. What is GOOD (do not "fix" these)

Recording these so nobody burns a day re-discovering them:

- **Indexes are strong.** 24 of 26 tables carry proper secondary indexes; every hot `WHERE` /
  `ORDER BY` column is covered. The two without (`badge_defs` 42 rows, `webhooks` 0) are CONFIG
  tables where it does not matter. **Indexing is not the problem here.**
- **Leaderboards are materialised**, not live-aggregated — `wb_gam_leaderboard_snapshot` writes
  `wb_gam_leaderboard_cache` every 5 min via Action Scheduler.
- **`wb_gam_user_totals` exists** — running totals are a PK lookup, not a `SUM()` over `points`.
- **The award hot path is measured.** `wp wb-gamification scale` seeds 10k users and benchmarks 6
  queries against explicit budgets (`audit/scale-baseline.md`, first measured 2026-05-28).
- **The notifications queue is bounded on write** (1.6.4) — cannot grow without limit even where
  WP-Cron never fires.

---

## 2. Findings — the work queue

Ordered by blast radius. Each has a stable ID so we can work them one by one.

### Tier 1 — hot path, hits every request

| # | Finding | Where | Class | Why it breaks at scale |
|---|---|---|---|---|
| **S-01** | Badge **rarity map** runs `SELECT badge_id, COUNT(DISTINCT user_id) … GROUP BY badge_id` over `wb_gam_user_badges` **plus** `COUNT(*) FROM wp_users` on **every request**, and its own comment says it is *"not suitable for generic caching"*. | `src/API/BadgesController.php:758` `get_rarity_map()` | EVENT | Big-scan/small-result. At 100k members × N badges this full-scans an EVENT table on a hot REST path, uncached. **Fix = materialise or cache with badge-level keys, not a LIMIT.** |
| **S-02** | Scale benchmark **is not enforced**. `composer scale:bench` exists but is **not** a stage in `bin/local-ci.sh` and **not** a gate in `bin/build-release.sh`. | `composer.json`, `bin/local-ci.sh` | — | The 100k claim is only re-verified if a human remembers to type it. A regression ships silently. |
| **S-03** | Scale benchmark **under-covers**. It budgets 6 queries (award path + leaderboard). It does **not** cover badge rarity (S-01), weekly-email fan-out (S-04), cohort processing (S-06), any admin list page, GDPR export (S-07), or the notifications queue. | `src/CLI/ScaleCommand.php:58` `BUDGETS_MS` | — | We measure the paths we already know are fast. The unmeasured ones are exactly the ones in this register. |

### Tier 2 — background jobs that try to do the whole site in one tick

| # | Finding | Where | Class | Why it breaks at scale |
|---|---|---|---|---|
| **S-04** | **Weekly email fan-out**: `SELECT DISTINCT p.user_id … LEFT JOIN member_prefs` for everyone active in 7 days, pulled into PHP as one array, then `foreach` → `get_userdata()` **per member** (N+1). | `src/Engine/WeeklyEmailEngine.php:115` + `:145` | EVENT+MEMBER | On a busy 100k site this is a 6-figure `user_id` array in memory, then one user query each. Correctly enqueues per-member Action Scheduler jobs — but the *selection* step is the unbounded part. **Fix = batch the SELECT with a cursor.** |
| **S-05** | **Tenure / status-retention / points-decay** jobs iterate members with per-row `get_userdata()` / `get_user_meta()` inside the loop. | `TenureBadgeEngine.php:201`, `StatusRetentionEngine.php:139`, `PointsExpiry.php:129` | MEMBER/EVENT | N+1 across the member base on a nightly tick. |
| **S-06** | **Cohort engine** runs several site-wide unbounded reads over `wb_gam_points` and `wb_gam_cohort_members`. | `CohortEngine.php:139,232,240,375` | EVENT+MEMBER | Whole-table reads with no LIMIT on the two biggest classes. |
| **S-07** | **GDPR export/erase** reads a member's full history with no LIMIT across `points`, `user_badges`, `submissions`, `events`. | `src/Engine/Privacy.php:203,238,371,424` | EVENT | A member with 50k point rows OOMs the export. WP's privacy exporter is **designed to be paginated** — we ignore it. |
| **S-08** | **Importers** use `'numberposts' => -1` (unbounded `WP_Query`). | `BadgeOSImporter.php:87,205`, `MyCredImporter.php:169`, `GamiPressImporter.php:239` | — | Importing a large BadgeOS/GamiPress site loads every post at once. |
| **S-09** | `CredentialExpiryEngine`, `CommunityChallengeEngine`, `StatusRetentionEngine` do site-wide unbounded reads on EVENT tables. | `CredentialExpiryEngine.php:91`, `CommunityChallengeEngine.php:211`, `StatusRetentionEngine.php:101` | EVENT | Same shape as S-06. |

### Tier 3 — map/contract gaps (we cannot audit what we do not record)

| # | Finding | Where | Why it matters |
|---|---|---|---|
| **S-10** | **`three_entry_point` is unrecorded for 23 of 26 tables** (only `notifications_queue`, `user_intelligence` satisfied; `side_effect_failures` an intentional exception). | `audit/manifest.json#/tables[].three_entry_point` | The three-entry-point rule (every data store reachable from frontend + admin + API) **cannot be audited today** — the map does not carry it. It also cannot be reliably auto-derived: the plugin layers Admin → Service → Repository → table, so a static scan misses surfaces (it wrongly reports `submissions` as having no admin page — it has one). **This must be filled in by walking each table's surfaces.** |
| **S-11** | Manifest records **indexes for only 5 of 26 tables**, though the live schema has them on 24. | `audit/manifest.json#/tables[].indexes` | Any index audit run off the manifest will produce false "missing index" findings. |
| **S-12** | **No per-flow scale verdict exists anywhere** — this file is the first. | — | Which is why the same tables get re-audited every release. |

---

## 3. The functionality register — audit these one by one

Every flow, its surfaces (the three-entry-point contract), its tables, and its scale status.
`?` = not yet audited. Work top-down.

| # | Flow | Frontend | Admin | API | Tables (class) | Scale |
|---|---|---|---|---|---|---|
| F-01 | Award points on event | — | Award Points | `points`, `events` | `points`(E) `events`(E) `user_totals`(M) | measured ✅ |
| F-02 | Rate limits (cooldown / daily / weekly cap) | — | Settings ▸ Points | — | `points`(E) | measured ✅ |
| F-03 | Earning exclusion (staff/bots) | — | Settings ▸ Access | `members` | `member_prefs`(M) | ? |
| F-04 | Multi-currency point types | — | Point Types | `point-types` | `point_types`(C) `user_totals`(M) | measured ✅ |
| F-05 | Point-type conversion | `[wb_gam_hub]` | Conversions | `point-type-conversions` | `point_type_conversions`(C) | measured ✅ |
| F-06 | Points expiry / decay | — | Settings ▸ Points | — | `points`(E) | **S-05** |
| F-07 | Badge award + rules | `badge-showcase` | Badge Library | `badges` | `badge_defs`(C) `rules`(C) `user_badges`(E) | ? |
| F-08 | Badge rarity | `badge-showcase` | — | `badges` | `user_badges`(E) | **S-01** |
| F-09 | Badge share page / OG image | share page | — | `badges` | `user_badges`(E) | ? |
| F-10 | Tenure badges | — | Badge Library | — | `user_badges`(E) | **S-05** |
| F-11 | Credential expiry / status retention | — | — | — | `user_badges`(E) `points`(E) | **S-09** |
| F-12 | Levels | `level-progress` | Settings ▸ Levels | `levels` | `levels`(C) | ✅ CONFIG |
| F-13 | Streaks + milestones | `streak` | Streaks | — | `streaks`(M) | ? |
| F-14 | Leaderboard (render) | `leaderboard`, `top-members` | — | `leaderboard` | `leaderboard_cache`(M) | materialised ✅ |
| F-15 | Leaderboard snapshot job | — | — | — | `points`(E) → `leaderboard_cache`(M) | ? (rebuild cost) |
| F-16 | Leaderboard nudge | — | — | — | `leaderboard_cache`(M) | fan-out via AS ✅ |
| F-17 | Cohorts / cohort rank | `cohort-rank` | — | `cohort-settings` | `cohort_members`(M) `points`(E) | **S-06** |
| F-18 | Challenges | `challenges` | Challenges | `challenges` | `challenges`(C) `challenge_log`(E) | ? |
| F-19 | Community challenges | `community-challenges` | Community Challenges | `community-challenges` | `community_challenges`(C) `contributions`(E) | **S-09** |
| F-20 | Kudos (give / feed / moderation) | `give-kudos`, `kudos-feed` | — | `kudos` | `kudos`(E) | ? |
| F-21 | Redemption store | `redemption-store`, `my-rewards` | Redemption Store | `redemptions` | `redemptions`(E) `redemption_items`(C) | ? |
| F-22 | Submissions | `submit-achievement` | Submissions | `submissions` | `submissions`(E) | ? |
| F-23 | Notifications / toasts | (all pages) | — | `members/me/toasts`, SSE | `notifications_queue`(E) | **bounded ✅ (1.6.4)** |
| F-24 | Weekly digest email | — | Settings ▸ Email | — | `points`(E) `member_prefs`(M) | **S-04** |
| F-25 | Member hub | `hub` | — | `members` | many | ? |
| F-26 | Members roster | — | Members | `members` | `user_totals`(M) | ? (paginated?) |
| F-27 | Analytics dashboard | — | Analytics | — | `points`(E) | date-bounded ✅ |
| F-28 | User intelligence (churn/anomaly) | — | Analytics | `members/{id}/intelligence` | `user_intelligence`(M) | ? |
| F-29 | Webhooks (outbound) | — | Webhooks | `webhooks` | `webhooks`(C) | retry via AS ✅ |
| F-30 | API keys | — | API Keys | `api-keys` | `api_keys`(C) | ✅ CONFIG |
| F-31 | GDPR export / erase | — | (WP core) | — | `points`(E) `user_badges`(E) `events`(E) | **S-07** |
| F-32 | Importers (BadgeOS/MyCred/GamiPress) | — | Import | `import` | — | **S-08** |
| F-33 | Side-effect reconcile | — | — | — | `side_effect_failures`(E) | ? |
| F-34 | 24 partner integrations / 126 triggers | — | — | — | `events`(E) | ? |

---

## 4. Method notes (so the next pass does not repeat my mistakes)

Static scans on this codebase produce **false findings** unless you do all of these:

1. **Multi-line calls.** `do_action(\n\t'hook'` — a single-line grep misses it. This codebase uses it.
2. **`integrations/` is not under `src/`.** Scanning `src/` alone silently drops a whole directory.
3. **Resolve `self::CONST` per file.** Many classes each define their own `CRON_HOOK`; a global
   const table collapses them all to one value.
4. **Strip comments first.** Docblock examples (`* do_action( 'x' )`) otherwise count as real fires.
5. **Action Scheduler ≠ WP-Cron.** A `wp_schedule_event`-only grep misses `as_schedule_*` entirely
   (it reports 6 cron hooks instead of 16).
6. **Repositories hide the table name.** Admin/API files never name the table, so a
   table→surface scan must traverse Admin → Service → Repository, not grep for the table string.
7. **Score by table growth class.** Otherwise you get 56 "unbounded query" alarms, ~44 of which
   are CONFIG tables and will never matter.
