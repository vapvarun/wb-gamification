# Changelog

All notable changes to **WB Gamification** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-05-28

Second bug-sweep release. Closes 21 reported issues across blocks, admin, notifications, and integrations. Adds a manual-award admin UI, a circuit-breaker for runaway Action Scheduler state, and two new local-CI gates that catch the bug classes we hit during the sweep before they ship again.

### Added

- **Manual-award form on the Badge edit screen.** New panel below the badge edit form (`BadgeAdminPage`) lets admins grant any rule-driven or manually-awarded badge to a chosen member without writing SQL. Uses the shared `admin-rest-form` pattern + `wp_dropdown_users`.
- **`MemberUploadCap` engine.** Grants `upload_files` to every logged-in member via the `user_has_cap` filter so the Submit Achievement block's Add Media button works for non-admins. Opt-out filter: `wb_gam_grant_member_uploads`. Booted via `FeatureFlags::CORE_ENGINES`.
- **Action Scheduler circuit-breaker + drain CLI.** `ActionSchedulerCleaner` now refuses to enqueue new cleanup jobs when the `actionscheduler_actions` table breaches the danger threshold; `wp wb-gamification as drain` provides a manual escape hatch. Closes the 3.5M-row runaway on the production install that triggered PERF-002.
- **Local-CI gate 2.13 (boot-invariants).** PHP tokenizer-based detector (`bin/check-boot-invariants.php`) walks `token_get_all`, finds every `T_CLASS` at depth=0, and fails the build if a `class_exists($name, false)` guard sits above it. Root cause of the silent boot failure that hid the admin menu.
- **Local-CI gate 2.14 (badge-condition contract).** Parses `Installer::seed_default_badges()` and asserts every "First X" / "N posts/comments" badge uses `action_count` with a matching action_id, and every "N points" badge uses `point_milestone`. Cross-references action_ids against `integrations/*.php`.
- **`CommunityChallengeEngine::get_visible()`.** Returns active + completed-but-not-expired challenges so the block keeps completed challenges visible until their expiry.
- **`CohortEngine::get_tier_name(int)` + `CohortEngine::SETTINGS_OPTION`.** Single resolver reads `wb_gam_cohort_settings` and falls back to the `TIERS` constant; consumed by the Cohort Rank block and admin surfaces.

### Changed

- **Toast notifications use WP Heartbeat `fast` interval (5 s).** `assets/js/heartbeat.js` default switched from `standard` (15 s) to `fast` for the gamification surface so realtime feedback feels immediate without paying the cost everywhere.
- **Earning Guide card layout reflowed.** Icon + points sit on a top row; the action label drops to a full-width row below so long action names no longer wrap vertically inside a cramped middle column.
- **Cohort Rank block carries tier-coloured accents.** `render.php` writes a `data-tier` attribute; per-tier CSS variables in `style.css` apply Bronze / Silver / Gold / Diamond accents.
- **Community Challenges completed-state visual treatment.** New green pill + gradient card so completed challenges read distinctly from active ones. `index.js` now `import './style.css'` (the missing import was silently dropping per-block CSS from the webpack build).
- **User Status Bar chevron + offset.** Replaced the filled-circle toggle with an SVG-mask chevron; added `--wb-gam-status-bar-top-offset` CSS variable so the sticky panel sits below custom theme admin bars.
- **WooCommerce purchase events fire on `woocommerce_payment_complete`.** Previously bound to `woocommerce_order_status_completed`, which only fires when an admin manually marks the order complete. First-purchase count query now uses `status IN ('processing', 'completed')` to account for orders that the gateway has confirmed but the admin has not yet marked complete.
- **Hub Challenges card combines counts.** `src/Blocks/hub/render.php` now uses a `panel_blocks` array supporting multiple blocks per hub panel; the Challenges card surfaces in-flight community challenges alongside personal challenges.
- **Default badge conditions corrected to match badge names.** First Post / Prolific Writer / Content Creator track `action_count` of `wp_publish_post`; First Comment / Engaged Reader track `action_count` of `wp_leave_comment`. Replaces the 50-points placeholder that fired on the wrong trigger.

### Fixed

