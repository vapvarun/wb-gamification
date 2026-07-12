# What Site Owners Expect — and where wb-gamification stands

**Purpose.** A micro-level checklist of what a WordPress site owner expects from a gamification
plugin, and an honest HAVE / PARTIAL / MISSING against our code. Re-runnable every release.

**Bar:** table stakes + parity with the market leaders. **Scope: the FREE plugin only** — Pro does
not exist yet, so a gap is a gap.

**Benchmarked against:** GamiPress (10k+ installs, 4.9★), myCred (10k+, 4.6★, 38× 1-star),
BadgeOS (**dead** — WP.org listing closed June 2023, 7 unpatched vulns; both leaders ship free
BadgeOS importers to harvest its refugees).

**Generated:** 2026-07-12 · branch `1.6.4` · every claim verified against code, file:line.

---

## 0. The headline

**We built a community gamification engine. The market buys a WordPress gamification plugin.**

Read the two lists below together and the strategy writes itself:

| The market's loudest complaints | Us |
|---|---|
| "It falls over at scale" — GamiPress recount died at 1,500 of 5,500 users; myCred is "the biggest problem causing slow page loads" | **Our strongest axis.** Materialised leaderboard, bounded queues, indexed hot paths, an enforced 100k benchmark gate |
| "Everything costs $49 more" — GamiPress core has **no leaderboard, no notifications, no reports, no redemption** | **All four in core** |
| "REST API is a paid afterthought; nobody ships WP-CLI" | **76 REST endpoints + 10 CLI commands, free** |
| "No setup wizard — it's all primitives, no recipes" | **We have one** |
| "Caps/limits are a paid add-on" | **Cooldowns + daily caps in core** |

