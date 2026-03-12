# WB Gamification — Product Plan

> Part of the **Reign Stack** — Wbcom's complete self-owned community platform built on WordPress + BuddyPress.

---

## Vision

**Meaningful gamification, not pointsification.**

Install → activate → gamification works immediately. Zero config required. Beautiful defaults. Advanced settings exist but are never forced.

Apple-like simplicity for site owners. Genuinely engaging — not manipulative — for community members.

> **The test:** Would a member still do the behavior if the gamification element were removed? If yes, we're amplifying intrinsic motivation. If no, we've built a dependency on extrinsic reward that will stop working within weeks.

---

## Why We Build This

The Reign Stack replaces SaaS community platforms (Bettermode, Circle, Mighty Networks, Tribe) entirely. Unlike SaaS platforms where clients are tenants, the Reign Stack gives every client **their own site, their own data, full ownership forever**.

GamiPress and myCred are the existing WordPress solutions — both have critical architectural failures, fragmented add-on models, and poor BuddyPress integration. WB Gamification is built specifically for the Reign Stack from the ground up.

---

## The Reign Stack Context

| Layer | Plugin | Status |
|---|---|---|
| Core platform | BuddyPress | ✅ |
| Theme & UI | Reign Theme | ✅ Wbcom |
| Profiles | BP Profile Pro | ✅ Wbcom |
| Groups | BuddyPress Groups Pro | ✅ Wbcom |
| Articles | BP Member Blog | ✅ Wbcom |
| Discussions | bbPress + BP Forums | ✅ |
| Polls | BuddyPress Polls | ✅ Wbcom |
| Media | BuddyPress Media | ✅ Wbcom |
| Reactions | BuddyPress Reactions | ✅ Wbcom |
| Status | BuddyPress Status | ✅ Wbcom |
| Moderation | BP Moderation Pro | ✅ Wbcom |
| Mobile App | BuddyPress Mobile App | ✅ Wbcom |
| Member Mgmt | BP Member Management | ✅ Wbcom |
| **Gamification** | **WB Gamification** | 🔨 This plugin |
| Events | Reign Events | 🔨 Planned |
| Analytics | Reign Stats | 🔨 Planned |
| AI Moderation | Moderation Pro addon | 🔨 Planned |
| Onboarding | Reign Onboarding | 🔨 Planned |
| PWA | Reign PWA | 🔨 Planned |
| Membership | Reign Membership | 🔨 Planned |
| Courses | Reign Courses | 🔨 Planned |

---

## Core Philosophy

- **Ownership over features** — clients own their data, no vendor lock-in
- **Meaningful over transactional** — reward contribution quality, not activity volume
- **Works out of the box** — zero config required, starter templates for common community types
- **Bundled, not fragmented** — core features included, no add-on paywall for basics
- **Extensible by design** — any plugin registers triggers via `wb_gamification_register_action()`
- **Modern WordPress stack** — Blocks, Interactivity API, REST API, Abilities API
- **Security-first** — prepared statements, strict nonce checks, no SQL injection surface
- **Works with or without BuddyPress** — full gamification on standalone WordPress blogs; BuddyPress adds community-layer features on top

---

## Deployment Modes

WB Gamification auto-detects what's active and loads the right hooks. **Zero configuration by site owner.**

| Mode | Detection | Active Integrations |
|---|---|---|
| **Standalone** | No BuddyPress | WordPress-native triggers (posts, comments, users) |
| **Community** | BuddyPress active | All standalone triggers + BuddyPress triggers |
| **Full Reign** | BuddyPress + Reign Stack plugins | Community triggers + media, polls, reactions, forums, member blog |

### Mode Behaviour

```
plugins_loaded priority 5  → Registry::init() — fires wb_gamification_register
plugins_loaded priority 8  → WB_Gam_WordPress_Hooks::init() — always registers core WP triggers
plugins_loaded priority 10 → WB_Gam_BuddyPress_Hooks::init() — only registers if BuddyPress is active
```

