=== WB Gamification ===
Contributors: vapvarun, wbcomdesigns
Tags: gamification, points, badges, leaderboard, buddypress
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.6.4
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete gamification for WordPress + BuddyPress. Points, badges, levels, leaderboards, challenges, streaks, kudos — zero config, works instantly.

== Description ==

WB Gamification is a universal gamification engine that works with any WordPress site. Install, activate, pick a template — gamification starts immediately. No configuration required.

The engine awards points automatically when members perform actions on your site. Points unlock badges, advance levels, fuel leaderboards, and power streaks. Everything is configurable from the admin, but sensible defaults mean it works from day one.

= Why WB Gamification? =

* **Zero config** — 5 starter templates pre-configure everything. Pick one and go.
* **Universal** — Works with plain WordPress, BuddyPress, WooCommerce, LearnDash, bbPress, and 5 more plugins. Auto-detects what you have installed.
* **Scalable** — Async award pipeline, snapshot-cached leaderboards, object caching everywhere. Built for 100K+ members.
* **API-first** — 64 REST endpoints, outbound webhooks, WP Abilities API. Mobile apps, headless frontends, and AI agents are first-class consumers. Admin UI is REST-driven internally — what the admin saves is what the API exposes (no parallel form-post surface).
* **No add-on model** — Every integration, every advanced engagement mechanic, every admin surface ships free. No paid extensions for BuddyPress support, cohort leagues, redemption store, or webhooks.

= Core Features (Free) =

* **Points Engine** — Configurable points for 30+ actions across WordPress, BuddyPress, WooCommerce, LearnDash, bbPress, and more. Event-sourced architecture ensures every point is traceable.
* **Badge System** — 30 pre-built badges with auto-award conditions (point milestones, action counts). Create unlimited custom badges with the visual badge editor.
* **Level Progression** — 5 default levels (Newcomer to Champion). Fully customizable thresholds. Progress bars on member profiles.
* **Leaderboard** — All-time, monthly, weekly, and daily rankings. Scope by BuddyPress group. Snapshot caching for performance at scale.
* **Challenges** — Time-bound goals with bonus points. Admin creates challenges, members track progress automatically.
* **Streaks** — Daily activity tracking with grace periods, milestone detection (7, 30, 100, 365 days), and bonus rewards.
* **Peer Kudos** — Members recognize each other with kudos. Configurable daily limits and point awards for both sender and receiver.
* **19 Gutenberg Blocks** — Leaderboard, member points, badge showcase, level progress, challenges, streak, top members, kudos feed, year recap, points history, earning guide, hub, redemption store, community challenges, cohort rank, daily bonus, give kudos, submit achievement, user status bar. Every block follows the Wbcom Block Quality Standard (apiVersion 3, per-side spacing × 3 breakpoints, hover/focus states, design tokens, per-instance scoped CSS).
* **17 Shortcodes** — Every customer-facing block is also available as a shortcode for classic editor and page builders (Elementor, Beaver Builder, Bricks).
* **REST API** — 64 endpoints across 28 controllers. Full CRUD for all resources. API key authentication for cross-site setups. Admin UI consumes the same REST API as 3rd-party integrations.
* **BuddyPress Integration** — Profile rank display, activity feed events, member directory badges, notification bridge.
* **Toast Notifications** — Real-time bottom-right popups when members earn points, badges, or level up. 6 notification types with auto-dismiss. Promise-based confirm modals replace native browser dialogs (a11y-friendly).
* **Analytics Dashboard** — 6 KPI cards, top actions, top earners, daily points sparkline. Period selector (7/30/90 days).
* **WP-CLI Commands** — `points award`, `member status`, `actions list`, `logs prune`, `export user`, `qa seed_pages`, `doctor` readiness check, plus a release-zip builder.
* **Developer Hooks** — 65 action hooks and 77 filter hooks for extending every write path. Every REST endpoint fires `before_*` filters (return WP_Error to abort) and `after_*` actions.
* **Cohort Leagues** — Duolingo-style weekly competitions with promotion/demotion percentages and per-cohort leaderboards.
* **Community Challenges** — Team goals with global progress (Pokemon GO model). Members contribute to a shared counter; everyone earns when the target is hit.
* **Redemption Store** — Members spend points on rewards. Built-in support for custom rewards (your hook), WooCommerce coupons, and Wbcom Credits SDK.
* **Badge Sharing** — Public share pages with OG meta, LinkedIn deep-links, OpenBadges 3.0 verifiable credentials.
* **Outbound Webhooks** — HMAC-signed webhooks for Zapier, Make, n8n. Configure events from the admin UI; deliveries auto-retry up to 3 times.
* **Weekly Recap Emails** — Automated weekly summary sent to members (opt-out per user).
* **Tenure & Site-First Badges** — Anniversary milestones (1yr, 2yr, 5yr, 10yr) plus first-mover badges that the first member to perform an action earns uniquely.
* **Privacy Compliant** — GDPR data export and erasure via WordPress privacy tools. Members opt out of leaderboard / hide rank from profile.

= Integrations (Auto-detected) =

All integrations are auto-detected and require zero configuration. Install the plugin — gamification actions appear automatically.

