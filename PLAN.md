# WB Gamification — Product Plan

> Part of the **Reign Stack** — Wbcom's complete self-owned community platform built on WordPress + BuddyPress.

---

## Vision

**Behavioral infrastructure, not a gamification plugin.**

Install → activate → gamification works immediately. Zero config required. But more importantly: any plugin, any theme, any headless frontend can plug in with three lines of code — and the engine evaluates, rewards, and emits events without knowing anything about the source.

> **The product test:** Would a member still do the behavior if the gamification element were removed? If yes, we're amplifying intrinsic motivation. If no, we've built a dependency on extrinsic reward that will stop working within weeks.

> **The architecture test:** If BuddyPress shuts down tomorrow, does the engine still work? If a headless React app replaces the theme, does gamification still function? If an AI assistant wants to reconfigure the rules, can it do so via API without touching PHP? All three must be yes.

---

## Why We Build This

The Reign Stack replaces SaaS community platforms (Bettermode, Circle, Mighty Networks, Tribe) entirely. Unlike SaaS platforms where clients are tenants, the Reign Stack gives every client **their own site, their own data, full ownership forever**.

GamiPress and myCred built **features**. Both have critical architectural failures, fragmented add-on models, and permanently hardcoded integrations. Every new WordPress plugin requires them to write a new add-on — they are always in catch-up mode.

WB Gamification is built as **infrastructure**. The engine owns three things: event normalization, rule evaluation, and output. Everything else — BuddyPress display, WooCommerce triggers, mobile rendering, AI scoring — is a consumer of those three surfaces. Consumers come and go. The surfaces stay stable.

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

- **Infrastructure, not features** — the engine is a behavioral event bus; integrations are consumers, never core
- **Zero-dependency core** — the engine has no hard dependency on BuddyPress, WooCommerce, or any theme; they are integrations
- **Events in, rules evaluate, effects out** — one data contract governs everything; the trigger source is irrelevant
- **Rules as data, not code** — badge conditions, point multipliers, level thresholds stored in DB; configurable by admin UI or API without touching PHP
- **Event sourcing** — the points log is an immutable event record; all derived state (badges, levels, leaderboards) can be replayed from it
- **Auto-discovery first** — any plugin drops a manifest file and WB Gamification auto-discovers it; no registration call required
- **REST + webhook native** — every state change emits a webhook; any automation tool (Zapier, Make, n8n), mobile app, or headless frontend is a first-class consumer
- **Meaningful over transactional** — reward contribution quality, not activity volume
- **Works out of the box** — zero config required, setup wizard, 5 starter templates
- **Security-first** — prepared statements, strict nonce checks, no SQL injection surface

---

## The Architecture: Universal Action Graph

```
┌──────────────────────────────────────────────────────────┐
│                    TRIGGER SOURCES                        │
│  Any WordPress hook · REST API · WP-CLI · Manifest file  │
└──────────────────────┬───────────────────────────────────┘
                       │  WB_Gam_Event object
                       ▼
┌──────────────────────────────────────────────────────────┐
│               EVENT NORMALIZATION LAYER                   │
│          ManifestLoader · Registry · EventFactory         │
│  { user_id, action_id, object_id, metadata, timestamp }  │
└──────────────────────┬───────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│                 RULE EVALUATION ENGINE                    │
│         Points rules · Badge conditions · Level          │
│         thresholds · Challenge progress · Streaks        │
│         Rules stored in DB — configurable without code   │
└──────────────────────┬───────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│                      EFFECTS                             │
│  Points ledger  ·  Badge award  ·  Level change          │
│  Notifications  ·  Activity stream  ·  Outbound webhook  │
│  WP action hooks  ·  Automation triggers                 │
└──────────────────────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────┐
│                 OUTPUT CONSUMERS                         │
│  Gutenberg blocks · BuddyPress display · REST API        │
│  Mobile app · Headless React · Zapier · n8n · Make       │
│  AI rule configurator · Third-party CRMs                 │
└──────────────────────────────────────────────────────────┘
```

**The engine owns exactly three things:**
1. **Event normalization** — any hook becomes a standard `WB_Gam_Event`
2. **Rule evaluation** — points, badges, levels evaluated against stored, configurable rules
3. **Output surfaces** — REST API, webhooks, WP action hooks

Everything else is a consumer. Consumers can be swapped, extended, or removed without touching the engine.

---

## Deployment Modes

WB Gamification auto-detects what plugins are active via `ManifestLoader` and loads the right triggers automatically. **Zero configuration by site owner. No add-on model — everything is bundled.**

| Mode | Detection | How triggers load |
|---|---|---|
| **Standalone** | No BuddyPress | `integrations/wordpress.php` manifest only |
| **Community** | BuddyPress active | WordPress manifest + `integrations/buddypress.php` manifest |
| **Full Reign** | BuddyPress + Reign Stack plugins | All above + Reign plugin manifests (polls, reactions, media, forums, member blog) |
| **Extended** | Any plugin with a manifest | All above + any `wb-gamification.php` found in active plugin directories |

### Boot Sequence

```
plugins_loaded priority 5  → ManifestLoader::scan() — discovers manifests, calls Registry::register() per trigger
plugins_loaded priority 6  → Registry::init() — finalises trigger registry, fires wb_gamification_register action
plugins_loaded priority 8  → Engine::init() — ready to accept WB_Gam_Event objects; hooks manifest triggers to WP hooks
plugins_loaded priority 10 → BuddyPress/WordPress display integrations boot (on bp_loaded for BP-specific display)
```

ManifestLoader runs first at priority 5 and feeds directly into Registry. Registry::init() at priority 6 finalises after all manifests are loaded and fires the `wb_gamification_register` action for any plugin still using the manual registration fallback. This guarantees deterministic order.

