# Changelog

All notable changes to **WB Gamification** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.6.4] - 2026-07-12

Stability and scale release. Contains a fix for a bug that could delete other plugins' queued background jobs, including WooCommerce orders and subscription renewals. Upgrading is strongly recommended for every site.

### Added

- Weekly cap is now settable per action in Settings > Points. The limit was already enforced, but there was no field to set it.
- `wp wb-gamification scale benchmark` covers the badge, notification, and streak read paths. A green run against a seeded 100k dataset is now required before a release can be packaged.

### Changed

- Points, badges, levels, and the Hub follow the active theme's colours in both light and dark mode on BuddyX, BuddyX Pro, and Reign.
- Rate-limit feedback ("on cooldown", "daily limit reached") is API-only. It is no longer shown to members, who did not ask for the award and should not be told it was declined.

### Fixed

- A reward with limited stock became unlimited the moment it sold out, and could then be redeemed without limit. Stock now has three distinct states: empty means unlimited, `0` means sold out, and any positive number is the quantity remaining. Rewards currently running with a stock of `0` stay unlimited and are migrated on upgrade.
- The per-member kudos cooldown was never applied on sites in a timezone behind UTC, so members could send kudos to the same person repeatedly.
- Two kudos sent to the same member at the same instant could both bypass the cooldown and record twice.
- The weekly digest's "this week" window was offset by the site's timezone, so the email could omit recent activity or include activity from the previous week.
- The weekly digest was also sent to the wrong people for the same reason: the recipient query used the database clock instead of the site's, so members active near the edge of the window were dropped from the send.
- Challenges opened and closed on the database's clock rather than the site's. On a site ahead of UTC a challenge scheduled to start at 09:00 stayed shut for hours - members could not join one that was already running - and one due to close at midnight kept accepting entries.
- The community-challenge countdown showed the wrong time remaining and could read "ended" hours before the challenge actually closed.
- Leaderboards serve from their snapshot table instead of aggregating the full points ledger on every view. The snapshot was disabled by every points award, and a timezone mismatch emptied it at the end of each rebuild on sites ahead of UTC.
- The all-time leaderboard reads the materialised member totals rather than summing the whole ledger.
- Members no longer receive duplicate weekly emails; an overdue cron re-fire could start a second send while the first was still queued.
- The notification queue is bounded per member and no longer replays a backlog of stale toasts on every page load.
- Weekly digest, cohort assignment, and status-retention jobs process members in batches instead of attempting the whole site in one run.
- Personal-data export is paginated, so an export for a member with a long history no longer exhausts memory.
- Award Points uses a searchable member picker instead of rendering every member on the site into a dropdown.
- Toasts set to a top position rendered behind the theme's header; they now sit below it, and below any other bar pinned to the top of the page.
- Added database indexes for badge, kudos, submission, redemption, and member-intelligence queries that previously scanned the full table.
- Badge rarity is cached, so public badge pages no longer aggregate the badge table on every request.
- The setup wizard's Coaching Platform and Nonprofit templates no longer seed actions that cannot fire; the wizard refuses to save an action it does not recognise.
- Licence assets no longer 404 on hosts where the plugin directory is a symlink.

### Security

- Action Scheduler cleanup no longer deletes other plugins' queued work. It previously removed every pending job older than the retention window with no ownership check, which could destroy WooCommerce orders and subscription renewals on any site. Cleanup is now fenced to this plugin's own jobs, and queued work is never aged out as routine housekeeping.

## [1.6.3] - 2026-07-08

### Fixed

- A per-action cooldown no longer surfaces a member toast ("You're on cooldown for this action - try again in a bit."). The cooldown skips the award silently, as it did before the notice was added in 1.4.1; a cooldown is a transient anti-burst limit and the notice read as an error for normal activity. Daily/weekly cap notices (a real, resetting limit) are unchanged. The surfaced set is filterable via `wb_gam_award_skip_toast_reasons`.

## [1.6.0] - 2026-06-21

Adds the Wbcom Family Kit integrations guide, share-ready badge pages with social images, and fully styled block editor previews.

### Added

