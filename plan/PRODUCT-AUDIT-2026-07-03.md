# WB Gamification — Product Audit (2026-07-03)

**Question answered:** as the plugin owner — what do we have, what are the major gaps
(skeleton view + people-expectation view), and what takes it to the next level?

**Inputs:** wppqa full audit @ v1.6.1 · manifest (64 REST endpoints, 14 admin pages,
17 shortcodes, 19 SSR blocks, 26 tables, 21 integrations, 10 CLI groups, 130 hooks) ·
site-owner code walkthrough · market research (GamiPress, myCred, BadgeOS,
WPAchievements, WooCommerce loyalty plugins, 2026 loyalty-trend sources).

---

## 1. Verdict

The skeleton is **stronger than both market leaders**: event-sourced immutable ledger,
snapshot leaderboards, Action Scheduler, 3 REST auth modes + OpenAPI + JS SDK, webhooks,
WP-CLI, granular caps, SSR blocks, 21 integrations, module kill-switches — all in ONE
free plugin, where GamiPress charges $49/add-on for notifications, reports, rate limits,
and REST. What's missing is not architecture; it is (a) a handful of **operational
surfaces** site owners will hit walls on, (b) **acquisition features** (importers,
seasons) that decide purchase comparisons, and (c) **polish debt** (a11y, empty states)
that makes the product feel less finished than it is.

Category-completeness score from the evaluator (73/100) used an LMS yardstick
(misclassification — it saw the LearnDash/Tutor signals); judged against the
gamification category below instead.

---

## 2. What a site owner CAN do today (verified in code)

| Lifecycle | Capability | Where |
|---|---|---|
| Setup | Wizard + 5 starter templates (blog/community/course/coaching/nonprofit), default badge library auto-seeded | `SetupWizard.php`, `Installer.php` |
| Economy | Per-action point values, daily caps/cooldowns, multi-currency point types + atomic conversion, opt-in point decay, per-user exclusion | Settings › Points/Kudos/Access, `RateLimiter`, `PointsExpiry` |
| Rewards | Badges (action_count / point_milestone / admin_awarded + 9 semantic engines, expiry, OpenBadges 3.0), levels→role automation, challenges, community challenges, cohort leagues, missions, rules CRUD | Badge/Challenge/Cohort admin pages + REST |
| Engagement | Realtime toasts (Heartbeat/SSE), transactional emails, weekly recap digest, leaderboard nudge, BP notifications, daily login bonus ladder | `TransactionalEmailEngine`, `LoginBonusEngine`, `NotificationBridge` |
| Operate | Analytics dashboard (active members, top earners, churn), members roster w/ per-member award/exclude/reset, manual award incl. debit, immutable audit log, `wp wb-gamification doctor` | `AnalyticsDashboard`, `MembersPage`, `ManualAwardPage` |
| Moderate | Revoke points, exclusion list, single-member reset, global progress reset, leaderboard rebuild, log pruning, UGC submission approve/reject | Tools + `SubmissionsPage` |
| Integrate | 21 integrations (BP, BuddyNext, Woo, LearnDash, Learnomy, bbPress, Jetonomy, MediaVerse, Listora, Career Board, GiveWP, LifterLMS, MemberPress, Events Calendar) + GraphQL + ActivityPub; webhooks (Zapier/Make/n8n outbound); custom events via manifest or REST | `integrations/`, `WebhooksAdminPage` |
| Monetize | Redemption store: WooCommerce coupon / % / fixed / free product / free shipping / custom / wbcom_credits; cosmetics (profile frames) | `RedemptionEngine`, `CosmeticEngine` |

**Business model note:** no Pro tier, no feature gating. The bundled EDD SL SDK provides
auto-updates only (preset free key). Everything above ships free.

---

## 3. Expectation gaps — the 2026 buyer's top-10 checklist vs us

Ranked by purchase-decision weight (from market research):