* **BuddyPress** — Activity updates, comments, friendships, groups, profile completion, reactions, polls, member blog (10 actions)
* **bbPress** — New topics, replies, resolved topics (3 actions)
* **WooCommerce** — Orders, first purchase, product reviews, wishlists (4 actions)
* **LearnDash** — Courses, lessons, topics, quizzes, assignments (5 actions)
* **LifterLMS** — Courses, lessons, quizzes, achievements, certificates (5 actions)
* **MemberPress** — Memberships, renewals, first signup (3 actions)
* **GiveWP** — Donations, first donation, recurring gifts, campaign goals (4 actions)
* **The Events Calendar** — RSVPs, tickets, check-ins (3 actions)
* **WPMediaVerse Pro** — Photo uploads, albums, comments, likes, follows, battles, challenges, tournaments (17 actions)

**Total: 62 gamification actions** across 10 integration manifests.

= Roadmap =

The free plugin ships every gamification mechanic out of the box. Future add-ons will focus on enterprise-grade features:

* **Profile Cosmetics & Frames** — Visual upgrades members can purchase with points (in development)
* **Mission Mode** — Branching, narrative-driven gamification flows
* **Real-time WebSocket layer** — Live leaderboard updates and notifications without polling
* **GraphQL API** — Flexible queries for mobile/headless frontends
* **AI intelligence** — Churn prediction, adaptive challenges, anti-gaming detection
* **JS/RN SDKs** — `@wbcom/wb-gamification-js-sdk` and React Native equivalent
* **ActivityPub federation** — Gamification events into the fediverse

== Installation ==

1. Upload the `wb-gamification` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. You will be redirected to the **Setup Wizard**. Choose a starter template that matches your site type, or skip to configure manually.
4. Points start awarding automatically as members interact with your site.
5. Add gamification blocks to any page using the block editor, or use shortcodes in the classic editor.

= Minimum Requirements =

* WordPress 6.4 or higher
* PHP 8.1 or higher
* MySQL 5.7 / MariaDB 10.3 or higher

= Recommended =

* BuddyPress 14.0+ (for social triggers and profile integration)
* Action Scheduler (bundled with WooCommerce, or install standalone for async processing)

== Frequently Asked Questions ==

= Does this work without BuddyPress? =

Yes. WB Gamification works on any WordPress site. BuddyPress adds social triggers (activity updates, friendships, groups) and profile integration, but the core points/badges/levels system works with standard WordPress actions (registration, posts, comments).

= How do members see their gamification status? =

Three ways: (1) Automatically on BuddyPress profiles — level name, points, and progress bar appear in the profile header. (2) Via Gutenberg blocks or shortcodes placed on any page. (3) Real-time toast notifications (bottom-right popups) when they earn points or badges.

= Can I customize point values? =

Yes. Every action has a configurable point value in **Gamification > Settings > Points**. You can also enable/disable individual actions, set daily caps, and configure cooldown periods. The setup wizard pre-configures sensible defaults based on your site type.

= Will this slow down my site? =

No. The engine uses an async award pipeline (via Action Scheduler), object caching on all hot paths, and leaderboard snapshot caching. Designed and tested for 100K+ member communities.

= Can other plugins register gamification actions? =