| Their table stakes | Us |
|---|---|
| Earn points per $ spent (the #1 WooCommerce loyalty behaviour) | **MISSING** |
| Pay with points at checkout | **MISSING** |
| Export anything to CSV | **MISSING** |
| Rich badge conditions (multi-condition, AND/OR, steps) | **3 conditions, single-condition only** |
| Broad trigger surface (GamiPress: ~150 free connectors) | **8 triggers on a vanilla WP site** |
| Edit email copy without touching PHP | **MISSING** |

We are strong exactly where they are weak, and weak exactly where they are strong. Closing Part 1
below makes us credible; Part 3 is already a moat and should be defended and marketed, not rebuilt.

---

## 1. TABLE STAKES — cannot be credible without these

An owner assumes these exist. Missing one is a lost evaluation, not a feature request.

### 1.1 Points

| # | Expectation | Status | Evidence / gap |
|---|---|---|---|
| T-01 | Set points per action, no code | **HAVE** | Settings ▸ Points, `SettingsPage.php:1414` |
| T-02 | Turn an action off | **HAVE** | `SettingsPage.php:1398` |
| T-03 | Manually award points to one member, with a reason | **HAVE** | `ManualAwardPage.php:166` |
| T-04 | Deduct points | **HAVE** | negative value, `ManualAwardPage.php:285` |
| T-05 | Bulk award (by role / all members) | **HAVE** | `ManualAwardPage.php:359` |
| T-06 | **Bulk deduct** | **MISSING** | explicitly refused, `ManualAwardPage.php:390`. GamiPress + myCred both do it |
| T-07 | Stop farming: per-action cooldown | **HAVE** | `SettingsPage.php:1448` — **and it's PAID in both rivals** |
| T-08 | Daily cap | **HAVE** | `SettingsPage.php:1462` |
| T-09 | **Weekly cap** | **PARTIAL — engine only** | `PointsEngine.php` enforces it (9 refs); `SettingsPage.php` exposes it (**0 refs**). Owner cannot set it. **`CAPABILITIES.md` claims it — we are overclaiming in our own docs.** |
| T-10 | Exclude staff/bots from earning | **HAVE** | Settings ▸ Access, `SettingsPage.php:2403` |
| T-11 | Transaction log / audit trail | **HAVE** | `wb_gam_events` + `wb_gam_points` |
| T-12 | **Edit or correct a log entry after the fact** | **MISSING** | myCred has admin-editable log entries |
| T-13 | Revoke ONE specific award from the UI | **MISSING (REST-only)** | `DELETE /points/{id}` exists (`PointsController.php:159`); no admin button calls it |
| T-14 | Multiple currencies | **HAVE** | Point Types page — **GamiPress charges for Exchanges** |
| T-15 | Convert between currencies | **HAVE** | Conversions page |
| T-16 | Expire / decay points | **PARTIAL** | inactivity-decay only (`PointsExpiry.php`). No fixed-date expiry, no FIFO aging. **Paid in both rivals** |

### 1.2 Badges — our thinnest area, and the #1 comparison axis

| # | Expectation | Status | Evidence / gap |
|---|---|---|---|
| T-17 | Create a badge in admin, upload an image | **HAVE** | `BadgeAdminPage.php:290` (media library) |
| T-18 | Award on: N of an action | **HAVE** | `action_count` |
| T-19 | Award on: reaching N points | **HAVE** | `point_milestone` |
| T-20 | Award manually | **HAVE** | `admin_awarded` |
| T-21 | **Multi-condition badge (AND / OR)** | **MISSING** | A badge is **one** condition, full stop. `BadgeEngine.php:238-252` |
| T-22 | **Award on reaching a LEVEL** | **MISSING** | no level condition |
| T-23 | **Award on earning another badge** | **MISSING** | no badge-chaining |
| T-24 | **Award on a streak** | **MISSING from the UI** | `StreakEngine` exists but you cannot build a streak badge |
| T-25 | **Award on tenure** | **MISSING from the UI** | `TenureBadgeEngine` is hardcoded, not a condition |
| T-26 | **Sequential steps within an achievement** | **MISSING** | GamiPress core |
| T-27 | Max earners ("first 100") | **HAVE** | `BadgeAdminPage.php:365` |
| T-28 | Badge expiry / credential validity | **HAVE** | `BadgeAdminPage.php:332` |

> **T-21 is the single most important gap in this document.** Three single conditions is below the
> bar for any plugin claiming to be a gamification suite. Owners *start* their evaluation here.

### 1.3 Levels · Leaderboards · Notifications

| # | Expectation | Status | Evidence / gap |
|---|---|---|---|
| T-29 | Level ladder with thresholds + auto-promotion | **HAVE** | `wb_gam_levels`, `LevelEngine` |
| T-30 | **Demotion when points drop** | **MISSING** | `RankAutomation` only ADDS roles |
| T-31 | Grant a role / group on level-up | **HAVE** | `SettingsPage.php:2066` — 3 automation actions |
| T-32 | Leaderboard, all-time + periods | **HAVE** | **GamiPress charges $49** |
| T-33 | Leaderboard as a **widget** | **MISSING** | shortcode + block only; no `WP_Widget` |
| T-34 | On-screen notification when earning | **HAVE** | toast + overlay — **GamiPress charges for this (2 add-ons)** |
| T-35 | Email on badge / level-up | **HAVE (off by default)** | 4 toggles, `SettingsPage.php:2875` |
| T-36 | **Edit email subject / body in admin** | **MISSING** | toggles only. Editing copy = override a PHP template in the theme. **This is a developer task, not an owner task.** |
| T-37 | Member digest email | **HAVE** | `WeeklyEmailEngine` |

### 1.4 Operating the thing

| # | Expectation | Status | Evidence / gap |
|---|---|---|---|
| T-38 | Dashboard: who's earning, what's being earned | **HAVE** | `AnalyticsDashboard` — **GamiPress charges for Reports** |
| T-39 | Member roster with search + per-member view | **HAVE** | `MembersPage.php` |
| T-40 | **Export members / points / leaderboard to CSV** | **MISSING** | **No CSV export exists anywhere in the codebase.** Only `/tools/export-settings` (config JSON). **For many owners this alone is disqualifying.** |
| T-41 | Full points-ledger browser with filters | **MISSING** | top-earners + per-member totals only |
| T-42 | Ban a member from earning | **HAVE** | `MembersPage.php:99` |
| T-43 | Reset one member / everyone | **HAVE** | `MembersPage.php:101`, `ProgressReset.php` |
| T-44 | Detect cheating | **PARTIAL** | Analytics anomaly panel surfaces it; **nothing acts on it** — no auto-flag, no auto-suspend |
| T-45 | **Retroactively award existing members on install** | **HAVE** | `Engine::recompute_users` (`Engine.php:839`) + admin + `ReplayCommand`. **This is GamiPress's #2 complaint** — their own docs concede "users who already completed them will not receive those points", and their recount **cannot recount logins or daily visits at all**. We should be shouting about this. |
| T-46 | Delegate to a community manager (not just full admins) | **HAVE** | 10 caps + role × capability matrix, `Capabilities.php:41`, `SettingsPage.php:2457` |
| T-47 | Import from GamiPress / myCred / BadgeOS | **HAVE** | 3 importers — **and BadgeOS is dead, so this is a live acquisition channel** |

### 1.5 First run

| # | Expectation | Status | Evidence / gap |
|---|---|---|---|
| T-48 | Sensible defaults; points flow immediately | **HAVE** | 5 levels + 30 badges + default currency seeded (`Installer.php:640,893,945`) |
| T-49 | Setup wizard | **HAVE** | `SetupWizard.php` — **neither GamiPress nor myCred has one** |
| T-50 | **Starter templates that actually work** | **BROKEN** | Coaching Platform seeds `check_in`, `goal_complete`; Nonprofit seeds `volunteer_hours` — **none of these action IDs exist in any manifest.** Pick "Coaching Platform" and 2 of 3 configured actions can never fire. `SetupWizard.php:429-450` |

---

## 2. PARITY — the market ships it, we don't

Not strictly table stakes, but each one is a documented reason an owner picks a competitor.

### 2.1 The commerce loop — our biggest structural hole

| # | Expectation | Status | Who has it |
|---|---|---|---|
| P-01 | **Earn points per $ spent** ("1 point per $1") | **MISSING** | myCred, GamiPress, Woo Points & Rewards. Our 5 WooCommerce triggers are **flat-rate only** (`wc_order_completed` etc.) — no order-value proportionality. **This is THE expected loyalty behaviour; a store owner hits it in the first 5 minutes.** |
| P-02 | **Pay with points at checkout** | **MISSING** | Both (GamiPress $; myCred core) |
| P-03 | Clawback on refund | **MISSING** | GamiPress does this properly (Points Gateway restores balance; Referrals revokes commission). **Unsolved industry-wide** — Woo P&R lets balances go negative and store owners eat the loss. Real chance to lead. |
| P-04 | Redeem for a coupon | **HAVE** | 4 Woo coupon types |
| P-05 | **Reward without WooCommerce** | **EFFECTIVELY MISSING** | Non-Woo sites get `custom` (= *write code*) or `wbcom_credits`. No digital download, no role grant, no course enrolment, no content unlock, no manual-fulfilment queue. `RedemptionStorePage.php:248-268` |
| P-06 | Fulfil a physical reward | **BROKEN** | `pending_fulfillment` → `fulfilled` status exists with **no admin control to transition it** |
| P-07 | Buy points with real money | **MISSING** | myCred **buyCRED** — turns points into a revenue line. Nobody else has it |
| P-08 | Cash out points | **MISSING** | both ($) |
| P-09 | User-to-user point transfer | **MISSING** | myCred core |

### 2.2 Trigger surface — the moat we don't have

**The honest number: 126 triggers across 21 manifests. But 76 of them (60%) only fire if the owner
also buys another Wbcom plugin.**

| Site profile | Triggers that actually work |
|---|---|
| Vanilla WordPress | **8** |
| + WooCommerce + LearnDash + BuddyPress | **50** |
| Full Wbcom suite | 126 |

GamiPress ships **~150 free connectors.** Not covered by us at all: **EDD, Tutor LMS, Sensei, Paid
Memberships Pro, Restrict Content Pro, Gravity Forms / WPForms / Formidable, Elementor, Ultimate
Member, wpForo, WP Job Manager, AffiliateWP**, social sharing, referrals, birthdays.

| # | Expectation | Status |
|---|---|---|
| P-10 | Referral / invite rewards | **MISSING** — the #1 growth lever owners ask for |
| P-11 | Form-submission triggers (Gravity / WPForms) | **MISSING** |
| P-12 | Social-share rewards | **MISSING** (we have a badge *share page*, but nothing awards for sharing) |
| P-13 | Daily login bonus | **HAVE** (`LoginBonusEngine` + 9 settings fields) |
| P-14 | Birthday | **MISSING** (myCred core). Tenure ≠ birthday |
| P-15 | EDD / Tutor / Sensei / PMPro / form builders | **MISSING** |

---

## 3. WHERE WE ALREADY BEAT THE MARKET — defend and market these

Do **not** rebuild these. Several are answers to the loudest complaints in the category.

| # | Capability | Why it matters |
|---|---|---|
| W-01 | **Scale** | The #1 complaint about both leaders. GamiPress's recount died at 1,500 of 5,500 users; myCred is "the biggest problem causing slow page loads" and only added indexes in v2.5. We ship a materialised leaderboard, bounded queues, indexed hot paths and an **enforced 100k benchmark gate**. Nobody else in WP does this. |
| W-02 | **Leaderboard, notifications, reports, redemption — all in CORE** | GamiPress core has **none** of the four. "$49 per feature" is the loudest grievance in the category; GameEngine and LoyaltyX are already winning positioning on it. |
| W-03 | **76 REST endpoints + 10 WP-CLI commands, free** | GamiPress's useful REST is a $49 add-on; myCred's REST is paid; BadgeOS's is paid. **None of the three ship WP-CLI.** For anyone building a mobile app or headless front-end, all three are dead ends. |
| W-04 | **Retroactive backfill that works** | Their #2 complaint. GamiPress's own docs concede existing members get nothing, and logins/daily-visits **cannot be recounted at all**. |
| W-05 | **Setup wizard + opinionated defaults** | Neither leader has one. The category is all primitives, no recipes. |
| W-06 | **Anti-farming in core** (cooldowns, caps, exclusions, anomaly detection) | Caps are a **paid add-on** in both. |
| W-07 | **Community-native mechanics** — cohorts/leagues, kudos, streaks, community challenges | Nobody in WP has weekly promotion/demotion leagues. |
| W-08 | **Multi-currency + conversions in core** | GamiPress charges for Exchanges. |
| W-09 | **Delegation** — 10 caps, role × capability matrix | Rivals are admin-only in practice. |
| W-10 | **BadgeOS importer** | BadgeOS is **dead** (listing closed, 7 unpatched vulns). Its users are actively looking for an exit. |

**Uncontested, nobody in WordPress does it:** tie gamification to **permissions/trust** (Discourse
trust levels, Khoros rank-gated privileges). In every WP plugin, points are cosmetic. We already
have `RankAutomation` granting roles on level-up — we are one step from the only trust-level system
in WordPress.

---

## 4. THE RUNNABLE CHECKLIST — the owner's five stages

Walk this every release. Any **NO** in stage 1–2 is a release blocker.

### Stage 1 — Evaluate ("will this do what I need?")
- [ ] Can an owner see the full trigger list **for their stack** before installing? (Today the "126" number misleads — 8 work on vanilla WP)
- [ ] Does the feature list survive a side-by-side with GamiPress's free tier?
- [ ] **T-40** Can they get their data back out? (CSV export) — **currently NO**

### Stage 2 — Set up ("activation → first point")
- [ ] Points flow within 5 minutes of activation, with zero config — **YES**
- [ ] Wizard completes without dead options — **NO (T-50, broken templates)**
- [ ] Existing members are not left at zero — **YES (T-45, backfill)**
- [ ] Default badges exist for the owner's stack — **NO** (30 seeded badges are WP/BP only; a WooCommerce or LearnDash site gets **zero** default badges)

### Stage 3 — Configure ("tune it without code")
- [ ] Per-action points, cooldown, daily cap — **YES**
- [ ] **Weekly cap — NO (T-09)**
- [ ] Build the badges the community actually needs — **NO (T-21: single-condition only)**
- [ ] Give members something worth earning, without WooCommerce — **NO (P-05)**
- [ ] Edit what the emails say — **NO (T-36)**

### Stage 4 — Operate ("see it, fix it, stop abuse")
- [ ] See who's earning and what — **YES**
- [ ] Undo a mistake (revoke one award from the UI) — **NO (T-13)**
- [ ] Correct a bad log entry — **NO (T-12)**
- [ ] Stop a farmer — **YES** (cooldowns, caps, exclude, sandbox)
- [ ] Act on detected cheating — **NO (T-44: read-only panel)**
- [ ] Export for reporting — **NO (T-40)**
- [ ] Delegate to a community manager — **YES**

### Stage 5 — Grow ("does it change behaviour, can I prove it?")
- [ ] Prove impact (retention, churn risk) — **YES** (churn-risk panel)
- [ ] Close the earn→spend loop — **PARTIAL** (Woo only)
- [ ] Reward referrals / invites — **NO (P-10)**
- [ ] Survive success (100k members) — **YES** (the one thing the market cannot do)

---

## 5. RANKED FIX QUEUE

Ordered by *"would this alone lose us the owner?"*

**Tier 1 — credibility. Ship before positioning this as a general WP gamification plugin.**
1. **T-21** Multi-condition badges (AND/OR; level-reached; badge-earned; streak; tenure). The #1 comparison axis; we have 3 single conditions.
2. **T-40** CSV export (members, points ledger, leaderboard, redemptions). Disqualifying for many owners on its own.
3. **P-01** Earn points per $ spent. The expected WooCommerce loyalty behaviour; we only do flat-rate.
4. **T-50** Fix the broken starter templates — a first-run bug on the first-impression screen.
5. **T-36** Owner-editable email copy.
6. **T-09** Expose the weekly cap (engine already enforces it) **and fix `CAPABILITIES.md`, which currently overclaims it**.

**Tier 2 — parity.**
7. **P-05** Rewards without WooCommerce (role grant, download, content unlock, manual-fulfilment queue) + **P-06** fix the dead `pending_fulfillment` status.
8. **T-13 / T-12** Revoke one award from the UI; correct a log entry.
9. **P-02 / P-03** Pay with points + refund clawback (**P-03 is unsolved industry-wide — a chance to lead, not follow**).
10. **P-10** Referral rewards.
11. **T-06** Bulk deduct · **T-33** widget · **T-30** demotion.

**Tier 3 — breadth.**
12. Trigger connectors for the ecosystem we ignore: EDD, Tutor, Sensei, PMPro, Gravity/WPForms, Elementor, Ultimate Member, AffiliateWP.
13. Default badges per stack (Woo/LMS sites currently get none).
14. **T-44** Act on detected cheating, not just display it.

**Strategic (uncontested):**
15. **Trust levels** — gate privileges on rank. `RankAutomation` already grants roles on level-up. No WordPress plugin does this; every serious community platform does.

---

## 6. Corrections to our own docs (found while writing this)

- **`CAPABILITIES.md` claims the weekly cap ships.** The engine enforces it; **the admin UI has zero
  references to it.** An owner cannot set it. Fix the doc or the UI — preferably the UI.
- **`CAPABILITIES.md` / `manifest.summary.json` say "24 integrations".** There are **21** manifest
  files. The other 3 (ActivityPub, GraphQL, Wbcom Credits SDK) are adapters that award no points.
- **"126 triggers" is true but misleading** as a marketing number: **76 of them (60%) require buying
  another Wbcom plugin**; **8** work on a vanilla WordPress site. Quote the reachable number for the
  owner's stack, or it reads as a bait-and-switch on first install.
- **`audit/FEATURE_AUDIT.md` is stale** — its "permission monoculture: only full admins can operate
  the plugin" claim is **wrong** (10 caps + a role matrix exist), and its page/block/shortcode counts
  are all out of date. Do not cite it.