| # | Buyer asks | Status | Gap detail |
|---|---|---|---|
| 1 | Earn on WooCommerce purchases + redeem at checkout | 🟡 Partial | Earn ✓, coupon redemption ✓. **Missing: pay-with-points gateway / partial payment at checkout** (GamiPress + myCred both sell this; WooCommerce loyalty plugins lead with it) |
| 2 | Integrates with my stack | 🟢 Strong | 21 integrations. **Missing: form builders (Gravity/Ninja/CF7/Fluent) and native Uncanny Automator / AutomatorWP recipes** (webhooks ≠ recipe UX) |
| 3 | Survives 100k users / 1M log rows | 🟢 Have, 🔴 unmarketed | Snapshots, pruning, scale CLI benchmark exist. GamiPress has PUBLIC meltdowns (1.1M-row log took a site down; 142s queries). **We never say it.** Publish a benchmark page |
| 4 | Seasonal competitions | 🔴 Missing | Rolling windows (day/week/month) ≠ seasons. No scheduled reset + archive + prizes + battle-pass track. **Both competitors also fail this (PHP-snippet territory) → #1 leapfrog** |
| 5 | Streaks + mercy mechanics | 🟡 Partial | Streaks + login bonus ladder ✓. **Missing: streak freeze tokens, comeback bonuses** (Duolingo-pattern is the 2026 default) |
| 6 | How do members find out they earned? | 🟢 Strong | Toasts + emails + digests + BP notifications + nudges — all free (GamiPress charges for each). Marketing win available |
| 7 | Stop cheaters, fix mistakes | 🟡 Partial | Caps, exclusion, revoke, audit log ✓. **Missing: retroactive recount for pre-install activity** (top GamiPress support topic), anomaly detection |
| 8 | Total cost / bundling | 🟢 Winning | One free plugin vs GamiPress $199–699/yr add-on fragmentation and myCred $99–299/yr. Under-communicated |
| 9 | Migration path TO you | 🔴 Missing | **No importers.** `doctor` only detects myCred/GamiPress/BadgeOS as conflicts. BadgeOS refugees (security removals, institutional deprecations) are an active, searching market. GamiPress ships free importers as an acquisition weapon |
| 10 | 2026-native (AI/REST/blocks/GDPR) | 🟡 Partial | REST+CLI+webhooks+SSR blocks+Abilities API ✓, GDPR export ✓. **Missing: AI assistant surface** (myCred just shipped one on WP AI Client + Abilities — contestable ground since we already register abilities) |

---

## 4. Skeleton gaps (internal quality — from wppqa + 3-entry-point check)

### 4.1 Three-entry-point violations (our own CLAUDE.md rule 6)
Every other data store passes. Two fail:

| Store | Frontend | Admin UI | REST | Fix |
|---|---|---|---|---|
| **Streaks** | ✓ block | ✗ none | ✗ read-only | Admin streak browser + adjust/reset + write REST |
| **Kudos** | ✓ blocks | ✗ no moderation UI | ✓ | Admin kudos moderation (browse/revoke abusive kudos) |

Also: no raw event-log browser in admin (audit trail only reachable per-member/REST/CLI).

### 4.2 Owner operational walls
1. **No CSV import/export** of points/badges (only per-user JSON + GDPR export).
2. **No competitor importers** (see §3 row 9).
3. **Settings JSON export moves config only** — cannot clone a live economy between sites.
4. **No scheduled/seasonal leaderboard reset** (see §3 row 4).
5. **No multisite support** — zero `is_multisite()` handling; per-site tables only.
6. **No role-based earning rules** ("role X earns 2×, role Y can't earn Z").
7. **Granular caps unusable out-of-the-box** — all 11 `wb_gam_*` caps granted to
   administrator only; no UI to delegate to a community-manager role.
8. **Analytics depth** — no retention curves, no economy-inflation signal
   (issued vs redeemed trend), no analytics export.
9. **No owner-facing demo data** — wizard presets values but seeds no sample content
   (QA CLI seeder exists; not owner-facing).

