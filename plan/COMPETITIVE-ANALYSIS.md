# Competitive Analysis — WB Gamification vs. the field

**Status:** active
**Date:** 2026-05-06
**Cadence:** refresh quarterly or when a competitor ships a major release

This doc is the single source of truth for "what makes us better" and "what we still need to build" framed for marketing + roadmap. Every gap card in Basecamp Triage links back here.

## Field

Direct competitors — WordPress plugins:
- **GamiPress** — most direct competitor; freemium core, paid add-ons (BP, WC, LearnDash, email, frontend submission, etc.)
- **myCred** — points-first, multiple point types, ranks
- **BadgeOS** — older, less active

Direct competitors — SaaS:
- **GameLayer** — API-first gamification, B2C
- **Captain Up** — engagement widget
- **Smartico** — iGaming gamification
- **Influitive** — B2B advocacy

## What we already win on (lead the marketing here)

| Capability | Us | GamiPress / myCred | SaaS |
|---|---|---|---|
| OpenBadges v3 credential export | ✅ Native, per-badge endpoint | ❌ | Edu-niche only |
| Gutenberg-first (15 blocks, Interactivity API) | ✅ | Mostly shortcodes | n/a |
| REST-first + published OpenAPI spec | ✅ 51 routes + `/openapi` | Limited | Some |
| Headless-ready (cookie+nonce, App Passwords, API Keys) | ✅ | Limited | ✅ |
| Webhooks with HMAC + retry-with-backoff | ✅ | Premium add-on | ✅ |
| Event-sourced audit log (UUID + metadata per change) | ✅ | ❌ | Enterprise only |
| Cohort leagues + community challenges (collective) | ✅ | ❌ | Some |
| Bundled 37-SVG badge library | ✅ Out-of-box | Pay-per-design | n/a |
| 0 `admin_post_*` / 0 `wp_ajax_*` (Tier 0) | ✅ Modern arch | ❌ Legacy | n/a |
| Native BP + WC + LearnDash (free) | ✅ | All add-ons paid | n/a |

**Distinct positioning wedge:** *"OpenBadges + Gutenberg + Headless + Event-sourced + Free-bundled-badges."*

## Marketing tagline candidates

> "The only WordPress gamification plugin that ships verifiable OpenBadges credentials."

> "Headless-ready, event-sourced gamification — built for modern WP stacks."

> "Free badge library, native BuddyPress + WooCommerce + LearnDash, zero AJAX handlers."

## Critical gaps (table stakes — likely blocking deals today)

| # | Gap | Why it matters | Build status |
|---|---|---|---|
| 1 | **Multiple point types / parallel currencies** | GamiPress + myCred both let admins create XP / Coins / Karma as separate ledgers. We have one ledger. RFPs that ask for XP-for-learning + Coins-for-shop disqualify us. | not started |
| 2 | **Transactional emails** | Level-up / badge-earned / weekly-summary. We have webhooks but no email surface. Every comparison chart has this column. | not started |
| 3 | **Public profile pages** (`/u/{slug}`) | Sharable user achievement page. Loses the viral-loop angle without it. We have the Hub block, just need a permalink template. | not started |
| 4 | **Login streak / daily login bonus** (separate from activity streak) | Gamification 101. Almost every product advertises this. Ours tracks any activity, not specifically login. | not started |
| 5 | **Front-end achievement submission (UGC)** | User uploads "I did the thing" → admin reviews → awards. Foundational for community contests. GamiPress has this as paid add-on. | not started |

## High-value additions (would 2× market position)

| # | Gap | Notes |
|---|---|---|
| 6 | Referral system | Refer-a-friend points; "share your link, get 50 pts" |
| 7 | Time-bound campaigns + multipliers | "Double XP weekend"; overlay layer on the points engine |
| 8 | Slack / Discord native templates | Community managers' #1 ask; webhooks make it possible — just need official template |
| 9 | Web push notifications | In-browser level-up / badge-earned alerts |
| 10 | Visual rule builder | Drag-drop trigger + condition editor; GamiPress's main UX win |

## Nice-to-have (mark as roadmap / "coming soon")

11. CSV import/export of users/points/badges (enterprise migration ergonomics)
12. Real-time leaderboard updates (WebSocket / SSE)
13. Zapier / Make.com app listing
14. Pay-for-points (sell points via WooCommerce — revenue lever for sites)
15. Avatar frames / cosmetic rewards (was removed in v1.1, could reinstate)
16. Multi-tier referral / affiliate
17. Anti-abuse / cheat detection beyond rate limits
18. Public WPML / Polylang compatibility statement
19. GDPR data export / erase hooks
20. Mobile SDK (iOS / Android native) — long horizon

## Comparison-page strategy

Build `/compare/wb-gamification-vs-gamipress` highlighting:
- 6 features we have natively that need paid GamiPress add-ons (BP, WC, LearnDash, webhooks, OpenBadges, cohorts)
- 1 thing nobody else has (OpenBadges credential export)
- Honest "coming soon" row for the 5 critical gaps above

## Release cadence

The 10 prioritised gaps split across the first two public releases.

### v1.0.0 — first public release (the "feature parity" launch)

Closes all 5 critical gaps. See `plan/v1.0-release-plan.md` for full scope, build order, acceptance gates. Effort estimates revised per `plan/CODEBASE-AUDIT-2026-05-06.md` — several gaps turn out to be polish on existing infra rather than net-new builds.

| # | Feature | Effort | Notes | Card |
|---|---|---|---|---|
| 1 | Multiple point types | **L** | Net new — schema migration + back-compat shim | `9860174792` |
| 2 | Transactional emails | **XS** ⬇ | Email.php renderer + WeeklyEmailEngine + theme-override pipeline already exist; just add 2-3 templates and wire to events | `9860175602` |
| 3 | Public profile pages | **S** | Net new permalink template; reuses Hub block | `9860177823` |
| 4 | Login streak + daily bonus | **XS** ⬇ | `wp_login` already wired; StreakEngine already timezone-aware with milestones — add `type` column + bonus-tier display block | `9860179374` |
| 5 | Front-end achievement submission | **M** | Net new submissions table + admin queue; depends on #2 for notify-on-submit emails | `9860180261` |

**Net: only 2 of the 5 are true new builds (#1, #5). The other 3 ride existing infra.** Originally estimated as 3 sprints; with revised efforts ~1.5-2 sprints.

### v1.1.0 — high-value cycle

The 5 follow-ups. See `plan/v1.1-release-plan.md`.

| # | Feature | Effort | Notes |
|---|---|---|---|
| 6 | Referral system | S | Net new |
| 7 | Campaign multipliers / time-bound events | **S** ⬇ | RuleEngine already supports `points_multiplier` rule type with conditions — add time-window condition + admin UI |
| 8 | Slack / Discord integration templates | **XS** ⬇ | WebhookDispatcher + retry queue already in place — add Slack-Block-Kit + Discord-Embed payload formatters + setup wizard |
| 9 | Web push notifications | M | Net new (VAPID + service worker + subscription table) |
| 10 | Visual rule builder | L (1-2 month project) | Storage exists (`wb_gam_rules`); RuleEngine evaluates — UI is the project |

## How this doc is used

- **Marketing**: cite the strengths table on landing pages, comparison page, and outreach
- **Roadmap**: every Triage card for a new feature links here for context
- **QA / dev**: when scoping a gap-closer card, read the corresponding row above for "why" before designing

Updated by Varun — 2026-05-06.
