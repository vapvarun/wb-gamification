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

WB Gamification is a universal gamification engine that works with any WordPress site. It provides a complete points-badges-levels-leaderboard system that awards members automatically for their actions.

**Core Features (Free):**

* **Points Engine** — Configurable points for 30+ actions across WordPress, BuddyPress, WooCommerce, LearnDash, bbPress, and more. Event-sourced architecture ensures every point is traceable.
* **Badge System** — 30 pre-built badges with auto-award conditions (point milestones, action counts). Create unlimited custom badges with the visual badge editor.
* **Level Progression** — 5 default levels (Newcomer to Champion). Fully customizable thresholds. Progress bars on member profiles.
* **Leaderboard** — All-time, monthly, weekly, and daily rankings. Scope by BuddyPress group. Snapshot caching for 100K+ member scalability.
* **Challenges** — Time-bound goals with bonus points. Admin creates challenges, members track progress automatically.
* **Streaks** — Daily activity tracking with grace periods, milestone detection, and bonus rewards.
* **Peer Kudos** — Members recognize each other with kudos. Configurable daily limits and point awards for both sender and receiver.
* **10 Gutenberg Blocks** — Leaderboard, member points, badge showcase, level progress, challenges, streak, top members, kudos feed, year recap, points history.
* **10 Shortcodes** — Every block is also available as a shortcode for classic editor.
* **REST API** — 38 endpoints across 16 controllers. Full CRUD for all resources. API key authentication for cross-site setups.
* **BuddyPress Integration** — Profile rank display, activity feed events, member directory badges, notification bridge.
* **WP-CLI Commands** — `points award`, `member status`, `actions list`, `logs prune`, `export user`, `doctor` readiness check.
* **Developer Hooks** — 31 action hooks and 8 filter hooks for extending the engine.
* **Privacy Compliant** — GDPR data export and erasure via WordPress privacy tools.

**Integrations (Auto-detected):**

* BuddyPress (activity, friends, groups, profiles, reactions, polls, member blog)
* bbPress (topics, replies, resolved topics)
* WooCommerce (orders, first purchase, product reviews, wishlists)
* LearnDash (courses, lessons, topics, quizzes, assignments)
* LifterLMS (courses, lessons, quizzes, achievements, certificates)
* MemberPress (memberships, renewals, first signup)
* GiveWP (donations, recurring gifts, campaign goals)
* The Events Calendar (RSVPs, tickets, check-ins)
* WPMediaVerse Pro (photo uploads, albums, comments, likes, follows, battles, challenges, tournaments)

**Pro Add-on Features:**

* Cohort Leagues (Duolingo-style weekly competitions)
* Community Challenges (team goals with global progress)
* Weekly Recap Emails
* Cosmetics/Profile Frames
* Tenure Badges (anniversary milestones)
* Site-First Badges (first user to do X)
* Leaderboard Nudge Emails
* Redemption Store (spend points on rewards)
* Badge Share Pages (LinkedIn, OpenBadges 3.0)
* Outbound Webhooks (Zapier, Make, n8n)

== Installation ==

1. Upload the `wb-gamification` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Choose a starter template from the setup wizard, or skip to configure manually.
4. Points start awarding automatically as members interact with your site.

== Frequently Asked Questions ==

= Does this work without BuddyPress? =

Yes. WB Gamification works on any WordPress site. BuddyPress adds social triggers (activity updates, friendships, groups) and profile integration, but the core points/badges/levels system works with standard WordPress actions (registration, posts, comments).

= How do members see their gamification status? =

Three ways: (1) Automatically on BuddyPress profiles (level, points, progress bar), (2) Via Gutenberg blocks or shortcodes placed on any page, (3) Real-time toast notifications when they earn points or badges.

= Can I customize point values? =

Yes. Every action has a configurable point value in Settings > Points. You can also enable/disable individual actions, set daily caps, and configure cooldown periods.

= Will this slow down my site? =

No. The engine uses an async award pipeline (via Action Scheduler), object caching on all hot paths, and leaderboard snapshot caching. Tested for 100K+ member scalability.

= Can other plugins register gamification actions? =

Yes. Any plugin can drop a `wb-gamification.php` manifest file in its directory, or call `wb_gamification_register_action()` from code. The manifest is auto-discovered — no configuration needed.

= How do I check if everything is working? =

Run `wp wb-gamification doctor` from WP-CLI. It validates all 20 database tables, registered actions, badge conditions, REST API routes, cron jobs, and market readiness.

== Changelog ==

= 1.0.0 =
* Initial release.
* Event-sourced points engine with 30+ auto-detected actions.
* 30 pre-built badges with point milestone and action count conditions.
* 5-level progression system (Newcomer to Champion).
* Leaderboard with snapshot caching and group scoping.
* Challenges, streaks, and peer kudos.
* 10 Gutenberg blocks and 10 shortcodes.
* REST API with 38 endpoints and API key authentication.
* WP Abilities API registration for AI agent discovery.
* 9 first-party integration manifests.
* Setup wizard with 5 starter templates.
* WP-CLI commands including `doctor` readiness checker.
* GDPR-compliant data export and erasure.

== Screenshots ==

1. Settings page with sidebar navigation and card layout.
2. Badge library with grid display and auto-award conditions.
3. Challenge manager with smart defaults.
4. Analytics dashboard with KPI cards and sparkline chart.
5. Setup wizard with starter templates.