**Avoiding double-award:** `WB_Gam_WordPress_Hooks` splits into two groups:
- `register_always()` — triggers BuddyPress has no equivalent for (user registration, first login, profile completion, post receives a comment)
- `register_standalone()` — triggers only registered when BuddyPress is **not** active (publish post, leave comment) because BuddyPress hooks cover these when BP is present

---

## Competitive Research Summary

Conducted March 2026 across: Circle, Mighty Networks, Bettermode, Discord, GamiPress, myCred, Skool, Strava, Duolingo, Salesforce Trailhead, Stack Overflow, and community psychology research.

### What SaaS Platforms Get Right (That We Must Match)

- **Skool**: Visible level/rank next to name in every post — ambient social proof at all times. Leaderboard always visible. Level-locked content unlocks.
- **Circle**: Workflow automation tied to rank milestones. Opt-in/opt-out design. Points from likes (quality) not post volume.
- **Mighty Networks**: Streaks + habit trackers for accountability communities. Group challenges. Custom rank titles.
- **Strava**: Peer kudos (14 billion given in 2025). Club/team leaderboards. Personal records auto-notified.
- **Salesforce Trailhead**: Functional privilege gating (status = real access). Superbadges for hands-on skills. LinkedIn-shareable credentials.
- **Stack Overflow**: Points unlock moderation privileges. Upvote-weighted — quality beats volume.
- **Duolingo**: Weekly cohort leagues with promotion/demotion. Streak freeze (grace days). Personalized notification timing.

### What Existing WP Plugins Get Wrong (That We Must Fix)

**GamiPress failures:**
- Event log tables hit 1M+ rows → 142-second queries → site outages. No auto-pruning. Missing indexes.
- SQL injection CVE in AJAX log endpoint (CVE-2024-13496 — CVSS 7.5).
- No setup wizard. Blank admin on activation.
- BuddyPress integration is a paid add-on. Display bugs (shortcodes show logged-in user, not viewed profile).
- Every real feature is a paid add-on. Users need 8–12 add-ons for full stack.
- No streaks or challenge mechanics without paid add-ons.
- No auto-display in BP activity stream — requires manual shortcode placement.

**myCred failures:**
- Runs hooks at `init` priority 5, before BuddyPress components initialize → points don't award when BP is active.
- Rank logos don't display in BuddyPress/bbPress profile headers.
- Multiple XSS vulnerabilities in 2024.
- No achievement unlock chains or multi-condition requirements.

### What Community Psychology Research Says

