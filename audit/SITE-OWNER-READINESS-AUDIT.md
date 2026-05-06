# WB Gamification — Site-Owner Readiness Audit

**Generated:** 2026-05-06
**Lens:** site owner (no code skills) trying to ship gamification on a real community site
**Question per feature:** can they configure it, see it work, and explain it to a member without writing PHP?

## Legend

- 🟢 **READY** — site owner can configure + use without code; admin UI complete; verified end-to-end this session OR has prior verification.
- 🟡 **GAP** — works, but blocked by missing admin UI, missing docs, missing visibility, or untested edge.
- 🔴 **NOT-READY** — exists in code but admin can't realistically use it without writing PHP / SQL / CLI commands.

---

## Tier 1 — Core (must work on Day 1)

| # | Feature | Status | Admin surface | Frontend | Gap |
|---|---|---|---|---|---|
| 1 | **Points** (earn + display) | 🟢 | Settings → Points tab (per-action values) + Manual Award page | `[wb_gam_member_points]` block + shortcode | None — heart of the engine, fully wired. |
| 2 | **Levels** (thresholds + progression) | 🟢 | Settings → Levels tab (CRUD via REST) | `[wb_gam_level_progress]` block | None. |
| 3 | **Badges** (earnable rewards) | 🟡 | Badges admin page (BadgeAdminPage) | `[wb_gam_badge_showcase]` block | **Rule editor is JSON-only.** No visual rule builder yet (v1.1 backlog card on Basecamp). Admin can create badges + set conditions only by editing the manifest or JSON config — non-technical site owners blocked from anything beyond the seeded 30 badges. |
| 4 | **Streaks** (daily activity) | 🟡 | Settings → Points tab (configurable per-action; grace period in code) | `[wb_gam_streak]` block | **No admin UI for grace period or milestone amounts.** Current values (7/14/30/60/100/180/365 days) are hard-coded. Site owner can't change milestone tier values without editing code or filtering. |
| 5 | **Daily Login Bonus** (NEW v1.0) | 🟡 | None | `[wb_gam_daily_bonus]` block | **No admin UI for tier ladder.** Default `[1=>10, 3=>20, 7=>50, 14=>100, 30=>250]` only changeable via `wb_gam_login_bonus_tiers` JSON option or filter. Members CAN see their bonus, but admins can't tune it. |
| 6 | **Kudos** (peer-to-peer) | 🟢 | Settings → Kudos section (cooldown + daily limit) | `[wb_gam_kudos_feed]` block | None. |
| 7 | **Leaderboards** (daily/weekly/monthly/all-time) | 🟢 | None needed (auto) | `[wb_gam_leaderboard]` + `[wb_gam_top_members]` blocks | Respects `leaderboard_opt_out`. ✓ |
| 8 | **Toast Notifications** | 🟢 | None (auto) | Frontend overlay, fires on award | **Was rendering blank since IA migration — fixed this session** (`data-wp-each--toast` rename + welcome toast). |
| 9 | **Setup Wizard** | 🟢 | Hidden submenu, auto-redirect on activation; "Re-run wizard" link added this session | — | New "Default notifications & privacy" fieldset shipped. |
| 10 | **Hub Page** (auto-created) | 🟢 | Auto-creates `/gamification/`; success banner surfaces URL | `[wb_gam_hub]` block | None. |
| 11 | **Privacy / GDPR** | 🟢 | WP core Tools → Export/Erase Personal Data; per-user `wb_gam_profile_public` toggle | — | Coding-rule (Rule 11) prevents future drift. |

**Tier 1 verdict:** core points / levels / leaderboard / toasts / setup are READY. Three GAPS: **Badges (visual rule builder)**, **Streaks (milestone tuning)**, **Daily Login Bonus (tier-ladder UI)**.

---

## Tier 2 — Engagement (admin configures if interested)