**Avoiding double-award:** Each manifest file declares whether its triggers are `standalone_only: true` (skip when BuddyPress is active). `integrations/wordpress.php` marks `publish_post` and `leave_comment` as standalone-only because the BuddyPress manifest covers those same underlying actions.

**No add-on model — ever.** Every capability ships in core: BuddyPress integration, leaderboards, kudos, notifications, analytics, streaks, challenges. New plugin integrations come via manifest files (zero code in this plugin), not paid extensions.

---

## Competitive Research Summary

Conducted March 2026 across: community SaaS (Circle, Mighty Networks, Bettermode, Skool), gaming (Xbox, Steam, Pokemon GO), fitness (Strava, Nike Run Club, Peloton), education (Duolingo, Khan Academy, Codecademy), professional networks (Stack Overflow, GitHub, LinkedIn), enterprise software (Salesforce Trailhead, Notion, HubSpot), music/entertainment (Spotify), social platforms (Reddit, Discord), e-commerce loyalty programs, and existing WP plugins (GamiPress, myCred).

---

### Tier 1: Community SaaS (Direct Competitors)

- **Skool**: Visible level/rank next to name in every post — ambient social proof at all times. Leaderboard always visible. Level-locked content unlocks. Members pay $99/mo for this; we bundle it.
- **Circle**: Workflow automation tied to rank milestones. Opt-in/opt-out design. Points from likes (quality) not post volume.
- **Mighty Networks**: Streaks + habit trackers for accountability communities. Group challenges. Custom rank titles. Fixed point values = #1 complaint.
- **Bettermode**: Widget-based gamification — always requires configuration. Admin fatigue is real.
- **Discord**: Server Boosting as status signal (pay-to-flex, but the concept of visible contribution status works). Bot-driven point systems (MEE6, UnbelievaBoat) are the top third-party add-on category — proving demand.

---

### Tier 2: Gaming (Engagement Pioneers)

These platforms invented the mechanics everyone else copies.

- **Xbox Achievements / PlayStation Trophies**: The original badge system. Key insight: players value completion percentage and rare achievement rarity (only 2% of players earned this). **Implication:** Show badge rarity on earned badges — "Only 38 members have this."
- **Steam**: Trading cards, profile backgrounds, and badge crafting — points that become display items, not just numbers. Level visible on profile with prominent visual weight.
- **Pokemon GO**: Streak mechanics tied to physical action (daily catch). Community Day events create synchronized participation spikes — everyone plays the same challenge at the same time. **Implication:** Time-limited community challenges with visible global participation counters.
- **World of Warcraft**: Guild (group) achievements separate from individual ones. Realm-first achievements — first player on a server to accomplish something. **Implication:** Site-first badges ("First member to reach Champion rank") create permanent historical record.
- **League of Legends**: Honor system — peer-nominated recognition that's harder to game than algorithmic scoring. Visible honor badge next to username in all contexts. **Implication:** Peer kudos legitimacy > admin-awarded points for trust.

---

### Tier 3: Fitness Apps (Streak + Social Mechanics)

- **Strava**: Peer kudos (14 billion given in 2025). Club/team leaderboards. Personal records auto-notified. **Segment leaderboards** — you don't compete against all users, only people who ran the same route. **Implication:** Contextual leaderboards (same group, same challenge type) feel fair; global leaderboards feel hopeless.
- **Nike Run Club**: Guided runs with in-app coach narration — the reward is richer experience, not just points. Challenges that friends join together — social accountability without public shame.
- **Peloton**: Monthly challenge streaks with badge. Community output challenges (total output from all riders). **Leaderboard during live class** creates real-time competition that disappears afterward — no permanent shame. **Implication:** Time-scoped leaderboards that reset cleanly are healthier than permanent ranking.
- **Apple Fitness+**: Activity rings — visual completion mechanic with daily reset. Sharing rings creates ambient accountability without explicit competition. **Implication:** Simple circular/ring progress indicators outperform progress bars for daily habit loops.

---

### Tier 4: Education (Progression + Credentials)

- **Duolingo**: Weekly cohort leagues with promotion/demotion. Streak freeze (grace days). Personalized notification timing based on user's typical active hour. **XP is the currency of everything** — all features funnel to XP. **Implication:** One currency, many sources. Don't split points into multiple currencies.
- **Khan Academy**: Energy points that unlock avatar items — visual identity customization as reward. Mastery badges organized by subject tree. **Implication:** Let members customize their visible identity (avatar frame, profile decoration) with earned points — cosmetic rewards that don't affect fairness.
- **Codecademy**: Pro tracks unlock as progress is made — the product is the reward. Streak calendar visible on profile — contribution graph like GitHub. **Implication:** Visual contribution calendar (heatmap) is a stronger retention signal than a streak number.
- **Coursera / Udemy**: Course completion certificates that are professionally shareable. LinkedIn Learning auto-adds certifications to LinkedIn profile. **Implication:** The shareable credential UI must be frictionless — one click from badge page to LinkedIn profile.

---

### Tier 5: Professional Networks (Reputation Systems)

- **Stack Overflow**: Points unlock moderation privileges. Upvote-weighted — quality beats volume. **Reputation is functional, not cosmetic** — 2000 rep = you can edit others' posts. **Implication:** Level-gated moderation abilities (flag review, pin posts) are the most meaningful privilege system.
- **GitHub**: Contribution graph (heatmap) is the most widely recognized reputation signal in software. Stars on repos = peer validation. Follows = ambient social proof. **Implication:** Public contribution history visualization (heatmap of activity) signals long-term commitment better than a level number.
- **LinkedIn**: Skills endorsements (peer validation of competencies) + recommendations (social proof) + certifications (credential display). Profile completeness score drives profile improvement behavior. **Implication:** Profile completeness gamification is low-hanging fruit — show members what's missing, reward completion.
- **Product Hunt**: Maker streaks — visible on profile for consecutive days of shipping. Upvote = peer recognition. Maker badge = verified contributor. **Implication:** "Maker" / "Contributor" identity labels matter more than point totals for professional communities.

