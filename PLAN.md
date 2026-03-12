# WB Gamification — Product Plan

> Part of the **Reign Stack** — Wbcom's complete self-owned community platform built on WordPress + BuddyPress.

---

## Vision

**Zero config to start. Beautiful by default. Deep only if you want.**

Install → activate → gamification works immediately. Smart defaults. Site owners never need a setup wizard. Advanced settings exist but are never required.

Apple-like simplicity for site owners. Delightful experience for community members.

---

## Why We Build This

The Reign Stack replaces SaaS community platforms (Bettermode, Circle, Mighty Networks, Tribe) entirely. Unlike SaaS platforms where clients are tenants, the Reign Stack gives every client **their own site, their own data, full ownership**.

Gamification is one of the final gaps between a vanilla BuddyPress setup and a polished platform like Bettermode. WB Gamification closes that gap — entirely Wbcom-owned, no GamiPress or myCred dependency.

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
- **Works out of the box** — zero config required
- **Extensible by design** — any plugin can register triggers via `wb_gamification_register_action()`
- **Modern WordPress stack** — Blocks, Interactivity API, REST API, Abilities API
- **No third-party engine** — not a wrapper around GamiPress/myCred

---

## Features

### 1. Points Engine
- Configurable points per action
- Toggle each action on/off from admin
- Per-action point values editable inline
- Points history log per member
- Cooldown periods per action (prevent gaming)
- Role-based earning caps

**BuddyPress triggers (default):**
| Action | Hook | Default Points |
|---|---|---|
| Post activity update | `bp_activity_posted_update` | 10 |
| Comment on activity | `bp_activity_comment_posted` | 5 |
| Accept friendship | `friends_friendship_accepted` | 8 |
| Join a group | `groups_join_group` | 8 |
| Create a group | `groups_group_create_complete` | 20 |
| Complete profile | `xprofile_updated_profile` | 15 |
| Upload media | `bp_media_add` | 5 |
| Post in forum | `bbp_new_reply` | 8 |
| Receive reaction | `bp_reactions_add` | 3 |
| Create poll | `bp_polls_created` | 10 |
| Publish member blog post | `publish_post` (filtered) | 25 |

### 2. Badges & Achievements
- Default badge library (20 well-designed badges, ships with plugin)
- Custom badge creator — upload image + set trigger conditions
- Badge triggers: point milestones, specific action counts, admin-awarded
- Badges display on member profile
- Member selects 3 featured badges to showcase

### 3. Levels & Ranks
- Level system based on cumulative points
- Preset defaults (configurable):
  - Level 1 — Newcomer (0 pts)
  - Level 2 — Member (100 pts)
  - Level 3 — Contributor (500 pts)
  - Level 4 — Regular (1,500 pts)
  - Level 5 — Champion (5,000 pts)
- Rank title appears under member name everywhere (activity, forums, comments, profile)
- Animated progress bar to next level

### 4. Leaderboards
- All-time, weekly, monthly
- Group leaderboard (top members within a specific group)
- Gutenberg block + widget
- Filter by point category
- Real-time updates via Interactivity API

### 5. Challenges / Quests
- Admin creates time-bound or open-ended challenges
- Example: "Post 5 times this week" → +100 bonus points
- Progress shown to member
- Custom challenge types registerable by extensions

### 6. Streaks
- Daily login streak
- Daily activity streak
- Fire emoji animation on milestone days (7, 14, 30, 60, 100)
- Bonus points for streak milestones
- Streak counter shown on profile

### 7. Member Experience
- Toast notification on every point earn (Interactivity API — no page reload)
- Level-up moment: full-screen celebration when crossing level threshold
- Progress bar animates on profile
- Leaderboard position shown: "You're #14 — 200 points from #10"
- Locked badges shown as motivation (visible but greyed out)

---

## Extension API

Any plugin can register its own gamification triggers — shows automatically in admin.

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
| WB Gamification — Courses | Lesson/course/quiz completion triggers |
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
| GET | `/leaderboard` | `?period=week\|month\|all&limit=10` |
| GET | `/badges` | All available badges |
| GET | `/actions` | All registered actions (for headless/apps) |
| POST | `/points/award` | Admin: manually award points |
| DELETE | `/points/{id}` | Admin: revoke points |

---

## WordPress Abilities API

```php
wp_register_ability( 'wb_gam_earn_points' );      // Can earn points
wp_register_ability( 'wb_gam_view_leaderboard' ); // Can see leaderboard
wp_register_ability( 'wb_gam_redeem_rewards' );   // Can redeem rewards
wp_register_ability( 'wb_gam_manage_settings' );  // Admin access
wp_register_ability( 'wb_gam_award_manual' );     // Can manually award points
```

---

## Gutenberg Blocks

| Block | Purpose |
|---|---|
| `wb-gamification/leaderboard` | Full leaderboard with period switcher |
| `wb-gamification/member-points` | Current user points + level |
| `wb-gamification/badge-showcase` | Grid of earned/locked badges |
| `wb-gamification/level-progress` | Progress bar to next level |
| `wb-gamification/challenges` | Active challenges with progress |
| `wb-gamification/top-members` | Mini leaderboard widget |

---

## Database Schema