- Wbcom Family Kit: a new Integrations tab that guides you to related Wbcom products (WPMediaVerse, Jetonomy, BuddyNext, Learnomy, WP Career Board, WB Listora) with one-click install and activate for the free members.
- Badge share pages now generate a dynamic 1200x630 social share image per badge and earner, with Open Graph and Twitter card meta for rich link previews.
- Block editor previews now render every block styled inside the editor canvas, and the Give Kudos block gained full editing controls and a styled preview.
- New `wb_gam_og_accent_color` filter to customize the badge share image accent color.

### Changed

- Points settings are now grouped by the source plugin instead of by category, so each integration's points are easier to find.
- The setup wizard is reoriented around the Wbcom family of products.
- The badge share page is rebuilt as a polished card with copy-link and X, Facebook, and LinkedIn share actions, replacing the old browser prompt popup.
- Family product logos in the Integrations tab render at a consistent size.


## [1.5.6] - 2026-06-15

Bug-fix release: correct level progression, instant admin level edits, and a hardened release build.

### Changed

- Release build (`bin/build-release.sh`) now hard-fails if `vendor/` is incomplete after `composer install` (missing autoloader or Action Scheduler), so a deps-less zip can never be packaged (Basecamp 9993571511).

### Fixed

- Level progression now follows the configured level order (`sort_order`) instead of the raw points threshold, so the dashboard nudge and level-progress card name the correct next level when thresholds are edited out of order (Basecamp 9995220498).
- Editing a level in the admin now invalidates the level cache immediately, so changes reflect without waiting for the cache TTL.
- Badge creation via the REST API now returns HTTP 201 Created (was 200), matching the levels controller and REST convention.


## [1.5.5] - 2026-06-11

Member-facing polish: an admin accent control, a restrained on-brand activity stream, and faster realtime feedback.

### Added

- Settings > Appearance accent-color control applied across member-facing surfaces, so gamification matches the community brand.

### Changed

- Redesigned the BuddyPress activity cards (badge, level, kudos, challenge) to a single theme accent with a flat surface and a subtle edge, replacing the per-type rainbow and heavy top strip.
- Activity headlines now read as a short generic verb ("earned a badge") so they no longer repeat the card beneath them.
- Admin-screen consistency sweep: unified buttons, replaced blank navigation icons, tokenized colors for dark mode, responsive tables, accessible tap targets.

### Fixed

- Realtime points/badge toasts arrive in ~15s instead of up to a minute (heartbeat interval was falling back to the WordPress default).
- Badge artwork stays legible in dark mode via a light medallion plate on profiles and in the activity stream.
- Legacy activity items convert to the modern card automatically on update across all four event types, with no manual step.
- Public profiles show "1 badge" (singular) correctly.
- Accessible names on the email-notification toggles and the submission reject-reason field.
- i18n: aligned the "%d badge" translator comment across surfaces (make-pot warning-free).
- Admin screens on WordPress 6.9+ no longer show repeated "ability category not registered" notices and the "headers already sent" warnings they caused after install.

### Dev

- Gamification abilities now register correctly with the WP Abilities API: category registration, executable callbacks proxied to the documented REST routes, permission gates per auth level, and input schemas.
- Regression journey + unit test covering the activity card and generic-headline contract.


## [1.5.3] - 2026-06-02

Site-owner control release: the admin controls communities expect for managing who earns, plus bulk operations and config portability. All new REST endpoints live under `wb-gamification/v1` (admin-gated, namespaced - no WordPress core or BuddyPress conflict).

### Added