- **Class-hoist guard silently aborted boot.** Removed the `class_exists($plugin_class, false)` guard at the top of `wb-gamification.php`. PHP processes top-level class declarations during the compile phase, so the guard always returned true at file's first line; the boot closure never registered, the admin menu never appeared. Gate 2.13 (above) prevents the regression.
- **Setup Wizard now triggers on first activation in CLI / sandbox flows.** `Installer::maybe_install` on `plugins_loaded@0` covers restore-from-backup, container clone, and `wp plugin activate` scenarios that bypass the standard activation hook.
- **Redemption emails actually send.** Added `redemption` to the `EVENTS` whitelist in `EmailSettingsController` so the per-event toggle gates the redemption confirmation email correctly.
- **Redemption Store reads `stock=0` as Unlimited.** Block contract aligned with the admin UI (which already documented `stock=0` = unlimited).
- **Community challenge bonus no longer dead-letters.** `CommunityChallengeEngine` now registers `wb_gam_community_bonus_award` as an Action Scheduler handler that calls `PointsEngine::award` for every contributor; the bonus is no longer enqueued without a listener.
- **Cohort tier names reach the frontend.** `CohortEngine::get_tier_name()` now reads `wb_gam_cohort_settings` and the Cohort Rank block consumes the new resolver instead of the raw `TIERS` constant.
- **Duplicate toast notifications eliminated.** Set-based dedupe in `assets/js/toast.js` keyed on `_id` (or content fingerprint as fallback) closes the cursor-race that produced repeated bubbles when multiple heartbeat consumers fired in the same tick.
- **LeaderboardNudge infinite-AS recursion contained.** `points_changed` broadcasts no longer re-enter `LeaderboardNudge::dispatch_batch` on installs where the change handler can loop back through the dispatcher.

### Developer

- **Manifest v2.2 refreshed end-to-end.** `audit/manifest.json` + `manifest.summary.json` now reflect 56 endpoints, 23 tables, 19 blocks, 44 services, 13 admin pages, 10 WP-CLI commands. `audit/derived/` caches 16 static-analysis sub-checks including the new `boot-hoist-guards` finder.
- **plan/ + audit/ consolidated.** `plan/MASTER-CHECKLIST.md` is now the single roadmap (90 shipped / 10 pending); 21 dated release/bug-sweep/UX-audit files were folded in and removed. Recoverable via `git log --diff-filter=D --follow plan/<path>`.

## [1.4.0] — 2026-05-25

Bug-sweep release. 11 reported issues fixed plus two stability wins discovered during code-level triage of the Basecamp queue.

### Added

- **Give-kudos block + shortcode** — new `wb-gamification/give-kudos` block and `[wb_gam_give_kudos]` shortcode let any logged-in member send kudos from any frontend page. Recipient resolved server-side from username or email.
- **Per-action cooldown + daily-cap admin override** — Settings → Points table now exposes editable cooldown (seconds) and daily-cap inputs per action with autosave to `POST /actions/{id}/overrides`. Resettable via `DELETE`.
- **ActionSchedulerCleaner** — daily cron prunes pending, failed, and complete `actionscheduler_actions` rows older than 7 days. Retention is filterable via `wb_gam_as_retention_days`. Prevents the long-running AS bloat that was causing slow page loads on busy installs.

### Changed

- Settings dashboard container now uses 1600px max-width on wide monitors; consolidated a duplicate `.wbgam-wrap` rule that was overriding the limit.
- Challenges and Community Challenges admin pages unified under a single Challenges menu entry with Individual / Community tabs. Old `?page=wb-gam-community-challenges` URLs still route correctly.
- Async flag dropped on five low-volume BuddyPress + WPMediaVerse actions (activity update, activity comment, friend accept, media reaction, media comment received, photo favorite received) so points update synchronously without Action Scheduler delay.
- Admin notices now render above the WB Gamification chrome instead of being visually trapped inside `.wbgam-wrap` (DOM-level lift in `assets/js/admin-rest-form.js`).
- Configure Points and Top Actions dashboard CTAs route to the in-page Points tab via hash anchor (`#points`) instead of the broken `?tab=points` query string.

### Fixed