| # | Feature | Status | Admin surface | Frontend | Gap |
|---|---|---|---|---|---|
| 12 | **Challenges (Individual)** | 🟢 | Challenges admin page (CRUD) | `[wb_gam_challenges]` block | None. |
| 13 | **Community Challenges** | 🟢 | Community Challenges admin page (CRUD) | `[wb_gam_community_challenges]` block | None. |
| 14 | **Cohort Leagues** | 🟡 | Settings → Cohort sidebar section (basic config) | `[wb_gam_cohort_rank]` block | **Cohort assignment is automatic + cron-driven.** Site owner can configure but the connection between "I have these members" and "they're in cohort X" is opaque. Needs an admin "Cohorts → see who's in which cohort + reassign" view. |
| 15 | **Multi-Currency / Point Types** | 🟢 | Point Types admin page (CRUD) + Conversions admin page | Per-currency `[wb_gam_member_points type=]` | Just shipped end-to-end this session. |
| 16 | **Redemption Store** | 🟡 | Redemption Store admin page (CRUD) | `[wb_gam_redemption_store]` block | **Reward fulfillment is plumbing-level.** Admin can define rewards (free product, BP group access, custom) but the post-redemption fulfillment (e.g. send the coupon code by email, grant the group membership) requires `wb_gam_redemption_fulfilled` action listener. No "out of the box" delivery for non-WooCommerce / non-BP rewards. |
| 17 | **UGC Submissions** (NEW v1.0) | 🟢 | Submissions admin page (queue + approve/reject) | `[wb_gam_submit_achievement]` block | Help panel added this session. |
| 18 | **Public Profile Pages** (NEW v1.0) | 🟡 | None — toggle is per-user member preference; site-wide kill-switch is `wb_gam_profile_public_enabled` option | `/u/{user_login}` permalink | **No site-owner UI for the kill-switch.** Admin needs `wp option update wb_gam_profile_public_enabled 0` to disable site-wide. Should be a checkbox in Settings → Privacy. |
| 19 | **Year Recap** | 🟡 | None | `[wb_gam_year_recap]` block | **No admin trigger to send the recap email.** The recap data exists; the email pipeline doesn't. Site owner can render the block but can't email "your year in review" to members. |
| 20 | **Manual Award** | 🟢 | Award Points admin page | — | None. |

**Tier 2 verdict:** 4 GAPS: **Cohort visibility**, **Redemption fulfillment for non-WC/non-BP rewards**, **Public profile site-owner kill-switch UI**, **Year Recap email pipeline**.

---

## Tier 3 — Notifications (members hear about events)

| # | Feature | Status | Admin surface | Where it fires | Gap |
|---|---|---|---|---|---|
| 21 | **Toasts (in-page)** | 🟢 | None (auto) | On every award/badge/level event | Fixed this session. |
| 22 | **Transactional Emails** (NEW v1.0) | 🟢 | Settings → Emails section (per-event toggles, default OFF) | level_up / badge_earned / challenge_completed | New this session. |
| 23 | **Weekly Recap Email** | 🟡 | Settings → Emails section (toggle exists) | Monday digest, Action-Scheduler driven | **Per-user opt-out is DB-level.** No member-facing "unsubscribe from weekly recap" link in the email. WP doesn't enforce CAN-SPAM/GDPR consent for unsolicited weeklies — add unsubscribe link to template. |
| 24 | **Leaderboard Nudge** | 🔴 | None | "You moved up in the leaderboard" reminder | **Engine exists; not surfaced to admins.** No email template, no toggle, no documentation. Admin doesn't know it's a feature. |
| 25 | **Webhooks** | 🟢 | Webhooks admin page | Event-driven outbound POST | Help panel added this session. |
| 26 | **OpenBadges 3.0 Credentials** | 🟡 | None | `/wb-gamification/v1/credentials/{id}` (OG-friendly verifiable URL) | **No admin UI.** Members can share OpenBadges URLs (good) but admins don't see the surface — there's no "OpenBadges integration: ON/OFF" setting; it just always-on. Should be at least a section in docs explaining what verifiable credentials mean for the badges they award. |

**Tier 3 verdict:** 1 RED (Leaderboard Nudge invisible), 2 GAPs.

---

## Tier 4 — Specialized badge engines (always-on, low-touch)

| # | Feature | Status | Admin surface | Trigger | Gap |
|---|---|---|---|---|---|
| 27 | **Site-First Badges** | 🟡 | None | First time a user does X (publish post, comment, etc.) | **No admin list of "first-X" badges that ship.** Admin doesn't know what site-firsts auto-fire. Should be in the seeded badge library with category=`site-first`. |
| 28 | **Tenure Badges** | 🟡 | None | Daily cron checks user `created_date` against thresholds | **Thresholds hard-coded** (1 month, 6 months, 1 year, 2 years, 5 years). No admin UI to add a "10 year" badge. |
| 29 | **Personal Records** | 🟡 | None | Async eval after each award | **Visible only to the member** (their own best week). No admin "show me top performers by personal record" view. |
| 30 | **Status Retention** | 🟡 | None | Daily cron — keeps level if user logged in within window | **Window is hard-coded** (90 days). Admin can't tune. |
| 31 | **Credential Expiry** | 🟢 | Badge admin page (per-badge `validity_days` field) | Cron expires badges past validity_days | None — already configurable. |
| 32 | **Rank Automation** | 🟢 | Settings → Rules / Automation tab | Action-based rule eval | None. |