- Settings > Access: exclude roles or specific accounts from earning. `PointsEngine::user_can_earn()` enforces it at every award path - the registered-action rate-limit gate AND directly in `award()` / `award_batch()` (which login bonus, community-challenge bonus, and approved submissions call directly). Manual admin REST award + CLI `points award` override via `force`. Excluded users are also hidden from leaderboards. New filter `wb_gam_user_can_earn`. `wb_gam_sandboxed` meta added to GDPR export + erase.
- Members admin page (Gamification > Members): searchable, paginated roster of every member with points / level / badges, primed N+1-safe, plus per-member award (links to Award Points), exclude/include, and reset-points. New `GET wb-gamification/v1/members`, `POST .../{id}/exclude`, `POST .../{id}/reset-points`.
- Bulk award: `POST wb-gamification/v1/points/bulk` awards the same points to a whole role or all members via `PointsEngine::award_batch` (exclusion-aware). Bulk Award card on the Award Points page.
- Settings > Tools: import/export the plugin configuration as JSON (`WBGam\Engine\SettingsIO` + `ToolsController`: `GET .../tools/export-settings`, `POST .../tools/import-settings`). Runtime/derived/schema options (db version, feature schema gates, caches, snapshots, flush markers) are excluded so an import never corrupts the target site.
- Settings > Tools: Rebuild leaderboard maintenance button (`POST .../tools/recompute-leaderboard`) - the admin equivalent of `wp wb-gamification doctor --recompute-leaderboard`.
- Settings > Modules: optional-module on/off toggles (`WBGam\Engine\ModuleToggles`). Disabling a module suppresses its blocks (`render_block`) + shortcodes (`do_shortcode_tag`) and removes its admin submenu page (`remove_submenu_page`); data is preserved. Covers kudos, streaks, challenges, community challenges, cohort leagues, redemption store. Filter `wb_gam_module_enabled`. Distinct from `FeatureFlags` (schema gates).
- Settings > Points: opt-in inactivity point decay (`WBGam\Engine\PointsExpiry`, daily `wb_gam_points_decay` cron). OFF by default; when enabled, decays the balance of members inactive beyond N days by a configured percent, applied once per inactivity streak (`wb_gam_decayed_at` meta). Fires `wb_gam_points_decayed`.
- Settings > Tools: Reset all member progress (`WBGam\Engine\ProgressReset` + `POST .../tools/reset-progress`, admin-only + explicit `confirm`). TRUNCATEs 12 progress/derived tables, resets community-challenge counters, clears per-user progress meta, busts the leaderboard cache. KEEPS all definitions, config, member prefs, webhooks, API keys, settings. `ProgressResetTest` guards against a config table ever entering the wipe list. Fires `wb_gam_progress_reset`.

### Changed

- `PointsEngine::award()` / `award_batch()` gained a `force` parameter (default false) so deliberate admin/CLI grants can override earning exclusion while automatic awards respect it.

### Fixed

- Earning exclusion had no admin UI before this release - the `wb_gam_sandboxed` veto was only honored by the Jetonomy adapter, so native point awards (login bonus, submissions, manifest actions) could not be excluded without code.


## [1.5.2] - 2026-06-01

Performance and notification-quality release. Built to stay fast on large, live communities.

### Added

- WooCommerce My Account "Achievements" endpoint (`/my-account/achievements/`), gated on WooCommerce being active, for stores that run WooCommerce without BuddyPress. Renders the member's full Hub dashboard by reusing the hub block (My Account is always self-scoped) plus a link to the mapped Hub page. One-time guarded rewrite flush so the endpoint resolves after upgrade.
- Optional LearnDash profile "My Achievements" link to the mapped Hub page (via `learndash_shortcode_profile_before_template`). OFF by default - LearnDash has no native profile-section API, so the link is opt-in via `add_filter( 'wb_gam_learndash_profile_link', '__return_true' )`. Deliberately a single link, not stat blocks.
- Shared `WBGam\Engine\MemberSurface` renderer + `wb_gam_member_surface_html` filter: the BuddyPress tab, WooCommerce endpoint, and LearnDash link all reuse one path (asset enqueue, block rendering scoped to a member, mapped "View full dashboard" link, wrapper) with no duplicated display logic. Jetonomy is intentionally not given a surface - it has its own built-in leaderboard and its profiles ride on BuddyPress.
- BuddyPress profile "Achievements" tab with sub-tabs (Overview / Badges / Points / Streak). Renders the displayed member's points, level progress, streak, badges, and points history by reusing the existing blocks via their `user_id` shortcodes - no duplicated profile templates. Viewable on any member's profile, not just your own. Overview stays a concise personal summary (points + streak); the site-wide earning guide remains on the Hub.
- Admin setting for toast notification placement (Settings > Realtime): bottom-right (default), bottom-left, top-right, top-center, with corner-aware slide-in.
- `wb_gam_sse_allowed` filter to opt into SSE streaming on hosts provisioned for long-lived connections.
- `PointsEngine::prime_totals()` and `BadgeEngine::prime_earned_badges()` batch cache-prime APIs for per-row listing surfaces.
- `WBGam\Integrations\Jetonomy\DisplayDefer`: on a Jetonomy site the leaderboard defers to Jetonomy's reputation ranking. `JetonomyIntegration` already mirrors every reputation delta 1:1 into the points ledger, so wb-gam's leaderboard is a duplicate of Jetonomy's `ORDER BY reputation DESC` ranking. The `leaderboard` and `top-members` blocks/shortcodes are suppressed (via `render_block` + `do_shortcode_tag`) and the Hub `Leaderboard` card is dropped, so members see one ranking. Default-on when `JETONOMY_VERSION` is defined; override with `wb_gam_defer_leaderboard_to_jetonomy`. Badges are deliberately NOT deferred - wb-gam's badge engine (OpenBadges 3.0, expiry, share pages, cross-integration triggers) is the broader system and the two badge sets are complementary, not duplicates.