Yes. Any plugin can drop a `wb-gamification.php` manifest file in its directory. The file returns a PHP array of triggers — WB Gamification auto-discovers it at boot time. No registration call needed. See the [developer guide](https://github.com/vapvarun/wb-gamification/blob/main/docs/website/developer-guide/manifest-files.md) for the manifest format.

= How do I check if everything is working? =

Run `wp wb-gamification doctor` from WP-CLI. It validates all 26 database tables, registered actions, badge conditions, REST API routes, cron jobs, integration detection, and market readiness. Use `--verbose` for full detail or `--fix` to auto-repair issues.

= Is this GDPR compliant? =

Yes. WB Gamification integrates with WordPress privacy tools. Members can request data export (points, badges, levels, streaks, kudos) and data erasure through the standard WordPress privacy request system. Members can also opt out of the leaderboard and hide their rank from their profile.

= What happens if I deactivate the plugin? =

All data is preserved in the database. Reactivating the plugin restores everything. If you delete the plugin via the Plugins screen, the `uninstall.php` file removes all 26 tables, options, cron jobs, and transients — a clean uninstall.

== Changelog ==

= 1.6.4 - July 2026 =

Stability and scale release. Contains a fix for a bug that could delete other plugins' queued background jobs, including WooCommerce orders and subscription renewals. Upgrading is strongly recommended for every site.

* New      - Weekly cap is now settable per action in Settings > Points. The limit was already enforced, but there was no field to set it.
* Improve  - Points, badges, levels, and the Hub follow the active theme's colours in both light and dark mode on BuddyX, BuddyX Pro, and Reign.
* Fix      - Leaderboards now serve from their snapshot table instead of aggregating the full points ledger on every view. The snapshot was disabled by every points award, and a timezone mismatch emptied it at the end of each rebuild on sites ahead of UTC, so leaderboard views fell back to a full-table query.
* Fix      - The all-time leaderboard reads the materialised member totals rather than summing the whole ledger.
* Fix      - Members no longer receive duplicate weekly emails. An overdue cron re-fire could start a second send while the first was still queued.
* Fix      - Notification queue is bounded per member and no longer replays a backlog of stale toasts on every page load.
* Fix      - Weekly digest, cohort assignment, and status-retention jobs now process members in batches instead of attempting the whole site in a single run.
* Fix      - Personal-data export is paginated, so an export for a member with a long history no longer exhausts memory.
* Fix      - Award Points now uses a searchable member picker instead of rendering every member on the site into a dropdown.
* Fix      - Added database indexes for badge, kudos, submission, redemption, and member-intelligence queries that previously scanned the full table.
* Fix      - Badge rarity is cached, so public badge pages no longer aggregate the badge table on every request.
* Fix      - Setup wizard's Coaching Platform and Nonprofit templates no longer seed actions that cannot fire; the wizard now refuses to save an action it does not recognise.
* Fix      - Licence assets no longer 404 on hosts where the plugin directory is a symlink or the document root does not prefix-match the plugin path.
* Security - The Action Scheduler cleanup no longer deletes other plugins' queued work. It previously removed every pending Action Scheduler job older than the retention window, with no ownership check, which could destroy WooCommerce orders and subscription renewals on any site. Cleanup is now fenced to this plugin's own jobs, and queued work is never aged out as routine housekeeping.
* Dev      - `wp wb-gamification scale benchmark` now covers the badge, notification, and streak read paths, and a green run against a seeded dataset is required before a release can be packaged.

= 1.6.3 - July 2026 =

* New      - Learnomy integration: members earn points when they pass a Learnomy quiz, and an Achievements link is added to the account page.
* New      - Eventonomy integration: members earn points for reserving a place at an event, submitting an event, completing a ticket order, attending an event, and following an organizer.
* Improve  - Award-skip toasts are silent to members by default for every skip reason (cooldown, daily cap, weekly cap); the award is skipped without a "you earned nothing" notice. Use the wb_gam_award_skip_toast_reasons filter to opt specific reasons back in.
* Fix      - Frontend blocks, interactive surfaces (Hub, leaderboard, toasts, celebrations), and the block editor now display in the active site language; several strings previously always rendered in English. Ships German (de_DE) translations.
* Fix      - Badge award-window start and end columns are writable and can be cleared back to empty.
* Fix      - Reward toast and celebration overlay are readable in dark mode.
* Dev      - Documented the manifest scan-timing constraint for third-party integrations: a wb-gamification.php manifest cannot gate its returned array on function_exists() for a plugin that defines its API inside its own plugins_loaded callback (FluentCRM, for example), or the file returns no triggers; added a late-registration example under examples/.

= 1.6.2 - July 2026 =

Migrate members from GamiPress, myCred, and BadgeOS, moderate kudos and streaks, and delegate management to trusted staff.

* New      - Import screen migrates existing points, achievements/badges, and ranks/levels from GamiPress, myCred, and BadgeOS, with source detection, a preview, and reconciliation against each plugin's own balances. The same import runs from WP-CLI.
* New      - Kudos moderation admin page to review and revoke kudos, backed by a REST endpoint.
* New      - Streaks moderation admin page and write API to adjust member streaks, backed by a REST endpoint.
* New      - Staff delegation: grant trusted members the new wb_gam_manage_members capability to manage gamification without a full administrator role.
* New      - Optional deactivation feedback survey.
* Improve  - The BuddyNext "Profile completed" award follows the Profile Strength checklist members actually see, so it fires exactly when their widget reads "All set" (pairs with BuddyNext 1.0.5; dormant on older BuddyNext).
* Improve  - Leaderboard trend arrows now reflect real rank movement by retaining each member's previous rank between refreshes.
* Improve  - Overlays respect the prefers-reduced-motion setting and move focus on open for better keyboard and screen-reader accessibility.
* Improve  - Network requests now time out cleanly instead of hanging, using an AbortSignal on every fetch.
* Fix      - Fresh installs no longer fatal when the bundled licensing SDK loader is missing from the package.
* Fix      - BuddyNext profile-update points award on any completion change; the old under-100 percent exclusion could skip legitimate edits.
* Fix      - Toast notifications enqueue their renderer at render time so they survive host themes that isolate plugin assets.
* Fix      - The gamification hub block now adopts the site owner's configured accent color.
* Fix      - The hidden Community Challenges admin page no longer emits a PHP 8.1+ deprecation notice or renders an empty browser tab title.
* Fix      - Members keep their redemption history after the site owner removes a reward from the catalog, instead of the entry silently disappearing.
* Fix      - The member gamification Hub now renders in dark mode on themes beyond BuddyX (Reign and other themes that signal dark mode without BuddyX color tokens), instead of showing white cards.
* Security - Hardened nonce and capability checks across the admin form handlers.
* Dev      - New wb_gam email filters for subject, recipient, and body, plus a template footer hook, for customizing transactional emails.
* Dev      - New KudosEngine::get_received() getter for per-member kudos feeds.
* Dev      - Import-mode ingestion supports occurred_at timestamps, event suppression, and idempotency.


= 1.6.1 - June 2026 =

Wbcom Family Kit integrations, share-ready badge pages, a public points-spend API, and reliability fixes. This release also includes the 1.6.0 work, which was not separately released.

* New      - Public points-spend API: wb_gam_spend_points() and wb_gam_can_afford() let other Wbcom plugins redeem points for external purchases (such as BuddyNext membership tiers) through one audited, atomic debit, firing a wb_gam_points_spent action on success.
* New      - Wbcom Family Kit: a new Integrations tab that guides you to related Wbcom products (WPMediaVerse, Jetonomy, BuddyNext, Learnomy, WP Career Board, WB Listora) with one-click install and activate for free members.
* New      - Badge share pages now generate a dynamic 1200x630 social share image per badge and earner, with Open Graph and Twitter card meta for rich link previews.
* New      - Block editor previews now render every block styled inside the editor canvas, and the Give Kudos block gained full editing controls and a styled preview.
* Improve  - Points settings are now grouped by the source plugin instead of by category, so each integration's points are easier to find.
* Improve  - The setup wizard is reoriented around the Wbcom family of products.
* Improve  - The badge share page is rebuilt as a polished card with copy-link and X, Facebook, and LinkedIn share actions, replacing the old browser prompt popup.
* Improve  - Family product logos in the Integrations tab render at a consistent size.
* Fix      - Async points evaluation no longer fails on busy requests. The per-request event queue is split into size-bounded Action Scheduler jobs so it never exceeds the 8000-character args limit, which previously dropped batches when many events fired at once (for example while seeding demo data).
* Fix      - Leaderboard and Top Members blocks no longer render as a silently blank block when their display is deferred to Jetonomy. Site editors now see a notice explaining the deferral and how to override it with the wb_gam_defer_leaderboard_to_jetonomy filter; visitors still see nothing.
* Fix      - Resolved a WordPress 6.7+ "textdomain loaded too early" notice from the cron schedule label.
* Dev      - Recurring jobs moved to Action Scheduler, dropping the custom WP-Cron interval.
* Dev      - Composer is no longer required at runtime; runtime dependencies ship bundled in libs/.
* Dev      - New wb_gam_og_accent_color filter to customize the badge share image accent color.


= 1.5.6 - June 2026 =

Bug-fix release: correct level progression, instant admin level edits, and a hardened release build.

* Fix      - The dashboard nudge and level progress now name the next level by the configured level order, so an out-of-order points threshold no longer points members at the wrong level.
* Fix      - Editing a level in the admin now takes effect immediately instead of waiting for the cache to expire.
* Fix      - Creating a badge through the REST API now returns the correct 201 Created status.
* Dev      - The release build fails fast when its bundled dependencies are incomplete, so a packaged zip can never ship without Action Scheduler.


= 1.5.5 - June 2026 =

Member-facing polish: an admin accent control, a restrained on-brand activity stream, and faster realtime feedback.

* New      - Settings > Appearance lets site owners set the accent color used across member-facing surfaces, so gamification matches the community brand instead of a fixed default.
* Improve  - Redesigned the BuddyPress activity cards (badge, level, kudos, challenge) to a single theme accent with a flat surface and a subtle edge, replacing the per-type rainbow and heavy top strip so they fit the theme.
* Improve  - Gamification activity headlines now read as a short generic verb ("earned a badge") so they no longer repeat the card beneath them.
* Improve  - Swept every admin screen for consistency: unified button styling, replaced blank navigation icons, tokenized colors for dark mode, responsive tables, and accessible tap targets.
* Fix      - Realtime points and badge toasts now arrive in about 15 seconds instead of up to a minute; the heartbeat interval was silently falling back to the WordPress default.
* Fix      - Badge artwork stays legible in dark mode; the medallion now sits on a light plate on profiles and in the activity stream.
* Fix      - Legacy activity items created before the card redesign convert to the modern card automatically on update, across all four event types, with no manual step.
* Fix      - Public profiles show "1 badge" (singular) correctly.
* Fix      - Accessible names added to the email-notification toggles and the submission reject-reason field.
* Fix      - Admin screens on WordPress 6.9+ no longer show repeated "ability category not registered" notices and the "headers already sent" warnings they caused after install.
* Dev      - Gamification abilities now register correctly with the WP Abilities API: category registration, executable callbacks proxied to the documented REST routes, permission gates per auth level, and input schemas.
* Dev      - Added a regression journey and unit test covering the activity card and generic-headline contract.


= 1.5.4 - June 2026 =

WPMediaVerse competition rewards now match the per-competition XP a site owner configures.

* New      - WPMediaVerse Pro competitions award the XP configured per competition (challenge placing and participation, tournament round win, and champion) instead of a flat default. The integration manifest carries each competition's id in event metadata so Pro resolves the configured amount through the wb_gam_points_for_action filter.
* New      - Members can set their own profile public or private. A toggle on the public profile page writes the per-user visibility choice through a new self-service REST endpoint; the read paths and GDPR export/erase already honored the setting, this adds the missing member-facing control.
* New      - Settings > Engagement gives site owners direct control over features that previously only had code defaults: the daily login bonus (enable plus tier ladder), streak grace days and milestone bonus, the weekly recap email (enable plus subject), the leaderboard nudge email, the four BuddyPress activity-stream event toggles, and the public-profile URL slug.
* New      - Settings > Points adds an event-log retention control (default 12 months) next to points-history retention, and corrects the help text that wrongly stated the event log is never pruned.
* Improve  - Declared WordPress 6.5 as the minimum (the hub block's script modules require it) and marked Tested up to 7.0 so the plugin surfaces correctly in WordPress.org search.
* Fix      - Earned badges display again. Badges awarded on 1.5.0 to 1.5.3 were stored with a broken expiry date that hid them from profiles, the Members page, and badge counts while the data stayed intact. Awards now store the expiry correctly, upgrading repairs every affected row automatically, and wp wb-gamification doctor --fix repairs it on demand.
* Fix      - Stop the early-textdomain notice triggered by translated labels in the WPMediaVerse integration manifest.
* Security - Hardened the realtime SSE stream against event-field injection by stripping CR/LF from the event name before each event-stream record is written.
* Dev      - Removed the dead points_callback from the challenge-winner and streak triggers; the engine never consumed it, so awards already fell through to default_points.
* Compat   - Pairs with WPMediaVerse Pro 1.6.0. Install both updates together for per-competition XP to take effect.


= 1.5.3 - June 2026 =

Site-owner control release. The admin controls communities expect for managing who earns and bulk operations.

* New      - Settings > Access: exclude roles or specific accounts from earning points, badges, levels, and streaks (administrators, staff, support agents, bots). Excluded members keep existing points but stop accruing and are hidden from leaderboards. Enforced on every award path; filter wb_gam_user_can_earn for code-level control.
* New      - Members admin page (Gamification > Members): searchable, paginated roster of every member with points, level, and badges, plus per-member award, exclude/include, and reset-points actions.
* New      - Bulk award: grant the same points to every member of a role, or to all members at once, from the Award Points page. Excluded accounts are skipped automatically.
* New      - Settings > Tools: export the plugin configuration to a JSON file and import it on another site. Runtime and schema state are excluded so a config move never corrupts the target site.
* New      - Settings > Tools: a Rebuild leaderboard button that recomputes the snapshot and clears its caches, for when the leaderboard looks stale after a manual award or import.
* New      - Settings > Modules: turn off engagement modules your community does not use (kudos, streaks, challenges, community challenges, cohort leagues, redemption store). A disabled module's blocks and shortcodes render nothing and its admin page is hidden; nothing is deleted, so re-enabling restores it. Points, badges, levels, and leaderboards are always on.
* New      - Settings > Points: optional point expiry. Off by default. When enabled, a daily job decays the balance of members who have not earned for a chosen number of days (applied once per inactive streak), to nudge re-engagement.
* New      - Settings > Tools: Reset all member progress. Permanently clears accumulated member data (points, badges, streaks, kudos, leaderboards, redemptions, submissions) while keeping all configuration and definitions. Requires explicit confirmation.
* Dev      - New admin REST endpoints under wb-gamification/v1 (members collection, member exclude/reset, points bulk, tools export/import/recompute/reset-progress), all admin-gated and namespaced so they never collide with WordPress core or BuddyPress routes. New hooks: wb_gam_user_can_earn, wb_gam_module_enabled, wb_gam_progress_reset, wb_gam_points_decayed.


= 1.5.2 - June 2026 =

Performance and notification-quality release. Built to stay fast on large, live communities.

* New      - BuddyPress profile "Achievements" tab with Overview, Badges, Points, and Streak sub-tabs. Renders the displayed member's points, level progress, streak, badges, and points history by reusing the existing blocks - viewable on your own profile and other members'.
* New      - WooCommerce My Account "Achievements" endpoint (/my-account/achievements/) for stores running WooCommerce without BuddyPress. Renders the member's full gamification dashboard by reusing the Hub block, with a link to the mapped Hub page. Loads only when WooCommerce is active.
* New      - Optional LearnDash profile "My Achievements" link to the gamification dashboard (the mapped Hub page). OFF by default; enable with add_filter( 'wb_gam_learndash_profile_link', '__return_true' ).
* Dev      - Member achievements surfaces share one renderer (WBGam\Engine\MemberSurface) with a wb_gam_member_surface_html filter, so the BuddyPress, WooCommerce, and LearnDash integrations reuse the same blocks and mapped-hub link with no duplicated display logic.
* New      - Admin setting for notification placement (Settings > Realtime): bottom-right default, plus bottom-left, top-right, and top-center, with corner-aware slide-in.
* New      - Filter wb_gam_sse_allowed to opt into SSE streaming on hosts provisioned for long-lived connections.
* New      - Reusable batch cache-prime APIs PointsEngine::prime_totals() and BadgeEngine::prime_earned_badges() for per-row listing surfaces.
* New      - On Jetonomy sites the leaderboard defers to Jetonomy's reputation ranking, since wb-gam already mirrors reputation 1:1 into points and the two rankings are identical. The wb-gam leaderboard and top-members blocks, shortcodes, and Hub card are hidden so members see one leaderboard. Badges are unaffected (both badge sets are kept). Filter wb_gam_defer_leaderboard_to_jetonomy to override.
* Compat   - Jetonomy badges and reputation continue to award wb-gam points (Jetonomy badge earned, reputation change, space join, polls, messages); wb-gam adds the levels, streaks, challenges, and redemption layer on top.
* Improve  - Realtime now defaults to WP Heartbeat instead of SSE, removing a long-poll that pinned a PHP worker per logged-in page; SSE is opt-in.
* Improve  - Heartbeat polls every 15 seconds at rest (was 5), bursts to 5 seconds for 30 seconds after a member action, and nearly suspends on backgrounded tabs.
* Improve  - Member directory, leaderboard, and top-members no longer run per-row queries; query count is now constant regardless of community size.
* Improve  - Reward toasts always state what the points were for, using the action label or the admin-entered reason.
* Improve  - Frontend surfaces (Hub, blocks, member profile) map their neutral colors to the active theme's tokens, so they follow BuddyX and BuddyX Pro light and dark mode automatically; themes without those tokens keep the original light palette.
* Improve  - My Badges flyout shows two columns so each badge's art, title, and description are readable instead of cramped three-up.
* Fix      - Toast stack no longer overlaps the theme header or navigation.
* Fix      - Duplicate toasts when both SSE and Heartbeat delivered the same event.
* Fix      - Points toast showed a contextless "+N Points (M actions)" count instead of naming the action.
* Fix      - Member profile pages at /u/{username} returned 404 for everyone because public visibility required an opt-in that no screen ever set; public profiles are now on by default, and the owner and admins can always view a profile.
* Fix      - Removed em-dashes from all user-facing labels and descriptions (frontend blocks, member profile, admin settings) per house style; hyphens only. Existing seeded badge descriptions were migrated in the database too.


= 1.5.1 - June 2026 =

PHP compatibility hotfix. Restores parsing on PHP 8.0 and 8.1 and lowers the supported floor to PHP 8.0.

* Fix      - Fatal E_COMPILE_ERROR on PHP 8.1 and below. KudosEngine::send() declared a `true|WP_Error` return type; the standalone `true` literal type only exists in PHP 8.2+, so on PHP 8.0/8.1 it was parsed as a class name (WBGam\Engine\true) and crashed the site. Changed to `bool|WP_Error`.
* Fix      - Event value object used readonly properties (PHP 8.1+), breaking parsing on PHP 8.0. Properties are now plain public; immutability is enforced by convention (constructor-only writes, copy-on-change).
* Fix      - OpenApiCommand::error() used the `never` return type (PHP 8.1+); changed to `void` so the file parses on PHP 8.0.
* Compat   - Minimum supported PHP lowered from 8.1 to 8.0. CI now lints PHP 8.0 through 8.4.

= 1.5.0 - May 2026 =

Second bug-sweep release. Closes 21 reported issues across blocks, admin, notifications, and integrations. Adds a manual-award admin UI, a circuit-breaker for runaway Action Scheduler state, and two new local-CI gates that catch the bug classes we hit during the sweep before they ship again.

* New      - Manual-award form on the Badge edit screen lets admins grant any rule-driven or manually-awarded badge to a chosen member without writing SQL.
* New      - MemberUploadCap engine grants the upload_files capability to members only while the Submit Achievement editor is rendering and during the media-upload action, so the Add Media button works for non-admins without exposing the full Media Library. Opt-out filter wb_gam_grant_member_uploads.
* New      - Action Scheduler circuit-breaker plus drain CLI (wp wb-gamification as drain) for sites whose actionscheduler_actions table has grown past safe limits.
* New      - Local-CI gate 2.13 boot-invariants detects class_exists guards above top-level class declarations (the root cause of the silent boot failure that hid the admin menu on one install).
* New      - Local-CI gate 2.14 enforces the seed-default-badge contract so every default badge condition (action_count, point_milestone) matches the badge name's literal action.
* Improve  - Toast notifications now use the WordPress Heartbeat fast interval (5 s) on gamification surfaces so realtime feedback feels immediate.
* Improve  - Earning Guide card layout puts the action label below the icon and points row so long action names no longer wrap vertically inside a cramped middle column.
* Improve  - Cohort Rank block gets tier-coloured accents (Bronze / Silver / Gold / Diamond) driven by a data-tier attribute and per-tier CSS variables.
* Improve  - Community Challenges block ships a proper completed-state visual treatment (green pill plus gradient card) so completed challenges read distinctly from active ones.
* Improve  - User Status Bar block uses an SVG-mask chevron toggle and exposes a theme-aware top offset so the sticky panel sits below custom theme admin bars.
* Improve  - Activity Stream block alignment tightened to match the Reign-stack social-feed conventions.
* Fix      - Point-type conversion now credits the destination currency. The conversion path previously debited the source point type but never wrote the credit (a broken transaction nesting plus a duplicate-key write), so members lost points on every conversion. Conversions now run as one atomic debit-plus-credit sharing a single audit event.
* Fix      - Badge award conditions are saved atomically. A failed save no longer leaves a badge with no condition (which silently stopped it from auto-awarding); the editor now reports an error instead of a false success.
* Fix      - API key creation verifies the key was stored before returning it, so admins are never handed a key that was never persisted and can never authenticate.
* Fix      - Submission approval only marks a submission approved once its points award succeeds; on failure the submission stays pending instead of approving with zero points awarded.
* Fix      - Admin REST writes across challenges, community challenges, levels, rules, webhooks, rewards, and badges now return a 500 on a database failure instead of silently reporting success, and multi-row deletes roll back together.
* Fix      - Deactivating the plugin now clears every scheduled cron hook, leaving no orphaned events behind.
* Security - The member upload_files grant is scoped to the achievement-submission flow instead of being granted site-wide to every logged-in user, closing a privilege-escalation and storage-abuse vector on open-registration communities.
* Fix      - Setup Wizard now triggers on first activation in CLI and one-click sandbox flows; Installer::maybe_install on plugins_loaded@0 covers restore-from-backup and container clone scenarios that bypassed the activation hook.
* Fix      - WooCommerce purchase events fire on woocommerce_payment_complete instead of woocommerce_order_status_completed so members earn points the moment the gateway confirms payment, not whenever an admin manually marks the order complete. First-purchase detection counts processing and completed orders together.
* Fix      - Redemption email events are now whitelisted in EmailSettingsController so the per-event toggle actually sends the redemption confirmation email when enabled.
* Fix      - Redemption Store block reads stock=0 as Unlimited (not Out of stock) to match the documented admin contract.
* Fix      - Hub Challenges card now surfaces in-flight community challenges alongside personal challenges and uses a panel_blocks array so the hub can mount multiple blocks per panel.
* Fix      - Community challenge bonus award no longer dead-letters; CommunityChallengeEngine listens on its own wb_gam_community_bonus_award AS hook and routes through PointsEngine::award for every contributor.
* Fix      - Completed community challenges remain visible until their expiry instead of vanishing the instant the global goal is hit; CommunityChallengeEngine::get_visible() returns active plus completed-but-not-expired entries.
* Fix      - Cohort tier names edited from the Cohort Settings admin page now flow through to the Cohort Rank block via CohortEngine::get_tier_name() which reads wb_gam_cohort_settings before falling back to the TIERS constant.
* Fix      - Duplicate toast notifications eliminated via a Set-based dedupe in assets/js/toast.js keyed on toast id or content fingerprint, closing the cursor-race that produced repeated bubbles.
* Fix      - Default badge conditions corrected to match their names: First Post, Prolific Writer, and Content Creator track wp_publish_post action_count; First Comment and Engaged Reader track wp_leave_comment action_count. Replaces the 50-points placeholder that previously fired on the wrong trigger.
* Fix      - LeaderboardNudge no longer enters infinite Action Scheduler recursion on databases where points_changed broadcasts can re-enter the dispatcher. Closes the 3.5M-row runaway encountered on one production install.
* Fix      - Class-hoist guard at the top of wb-gamification.php removed; the guard ran against an already-hoisted top-level class declaration and silently aborted boot, hiding the Gamification admin menu on affected installs. Local-CI 2.13 now prevents the regression.
* Dev      - Admin CSS is fully tokenized: every color now resolves through the --wbgam-* design-token palette in tokens.css (zero hardcoded hex outside the token block), so a single palette edit re-themes the whole admin UI.
* Dev      - Manifest v2.2 refreshed end-to-end; audit/derived/ now caches 16 static-analysis sub-checks including the new boot-hoist-guards finder.
* Dev      - plan/ and audit/ folders consolidated; the single plan/MASTER-CHECKLIST.md replaces every dated release plan, bug-sweep spec, and UX-audit markdown that previously accumulated under plan/.

= 1.4.0 - May 2026 =

Bug-sweep release. 11 reported issues fixed plus two stability wins discovered during code-level triage of the queue.

* New      - Give-kudos block + shortcode (wb-gamification/give-kudos, [wb_gam_give_kudos]) for sending kudos from any frontend page.
* New      - Per-action cooldown + daily-cap admin override (Points settings table) with autosave to /actions/{id}/overrides REST.
* New      - ActionSchedulerCleaner daily cron prunes pending, failed, and complete action-scheduler rows older than 7 days (filter wb_gam_as_retention_days).
* Improve  - Settings dashboard container now uses 1600px max-width on wide monitors and consolidates a duplicate .wbgam-wrap rule.
* Improve  - Challenges and Community Challenges admin pages unified under a single Challenges menu entry with Individual / Community tabs.
* Improve  - Async award flag dropped on five low-volume BuddyPress + WPMediaVerse actions so points update synchronously without Action Scheduler delay.
* Improve  - Admin notices now render above the WB Gamification chrome instead of being visually trapped inside .wbgam-wrap.
* Improve  - Configure Points and Top Actions dashboard links route to the in-page Points tab via hash anchor instead of broken query-string routing.
* Fix      - LeaderboardNudge no longer enqueues duplicate Action Scheduler jobs for the same user; a runaway loop on long-running sites is contained by the new as_has_scheduled_action guard.
* Fix      - Challenge time queries use UTC_TIMESTAMP() instead of NOW() so UTC-stored start and end columns activate at the correct moment on servers with a non-UTC MySQL session timezone.
* Fix      - datetime-local admin inputs hydrate UTC values into the browsers local time on page load so the Challenges and Community Challenges edit forms no longer drift the saved time by the timezone offset on every edit.
* Fix      - Level-up and streak-milestone toasts now include a translated message string so the toast bubble is no longer empty.
* Fix      - ChallengeEngine duplicate do_action removed so BP activity rows, webhook deliveries, and emails fire once per challenge completion instead of twice.
* Fix      - KudosController create_item resolves recipient_login (username or email) server-side for the new give-kudos block; receiver_id remains supported.
* Fix      - BuddyPress activity filter labels now expose four distinct entries (Badge earned, Level up, Kudos sent, Challenge complete) instead of collapsing into one Gamification row; sites can override via the wb_gam_activity_context_label filter.
* Fix      - Gamification top-level admin menu icon now renders on every wp-admin page (Lucide font + the icon-paint CSS rule are enqueued globally so the icon does not disappear when viewing Posts, Pages, Tools, etc.).
* Improve  - Leaderboard rows now show points-with-icon and badges-earned count next to each member; member directory entries now display Level, Points, and Badge count instead of just the level name.
* New      - Jetonomy free integration manifest covers four previously-unrewarded events: joining a space, approval into a gated space, trust-level promotion (TL0 to TL5), and paid-membership activation (RCP / PMPro / MemberPress / WooCommerce Subscriptions / Sensei / LearnDash / MasterStudy / Tutor / LifterLMS).
* New      - Jetonomy Pro DM-received signal (recipient side) now earns gamification points with cooldown plus daily cap to prevent spam-DM gaming.
* Improve  - JetonomyIntegration class no longer registers three filter listeners (jetonomy_reputation_points_map, jetonomy_reputation_pre_change, jetonomy_leaderboard_items) that have no emit sites in upstream Jetonomy 1.4.4. Listeners were dead wiring; removal clears confusion about which contracts the integration actually honors. Sandbox veto via wb_gam_sandboxed user meta now runs on the working jetonomy_reputation_changed mirror path instead.
* Dev      - New wb_gam_as_retention_days filter (default 7) to tune Action Scheduler retention per site.
* Dev      - New wb_gam_activity_context_label filter exposes BP activity context labels for per-type customisation.

= 1.3.0 - May 2026 =

Jetonomy 1.4.3 and Jetonomy Pro event triggers, plus an in-tree WPMediaVerse Free + Pro manifest stack.

* New      - Jetonomy 1.4.3 reputation and leaderboard integration.
* New      - Jetonomy Pro event triggers for polls, direct messages, badges, and reactions.
* New      - WPMediaVerse Free and Pro integration manifests shipped in-tree so the host site no longer carries the manifest layer.
* New      - Engine now fires wb_gam_award_skipped from every silent-skip path so integrations can react to skipped awards.
* Fix      - Points history view renders the manifest label instead of the raw action_id.
* Fix      - WPMediaVerse handlers use upstream hook arguments instead of a broken static lookup.
* Fix      - Boot path shows an admin notice instead of a fatal error when vendor/ is missing.

= 1.2.0 - May 2026 =

Distribution pipeline and admin polish ahead of the integration release.

* New      - EDD SDK integration for automatic plugin updates from wbcomdesigns.com.
* Improve  - Consolidated admin stylesheets into a single admin.css.
* Improve  - Populated admin dashboard with KPI cards, top actions, top earners, and a daily sparkline.
* Dev      - submit-achievement view.js is translation-ready.

= 1.0.0 =
* First public release.
* Event-sourced points engine with 30+ auto-detected actions across 10 integrations.
* 30 pre-built badges with point milestone and action count auto-award conditions.
* 5-level progression system (Newcomer to Champion) with configurable thresholds.
* Leaderboard with snapshot caching, group scoping, and 4 time periods.
* Individual challenges with admin manager, bonus points, and date ranges.
* Daily streak tracking with grace period, 7 milestones, and bonus rewards.
* Peer kudos with daily limits, receiver/giver points, and feed display.
* 17 Gutenberg blocks and 15 shortcodes for frontend display.
* REST API with 65 endpoints across 24 controllers.
* API key authentication for cross-site gamification center mode.
* WP Abilities API registration (12 abilities) for AI agent discovery.
* 9 first-party integration manifests (BuddyPress, bbPress, WooCommerce, LearnDash, LifterLMS, MemberPress, GiveWP, The Events Calendar).
* Setup wizard with 5 starter templates (Blog, Community, Course, Coaching, Nonprofit).
* Modern admin UI with sidebar navigation, card layout, and field descriptions.
* Analytics dashboard with 6 KPI cards, top actions/earners, daily sparkline.
* Toast notifications via Interactivity API and REST polling.
* WP-CLI commands: points, member, actions, logs, export, doctor.
* GDPR-compliant data export and erasure.
* 60+ pages of documentation at docs/website/.

== Screenshots ==

1. **Settings Page** — Sidebar navigation with card layout. Points tab showing all registered actions grouped by category with configurable point values.
2. **Badge Library** — Grid display of all badges with earned counts and auto-award/manual indicators. Click to edit.
3. **Challenge Manager** — Create challenges with action, target, bonus points, and date range. All fields have helper descriptions.
4. **Analytics Dashboard** — 6 KPI cards (points, members, badges, challenges, streaks, kudos), top actions table, daily sparkline.
5. **Setup Wizard** — 5 starter templates with point previews. Choose your site type and go.
6. **Award Points** — Manual point award page with user selector, point amount, and reason field.
7. **API Keys** — Generate and manage API keys for remote site authentication.
8. **Member Hub (Frontend)** — The default Hub page combining points total, level progress, badge count, streak, and a leaderboard widget showing the member's rank.
9. **How-to-Earn Drawer** — Hub side drawer listing every earnable action with point values. Updates live as admins change the point config.
10. **Redemption Store** — Admin catalog UI to define rewards (custom or WooCommerce-backed) with point cost, stock, and active/inactive status.

== Upgrade Notice ==

= 1.5.1 =
PHP compatibility hotfix: fixes a fatal error on PHP 8.0/8.1 (broken `true` return type) and restores PHP 8.0 support. Strongly recommended for any site on PHP 8.1 or below. No schema changes.

= 1.5.0 =
Second bug-sweep release closing 21 reported issues. Adds a manual-award UI on the Badge edit screen, an Action Scheduler circuit-breaker, and two new local-CI gates. Safe upgrade with no schema changes.

= 1.4.0 =
Bug-sweep release with two stability wins: contains a LeaderboardNudge Action Scheduler runaway and adds a 7-day AS retention cron. Safe upgrade with no schema changes.

= 1.3.0 =
Adds Jetonomy 1.4.3 + Pro event coverage and ships the WPMediaVerse manifests in-tree. Safe upgrade with no schema changes.

= 1.2.0 =
Adds EDD SDK auto-updates and consolidates the admin stylesheets. Safe upgrade with no schema changes.

= 1.0.0 =
First public release. Install and activate to start gamifying your WordPress site immediately.