### 4.3 Polish debt (wppqa evaluators, plugin-own code only)
- **a11y (18):** `outline:none` without `:focus-visible` (kudos/share/hub CSS);
  click handlers without keyboard alternative (2 block view.js + 6 assets JS).
  Conflicts with ux-foundation WCAG 2.1 AA.
- **Empty states:** ~40 templates/loops render silently blank when empty — violates
  big-site checklist item 9 (empty/error/loading). Biggest "feels unfinished" driver.
- **19 `fetch()` without `.catch()`** — network failures invisible to members.
- **Modals:** missing ESC handler (4), ARIA dialog role (3), close button (1).
- **Admin forms:** 21 without loading feedback, double-submit protection gaps,
  17 direct `$_POST`→`update_option` + 11 POST-without-nonce flags (heuristic — needs
  triage; some are false positives of the scanner, but the pattern check is due).
- **Email templates have zero hooks** — devs can't customize without overriding files.
- **`.card` unprefixed CSS selector** + 6 `!important` — theme-conflict risk.
- **No deactivation feedback capture** — churn reasons invisible.
- Known false positives (do NOT burn time): 17 "blocks lack SSR" (all 19 blocks have
  block.json `render` → render.php), "version mismatch 1.0.0" (read a bundled lib
  header), libs/ enum + nonce findings (vendored Action Scheduler / EDD SDK).

---

## 5. Next-level roadmap (proposed waves)

### Wave 1 — “Finish what exists” (1.6.2 — polish + trust)
Closes §4.1 + §4.3. No new concepts, pure completeness:
streak/kudos admin surfaces + write REST · empty-state sweep (every block/template
gets an empty/error/loading state with CTA) · a11y sweep (focus-visible, keyboard,
modal ARIA/ESC) · fetch error handling · caps-delegation UI (grant `wb_gam_*` per
role from Settings › Access) · admin-form nonce/Settings-API triage · email template
hooks · deactivation feedback.

### Wave 2 — “Seasons” (1.7 — the leapfrog release)
The feature neither competitor has natively: **Seasons engine** — scheduled
leaderboard resets (monthly/quarterly/custom) with archived past seasons, season
prizes (auto-award badge/points/coupon to top N), team/squad challenges,
battle-pass-style tiered reward track. Plus **streak freeze tokens + comeback
bonuses** (fits redemption store: spend points to buy a freeze).

### Wave 3 — “Switch to us” (growth/acquisition)
**Importers: BadgeOS first** (stranded users, active searches), then GamiPress,
myCred — points, badges, ranks, logs mapped into `wb_gam_*` tables with dry-run
preview. CSV import/export of points/badges. Retroactive recount tool (award for
pre-install WooCommerce orders / posts / course completions). **Publish the scale
benchmark** (1M-event seeded run vs documented GamiPress failure mode) as a
marketing page.

### Wave 4 — “Commerce depth”
Pay-with-points WooCommerce gateway + partial payments · points-purchase
(buyCred-parity, optional) · form-builder integrations (Gravity/Ninja/CF7/Fluent) ·
native Uncanny Automator / AutomatorWP integration pack.

### Wave 5 — “2026-native flag-plants”
AI assistant on the WP AI Client + Abilities API (natural-language: “create a badge
for 10 comments”, “why did member X get 500 points?”, “summarize this week’s
economy”) — we already register abilities, myCred’s is experimental; beatable.
Adaptive nudges (per-member disengagement prediction using existing churn analysis).
Market the GDPR + privacy story nobody else tells.

### Cross-cutting marketing corrections (cheap, this month)
readme leads with problem not feature list · say “everything included, no $49
add-ons” explicitly · say “built for 100k members” with the benchmark link ·
comparison landing pages (vs GamiPress / vs myCred / BadgeOS refugees).

---

*Full evaluator dumps: wppqa audit tool-result 2026-07-03; site-owner inventory and
market research agent reports archived in session. Re-run `wppqa_audit_plugin` after
each wave; keep findings filtered to non-`libs/` paths.*