**Tier 4 verdict:** 4 GAPS — all "thresholds hard-coded; no admin tuning". Not blocking but limits site-owner customization.

---

## Tier 5 — Integrations (works ONLY if companion plugin is active)

**VERIFIED via grep.** Integrations live as `integrations/*.php` manifest files (auto-loaded by `ManifestLoader`), separate from `src/Integrations/` which is for richer PHP-class integrations.

### First-tier integrations (manifest in `integrations/`)

| # | Feature | Status | File | Earnable actions |
|---|---|---|---|---|
| 33 | **WordPress core** | 🟢 | `integrations/wordpress.php` | publish_post, comment, login (configurable per-action) |
| 34 | **WooCommerce** | 🟢 | `integrations/woocommerce.php` + `src/Integrations/WooCommerce/` | order_completed, product_purchased, refund |
| 35 | **BuddyPress** | 🟢 | `integrations/buddypress.php` + `src/BuddyPress/*` (4 classes + 5 stream cards) | activity_post, comment, friends, kudos, group_join, profile_updated |
| 36 | **LearnDash** | 🟢 | `integrations/learndash.php` | ld_course_completed, ld_lesson_completed, ld_topic_completed, ld_quiz_passed, ld_assignment_approved |
| 37 | **bbPress** | 🟢 | `integrations/bbpress.php` | forum reply, topic create |

### Contrib integrations (`integrations/contrib/`)

| # | Feature | Status | File |
|---|---|---|---|
| 38 | **GiveWP** (donations) | 🟢 | `integrations/contrib/givewp.php` |
| 39 | **LifterLMS** | 🟢 | `integrations/contrib/lifterlms.php` |
| 40 | **MemberPress** | 🟢 | `integrations/contrib/memberpress.php` |
| 41 | **The Events Calendar** | 🟢 | `integrations/contrib/the-events-calendar.php` |

### Claimed but NOT shipped (CLAUDE.md is wrong)

| Feature | Reality | Action |
|---|---|---|
| **Elementor** | ❌ No integration manifest. Only a "detection toggle" in Settings → Integrations that flags whether Elementor is active. Site owners who expect "Elementor form submit awards points" are blocked. | Update CLAUDE.md to remove Elementor from the integration list, OR add a manifest file at `integrations/contrib/elementor.php`. |
| **ACF** | ❌ Zero references in code. CLAUDE.md mentions ACF as one of the "8 integrations". | Same — drop from docs OR ship a manifest file. |
| **BP Reactions / Media / Groups** as separate integrations | These are sub-surfaces of the BuddyPress integration, not separate plugins. CLAUDE.md was over-counting. | Update CLAUDE.md to clarify BP integration scope. |

**Tier 5 verdict:** **9 real integrations ship**, more than CLAUDE.md's "8" count claims. **2 phantom claims** (Elementor, ACF) need either a manifest file or removal from docs. Otherwise all integrations gracefully no-op when the companion plugin is inactive (function_exists / class_exists guards confirmed in learndash.php).

---

## Tier 6 — Infrastructure (admin-facing, advanced)

| # | Feature | Status | Admin surface | Gap |
|---|---|---|---|---|
| 40 | **Analytics Dashboard** | 🟢 | Analytics admin page | KPI cards + charts shown. No CSV export — admin can't take the data offline. |
| 41 | **API Keys** | 🟢 | API Keys admin page | Help panel added this session. |
| 42 | **Webhooks** | 🟢 | Webhooks admin page | Help panel added this session. Manual retry of failed webhooks needs DB edit. |
| 43 | **Setup Checklist** (NEW this session) | 🟢 | Dashboard tab card | Auto-checks 5 steps; dismissible. |
| 44 | **WP-CLI Commands** | 🟢 | `wp wb-gamification {points,member,actions,logs,export,doctor,replay,qa,scale,email-test}` | All 10 commands work; CLI bug fixed this session. |
| 45 | **Privacy / GDPR** | 🟢 | WP core Tools → Export/Erase Personal Data | Rule 11 prevents future drift. |

**Tier 6 verdict:** 1 minor GAP (Analytics CSV export). Otherwise solid.

---

## Cross-cutting issues