**What actually retains members:**
- Recognition of genuine contribution, not activity volume
- Social belonging over individual competition
- Meaningful milestones (first accepted answer, 2-year member) > arbitrary thresholds (logged in 5 days)
- Variable rewards with social dimension (someone acted on your advice — Nir Eyal's "tribe rewards")
- Privilege unlocking — status = functional power (Stack Overflow model)
- First-contribution celebration — research-backed reduction in new member drop-off
- Personalized private progress summaries — 43–59% email open rates vs. public leaderboard pressure
- Team/collaborative challenges — group challenges increase individual completion 45%

**What drives people away (never build these):**
- Points for every post/login → incentivizes spam, devalues system
- Fixed point values → Mighty Networks' #1 complaint
- Mandatory leaderboard participation → exclusion stress for non-top-10
- Points decay (expiring points) → widely resented, destroys long-term loyalty
- Streaks that reset at midnight regardless of timezone
- Push notification for every point earned → users disable notifications within days
- Generic badges ("Logged In," "Joined") → seen as transparent manipulation
- Public competitive leaderboards in sensitive contexts (weight loss, mental health coaching)

---

## Features

### 1. Points Engine

**Core behavior:**
- Custom point values per action (admin sets — never fixed)
- Toggle each action on/off inline
- Points for quality: reactions/kudos *received* earn more than raw posting volume
- Rate limiting per action: daily/weekly caps prevent farming
- Minimum thresholds: post word count, profile completeness, account age
- Cooldown periods per action
- Role-based earning caps
- Account must be X days old before leaderboard eligibility
- Async processing queue for high-volume events (>500 members)

**Log management (solves GamiPress's core failure):**
- Configurable retention period (default: 6 months)
- Auto-pruning runs on WP-Cron — never unbounded
- Proper composite indexes from day one
- Archiving option before pruning

**WordPress-native triggers — always active (Standalone + Community + Full):**

| Action ID | Hook | Default Points | Notes |
|---|---|---|---|
| `wp_user_register` | `user_register` | 15 | Once — on account creation |
| `wp_first_login` | `wp_login` | 10 | Once — very first login |
| `wp_profile_complete` | `personal_options_update` | 10 | Once — requires bio to be meaningful |
| `wp_post_receives_comment` | `comment_post` (post author) | 3 | Only approved comments count |

**WordPress-native triggers — Standalone mode only (no BuddyPress):**

| Action ID | Hook | Default Points | Notes |
|---|---|---|---|
| `wp_publish_post` | `publish_post` | 25 | Standard `post` type only |
| `wp_first_post` | `publish_post` | 20 | Once — first-contribution bonus |
| `wp_leave_comment` | `comment_post` (commenter) | 5 | Logged-in users only; approved only |
| `wp_comment_approved` | `transition_comment_status` | 5 | Moderated comment gets approved |

**BuddyPress triggers — Community + Full modes (BuddyPress required):**

| Action | Hook | Default Points | Quality Weight |
|---|---|---|---|
| Post activity update | `bp_activity_posted_update` | 10 | +5 per reaction received |
| Comment on activity | `bp_activity_comment_posted` | 5 | +3 per reaction received |
| Accept friendship | `friends_friendship_accepted` | 8 | — |
| Join a group | `groups_join_group` | 8 | — |
| Create a group | `groups_group_create_complete` | 20 | — |
| Complete BP profile | `xprofile_updated_profile` | 15 | Once only |
| Upload media | `bp_media_add` | 5 | — |
| Post in forum | `bbp_new_reply` | 8 | +5 if marked as answer |
| Receive reaction | `bp_reactions_add` | 3 | — |
| Create poll | `bp_polls_created` | 10 | — |
| Publish member blog post | `publish_post` (filtered) | 25 | — |
| Give peer kudos | internal | 2 | — |
| Receive peer kudos | internal | 5 | — |
| First ever post | internal (once) | 20 | First-contribution bonus |

### 2. Peer Kudos

Missing from all SaaS platforms except Strava. Highest-request social mechanic.

- Any member can award kudos to another member
- Configurable daily limit per giver (default: 5/day)
- Configurable point value per kudos (default: 5 pts for receiver, 2 pts for giver)
- Kudos appear in BP activity stream: "Sarah gave kudos to Mike"
- Kudos count visible on member profile
- Cannot give kudos to yourself
- Rate-limited: prevents farming via friend accounts

### 3. Badges & Achievements

- Default badge library (30 well-designed badges, ships with plugin)
- Custom badge creator — upload image + set trigger conditions
- Badge triggers: point milestones, specific action counts, admin-awarded, peer-nominated
- Member selects 3 featured badges to showcase prominently on profile
- Locked badges visible but greyed out — forward motivation
- **Mission-aligned mode**: badge names configurable per community type (e.g., "Impact Award" instead of "Badge")
- Badges auto-appear in BP profile header — no shortcode required
- Level-up and badge-earned events auto-post to BP activity stream (toggleable)
- Credential badges (Phase 3): shareable outside community (LinkedIn format)

**Default badge library includes:**
First Post, First Comment, First Friend, Profile Complete, Group Creator, Forum Helper, Top Contributor (weekly), Consistent Member (30-day streak), Community Veteran (1 year), Early Adopter, Kudos Champion, Poll Creator, Content Creator (Member Blog), Event Host, Mentor (helped 10 members), and more.

### 4. Levels & Ranks

- Level system based on cumulative points
- **Rank title visible under member name in ALL contexts** — activity posts, forum replies, comments, group headers, member directory, profile
- Preset defaults (fully configurable, custom names supported):
  - Level 1 — Newcomer (0 pts)
  - Level 2 — Member (100 pts)
  - Level 3 — Contributor (500 pts)
  - Level 4 — Regular (1,500 pts)
  - Level 5 — Champion (5,000 pts)
- Animated progress bar to next level on profile
- **Level-gated access**: admin can restrict BP group access, specific content, or menu items to a minimum level
- **Privilege gating**: higher levels can unlock moderation capabilities (flag review, pin posts)
- Level-up moment: full-screen celebration overlay (confetti, animation) — like Apple Activity rings

### 5. Leaderboards

- Periods: **daily, weekly, monthly, all-time** — all four available
- Group leaderboard: top members within a specific BP group
- **Team/cohort leaderboard**: aggregate team scores ranked against other teams
- Gutenberg block with period switcher
- Real-time updates via Interactivity API
- **Member opt-out**: members can hide themselves from leaderboards
- Leaderboard position shown privately to member: "You're #14 — 200 points from #10"
- Monthly leaderboard resets create recurring competition cycles

### 6. Challenges / Quests

- Admin creates time-bound or open-ended challenges
- Challenge types: individual (post 5 times this week) and team (group achieves 100 posts together)
- Progress shown to member with animated bar
- Bonus points on completion
- **Seasonal/time-limited challenges** with separate leaderboards — drives activity spikes
- Custom challenge types registerable by extensions
- Challenge completion auto-posts to BP activity stream

### 7. Streaks

- **Activity streak** (not login streak) — tied to meaningful community actions, not just page visits
- Timezone-aware midnight — streak resets at the member's local midnight, not server midnight
- **Grace period**: configurable 1–3 day buffer before streak resets (prevents anxiety from life disruption)
- Fire animation on milestone days (7, 14, 30, 60, 100)
- Bonus points for streak milestones
- Streak counter on profile
- **Accountability partners**: admin can pair two members who see each other's streaks — coaching and fitness communities

### 8. Starter Templates (Setup Wizard)

On first activation, admin chooses a template. Pre-configures everything with sensible defaults. No blank admin.

| Template | Mode | Point Weighting | Default Badges | Leaderboard Style |
|---|---|---|---|---|
| **Blog / Publisher** | Standalone WP | Writing heavy — posts, comments received | Writer milestone badges | Top Authors monthly |
| **Community Engagement** | Community/Full | Balanced — posting + reactions + kudos | Social badges | Weekly + all-time |
| **Online Course** | Any | Course completion heavy | Progress + credential badges | Cohort-based |
| **Coaching Platform** | Any | Check-ins + goal completions | Coach-awarded + milestone | Private (opt-in only) |
| **Nonprofit** | Any | Volunteer hours + donations | Impact awards (mission language) | Team/chapter only |
| **Custom** | Any | Manual configuration | Manual setup | Manual setup |

### 9. Admin Analytics Dashboard

Admins need behavioral data, not vanity metrics.

- **Retention cohort**: do members who earned their first badge retain at higher rates 90 days out?
- **Contribution quality**: are gamified users posting content that gets responses?
- **Action effectiveness**: which triggers drive the most downstream engagement?
- **Leaderboard health**: are lurkers leaving after seeing leaderboards? (churn signal)
- **Top contributors** — visible without publicly embarrassing everyone else
- Monthly ROI questions: did more members stay active? Did active members contribute more?

### 10. Member Experience

- Toast notification on point earn — Interactivity API, no page reload
- Toast is **smart**: batches rapid events (don't show 10 toasts in 2 seconds)
- Level-up celebration overlay — full-screen moment
- Badge unlock animation
- Progress bar animates on profile
- **Personalized weekly summary email**: "You're #3 this month — 120 points to #1" — private, not public shaming
- First-contribution celebration — special moment for a member's very first post

---

## Anti-Patterns — Never Build These

Research-backed decisions on what to explicitly exclude:

| Anti-Pattern | Reason |
|---|---|
| **Points decay** (expiring points) | Widely resented. Destroys long-term loyalty. No evidence it improves engagement. |
| **Mandatory leaderboard visibility** | Public rank creates exclusion stress for non-top-10. Always opt-out available. |
| **Points for login/page visits** | Incentivizes meaningless behavior. Devalues whole system. |
| **Fixed point values** | Mighty Networks' #1 complaint. Admins must control weighting. |
| **Streaks that reset at UTC midnight** | Confuses and angers users in non-UTC timezones. |
| **Notification for every point** | Users disable push notifications within days. Smart batching only. |
| **Generic badges** (Logged In, Downloaded File) | Members recognize manipulation. Badge must reflect real achievement. |
| **Unbounded log table** | GamiPress's core failure. Auto-prune from day one. |
| **Add-on paywall for basics** | GamiPress's business model complaint. BP integration, leaderboards, notifications all bundled. |
| **Public competitive leaderboards in sensitive contexts** | Coaching (weight loss, mental health) — compare against personal baseline, not peers. |

---

## Community-Type Specific Features

### Fitness Communities
- Activity streaks tied to workout actions, not logins
- Personal record (PR) notifications — "Your fastest activity this month"
- Team/club aggregate leaderboards (Strava clubs model)
- Peer kudos as primary engagement mechanic

### Course / E-Learning
- Module/course progress bars (always visible)
- Weekly cohort leagues with promotion/demotion (Duolingo model)
- Certificate badge on course completion (credential-quality, shareable)
- Streak freeze — grace days bank for consistent learners
- Prerequisite unlock trees (completing module A unlocks module B)

### Nonprofits
- Mission-aligned language mode (points → impact, badges → awards, leaderboard → recognition wall)
- Giving streaks (consecutive month recurring donor recognition)
- Team/chapter leaderboards — individual comparison avoided
- Collective milestone unlocks (group achieves goal, everyone benefits)
- Volunteer hours as point source

### Creator Communities
- Milestone-gated content unlocks — creator's own content is the reward
- "Day 1 OG" / founding member badge for early joiners
- Creator manual badge award — human recognition > algorithmic
- Referral tracking badge — "Brought 3 new members"
- Event attendance streaks — attended 5 consecutive live calls

### Coaching Platforms
- Private leaderboard mode — members only see their own ranking (opt-in to see others)
- Progress vs personal baseline, not peer comparison
- Coach manual award with personal message
- Accountability partner pairing
- Client milestone alert to coach — system pings coach when client hits milestone

### Enterprise / Professional
- Skills-based badge framing (verifies competency, not participation)
- Functional privilege gating — higher rep = moderation access, early beta access
- Team/department leaderboards — manager sees team completion rates
- Anti-gaming built-in: upvote-weighted points, minimum thresholds, peer validation

---

## Extension API

Any plugin can register its own gamification triggers. Shows automatically in admin. No core changes needed.

### Register an Action
```php
wb_gamification_register_action( [
    'id'             => 'wc_purchase_complete',
    'label'          => 'Complete a purchase',
    'description'    => 'Earn points when an order is completed',
    'hook'           => 'woocommerce_order_status_completed',
    'user_callback'  => fn( $order_id ) => get_post_meta( $order_id, '_customer_user', true ),
    'default_points' => 50,
    'category'       => 'commerce',
    'icon'           => 'cart',
    'repeatable'     => true,
    'cooldown'       => 0,
] );
```

### Register a Badge Trigger
```php
wb_gamification_register_badge_trigger( [
    'id'        => 'first_purchase',
    'label'     => 'First Purchase',
    'hook'      => 'woocommerce_order_status_completed',
    'condition' => fn( $order_id, $user_id ) =>
        wb_gam_get_user_action_count( $user_id, 'wc_purchase_complete' ) === 1,
] );
```

### Register a Challenge Type
```php
wb_gamification_register_challenge_type( [
    'id'        => 'purchase_challenge',
    'label'     => 'Purchase Challenge',
    'action_id' => 'wc_purchase_complete',
    'countable' => true,
] );
```

### Developer Filters
```php
// Modify points before awarding
add_filter( 'wb_gamification_points_for_action', fn( $points, $action_id, $user_id ) => $points, 10, 3 );

// Block point award under custom condition
add_filter( 'wb_gamification_should_award', fn( $should, $action_id, $user_id ) => $should, 10, 3 );

// After points awarded
add_action( 'wb_gamification_points_awarded', fn( $user_id, $action_id, $points ) => null, 10, 3 );

// Leaderboard query args
add_filter( 'wb_gamification_leaderboard_args', fn( $args ) => $args );
```

### Planned Official Extensions
| Extension | Adds |
|---|---|
| WB Gamification — Events | RSVP, attend, host event triggers |
| WB Gamification — Courses | Lesson/course/quiz completion, certificate badge |
| WB Gamification — Membership | Plan upgrade, renewal, referral triggers |

---

## REST API

**Namespace:** `wb-gamification/v1`

| Method | Endpoint | Description |
|---|---|---|
| GET | `/members/{id}` | Full gamification profile |
| GET | `/members/{id}/points` | Points + history |
| GET | `/members/{id}/badges` | Earned badges |
| GET | `/members/{id}/level` | Current level + progress |
| GET | `/members/{id}/streak` | Current streak data |
| GET | `/leaderboard` | `?period=day\|week\|month\|all&limit=10&group_id=X` |
| GET | `/leaderboard/team` | Team/group aggregate leaderboard |
| GET | `/badges` | All available badges |
| GET | `/actions` | All registered actions (headless/app discovery) |
| GET | `/challenges` | Active challenges + member progress |
| POST | `/points/award` | Admin: manually award points with message |
| POST | `/kudos` | Member gives kudos to another member |
| DELETE | `/points/{id}` | Admin: revoke points |

---

## WordPress Abilities API

```php
wp_register_ability( 'wb_gam_earn_points' );        // Can earn points
wp_register_ability( 'wb_gam_view_leaderboard' );   // Can see leaderboard
wp_register_ability( 'wb_gam_appear_leaderboard' ); // Can appear in leaderboard (opt-out)
wp_register_ability( 'wb_gam_give_kudos' );         // Can give peer kudos
wp_register_ability( 'wb_gam_redeem_rewards' );     // Can redeem rewards
wp_register_ability( 'wb_gam_manage_settings' );    // Admin access
wp_register_ability( 'wb_gam_award_manual' );       // Can manually award points
wp_register_ability( 'wb_gam_moderate' );           // Unlocked by level threshold
```

---

## Gutenberg Blocks

| Block | Purpose |
|---|---|
| `wb-gamification/leaderboard` | Full leaderboard — period switcher, group filter, opt-out aware |
| `wb-gamification/member-points` | Current user points + level + progress bar |
| `wb-gamification/badge-showcase` | Grid of earned/locked badges |
| `wb-gamification/level-progress` | Animated progress bar to next level |
| `wb-gamification/challenges` | Active challenges with progress bars |
| `wb-gamification/top-members` | Mini leaderboard widget |
| `wb-gamification/kudos-feed` | Recent peer kudos activity |
| `wb-gamification/streak` | Current streak with milestone markers |

---

## Database Schema

```sql
-- Points ledger (custom table — never post meta)
CREATE TABLE wb_gam_points (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    action_id  VARCHAR(100)    NOT NULL,
    points     INT             NOT NULL,
    object_id  BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_created (user_id, created_at),  -- composite for user history
    KEY idx_action (action_id),
    KEY idx_created (created_at)                 -- for pruning queries
);

-- Earned badges
CREATE TABLE wb_gam_user_badges (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   BIGINT UNSIGNED NOT NULL,
    badge_id  VARCHAR(100)    NOT NULL,
    earned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_badge (user_id, badge_id)
);

-- Badge definitions
CREATE TABLE wb_gam_badge_defs (
    id            VARCHAR(100) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    description   TEXT,
    image_url     VARCHAR(500),
    trigger_type  VARCHAR(50)  NOT NULL,
    trigger_value VARCHAR(255),
    is_credential TINYINT(1)   DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

-- Level definitions
CREATE TABLE wb_gam_levels (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name       VARCHAR(255)    NOT NULL,
    min_points BIGINT UNSIGNED NOT NULL,
    icon_url   VARCHAR(500),
    sort_order INT             DEFAULT 0,
    PRIMARY KEY (id),
    KEY min_points (min_points)
);

-- Streaks (timezone-aware)
CREATE TABLE wb_gam_streaks (
    user_id        BIGINT UNSIGNED NOT NULL,
    current_streak INT UNSIGNED    DEFAULT 0,
    longest_streak INT UNSIGNED    DEFAULT 0,
    last_active    DATE,
    timezone       VARCHAR(50)     DEFAULT 'UTC',
    grace_used     TINYINT(1)      DEFAULT 0,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
);

-- Challenges
CREATE TABLE wb_gam_challenges (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title        VARCHAR(255)    NOT NULL,
    type         VARCHAR(20)     DEFAULT 'individual',  -- individual | team
    action_id    VARCHAR(100)    NOT NULL,
    target       INT UNSIGNED    NOT NULL,
    bonus_points INT             NOT NULL DEFAULT 0,
    period       VARCHAR(20)     DEFAULT 'none',
    starts_at    DATETIME,
    ends_at      DATETIME,
    status       VARCHAR(20)     DEFAULT 'active',
    PRIMARY KEY (id),
    KEY status (status)
);

-- Challenge progress
CREATE TABLE wb_gam_challenge_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    progress     INT UNSIGNED    DEFAULT 0,
    completed_at DATETIME        DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY user_challenge (user_id, challenge_id),
    KEY challenge_id (challenge_id)
);

-- Peer kudos
CREATE TABLE wb_gam_kudos (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    giver_id    BIGINT UNSIGNED NOT NULL,
    receiver_id BIGINT UNSIGNED NOT NULL,
    message     VARCHAR(255)    DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY giver_date (giver_id, created_at),
    KEY receiver_id (receiver_id)
);

-- Accountability partners
CREATE TABLE wb_gam_partners (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id_1  BIGINT UNSIGNED NOT NULL,
    user_id_2  BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY partner_pair (user_id_1, user_id_2)
);

-- Member preferences
CREATE TABLE wb_gam_member_prefs (
    user_id           BIGINT UNSIGNED NOT NULL,
    leaderboard_opt_out TINYINT(1)    DEFAULT 0,
    show_rank         TINYINT(1)      DEFAULT 1,
    notification_mode VARCHAR(20)     DEFAULT 'smart', -- smart | all | none
    PRIMARY KEY (user_id)
);
```

---

## Plugin File Structure

```
wb-gamification/
├── wb-gamification.php
├── PLAN.md
├── README.md
├── composer.json
├── package.json
├── src/
│   ├── Engine/
│   │   ├── Installer.php          # DB tables + seeding
│   │   ├── Registry.php           # Central extension registry
│   │   ├── PointsEngine.php       # Award, revoke, rate-limit, quality-weight
│   │   ├── BadgeEngine.php        # Badge evaluation + award
│   │   ├── LevelEngine.php        # Level calc + privilege gating
│   │   ├── StreakEngine.php        # Timezone-aware streak tracking
│   │   ├── ChallengeEngine.php    # Individual + team challenge progress
│   │   ├── KudosEngine.php        # Peer-to-peer kudos
│   │   └── LogPruner.php          # Auto-pruning via WP-Cron
│   ├── API/
│   │   ├── PointsController.php
│   │   ├── BadgesController.php
│   │   ├── LeaderboardController.php
│   │   ├── ActionsController.php
│   │   ├── ChallengesController.php
│   │   └── KudosController.php
│   ├── Abilities/
│   │   └── AbilitiesRegistrar.php
│   ├── Integrations/
│   │   └── WordPress/
│   │       └── HooksIntegration.php # WP-native triggers: always + standalone groups
│   ├── BuddyPress/
│   │   ├── HooksIntegration.php   # All BP action hooks (on bp_loaded, not init)
│   │   ├── ProfileIntegration.php # Auto-inject rank/badges into BP profile header
│   │   ├── ActivityIntegration.php # Badge/level-up events → activity stream
│   │   └── DirectoryIntegration.php # Rank visible in member directory
│   ├── Admin/
│   │   ├── SetupWizard.php        # First-activation template chooser
│   │   ├── SettingsPage.php       # Points, Levels, Badges, Leaderboard tabs
│   │   └── AnalyticsDashboard.php # Retention cohort, action effectiveness
│   └── Extensions/
│       └── functions.php          # Public API functions
├── blocks/
│   ├── leaderboard/
│   ├── member-points/
│   ├── badge-showcase/
│   ├── level-progress/
│   ├── challenges/
│   ├── top-members/
│   ├── kudos-feed/
│   └── streak/
├── assets/
│   ├── css/frontend.css
│   ├── js/
│   └── interactivity/index.js
└── languages/
```

---

## Build Phases

### Phase 1 — Core (MVP)
- [ ] Database installer (all tables + indexes)
- [ ] Log auto-pruner (WP-Cron, configurable retention)
- [ ] Registry (extension API)
- [ ] PointsEngine (rate limiting, quality weighting, async queue)
- [ ] WordPress-native hooks (`WB_Gam_WordPress_Hooks`) — standalone + always groups
- [ ] BuddyPress hooks — on `bp_loaded`, not `init` (fix myCred's bug by design)
- [ ] BuddyPress profile auto-injection (rank in header — no shortcode)
- [ ] LevelEngine + level-gated access
- [ ] Setup wizard with 5 starter templates (including "Blog / Publisher" for standalone WP)
- [ ] Admin settings page (Points + Levels — inline editable, shows active mode)
- [ ] REST API read endpoints
- [ ] Abilities registration
- [ ] Member opt-out preference (leaderboard + rank visibility)

### Phase 2 — Badges + Leaderboards + Kudos
- [ ] BadgeEngine
- [ ] Default badge library (30 badges)
- [ ] Custom badge creator
- [ ] KudosEngine (peer-to-peer)
- [ ] BP activity stream integration (badge/level-up/kudos events)
- [ ] BP member directory rank display
- [ ] Leaderboard (daily/weekly/monthly/all-time + group)
- [ ] Team/cohort leaderboard
- [ ] Gutenberg blocks (leaderboard, member-points, badge-showcase, kudos-feed)
- [ ] Interactivity API — smart-batched toast notifications, level-up overlay
- [ ] Weekly summary email

### Phase 3 — Engagement + Analytics
- [ ] StreakEngine (timezone-aware, grace period, accountability partners)
- [ ] ChallengeEngine (individual + team challenges)
- [ ] Level-up celebration overlay
- [ ] Remaining Gutenberg blocks (level-progress, challenges, streak)
- [ ] Admin analytics dashboard (retention cohort, action effectiveness, churn signals)
- [ ] Mission-aligned mode (nonprofit/professional language override)
- [ ] Community-type specific templates (coaching, nonprofit, fitness)

### Phase 4 — Extensions + Credentials + Rewards
- [ ] Credential badges (LinkedIn-shareable format)
- [ ] Points redemption store
- [ ] Extension packs (Events, Courses, Membership)
- [ ] Weekly cohort leagues with promotion/demotion
- [ ] REST API write endpoints with full auth
- [ ] Full test coverage
- [ ] Performance audit (query analysis, caching layer)

---

## Key Architectural Decisions

1. **Custom DB tables, never post meta** — points ledger can hit millions of rows; meta degrades at scale
2. **Hook on `bp_loaded`, not `init`** — fixes myCred's fundamental BuddyPress init-order conflict
3. **Auto-pruning from day one** — GamiPress's 142-second query problem starts at install, not later
4. **Composite indexes on (user_id, created_at)** — most common query pattern; single-column indexes miss this
5. **Registry pattern** — all actions auto-discovered; admin never needs manual sync
6. **Bundled, not fragmented** — BP integration, leaderboards, kudos, notifications all in core
7. **Interactivity API, not custom React** — WordPress-native, no framework overhead
8. **Quality-weighted points** — reactions received > post volume; prevents spam, rewards contribution
9. **Member opt-out everywhere** — competitive mechanics always optional; never forced
10. **Prepared statements in all AJAX handlers** — CVE-2024-13496 (GamiPress) happened here; never repeat
11. **Setup wizard on first activation** — not a blank admin; reduces abandonment
12. **No points decay** — research confirms this destroys loyalty with no engagement benefit
13. **Mode auto-detection, never config** — WP-native hooks always load; BuddyPress hooks load only when BP is active; site owner never sets a "mode" toggle
14. **Split always/standalone trigger groups** — prevents double-awarding when BuddyPress covers the same underlying WordPress hooks (e.g. `publish_post`)