---

### Tier 6: Enterprise SaaS (Adoption Gamification)

- **Salesforce Trailhead**: Functional privilege gating (status = real access). Superbadges for hands-on skills. LinkedIn-shareable credentials with Salesforce-verified metadata. Trail maps — visual learning paths with checkpoint badges. **Implication:** Badge categories organized as learning paths (not just flat achievement lists) increase badge completion rates.
- **HubSpot Academy**: Certifications that expire and require renewal — creates recurring re-engagement. Public certification page that employers can verify. **Implication:** Credential expiry (optional) creates return visits; verification URL makes credential useful outside the platform.
- **Notion**: Template gallery — community-created templates earn creator attribution and visibility. Upvote system drives discovery. **Implication:** User-generated content as a reputation driver — members who create resources others use should earn recognition.
- **Figma**: Community files with follower/like counts. Contributor badge for community resources. **Implication:** Same pattern — created-and-used content > volume metrics.

---

### Tier 7: Entertainment + Social (Habit Loops)

- **Spotify Wrapped**: Annual retrospective that shows your listening personality — shareable on social media. **Implication:** Annual/monthly "your gamification story" email or shareable card — "Your 2026 in community: 847 posts, 23 badges, 14 weeks at #1." Personal narrative over raw stats.
- **Reddit**: Karma split into post karma and comment karma — separates contribution types. Community-specific awards (user-purchased, given to others). **Implication:** Separating point types (posting vs. commenting vs. helping) gives richer signals than a single number. User-purchasable awards that benefit the receiver create economic micro-engagement.
- **Discord**: Server level (boosters get perks). Role-based color/icons visible in member list — instant visual hierarchy. Custom roles as admin reward mechanic. **Implication:** Custom role names visible in member lists = free gamification. Any BP member directory integration should make rank role visually distinct.
- **Twitch**: Subscriber streak — visible badge next to username showing consecutive months subscribed. Bit badges — visual progression for cumulative donations. **Implication:** Tenure badges (1 year member, 2 year member) with visual badge that upgrades automatically are underutilized in community platforms.

---

### Tier 8: E-Commerce Loyalty (Points Redemption Models)

- **Starbucks Rewards**: Stars expire if not used within 6 months — but this is EXCEPTION. Only works because of high purchase frequency and strong brand identity. **Anti-pattern for most communities.**
- **Amazon Prime**: Membership status unlocks access, not just discounts — functional gating. Progress to next tier shown in account dashboard at all times.
- **Airline frequent flyer programs**: Status tiers with clear published thresholds. Tier-qualifying vs. total points (redeemable vs. status-earning separated). Year-end status challenge — "Earn 5,000 more miles to requalify." **Implication:** Show members their status renewal threshold as end-of-period approaches — creates urgency without manipulation.
- **Shopify/WooCommerce loyalty apps (Smile.io, LoyaltyLion)**: Points → store credit. Referral as a separate earning mechanic with dedicated tracking. Birthday bonus points. **Implication:** For course/product communities — points → discount redemption is a clear Phase 4 feature that dramatically increases purchase intent. Integrates via the WooCommerce manifest.

---

### Cross-Domain Patterns That Appear Everywhere

| Pattern | Seen In | Implementation |
|---|---|---|
| **Visible rank/status in all contexts** | Discord, Skool, Stack Overflow, Twitch | BP directory + profile header ✅ |
| **Personal record notification** | Strava, Apple Fitness, Peloton | "Your best week" notification (Phase 2) |
| **Badge rarity display** | Xbox, Steam, Pokemon GO | Show "X members earned this" on badge |
| **Contribution heatmap** | GitHub, Codecademy | Activity calendar on profile (Phase 3) |
| **Time-scoped leaderboard** | Peloton, Duolingo, Skool | Weekly reset + cohort leagues |
| **Profile completeness score** | LinkedIn, Salesforce | Gamify profile completion (Phase 1 ✅) |
| **Peer-nominated recognition** | League of Legends, Stack Overflow, Strava | Kudos system (Phase 2) |
| **Learning path with checkpoint badges** | Trailhead, Duolingo, Khan Academy | Badge categories + unlock chains |
| **Cosmetic rewards (non-functional)** | Khan Academy, Steam, Xbox | Avatar items, profile frames (Phase 4) |
| **Tenure badges** | Twitch subscriber streaks, LinkedIn | Auto-upgrading "1 year member" badges |
| **Shareable credential page** | Coursera, Trailhead, HubSpot Academy | Share URL + LinkedIn meta (Phase 2) |
| **Site-first / rarity achievement** | WoW realm-first, Xbox rare achievements | "First to Champion" type badges |
| **Annual retrospective** | Spotify Wrapped, GitHub Year in Code | "Your year in community" card (Phase 3) |

---

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

---

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
- Scoped leaderboard: top members within any defined scope — BP group is one scope type, not a hard dependency; scope passed as `scope_type` (e.g. `bp_group`, `challenge`, `custom`) + `scope_id` params, or via `wb_gamification_leaderboard_scope` filter
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
- Custom challenge types registerable by any plugin via manifest
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
| **Add-on / extension model** | GamiPress needs 8–12 paid add-ons for a complete stack. We ship everything in one plugin. New integrations come via manifest files — never a paid gate. |
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

Three integration surfaces, ordered from least to most friction.

---

### Surface 1: Manifest File (Zero-friction — recommended for plugin authors)

Drop a file named `wb-gamification.php` anywhere inside your plugin directory. WB Gamification scans for and auto-loads it at boot. **No registration call. No hook. No dependency on WB Gamification being active.**