| # | Issue | Status | Notes |
|---|---|---|---|
| 46 | **Mission Mode** referenced in CLAUDE.md Phase 3 | 🔴 | **Confirmed: does NOT exist.** Zero matches for `MissionEngine` in `src/Engine/`. CLAUDE.md is wrong. Action: drop from docs. |
| 47 | **Cosmetics** referenced in CLAUDE.md Phase 4 | 🔴 | **Confirmed: removed in v1.1.0.** `src/Engine/DbUpgrader.php` comment explicitly states: "the CosmeticEngine had no user-facing surface — a Tier-violation. The class is removed in v1.1.0." Action: drop from CLAUDE.md Phase 4. |
| 48 | **Documentation site** | 🟡 | `docs/website/` exists per CLAUDE.md but I didn't audit. Need to confirm every Tier 1 + Tier 2 feature has a doc. |
| 49 | **Empty-state quality** across blocks | 🟡 | Some blocks render "Log in to see..." for guests; haven't audited all 17. |
| 50 | **Site-owner first 30 minutes** | 🟢 | Setup Wizard → success banner → checklist → test event → toast → Hub page. End-to-end shipped this session. |

---

## Prioritized punch list

### P0 — block public release (must fix before tagging v1.0.0)
1. **Update CLAUDE.md + readme.txt to remove phantom claims:**
   - Drop "Mission Mode" from Phase 3 (doesn't exist).
   - Drop "Cosmetics" from Phase 4 (removed in v1.1.0).
   - Drop "Elementor" and "ACF" from the integration list — neither has a manifest file. Either ship a manifest in `integrations/contrib/` OR remove the claim.
   - Reframe BP Reactions/Media/Groups as sub-surfaces of the BuddyPress integration, not separate integrations.
2. **Confirm the contrib integrations are documented** — GiveWP, LifterLMS, MemberPress, The Events Calendar are real and shipping but CLAUDE.md never mentions them. They deserve at least a docs page each.

### P1 — limits non-technical site owners (fix in v1.1)
3. **Visual rule builder for badges** — already on Basecamp Triage as v1.1 card.
4. **Daily Login Bonus tier-ladder admin UI** — small UI to tune the ladder.
5. **Streak milestone admin UI** — let admin tune the 7/14/30 thresholds.
6. **Public profile site-owner kill-switch UI** — checkbox in Settings.
7. **Year Recap email pipeline** — turn the recap data into a sendable email.
8. **Cohort visibility view** — "who's in which cohort, can I reassign".
9. **Leaderboard Nudge surfacing** — make it discoverable to admins (toggle + docs).
10. **Tenure Badge threshold admin UI** — let admin add custom thresholds.
11. **Status Retention window admin UI** — let admin tune the 90-day default.

### P2 — polish (fix when other work is done)
12. **Analytics CSV export** — let admin take their data offline.
13. **Manual webhook retry button** — UI for failed-deliveries.
14. **OpenBadges integration explainer** — docs section explaining what it means.
15. **Weekly recap unsubscribe link in email** — CAN-SPAM hygiene.
16. **Cohort league transparency** — admin sees who's in which cohort.

### P3 — nice-to-have
17. **Empty-state audit across 17 blocks** — confirm every block has a useful zero-data state.
18. **Site-First Badge inventory in admin** — list what auto-fires.
19. **Documentation site audit** — every Tier 1+2 feature has a doc page.

---

## Headline takeaway for site owners

**On a fresh install with default settings, a site owner can:**
- ✅ Run the wizard, pick a template, get a working Hub page
- ✅ Have members earn points for posting / commenting / first action
- ✅ See their leaderboard fill up
- ✅ Award badges from the seeded library
- ✅ Send transactional emails when toggled on
- ✅ Connect remote sites via API keys / webhooks

**On a fresh install, the site owner CANNOT (without code):**
- ❌ Build custom badge rules beyond the 30 seeded
- ❌ Tune streak milestones or login-bonus tiers
- ❌ Disable public profiles via an admin checkbox
- ❌ Send a "year in review" email to members
- ❌ See who's in which cohort league
- ❌ Confirm whether LearnDash/bbPress integrations are actually wired

**Verdict:** v1.0 is **shippable for inhouse / closed-beta** — Tier 1 is fully ready. Public marketplace release should wait on:
- The P0 doc cleanup (drop Mission Mode + Cosmetics + Elementor + ACF claims; document the 4 contrib integrations) — small text edits, half a day's work.
- At least 3–4 of the P1 items, with the **visual badge rule builder** being the single most important customer-facing limitation (already on Basecamp Triage as a v1.1 card).

The plugin is a substantially more capable product than CLAUDE.md currently advertises (9 real integrations vs 8 claimed; 4 of which CLAUDE.md never mentions). The doc inaccuracies are easy wins — both removing phantom claims and surfacing the contrib integrations.
