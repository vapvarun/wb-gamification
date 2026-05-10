# Changelog

All notable changes to **WB Gamification** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2026-05-06

First public release.

### Added — Engines & APIs

- **Event-sourced points engine** with 30+ auto-detected actions across 10 integrations.
- **Async award pipeline** via Action Scheduler — points / badges / streaks resolve out of the request hot path.
- **Materialised user-totals table** (`wb_gam_user_totals`) — single-row PK lookups instead of full-aggregation SUMs on the leaderboard.
- **Multi-currency points** — `wb_gam_point_types` + `wb_gam_point_type_conversions` tables, atomic `START TRANSACTION` + `FOR UPDATE` lock for currency conversions.
- **Snapshot-cached leaderboard** with 4 time periods (daily, weekly, monthly, all-time) and group-scoped queries.
- **Cohort leagues** — automatic ranking within signup-week / role / custom cohort groups.
- **30 pre-built badges** with point-milestone and action-count auto-award conditions; visual editor for custom badges.
- **5-level progression** (Newcomer → Apprentice → Member → Champion → Legend) with configurable thresholds.
- **Individual challenges** with admin manager, bonus points, and date-range gating.
- **Community challenges** with aggregate group target + per-contributor reward distribution.
- **Daily / weekly streak tracking** with grace period, 7 milestone tiers, bonus rewards.
- **Peer kudos** with daily-give cooldown, receiver / giver points, public feed.
- **Submission queue** (`wb_gam_submissions` table) — UGC achievements with admin approval routing through the standard award pipeline.
- **Daily login bonus** with 5-tier ladder (`[1=>10, 3=>20, 7=>50, 14=>100, 30=>250]`).
- **Public profile pages** at `/u/{user_login}` with privacy gate, OG meta, Schema.org JSON-LD.
- **Year-in-review recap** with shareable summary (top action, top badges, longest streak).
- **Redemption store** — custom / WooCommerce-backed rewards with point cost, stock, active/inactive state.
- **Outbound webhooks** for Zapier / Make / n8n integrations.
- **Weekly recap email engine** with member-personalised digests + admin test-send.
- **Transactional emails** for level-up / badge-earned / challenge-completed / kudos-received.
- **OpenBadges 3.0 credential issuance** at `/credentials/{badge}/{user}`.
- **Public OG share URL** for badges (`/?wb-gam-share=<badge_slug>`).

### Added — Frontend

- **17 Gutenberg blocks** — leaderboard, hub, member-points, points-history, badge-showcase, level-progress, challenges, community-challenges, cohort-rank, top-members, kudos-feed, streak, redemption-store, year-recap, daily-bonus, submit-achievement, earning-guide.
- **15 shortcodes** with parity to the blocks.
- **Toast notifications** via WordPress Interactivity API + REST polling.
- **Hub page** auto-created on activation (idempotent — never duplicates).
- **Mobile-responsive** at 390px viewport across every block + admin page.
- **Dark-mode-aware** token re-derivation; WCAG AA contrast on every surface.

### Added — Admin

- **Setup wizard** with 5 starter templates (Blog, Community, Course, Coaching, Nonprofit).
- **Modern admin UI** — sidebar navigation, card layout, 14px nav labels with 18px Lucide icons, 40px tap targets.
- **Analytics dashboard** — 6 KPI cards, top-actions table, daily sparkline, configurable date range (7d / 30d / 90d).
- **Manual award page** — admin REST endpoint with cap-drift sentinel (subscriber-attempt regression test).
- **13 admin pages**: Dashboard, Analytics, Badges, Challenges, Community Challenges, Cohort Leagues, Award Points, API Keys, Redemption Store, Webhooks, Submissions, Point Types, Point-Type Conversions.
- **Welcome notice** on plugin admin pages until setup wizard is completed.
- **3rd-party-notice suppression** via body-class-scoped CSS (keeps plugin own + critical core).

### Added — Platform

- **REST API** with 65 endpoints across 24 controllers; standardised envelope (`items_key`, `total`, `pages`, `has_more`); all responses ISO 8601 UTC; per-resource filter hooks.
- **OpenAPI 3.0 spec** auto-generated at `/wp-json/wb-gamification/v1/openapi.json`.
- **API-key authentication** via `X-WB-Gam-Key` header for cross-site / mobile / headless clients.
- **WP Abilities API** registration of 12 abilities for AI-agent discovery.
- **GDPR-compliant** data export + erasure registered with WP core.
- **WP-CLI** commands: `points`, `member`, `actions`, `logs`, `export`, `doctor`, `replay`, `email-test`, `qa`, `scale`.

### Added — Integrations

- **9 first-party integration manifests**: BuddyPress, bbPress, WooCommerce, LearnDash, LifterLMS, MemberPress, GiveWP, The Events Calendar, ACF, Elementor.
- **Graceful degradation** — every integration silent when its host plugin is absent (verified by `audit/journeys/release/06-integration-graceful-degradation.md`).

### Added — Quality

- **Documentation** — 55 customer-facing markdown files across 7 categories at `docs/website/`.
- **Unit + integration test suite** — 108 tests, 236 assertions, PHPUnit + Brain Monkey.
- **Static analysis** — PHPStan level 5 clean, baseline 0 entries.
- **Coding standards** — WPCS pass on every file in CI; 11 plugin-specific rules enforced via `bin/coding-rules-check.sh`.
- **Journey corpus** — 15 deterministic journey files at `audit/journeys/` covering customer / admin / qa / security / release flows.
- **Pre-release agent smoke** — generic Claude-level `wp-plugin-smoke` skill consumes per-plugin `docs/qa/qa.config.json` to dispatch Sonnet for the full smoke walk.
- **Build-release gate** — `bin/build-release.sh` refuses to package without a green smoke report at `docs/qa/.last-smoke-pass.json`.

[Unreleased]: https://github.com/vapvarun/wb-gamification/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vapvarun/wb-gamification/releases/tag/v1.0.0