```php
// wp-content/plugins/woocommerce/wb-gamification.php
// This file is discovered and loaded automatically if WB Gamification is active.

return [
    'plugin'   => 'WooCommerce',
    'version'  => '1.0.0',
    'triggers' => [
        [
            'id'             => 'wc_first_purchase',
            'label'          => 'Complete first purchase',
            'hook'           => 'woocommerce_order_status_completed',
            'user_callback'  => fn( $order_id ) => (int) get_post_meta( $order_id, '_customer_user', true ),
            'metadata'       => fn( $order_id ) => [
                'order_total'   => (float) wc_get_order( $order_id )->get_total(),
                'product_count' => (int) wc_get_order( $order_id )->get_item_count(),
            ],
            'default_points' => 50,
            'category'       => 'commerce',
            'repeatable'     => false,
            'daily_cap'      => 1,
        ],
        [
            'id'             => 'wc_purchase',
            'label'          => 'Complete a purchase',
            'hook'           => 'woocommerce_order_status_completed',
            'user_callback'  => fn( $order_id ) => (int) get_post_meta( $order_id, '_customer_user', true ),
            'metadata'       => fn( $order_id ) => [ 'order_total' => (float) wc_get_order( $order_id )->get_total() ],
            'default_points' => 20,
            'category'       => 'commerce',
            'repeatable'     => true,
        ],
    ],
    'badges' => [
        [
            'id'           => 'first_purchase',
            'name'         => 'First Purchase',
            'trigger_type' => 'action_count',
            'trigger_value' => json_encode( [ 'action_id' => 'wc_first_purchase', 'count' => 1 ] ),
            'category'     => 'commerce',
        ],
    ],
];
```

**What this enables:** WooCommerce community members, the WooCommerce team itself, or any third party can publish gamification support for WooCommerce — without touching WB Gamification's codebase. Same for LearnDash, LifterLMS, GiveWP, The Events Calendar, bbPress, any plugin.

---

### Surface 2: Event Object API (For direct PHP integration)

When you need programmatic control — custom conditions, async flows, or events from code that doesn't fire a WordPress hook:

```php
// Process a normalized event through the full rule engine.
WB_Gam_Engine::process( new WB_Gam_Event([
    'action_id' => 'wc_purchase_complete',
    'user_id'   => $user_id,
    'object_id' => $order_id,
    'metadata'  => [
        'order_total'   => 149.99,
        'product_count' => 3,
        'is_renewal'    => false,
    ],
]) );
```

The `WB_Gam_Event` object is a typed value object:

```php
class WB_Gam_Event {
    public readonly string $action_id;
    public readonly int    $user_id;
    public readonly int    $object_id;   // 0 = no object
    public readonly array  $metadata;    // arbitrary key-value; persisted with event
    public readonly string $created_at;  // ISO-8601, UTC
    public readonly string $event_id;    // UUID, auto-generated
}
```

The `metadata` field is the AI extensibility hook. Future rule conditions can reference metadata keys:
```json
{ "condition": "metadata.order_total > 100", "bonus_multiplier": 1.5 }
```

---

### Surface 3: Rules as Data (Admin UI + API-configurable)

Badge conditions, point multipliers, and level thresholds are stored as structured data in the database — not hardcoded PHP. Admins configure them in the UI. AI assistants can configure them via REST API. No PHP changes required.

**Stored rule example (badge condition):**
```json
{
  "badge_id": "power_shopper",
  "name": "Power Shopper",
  "conditions": [
    { "type": "action_count", "action_id": "wc_purchase", "count_gte": 10 },
    { "type": "metadata_sum", "action_id": "wc_purchase", "field": "order_total", "sum_gte": 500 }
  ],
  "condition_logic": "AND"
}
```

**Stored rule example (point multiplier):**
```json
{
  "rule_id": "weekend_bonus",
  "action_id": "*",
  "multiplier": 2.0,
  "condition": { "type": "day_of_week", "days": [0, 6] },
  "label": "Weekend double points"
}
```

Rules are evaluated by `WB_Gam_Rule_Engine` against the incoming `WB_Gam_Event`. Adding a new rule type = adding one evaluator class. No changes to the event pipeline.

---

### Developer Hooks (WP-native action/filter surface)

```php
// Enrich event metadata before rule evaluation (add quality signals, AI scores, etc.)
add_filter( 'wb_gamification_event_metadata', function( array $metadata, WB_Gam_Event $event ): array {
    if ( 'wp_leave_comment' === $event->action_id ) {
        $metadata['word_count'] = str_word_count( get_comment( $event->object_id )->comment_content );
    }
    return $metadata;
}, 10, 2 );

// Intercept before rule evaluation — block or modify
add_filter( 'wb_gamification_before_evaluate', fn( bool $proceed, WB_Gam_Event $event ) => $proceed, 10, 2 );

// React after points are awarded
// ⚠ BREAKING CHANGE (Phase 0): second arg changed from string $action_id to WB_Gam_Event $event.
// Old: add_action( 'wb_gamification_points_awarded', fn( int $user_id, string $action_id, int $points ) => ... )
// New: use $event->action_id instead of $action_id; all event metadata available via $event->metadata
add_action( 'wb_gamification_points_awarded', fn( int $user_id, WB_Gam_Event $event, int $points ) => null, 10, 3 );

// React after badge is earned
add_action( 'wb_gamification_badge_earned', fn( int $user_id, string $badge_id, WB_Gam_Event $event ) => null, 10, 3 );

// React after level change
add_action( 'wb_gamification_level_changed', fn( int $user_id, int $old_level, int $new_level ) => null, 10, 3 );

// Modify leaderboard query
add_filter( 'wb_gamification_leaderboard_args', fn( array $args ) => $args );
```

---

### Outbound Webhooks

Register an endpoint in Settings → Webhooks. WB Gamification POSTs a signed JSON payload for each event type you subscribe to.