- **LeaderboardNudge AS queue runaway** — `dispatch_batch()` now calls `as_has_scheduled_action()` before enqueueing per-user nudges, so repeated cron runs no longer compound into thousands of duplicate pending jobs.
- **Challenge timezone drift** — `ChallengeEngine` start / end queries use `UTC_TIMESTAMP()` instead of MySQL `NOW()`, matching the UTC-stored values written by the admin form. Challenges now activate at the correct moment on servers with a non-UTC MySQL session timezone.
- **datetime-local UTC hydration** — admin Challenge + Community Challenge edit inputs now convert UTC values to the browser's local time on page load (`data-wb-gam-utc` + `hydrateUtcDateTimeInputs`), so the saved time stops drifting by the timezone offset on every edit.
- **Empty toast text** — `NotificationBridge::on_level_changed` and `on_streak_milestone` now backfill a translated `message` string so the toast bubble is no longer empty.
- **Duplicate challenge completion side effects** — `ChallengeEngine::complete_challenge()` no longer calls `do_action('wb_gam_challenge_completed')` twice; BP activity rows, webhook deliveries, and emails now fire once per completion.
- **Kudos recipient lookup** — `KudosController::create_item` accepts a `recipient_login` field and resolves usernames or emails to user IDs server-side, so the new give-kudos block doesn't depend on the public `/wp/v2/users` endpoint (which only returns post authors).
- **BP activity filter labels** — `ActivityIntegration::register_activity_types` now exposes four distinct filter entries (Badge earned, Level up, Kudos sent, Challenge complete) instead of collapsing into one Gamification row. Sites can override via the new `wb_gam_activity_context_label` filter.
- **Admin menu icon disappears outside plugin pages** — the Gamification top-level menu icon is painted via a CSS pseudo-element that uses the Lucide font. The font + paint rule were only loaded on WB Gamification admin pages, so the icon showed as a blank square on Posts, Pages, Tools, etc. Both are now enqueued globally on every admin screen via a dedicated `wb-gam-admin-menu-icon` style handle.

### Improved (post-merge follow-ups)

- **Leaderboard rows** show points-with-icon plus badges-earned count alongside each member. New helper `BadgeEngine::count_user_badges()` reuses the object-cached badge list so leaderboard rendering stays O(N) on per-user lookup.
- **BuddyPress member directory** rank line now renders Level · Points · Badge count (with icons) instead of just the level name. Falls back gracefully — nothing rendered for members with no level, no points, and no badges.
- **Jetonomy free integration** — new `integrations/jetonomy.php` manifest covers four events that previously had no gamification coverage:
  - `jetonomy_space_joined` (5 pts) — joining a community space.
  - `jetonomy_join_request_approved` (10 pts) — admission to a gated space.
  - `jetonomy_trust_level_up` (50 pts) — promotion to a higher Jetonomy trust level (demotions never award).
  - `jetonomy_membership_activated` (25 pts) — paid-membership activation across all 9 host-plugin adapters (RCP, PMPro, MemberPress, WooCommerce Subscriptions, Sensei, LearnDash, MasterStudy, Tutor, LifterLMS).
- **Jetonomy Pro DM-received** (1 pt, cooldown 120 s, daily cap 10) — recipient earns points when a Jetonomy private message is delivered. Self-DMs return user id 0 so they never award. Daily cap protects against spam-DM gaming.

### Removed

- `JetonomyIntegration` no longer registers three filter listeners (`jetonomy_reputation_points_map`, `jetonomy_reputation_pre_change`, `jetonomy_leaderboard_items`) — audit of Jetonomy 1.4.4 confirmed those filters have no emit site upstream, so the listeners + their supporting methods (`filter_points_map`, `filter_pre_change`, `filter_leaderboard_items`, `campaign_is_active`, `campaign_covers_action`) were dead code. The `wb_gam_sandboxed` veto preserved by moving the check into the working `on_reputation_changed` mirror path. Tracked upstream as a Jetonomy card to land the missing filter emissions.

### Dev

- New filter `wb_gam_as_retention_days` (default `7`) to tune Action Scheduler retention per site.
- New filter `wb_gam_activity_context_label` exposes BP activity context labels for per-type customisation.
- New REST routes: `POST /actions/{id}/overrides`, `DELETE /actions/{id}/overrides` (admin-only).

## [1.3.0] — 2026-05-18

Jetonomy 1.4.3 + Pro event triggers and an in-tree WPMediaVerse Free + Pro manifest stack.

### Added

- **Jetonomy 1.4.3 integration** — reputation and leaderboard wiring via the manifest layer.
- **Jetonomy Pro event triggers** — polls, direct messages, badges, and reactions emit gamification events.
- **WPMediaVerse Free + Pro manifests** — owned in-tree so the host site no longer ships the manifest layer.
- **`wb_gam_award_skipped` hook** — fires from every silent-skip path in the engine so integrations can react to skipped awards.

### Fixed

- Points history view renders the manifest label instead of the raw `action_id`.
- WPMediaVerse handlers use upstream hook arguments instead of a broken static lookup.
- Boot path shows an admin notice instead of a fatal error when `vendor/` is missing (#49).

## [1.2.0] — 2026-05-10

Distribution pipeline and admin polish ahead of the integration release.

### Added

- EDD SDK integration for automatic plugin updates from wbcomdesigns.com.

### Changed

- Consolidated admin stylesheets into a single `assets/css/admin.css`.
- Populated admin dashboard with KPI cards, top actions, top earners, and a daily sparkline.
- `submit-achievement` view.js is translation-ready.

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
