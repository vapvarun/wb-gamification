=== WB Gamification ===
Contributors: vapvarun, wbcomdesigns
Tags: gamification, points, badges, leaderboard, buddypress
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete gamification engine for WordPress and BuddyPress. Points, badges, levels, leaderboards, challenges, streaks, and kudos — zero config, works out of the box.

== Description ==

WB Gamification is a universal gamification engine that works with any WordPress site. Install, activate, pick a template — gamification starts immediately. No configuration required.

The engine awards points automatically when members perform actions on your site. Points unlock badges, advance levels, fuel leaderboards, and power streaks. Everything is configurable from the admin, but sensible defaults mean it works from day one.

= Why WB Gamification? =

* **Zero config** — 5 starter templates pre-configure everything. Pick one and go.
* **Universal** — Works with plain WordPress, BuddyPress, WooCommerce, LearnDash, bbPress, and 5 more plugins. Auto-detects what you have installed.
* **Scalable** — Async award pipeline, snapshot-cached leaderboards, object caching everywhere. Built for 100K+ members.
* **API-first** — 38 REST endpoints, outbound webhooks, WP Abilities API. Mobile apps, headless frontends, and AI agents are first-class consumers.
* **No add-on model** — Every integration ships free. No paid extensions for BuddyPress support, WooCommerce support, etc.

= Core Features (Free) =

* **Points Engine** — Configurable points for 30+ actions across WordPress, BuddyPress, WooCommerce, LearnDash, bbPress, and more. Event-sourced architecture ensures every point is traceable.
* **Badge System** — 30 pre-built badges with auto-award conditions (point milestones, action counts). Create unlimited custom badges with the visual badge editor.
* **Level Progression** — 5 default levels (Newcomer to Champion). Fully customizable thresholds. Progress bars on member profiles.
* **Leaderboard** — All-time, monthly, weekly, and daily rankings. Scope by BuddyPress group. Snapshot caching for performance at scale.
* **Challenges** — Time-bound goals with bonus points. Admin creates challenges, members track progress automatically.
* **Streaks** — Daily activity tracking with grace periods, milestone detection (7, 30, 100, 365 days), and bonus rewards.
* **Peer Kudos** — Members recognize each other with kudos. Configurable daily limits and point awards for both sender and receiver.
* **11 Gutenberg Blocks** — Leaderboard, member points, badge showcase, level progress, challenges, streak, top members, kudos feed, year recap, points history, earning guide.
* **11 Shortcodes** — Every block is also available as a shortcode for the classic editor.
* **REST API** — 38 endpoints across 16 controllers. Full CRUD for all resources. API key authentication for cross-site setups.
* **BuddyPress Integration** — Profile rank display, activity feed events, member directory badges, notification bridge.
* **Toast Notifications** — Real-time bottom-right popups when members earn points, badges, or level up. 6 notification types with auto-dismiss.
* **Analytics Dashboard** — 6 KPI cards, top actions, top earners, daily points sparkline. Period selector (7/30/90 days).
* **WP-CLI Commands** — `points award`, `member status`, `actions list`, `logs prune`, `export user`, `doctor` readiness check.
* **Developer Hooks** — 31 action hooks and 8 filter hooks for extending the engine.
* **Privacy Compliant** — GDPR data export and erasure via WordPress privacy tools.

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

= Pro Add-on Features =

The Pro add-on unlocks advanced engagement mechanics:

* **Cohort Leagues** — Duolingo-style weekly competitions with promotion/demotion
* **Community Challenges** — Team goals with global progress (Pokemon GO model)
* **Redemption Store** — Members spend points on rewards (discounts, custom rewards)
* **Badge Sharing** — Public share pages with OG meta, LinkedIn deep-links, OpenBadges 3.0 verifiable credentials
* **Outbound Webhooks** — HMAC-signed webhooks for Zapier, Make, n8n
* **Weekly Recap Emails** — Automated weekly summary sent to members
* **Profile Cosmetics** — Frames and visual upgrades for member profiles
* **Tenure Badges** — Anniversary milestone badges (1yr, 2yr, 5yr, 10yr)
* **Site-First Badges** — First member to perform an action earns a unique badge

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

Run `wp wb-gamification doctor` from WP-CLI. It validates all 20 database tables, registered actions, badge conditions, REST API routes, cron jobs, integration detection, and market readiness. Use `--verbose` for full detail or `--fix` to auto-repair issues.

= Is this GDPR compliant? =

Yes. WB Gamification integrates with WordPress privacy tools. Members can request data export (points, badges, levels, streaks, kudos) and data erasure through the standard WordPress privacy request system. Members can also opt out of the leaderboard and hide their rank from their profile.

= What happens if I deactivate the plugin? =

All data is preserved in the database. Reactivating the plugin restores everything. If you delete the plugin via the Plugins screen, the `uninstall.php` file removes all 20 tables, options, cron jobs, and transients — a clean uninstall.

== Changelog ==

= 1.0.0 =
* Initial release.
* Event-sourced points engine with 30+ auto-detected actions across 10 integrations.
* 30 pre-built badges with point milestone and action count auto-award conditions.
* 5-level progression system (Newcomer to Champion) with configurable thresholds.
* Leaderboard with snapshot caching, group scoping, and 4 time periods.
* Individual challenges with admin manager, bonus points, and date ranges.
* Daily streak tracking with grace period, 7 milestones, and bonus rewards.
* Peer kudos with daily limits, receiver/giver points, and feed display.
* 11 Gutenberg blocks and 11 shortcodes for frontend display.
* REST API with 38 endpoints across 16 controllers.
* API key authentication for cross-site gamification center mode.
* WP Abilities API registration (12 abilities) for AI agent discovery.
* 9 first-party integration manifests (BuddyPress, bbPress, WooCommerce, LearnDash, LifterLMS, MemberPress, GiveWP, The Events Calendar).
* Setup wizard with 5 starter templates (Blog, Community, Course, Coaching, Nonprofit).
* Modern admin UI with sidebar navigation, card layout, and field descriptions.
* Analytics dashboard with 6 KPI cards, top actions/earners, daily sparkline.
* Toast notifications via Interactivity API and REST polling.
* WP-CLI commands: points, member, actions, logs, export, doctor.
* GDPR-compliant data export and erasure.
* 50-page documentation at docs/website/.

== Screenshots ==

1. **Settings Page** — Sidebar navigation with card layout. Points tab showing all registered actions grouped by category with configurable point values.
2. **Badge Library** — Grid display of all badges with earned counts and auto-award/manual indicators. Click to edit.
3. **Challenge Manager** — Create challenges with action, target, bonus points, and date range. All fields have helper descriptions.
4. **Analytics Dashboard** — 6 KPI cards (points, members, badges, challenges, streaks, kudos), top actions table, daily sparkline.
5. **Setup Wizard** — 5 starter templates with point previews. Choose your site type and go.
6. **Award Points** — Manual point award page with user selector, point amount, and reason field.
7. **API Keys** — Generate and manage API keys for remote site authentication.
8. **Leaderboard Block** — Frontend leaderboard with avatars, rank, and points. Configurable period and limit.
9. **Member Profile** — BuddyPress profile header showing level, points, and progress bar.
10. **Earning Guide Block** — Frontend display showing all actions with point values, grouped by category.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Install and activate to start gamifying your WordPress site immediately.
