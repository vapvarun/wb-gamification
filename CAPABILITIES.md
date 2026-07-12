# WB Gamification — Capabilities

What this plugin actually does for a site owner, in their language. The manifest
(`audit/manifest.json`) lists 76 REST endpoints, 26 tables and 142 hooks; it never says what
they add up to. This file does.

**Trust order:** `audit/manifest.summary.json` (code-verified state) > this file > code.
Anything else (dated snapshots, plan docs) is history — verify before trusting.

**Last verified against code: 2026-07-12 (branch `1.6.4`).**
Status: `YES` shipped & code-verified · `PARTIAL` works with a named limit · `PLANNED` unbuilt · `NO` absent.

---

## Rewarding members

| Can it… | Status | How |
|---|---|---|
| Award points for activity automatically? | YES | Rules engine over 126 integration triggers; `wb_gam_points`, `wb_gam_events` |
| Support more than one currency (XP, Coins, Credits)? | YES | Point Types admin page; `wb_gam_point_types`, `wb_gam_user_totals` |
| Convert one currency into another? | YES | Conversions page; `wb_gam_point_type_conversions` |
| Stop members farming points? | YES | Per-action cooldown, daily cap, **weekly cap**, earning exclusions (`PointsEngine::passes_rate_limits`). All four settable per action in Settings ▸ Points. (Caps are a PAID add-on in both GamiPress and myCred.) |
| Expire or decay unused points? | YES | `wb_gam_points_decay` cron (`PointsExpiry`) |
| Award points by hand, with a reason the member sees? | YES | Award Points admin page; the typed reason surfaces on the member's toast |
| Let members earn without being told when they *don't*? | YES | **By design, 1.6.4.** No skip toast exists. A cap is the site's anti-farming guard, not the member's business — see `NotificationBridge::init()` |

## Badges, levels, streaks

| Can it… | Status | How |
|---|---|---|
| Issue badges on conditions? | YES | Badge Library; `wb_gam_badge_defs`, `wb_gam_user_badges` |
| Award badges for tenure (time on site)? | YES | `TenureBadgeEngine`, `wb_gam_tenure_check` cron |
| Run levels with thresholds? | YES | `wb_gam_levels`, `LevelEngine` |
| Track daily streaks + milestones? | YES | `wb_gam_streaks`, `[wb_gam_streak]` |
| Expire a credential/status if unearned? | YES | `CredentialExpiryEngine`, `StatusRetentionEngine` |
| Let a member share a badge publicly? | YES | Badge share page + OG image route |

## Competition

| Can it… | Status | How |
|---|---|---|
| Show leaderboards? | YES | `[wb_gam_leaderboard]`, `wb_gam_leaderboard_cache` |
| Keep leaderboards fast on a big site? | YES | Materialised snapshot via Action Scheduler (`wb_gam_leaderboard_snapshot`, 5-min recurring) — not a live aggregate |
| Scope a leaderboard to a group/cohort? | YES | `wb_gam_cohort_members`, `[wb_gam_cohort_rank]` |
| Run individual challenges? | YES | Challenges page; `wb_gam_challenges`, `wb_gam_challenge_log` |
| Run community-wide (shared-goal) challenges? | YES | Community Challenges; `wb_gam_community_challenges` + contributions table |
| Nudge members who are close to overtaking someone? | YES | `LeaderboardNudge` — weekly, fanned out one Action Scheduler job per member (`wb_gam_nudge_single_user`) so a 100k-member send doesn't land in one cron tick |

## Spending points (the loop that keeps them earning)

| Can it… | Status | How |
|---|---|---|
| Run a redemption store? | YES | Redemption Store admin; `wb_gam_redemptions`, `wb_gam_redemption_items` |
| Offer a reward with limited stock and trust the limit? | YES | **1.6.4.** Stock has three states: empty = unlimited, `0` = sold out, positive = quantity left. Before 1.6.4 a sold-out reward silently became unlimited (0 was read as "unlimited"), so a one-of-a-kind reward could be redeemed without limit. Decrement is atomic, so two members cannot both take the last unit |
| Show a member what they've redeemed? | YES | `[wb_gam_my_rewards]` — history survives item deletion (fixed 1.6.2) |
| Let members submit things for reward? | YES | Submissions page; `wb_gam_submissions` |
| Let members give each other kudos? | YES | `[wb_gam_give_kudos]`, `[wb_gam_kudos_feed]`; `wb_gam_kudos` (moderatable) |