```json
POST https://your-crm.example.com/webhooks/gamification
X-WB-Gam-Signature: sha256=...

{
  "event":      "badge_earned",
  "site_url":   "https://community.example.com",
  "timestamp":  "2026-03-17T10:00:00Z",
  "user_id":    42,
  "user_email": "member@example.com",
  "data": {
    "badge_id":   "content_creator",
    "badge_name": "Content Creator",
    "event_id":   "01J8X..."
  }
}
```

**Supported event types:** `points_awarded`, `badge_earned`, `level_changed`, `streak_milestone`, `challenge_completed`, `kudos_given`.

Connects natively with Zapier, Make, n8n, ActiveCampaign, HubSpot, Firebase, OneSignal — zero custom integration code.

---

### Integration Manifest Repository

Planned: a public GitHub repo (`wbcom/wb-gamification-integrations`) where community authors publish manifest files for any WordPress plugin. WB Gamification ships with first-party manifests:

| Plugin | Manifest ships | Triggers |
|---|---|---|
| BuddyPress | ✅ Core | Activity, friends, groups, profiles |
| WooCommerce | Phase 2 | Purchase, review, wishlist |
| LearnDash | Phase 2 | Lesson, course, quiz, certificate |
| LifterLMS | Phase 2 | Lesson, course, quiz, membership |
| GiveWP | Phase 2 | Donation, campaign milestone |
| The Events Calendar | Phase 3 | RSVP, attend, host |
| bbPress | Phase 1 | Forum post, reply, marked answer |
| WPForms | Phase 3 | Form submission |
| MemberPress | Phase 3 | Membership, renewal, upgrade |
| Gravity Forms | Phase 3 | Form submission with field metadata |

Any community author can submit a manifest PR for any plugin not listed here.

---

## REST API

**Namespace:** `wb-gamification/v1`

Designed for three consumers equally: WordPress frontend (blocks), headless/mobile apps, and AI agents/automation tools.

### Member Endpoints
| Method | Endpoint | Description |
|---|---|---|
| GET | `/members/{id}` | Full gamification profile |
| GET | `/members/{id}/points` | Points total + paginated history |
| GET | `/members/{id}/events` | Full immutable event log for user |
| GET | `/members/{id}/badges` | Earned badges |
| GET | `/members/{id}/level` | Current level + progress to next |
| GET | `/members/{id}/streak` | Current streak data |

### Leaderboard + Discovery
| Method | Endpoint | Description |
|---|---|---|
| GET | `/leaderboard` | `?period=day\|week\|month\|all&limit=10&scope_type=bp_group&scope_id=X` |
| GET | `/leaderboard/team` | Scoped aggregate leaderboard (`scope_type=challenge\|bp_group\|custom`) |
| GET | `/badges` | All available badges with rarity counts |
| GET | `/actions` | All registered actions — headless/app discovery |
| GET | `/challenges` | Active challenges + member progress |

### Write Endpoints (Admin + AI-compatible)
| Method | Endpoint | Description |
|---|---|---|
| POST | `/events` | Ingest an event from any source (headless, mobile, CLI) |
| POST | `/points/award` | Manually award points with message |
| POST | `/kudos` | Member gives kudos to another member |
| DELETE | `/points/{id}` | Admin: revoke specific point row |

### Rule Engine (AI-configurable)
| Method | Endpoint | Description |
|---|---|---|
| GET | `/rules` | List all stored rules (badge conditions, multipliers) |
| POST | `/rules` | Create a new rule — no PHP required |
| PUT | `/rules/{id}` | Update an existing rule |
| DELETE | `/rules/{id}` | Remove a rule |

### Webhook Management
| Method | Endpoint | Description |
|---|---|---|
| GET | `/webhooks` | List registered webhook endpoints |
| POST | `/webhooks` | Register a new webhook URL + event subscriptions |
| DELETE | `/webhooks/{id}` | Remove a webhook |

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

The schema follows event sourcing: `wb_gam_events` is the immutable source of truth. `wb_gam_points` is a derived ledger kept in sync for query performance. All badge/level/leaderboard state can be rebuilt by replaying `wb_gam_events` against current rules.