### Changed

- Realtime transport now defaults to WP Heartbeat instead of SSE/auto; SSE is opt-in behind `wb_gam_sse_allowed`. Removes a 28-second PHP-worker hold per logged-in page that did not scale on a standard PHP-FPM pool.
- Heartbeat steady-state interval raised from 5s to 15s, with a 30-second fast burst after member actions and near-suspend on backgrounded tabs (cuts steady-state request load roughly 3x).
- Member directory, leaderboard, and top-members blocks prime per-page data in a fixed number of queries, removing per-row N+1 (directory 82 to 4 queries per page; leaderboard badges 20 to 1; top-members levels 42 to 0 on warm data). Validated against a 1,000,000-row / 100,000-user dataset.
- Reward toasts resolve a human reason for every award (action label, or the admin-entered reason for manual awards); only same-action awards merge.
- Frontend surfaces (Hub, blocks, member profile) map their neutral color tokens (`--wb-gam-color-*`, `--gam-*`) to the host theme's `--bx-color-*` tokens, so they follow BuddyX and BuddyX Pro light/dark mode automatically. Muted text uses `color-mix` to stay readable on either surface. Tint backgrounds (icon chips, status pills, podium rows) mix over the theme surface so they adapt instead of showing opaque light blobs, and the brand accent keeps its hue but lightens on dark surfaces for contrast. Themes that do not expose `--bx-color-*` fall back to the original light values, so behaviour is unchanged off the BuddyX family.
- My Badges flyout shows two columns so each badge's art, title, and description are readable instead of cramped three-up.

### Fixed

- Toast stack overlapped the theme header/navigation; it now anchors to a configurable corner (default bottom-right).
- Duplicate toasts when SSE and Heartbeat both delivered the same event. SSE now stamps the canonical queue id the client dedupe keys on.
- Points toast displayed a contextless "+N Points (M actions)" count with no indication of what earned the points.
- Member profile pages at `/u/{username}` returned 404 for every member: public visibility required an opt-in flag that no member-facing UI ever wrote. Public profiles now default to on (opt-out via a `0` per-user flag), the owner and admins can always view a profile, and the Privacy visibility check uses the same opt-out default.
- Removed em-dashes (and en-dashes) from all translatable strings and seeded labels/descriptions across frontend and admin, replaced with hyphens per house style. Code comments are untouched. Existing seeded badge descriptions in the database were migrated too.


## [1.5.1] - 2026-06-01

PHP compatibility hotfix. Restores parsing on PHP 8.0/8.1 and lowers the supported floor to PHP 8.0. CI now lints PHP 8.0 through 8.4.

### Fixed

- **Fatal `E_COMPILE_ERROR` on PHP 8.1 and below.** `KudosEngine::send()` declared a `true|WP_Error` return type. The standalone `true` literal type was only added in PHP 8.2, so on PHP 8.0/8.1 the engine parsed `true` as a class name (`WBGam\Engine\true`) and the whole site crashed with `Cannot use 'WBGam\Engine\true' as class name as it is reserved`. Widened to `bool|WP_Error` (the docblock keeps the precise `@return true|WP_Error`).
- **`Event` value object used `readonly` properties (PHP 8.1+).** Broke parsing on PHP 8.0. Properties are now plain `public`; immutability is enforced by convention — the constructor is the only writer and `with_point_type()` returns a new instance.
- **`OpenApiCommand::error()` used the `never` return type (PHP 8.1+).** Changed to `void` so the file parses on PHP 8.0.

### Changed

- Minimum supported PHP lowered from **8.1 to 8.0** (`Requires PHP`, `composer.json`). The CI lint matrix already covers 8.0–8.4.

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