```sql
-- Points ledger
CREATE TABLE wb_gam_points (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    action_id   VARCHAR(100)    NOT NULL,
    points      INT             NOT NULL,
    object_id   BIGINT UNSIGNED DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (action_id),
    INDEX (created_at)
);

-- Earned badges
CREATE TABLE wb_gam_user_badges (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id   BIGINT UNSIGNED NOT NULL,
    badge_id  VARCHAR(100)    NOT NULL,
    earned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_badge (user_id, badge_id)
);

-- Badge definitions
CREATE TABLE wb_gam_badge_defs (
    id            VARCHAR(100) PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    description   TEXT,
    image_url     VARCHAR(500),
    trigger_type  VARCHAR(50)  NOT NULL,
    trigger_value VARCHAR(255),
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Level definitions
CREATE TABLE wb_gam_levels (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    min_points BIGINT UNSIGNED NOT NULL,
    icon_url   VARCHAR(500),
    sort_order INT DEFAULT 0
);

-- Streaks
CREATE TABLE wb_gam_streaks (
    user_id         BIGINT UNSIGNED PRIMARY KEY,
    current_streak  INT UNSIGNED DEFAULT 0,
    longest_streak  INT UNSIGNED DEFAULT 0,
    last_active     DATE,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Challenges
CREATE TABLE wb_gam_challenges (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    action_id    VARCHAR(100) NOT NULL,
    target       INT UNSIGNED NOT NULL,
    bonus_points INT NOT NULL DEFAULT 0,
    period       ENUM('daily','weekly','monthly','none') DEFAULT 'none',
    starts_at    DATETIME,
    ends_at      DATETIME,
    status       ENUM('active','inactive') DEFAULT 'active'
);

-- Challenge progress
CREATE TABLE wb_gam_challenge_log (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    challenge_id BIGINT UNSIGNED NOT NULL,
    progress     INT UNSIGNED DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    UNIQUE KEY user_challenge (user_id, challenge_id),
    INDEX (challenge_id)
);
```

---

## Plugin File Structure

```
wb-gamification/
├── wb-gamification.php          # Main plugin file
├── PLAN.md                      # This file
├── README.md                    # Setup & developer docs
├── composer.json
├── package.json
├── src/
│   ├── Engine/
│   │   ├── Installer.php        # DB table creation on activation
│   │   ├── Registry.php         # Central action/badge/challenge registry
│   │   ├── PointsEngine.php     # Award, revoke, query points
│   │   ├── BadgeEngine.php      # Badge evaluation & award
│   │   ├── LevelEngine.php      # Level calculation
│   │   ├── StreakEngine.php      # Streak tracking
│   │   └── ChallengeEngine.php  # Challenge progress
│   ├── API/
│   │   ├── PointsController.php
│   │   ├── BadgesController.php
│   │   ├── LeaderboardController.php
│   │   └── ActionsController.php
│   ├── Abilities/
│   │   └── AbilitiesRegistrar.php
│   ├── BuddyPress/
│   │   └── HooksIntegration.php # All BuddyPress action hooks
│   ├── Admin/
│   │   └── SettingsPage.php     # Single clean admin page
│   └── Extensions/
│       └── functions.php        # Public API: wb_gamification_register_action() etc.
├── blocks/
│   ├── leaderboard/
│   ├── member-points/
│   ├── badge-showcase/
│   ├── level-progress/
│   ├── challenges/
│   └── top-members/
├── assets/
│   ├── css/
│   │   └── frontend.css
│   ├── js/
│   └── interactivity/
│       └── index.js             # Interactivity API — toasts, animations
└── languages/
```

---

## Build Phases

### Phase 1 — Core (MVP)
- [ ] Database installer
- [ ] Registry (action registration system)
- [ ] PointsEngine
- [ ] BuddyPress hooks integration
- [ ] LevelEngine
- [ ] REST API (read endpoints)
- [ ] Abilities registration
- [ ] Admin settings page (Points + Levels)
- [ ] Basic frontend display (profile points + level)

### Phase 2 — Badges & Leaderboards
- [ ] BadgeEngine
- [ ] Default badge library (20 badges)
- [ ] Custom badge creator in admin
- [ ] Leaderboard (all-time, weekly, monthly)
- [ ] Gutenberg blocks (leaderboard, member-points, badge-showcase)
- [ ] Interactivity API toasts

### Phase 3 — Engagement Features
- [ ] StreakEngine
- [ ] ChallengeEngine
- [ ] Level-up celebration overlay
- [ ] Gutenberg blocks (level-progress, challenges, top-members)
- [ ] Admin dashboard (stats overview)

### Phase 4 — Extensions & Polish
- [ ] Extension packs (Events, Courses, Membership)
- [ ] REST API (write endpoints with full auth)
- [ ] Rewards system (point redemption)
- [ ] Performance optimization (caching layer)
- [ ] Full test coverage

---

## Key Decisions

1. **Custom DB tables over post meta** — points ledger can have millions of rows; meta is not appropriate
2. **Registry pattern** — all actions auto-discovered, admin never needs manual sync
3. **Interactivity API over custom JS** — WordPress-native real-time, no React overhead
4. **Abilities API** — granular permission control, consistent with WordPress standards
5. **No GamiPress dependency** — full ownership, no third-party engine
6. **BuddyPress-first, extensible** — BP triggers built-in, everything else via extension API