```sql
-- Immutable event log — source of truth for all derived state
-- Never deleted (except GDPR erasure). Archive before pruning points ledger.
CREATE TABLE wb_gam_events (
    id         VARCHAR(36)     NOT NULL,           -- UUID v4
    user_id    BIGINT UNSIGNED NOT NULL,
    action_id  VARCHAR(100)    NOT NULL,
    object_id  BIGINT UNSIGNED DEFAULT NULL,
    metadata   JSON            DEFAULT NULL,        -- arbitrary context, persisted forever
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_action (user_id, action_id),
    KEY idx_user_created (user_id, created_at),
    KEY idx_created (created_at)
);

-- Stored rule engine configuration — badge conditions, multipliers, level thresholds
-- Configurable via admin UI or REST API without PHP changes.
CREATE TABLE wb_gam_rules (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    type         VARCHAR(50)      NOT NULL,           -- badge_condition | points_multiplier | level_threshold
    target_id    VARCHAR(100)     DEFAULT NULL,       -- badge_id, action_id, or NULL for global
    rule_config  JSON             NOT NULL,           -- conditions, values, logic
    priority     INT              DEFAULT 10,
    active       TINYINT(1)       DEFAULT 1,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_type_target (type, target_id)
);

-- Outbound webhook registrations
CREATE TABLE wb_gam_webhooks (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    url          VARCHAR(500)     NOT NULL,
    events       JSON             NOT NULL,           -- array of subscribed event types
    secret       VARCHAR(64)      NOT NULL,           -- used to sign payloads
    active       TINYINT(1)       DEFAULT 1,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

-- Points ledger — derived from wb_gam_events for fast aggregation queries.
-- event_id links each row back to the immutable source event.
-- Never treat this as the source of truth; rebuild from wb_gam_events if needed.
CREATE TABLE wb_gam_points (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id   VARCHAR(36)     NOT NULL,            -- FK → wb_gam_events.id
    user_id    BIGINT UNSIGNED NOT NULL,
    action_id  VARCHAR(100)    NOT NULL,
    points     INT             NOT NULL,
    object_id  BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_event (event_id),
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

-- Badge definitions — identity and display only.
-- Award conditions live in wb_gam_rules (type='badge_condition', target_id=badge_id).
-- Separating identity from conditions lets rules be changed without touching badge records.
CREATE TABLE wb_gam_badge_defs (
    id            VARCHAR(100) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    description   TEXT,
    image_url     VARCHAR(500),
    is_credential TINYINT(1)   DEFAULT 0,        -- OpenBadges 3.0 shareable flag
    category      VARCHAR(50)  DEFAULT 'general', -- buddypress|commerce|learning|social|manual|general
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
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title          VARCHAR(255)    NOT NULL,
    type           VARCHAR(20)     DEFAULT 'individual',  -- individual | team
    team_group_id  BIGINT UNSIGNED DEFAULT NULL,          -- BP group ID for team challenges
    action_id      VARCHAR(100)    NOT NULL,
    target         INT UNSIGNED    NOT NULL,
    bonus_points   INT             NOT NULL DEFAULT 0,
    period         VARCHAR(20)     DEFAULT 'none',
    starts_at      DATETIME,
    ends_at        DATETIME,
    status         VARCHAR(20)     DEFAULT 'active',
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
│   │   ├── Event.php              # WB_Gam_Event — typed value object, carries metadata
│   │   ├── Engine.php             # WB_Gam_Engine::process() — main entry point
│   │   ├── RuleEngine.php         # Evaluates stored rules against events
│   │   ├── ManifestLoader.php     # Auto-discovers wb-gamification.php in all plugin dirs
│   │   ├── WebhookDispatcher.php  # Fires outbound webhooks with HMAC signature
│   │   ├── Installer.php          # DB tables + seeding
│   │   ├── Registry.php           # Central action registry (manual registration fallback)
│   │   ├── PointsEngine.php       # Award, revoke, daily/weekly caps, quality-weight
│   │   ├── BadgeEngine.php        # Badge evaluation + award
│   │   ├── LevelEngine.php        # Level calc + privilege gating
│   │   ├── StreakEngine.php        # Timezone-aware streak tracking
│   │   ├── ChallengeEngine.php    # Individual + team challenge progress
│   │   ├── KudosEngine.php        # Peer-to-peer kudos
│   │   └── LogPruner.php          # Auto-pruning via WP-Cron
│   ├── API/
│   │   ├── EventsController.php   # POST /events — ingest events from any source
│   │   ├── RulesController.php    # CRUD /rules — AI-configurable rule engine
│   │   ├── WebhooksController.php # CRUD /webhooks — outbound webhook management
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
│   │       └── HooksIntegration.php # WP-native triggers (Phase 0: replaced by integrations/wordpress.php)
│   ├── BuddyPress/
│   │   ├── HooksIntegration.php   # BP action hooks (Phase 0: replaced by integrations/buddypress.php)
│   │   ├── ProfileIntegration.php # Auto-inject rank/badges into BP profile header
│   │   ├── ActivityIntegration.php # Badge/level-up events → activity stream
│   │   └── DirectoryIntegration.php # Rank visible in member directory
│   ├── Admin/
│   │   ├── SetupWizard.php        # First-activation template chooser
│   │   ├── SettingsPage.php       # Points, Levels, Badges, Leaderboard tabs
│   │   ├── RuleEditor.php         # Visual rule builder — no PHP for badge conditions
│   │   └── AnalyticsDashboard.php # Retention cohort, action effectiveness
│   └── Extensions/
│       └── functions.php          # Public API functions
├── integrations/                  # First-party manifest files for ecosystem plugins
│   ├── buddypress.php             # BuddyPress manifest (all BP triggers)
│   ├── woocommerce.php            # WooCommerce manifest
│   ├── learndash.php              # LearnDash manifest
│   ├── lifterlms.php              # LifterLMS manifest
│   ├── givewp.php                 # GiveWP manifest
│   └── bbpress.php                # bbPress manifest
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

### Phase 0 — Architectural Foundation (Before Phase 1 ships to real sites)

These are not features. They are the infrastructure that makes all future phases possible without rewrites.

- [x] **`WBGam\Engine\Event` value object** — typed, immutable, UUID v4 auto-generated, carries `metadata: array`
- [x] **`WBGam\Engine\Engine::process( Event )`** — single entry point; all award paths route through here
- [x] **`wb_gam_events` DB table** — immutable event log with LONGTEXT metadata column + composite `(user_id, created_at)` index
- [x] **`wb_gam_rules` DB table** — stored rule configuration
- [x] **`wb_gam_webhooks` DB table** — outbound webhook registrations
- [x] **`ManifestLoader`** — scans first-party `integrations/*.php` + `WP_PLUGIN_DIR/{plugin}/wb-gamification.php` at boot (priority 5)
- [x] **`WebhookDispatcher`** — fires HMAC-SHA256-signed async POST requests via Action Scheduler
- [x] **`RuleEngine` (v1)** — `points_multiplier` rules with `day_of_week`, `action_id_match`, `metadata_gte` conditions; extensible via filter
- [x] **Migrate `PointsEngine::award()` calls** — Registry and functions.php now route through `Engine::process(Event)`
- [x] **First-party manifest: BuddyPress** — `integrations/buddypress.php` (9 triggers, loaded only when BP active)
- [x] **First-party manifest: WordPress core** — `integrations/wordpress.php` (8 triggers: 4 always-on + 4 standalone-only)

> **Why Phase 0:** Once real community data enters the system, migrating from `award()` to event-sourced `process()` requires a data migration. Do it before that happens.

---

### Phase 1 — Core (MVP)
- [x] Database installer (all tables + indexes) — composite `(user_id, created_at)` index, all tables
- [x] Log auto-pruner (WP-Cron, daily, configurable retention via `wb_gam_log_retention_months`)
- [x] Registry (extension API)
- [x] PointsEngine (cooldown, repeatable, daily/weekly caps — quality weighting and async queue below)
- [x] WordPress-native hooks (`WB_Gam_WordPress_Hooks`) — standalone + always groups
- [x] BuddyPress hooks — on `bp_loaded`, not `init` (fix myCred's bug by design)
- [x] BuddyPress profile auto-injection (rank in header — no shortcode, opt-out aware)
- [x] BuddyPress member directory rank display
- [x] LevelEngine + level-gated access (fully implemented — `maybe_level_up`, `get_progress_percent`, all read methods)
- [x] Setup wizard with 5 starter templates (including "Blog / Publisher" for standalone WP)
- [x] Admin settings page (Points + Levels — inline editable, shows active mode)
- [x] REST API read endpoints (`MembersController` — `/members/{id}`, `/points`, `/level`, `/badges`)
- [x] Abilities registration
- [x] Member opt-out preference (leaderboard + rank visibility)
- [x] Quality-weighted points (`wb_gamification_points_for_action` now receives `$event`; `ActivityIntegration` applies 5 pts for reactions on activity updates vs 3 for comments; `metadata_callback` on bp_activity_update/comment captures word_count)
- [x] Async processing via Action Scheduler — `Engine::process_async()` + AS handler wired; `async: true` flag on high-volume BP activity/comment triggers

### Phase 2 — Badges + Leaderboards + Kudos
- [x] BadgeEngine — evaluate_on_award, condition types (point_milestone, action_count, admin_awarded, custom filter), idempotent award, object-cache backed earned-ids
- [x] Default badge library (30 badges seeded in Installer: 5 points milestones, 6 WordPress, 12 BuddyPress, 7 special/admin-awarded)
- [x] LeaderboardEngine — periods (all/month/week/day), opt-out filtering, scope resolution (bp_group via filter), get_user_rank with points_to_next
- [x] LeaderboardController — GET /leaderboard + GET /leaderboard/me
- [x] BadgesController — GET /badges (with rarity), GET /badges/{id}, POST /badges/{id}/award (admin-only)
- [x] Custom badge creator (admin UI) — BadgeAdminPage.php: list/edit/create/delete; condition editor (point_milestone, action_count, admin_awarded); submenu under WB Gamification
- [x] KudosEngine — send(), daily limit, receiver/giver points via Engine::process(), wb_gamification_kudos_given hook
- [x] KudosController — POST /kudos, GET /kudos (feed), GET /kudos/me (stats)
- [x] BP activity stream integration — badge_earned / level_changed / kudos_given auto-post to stream; admin toggleable per type; activity types registered with `bp_activity_set_action`
- [x] Team/cohort leaderboard — GET /leaderboard/group/{group_id} in LeaderboardController; uses BP group scope in LeaderboardEngine
- [x] Weekly leaderboard nudge — LeaderboardNudge.php: weekly cron → AS batch dispatch per active user → send_nudge() with BP notification + optional email + `wb_gamification_weekly_nudge` hook
- [x] Gutenberg blocks — all 9 blocks built (leaderboard, member-points, badge-showcase, kudos-feed, level-progress, challenges, streak, top-members, year-recap); server-side rendered, block.json + render.php
- [x] Interactivity API — smart-batched toast notifications, level-up overlay
- [x] Weekly summary email — WeeklyEmailEngine.php: weekly cron, AS batch dispatch, best-week personal record detection, unsubscribe link with HMAC token
- [x] **Rank automation rules** — RankAutomation.php: on `wb_gamification_level_changed`, runs rules from option/filter; action types: add_bp_group, send_bp_message, change_wp_role, custom via `wb_gamification_rank_automation_action` hook
- [x] **Credential sharing UI** — BadgeShareController.php: GET /badges/{badge_id}/share/{user_id} returns badge def + earner data + LinkedIn share URL + OG meta; publicly accessible
- [x] **Personal milestone notification** — PersonalRecordEngine.php: hooks `wb_gamification_points_awarded` at priority 20; detects best_day/best_week/best_month vs stored user meta; fires BP notification + `wb_gamification_personal_record` action

### Phase 3 — Engagement + Analytics
- [x] StreakEngine — timezone-aware, grace period, accountability partners, `get_contribution_data()` for heatmap
- [x] ChallengeEngine — individual + team challenges with `team_group_id`, AS-backed bonus dispatch
- [x] Time-limited community challenges — CommunityChallengeEngine.php: atomic global counter (ON DUPLICATE KEY UPDATE), AS bonus award to all contributors on completion
- [x] Level-up celebration overlay
- [x] Remaining Gutenberg blocks — level-progress, challenges, streak (all built with render.php)
- [x] Admin analytics dashboard — AnalyticsDashboard.php: retention cohort chart, action effectiveness table, churn signals
- [x] Activity contribution heatmap on profile — streak block with `show_heatmap=true`; `StreakEngine::get_contribution_data()` returns day-indexed points array
- [x] Badge rarity display — BadgesController GET /badges returns `earner_count` + `rarity_label`; "Only N members have this"
- [x] Tenure badges — TenureBadgeEngine.php: weekly cron checks member anniversaries; awards 1y/2y/3y/5y badges
- [x] Site-first badges — SiteFirstBadgeEngine.php: race-safe transient lock; first Champion rank, first 10k points, first 100-day streak
- [x] "Your year in community" shareable recap card — RecapEngine.php + RecapController.php (GET /members/{id}/recap?year=); year-recap Gutenberg block with dark gradient card + Web Share API
- [x] Mission-aligned mode — MissionMode.php: 6 built-in modes (default/nonprofit/professional/fitness/education/coaching); `term()` helper for terminology overrides; `wb_gamification_mission_modes` filter
- [x] Community-type specific templates — covered by MissionMode + SetupWizard 5 starter templates

### Phase 4 — Credentials + Rewards + Integrations
- [x] OpenBadges 3.0 credential issuance — CredentialController.php: GET /badges/{badge_id}/credential/{user_id}; full JSON-LD OpenBadgeCredential; `Content-Type: application/ld+json`; `wb_gamification_credential_document` filter
- [x] OpenBadges 3.0 metadata — full `@context`, `VerifiableCredential`, `OpenBadgeCredential`, `AchievementSubject`, `Achievement`, `Profile` issuer; publicly cacheable (max-age=3600)
- [ ] HubSpot-style credential expiry (optional) — creates renewal re-engagement cycle
- [x] Points redemption store — RedemptionEngine.php + RedemptionController.php; reward types (discount_pct, discount_fixed, custom); atomic stock decrement; WC_Coupon creation; GET/POST /redemptions/items + POST /redemptions
- [x] Cosmetic rewards — CosmeticEngine.php: avatar frames, profile decorations, themes; BP avatar filter hook; cosmetic redemption handler; `wb_gam_cosmetics` + `wb_gam_user_cosmetics` tables
- [x] Official integration manifests — WooCommerce (purchase/review/wishlist), LearnDash, LifterLMS, The Events Calendar, MemberPress, GiveWP (donations), bbPress (topics/replies/resolved)
- [x] Weekly cohort leagues — CohortEngine.php: Bronze→Obsidian tiers; weekly assign (Mon 00:05 UTC) + process (Sun 23:00 UTC) crons; 33%/33% promote/demote; `wb_gamification_cohort_outcome` hook
- [x] End-of-period status challenge — StatusRetentionEngine.php: Thursday 18:00 UTC nudge; velocity-based threshold; BP notification + `wb_gamification_retention_nudge` hook
- [x] Full REST API rate limiting — RateLimiter.php: token bucket (60 capacity, 0.2 tokens/s refill); per-user via transients; `consume()` / `remaining()` / `reset()` API
- [x] Full test coverage — PHPUnit + Brain\Monkey test suite; phpunit.xml.dist; PointsEngineTest, RateLimiterTest, CohortEngineTest, RedemptionEngineTest; GDPR/Privacy also covered
- [x] Performance audit (query analysis, caching layer)

---

## Key Architectural Decisions

### Infrastructure Layer

1. **Event sourcing — the log IS the source of truth** — `wb_gam_events` stores immutable events; points/badges/levels are derived state. Change the rules, replay the log, state recalculates. GamiPress cannot do this — its log is its only copy of truth.

2. **Single entry point: `WB_Gam_Engine::process( WB_Gam_Event )`** — all award paths go through one method. Testable, interceptable, observable. Direct `PointsEngine::award()` calls are internal only.

3. **Rules as data, not code** — badge conditions and point multipliers live in `wb_gam_rules` (JSON). Admin UI edits them. REST API writes them. AI agents can configure the gamification behavior of a 10,000-member community without touching a PHP file. GamiPress cannot offer this without a full rewrite.

4. **Manifest auto-discovery** — any plugin drops `wb-gamification.php` in its directory and WB Gamification finds it at boot. Zero coupling, zero dependency. The integration ecosystem can grow without WB Gamification writing a single line of new code per integration.

5. **Outbound webhooks with HMAC signing** — every significant state change (points, badge, level) fires a signed webhook. Zapier, Make, n8n, any CRM, Firebase, OneSignal — all become consumers without any custom integration work.

6. **Rich metadata on every event** — `WB_Gam_Event->metadata` carries arbitrary context (word count, order total, reaction count). Future quality-weighted scoring and AI rule conditions depend on this data being captured from day one. Retrofitting metadata requires a migration; capturing it early costs nothing.

7. **Zero-dependency core** — `src/Engine/` has no `require` for BuddyPress, WooCommerce, or any theme. BuddyPress is an integration. This means the engine is testable in isolation and survives any ecosystem shift.

### Data Layer

8. **Custom DB tables, never post meta** — points ledger can hit millions of rows; meta degrades at scale
9. **Composite indexes on (user_id, created_at)** — most common query pattern; single-column indexes cause full table scans at 100k+ rows (the GamiPress 142-second query disaster)
10. **Auto-pruning from day one** — `LogPruner` via WP-Cron; configurable retention; 5000-row batches prevent long locks

### Integration Layer

11. **Hook on `bp_loaded`, not `init`** — fixes myCred's fundamental BuddyPress init-order conflict by design
12. **Mode auto-detection, never config** — WP-native hooks always load; BuddyPress hooks load only when BP is active; no "mode" toggle for site owner to misconfigure
13. **Split always/standalone trigger groups** — prevents double-awarding when BuddyPress and WordPress cover the same underlying event (e.g. `publish_post`)

### Product Layer

14. **Interactivity API, not custom React** — WordPress-native, no framework overhead, SSR-compatible
15. **Member opt-out everywhere** — competitive mechanics always optional; never forced on members
16. **Setup wizard on first activation** — not a blank admin; reduces abandonment (GamiPress's #1 complaint)
17. **No points decay** — research across all domains confirms expiring points destroy long-term loyalty with no measurable engagement benefit
18. **Prepared statements everywhere** — CVE-2024-13496 (GamiPress SQL injection in AJAX log endpoint) happened because one endpoint wasn't prepared; never repeat