## Putting it on the site

| Can it… | Status | How |
|---|---|---|
| Drop features into any page without code? | YES | **17 shortcodes** + **19 blocks** (registered via `ShortcodeHandler`) |
| Give members one place to see everything? | YES | `[wb_gam_hub]` — the Gamification Hub |
| Explain to a member how to earn? | YES | `[wb_gam_earning_guide]` |
| Show a year-in-review? | YES | `[wb_gam_year_recap]` |
| Notify a member the moment they earn? | YES | Toast + celebration overlay. Delivered over **WP Heartbeat (default)** or **SSE**; both read one queue through one reader (`NotificationBridge::fetch_unseen`) |
| Survive a member returning to a huge backlog? | YES | **1.6.4.** Reads are burst-capped at 5 and fast-forward past a backlog rather than replaying it. Queue is bounded per member on write, so it cannot grow without limit even where WP-Cron never runs |

## Running it at scale

| Can it… | Status | How |
|---|---|---|
| Handle 100k members? | YES | `wp wb-gamification scale` seeds + benchmarks hot-path queries against budgets |
| Survive a host where WP-Cron never fires? | YES | Queue bounded on write; Action Scheduler used for fan-out and retries |
| Diagnose itself? | YES | `wp wb-gamification doctor`, `qa`, `logs`, `replay` |
| Recover a failed side-effect? | YES | `wb_gam_side_effect_failures` + `wb_gam_reconcile_side_effects` |
| Spot at-risk / anomalous members? | YES | `wb_gam_user_intelligence`; churn-risk + anomaly panels on Analytics |

## Connecting to other systems

| Can it… | Status | How |
|---|---|---|
| Reward activity from other plugins? | YES, with an honest caveat | **21 integration manifests / 126 triggers** — but the reachable number depends on the owner's stack, and quoting 126 flat is misleading:<br>• **8** on a vanilla WordPress site<br>• **50** with WooCommerce + LearnDash + BuddyPress<br>• **126** on the full Wbcom suite (**76 of the 126 require another Wbcom plugin**)<br>Third-party: BuddyPress, bbPress, WooCommerce, LearnDash, LifterLMS, MemberPress, GiveWP, The Events Calendar. Wbcom: BuddyNext, Jetonomy, Learnomy, Listora, Eventonomy, WPMediaVerse, WP Career Board. (ActivityPub and GraphQL are adapters and award no points — they are not integrations in this sense.) |
| Push events out to another system? | YES | Outbound webhooks with retry/backoff (`wb_gam_webhooks`, `wb_gam_webhook_retry`) |
| Be driven from an external app? | YES | 76 REST endpoints + API keys (`wb_gam_api_keys`) + `openapi.json` export |
| Tell an API caller *why* an award didn't land? | YES | **1.6.4.** `POST /events` returns `{skipped: {reason, message, context}}` alongside `processed: false` |
| Import from BadgeOS? | YES | Import admin page + `BadgeOSImporter` |

---

## Known limits (planning inputs, not oversights)

| Limit | Detail |
|---|---|
| Skip/cap feedback is API-only | Deliberate. There is no setting to show members "you hit your daily limit" — the mechanism was removed in 1.6.4, not defaulted off. Owners who want to count skips can hook `wb_gam_award_skipped`. |
| SSE is not the default transport | SSE pins a PHP worker per connection; the default is WP Heartbeat. Owners opt in via `wb_gam_realtime_transport`. |
| Not release-ready by the strict QA gate | `wppqa` reports a11y (18) and admin-eval (28) failures. All 5 HIGH *security* findings are in bundled third-party libs, not our code. See `audit/wppqa-baseline-2026-07-12/SUMMARY.md`. |
