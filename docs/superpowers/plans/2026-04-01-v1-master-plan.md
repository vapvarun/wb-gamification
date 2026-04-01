# WB Gamification v1.0.0 — Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship wb-gamification 1.0.0 as a stable, scalable, API-first gamification engine for 100K+ member communities. Works as local plugin AND standalone gamification center for cross-site usage. Free + Pro split. REST-ready for mobile apps, AI agents, and headless frontends.

**Architecture:** Event-sourced core (free) with lazy-loaded Pro engines behind license check. API key authentication for cross-site mode. WP Abilities API registration for AI agent discovery. Full REST schemas on all endpoints. Enhanced WordPress native admin UX. Async pipeline for non-critical listeners. Leaderboard snapshot cache.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, Action Scheduler, WP Object Cache API, WP Interactivity API, WP Abilities API

---

## Phase 0: REST API & AI Integration Foundation (MUST DO FIRST)

### Task 0.1: API Key Authentication System [DONE - in progress]

**Goal:** Enable cross-site usage — remote WordPress sites authenticate via API key instead of cookie/nonce.

**Files:**
- Create: `src/API/ApiKeyAuth.php`

**Two auth modes:**
- **Local mode:** Standard WordPress cookie/nonce (current behavior, unchanged)
- **Remote mode:** `X-WB-Gam-Key` header or `?api_key` query param

**API Key features:**
- Generate keys with label, associated user_id, site_id, permissions (read/write)
- Revoke/delete keys
- Track last_used timestamp
- Site ID flows into event metadata for attribution
- `$GLOBALS['wb_gam_remote_site_id']` set during request for cross-site tracking

### Task 0.2: Capabilities Discovery Endpoint [DONE - in progress]

**Goal:** Mobile apps, AI agents, and remote sites can discover what the API offers.

**Files:**
- Create: `src/API/CapabilitiesController.php`

**Endpoint:** `GET /wb-gamification/v1/capabilities`

**Response shape:**
```json
{
  "authenticated": true,
  "user_id": 42,
  "site_id": "mediaverse-prod",
  "mode": "remote",
  "can": {
    "read_leaderboard": true,
    "read_badges": true,
    "award_points": false,
    "manage_badges": false,
    "submit_events": true,
    "give_kudos": true
  },
  "features": { "cohort_leagues": true, "redemption_store": true },
  "version": "1.0.0",
  "endpoints": { "members": "...", "leaderboard": "...", "badges": "..." }
}
```

### Task 0.3: REST Schema Completeness

**Goal:** All 14 REST controllers have proper `get_item_schema()` for OpenAPI/Swagger discovery.

**Files missing schema:**
- `src/API/ActionsController.php`
- `src/API/BadgeShareController.php`
- `src/API/CredentialController.php`
- `src/API/EventsController.php`
- `src/API/PointsController.php`
- `src/API/RecapController.php`

Each controller gets a `get_item_schema()` method describing the response shape in JSON Schema format.

### Task 0.4: WP Abilities API Registration

**Goal:** Register all gamification capabilities with the WordPress Abilities API so AI agents (Claude, GPT, Gemini) can discover and use the gamification system programmatically.

**Files:**
- Create: `src/API/AbilitiesRegistration.php`

**Abilities to register:**
```php
[
    // Read abilities
    'wb-gamification.read-leaderboard' => [
        'label'       => 'Read gamification leaderboard',
        'description' => 'Retrieve ranked member lists by points for any period',
        'endpoint'    => '/wb-gamification/v1/leaderboard',
        'methods'     => ['GET'],
    ],
    'wb-gamification.read-member-profile' => [
        'label'       => 'Read member gamification profile',
        'description' => 'Get points, level, badges, streak for a member',
        'endpoint'    => '/wb-gamification/v1/members/{id}',
        'methods'     => ['GET'],
    ],
    'wb-gamification.read-badges' => [
        'label'       => 'List available badges',
        'description' => 'Get all badge definitions and their award criteria',
        'endpoint'    => '/wb-gamification/v1/badges',
        'methods'     => ['GET'],
    ],
    'wb-gamification.read-challenges' => [
        'label'       => 'List active challenges',
        'description' => 'Get current challenges with progress and deadlines',
        'endpoint'    => '/wb-gamification/v1/challenges',
        'methods'     => ['GET'],
    ],
    'wb-gamification.read-actions' => [
        'label'       => 'List registered gamification actions',
        'description' => 'Enumerate all point-earning actions with their values',
        'endpoint'    => '/wb-gamification/v1/actions',
        'methods'     => ['GET'],
    ],
    // Write abilities
    'wb-gamification.award-points' => [
        'label'       => 'Award points to a member',
        'description' => 'Manually award gamification points with reason',
        'endpoint'    => '/wb-gamification/v1/events',
        'methods'     => ['POST'],
        'requires'    => 'manage_options',
    ],
    'wb-gamification.submit-event' => [
        'label'       => 'Submit a gamification event',
        'description' => 'Report a user action for point evaluation',
        'endpoint'    => '/wb-gamification/v1/events',
        'methods'     => ['POST'],
    ],
    'wb-gamification.give-kudos' => [
        'label'       => 'Give kudos to another member',
        'description' => 'Send peer recognition with a message',
        'endpoint'    => '/wb-gamification/v1/kudos',
        'methods'     => ['POST'],
    ],
    'wb-gamification.redeem-points' => [
        'label'       => 'Redeem points for rewards',
        'description' => 'Spend accumulated points on reward catalog items',
        'endpoint'    => '/wb-gamification/v1/redemption/redeem',
        'methods'     => ['POST'],
    ],
    // Admin abilities
    'wb-gamification.manage-badges' => [
        'label'       => 'Create and manage badges',
        'description' => 'Full CRUD on badge definitions and award rules',
        'endpoint'    => '/wb-gamification/v1/badges',
        'methods'     => ['GET', 'POST', 'PUT', 'DELETE'],
        'requires'    => 'manage_options',
    ],
    'wb-gamification.manage-api-keys' => [
        'label'       => 'Manage API keys for cross-site access',
        'description' => 'Create, revoke, and list API keys for remote sites',
        'endpoint'    => '/wb-gamification/v1/api-keys',
        'methods'     => ['GET', 'POST', 'DELETE'],
        'requires'    => 'manage_options',
    ],
]
```

**Registration approach:**
- Check `function_exists('wp_register_ability')` for WP 6.9+
- Fallback: register as a REST endpoint `/wb-gamification/v1/abilities` that returns the same data
- This ensures AI agents can discover capabilities on ANY WordPress version

### Task 0.5: API Keys Admin Page

**Goal:** Admin UI for managing API keys (create, view, revoke, delete).

**Files:**
- Create: `src/Admin/ApiKeysPage.php`

**UI:**
- Submenu "API Keys" under Gamification
- List of keys: label, site_id, created date, last used, status (active/revoked)
- "Generate New Key" form: label, site_id, user to associate
- Key shown ONCE on creation (like GitHub tokens)
- Revoke/delete buttons per key

### Task 0.6: CORS Support for Cross-Origin Requests

**Files:**
- Modify: `src/API/ApiKeyAuth.php`

**Add CORS headers when API key auth is used:**
```php
add_action( 'rest_api_init', function() {
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', function( $value ) {
        $origin = get_http_origin();
        if ( $origin ) {
            header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: X-WB-Gam-Key, Content-Type, Authorization' );
        }
        return $value;
    } );
} );
```

### Task 0.7: Site ID on Events Table

**Files:**
- Modify: `src/Engine/Installer.php` — add `site_id VARCHAR(100) DEFAULT ''` to `wb_gam_events`
- Modify: `src/Engine/DbUpgrader.php` — migration to ALTER TABLE add column
- Modify: `src/Engine/Engine.php` — persist site_id from event metadata into the column

This enables querying "show me all events from mediaverse-prod" or "leaderboard for jetonomy-site only".

---

## Phase 1: Core Cleanup & Scalability (Free Plugin) [COMPLETED]

### Task 1: Free/Pro Engine Split + Feature Flags [DONE]
### Task 2: Dead Code Removal [DONE]
### Task 3: Integration Cleanup [DONE]
### Task 4: Async Award Pipeline [DONE]
### Task 5: Leaderboard Snapshot Cache [DONE]
### Task 6: Hot-Path Query Caching [DONE]
### Task 7: PersonalRecordEngine Single Query [DONE]
### Task 8: Events Table Pruning [DONE]
### Task 9: Conditional Asset Loading [DONE]
### Task 10: Complete Public API [DONE]
### Task 11: Action ID Collision Guard [DONE]
### Task 12: Critical Bug Fixes [DONE]
### Task 13: Cron Consolidation [DONE]

---

## Phase 2: Premium Admin UX (Free Plugin)

### Task 14: Admin CSS Design System

**Goal:** Notion-inspired admin UX — cards, toggles, whitespace, system fonts. Shared across all Wbcom plugins.

**Files:**
- Create: `assets/css/admin-premium.css`
- Modify: `wb-gamification.php::enqueue_admin_assets()`

Design tokens:
- Cards: white bg, 1px border #e0e0e0, 8px radius, 24px padding, subtle shadow on hover
- Toggles: iOS-style switch replacing all checkboxes
- Status pills: rounded, color-coded (green=active, amber=pending, red=inactive, blue=info)
- Typography: -apple-system, system-ui. 14px base. 600 weight for headings.
- Spacing: 24px section gaps, 16px inner gaps
- Toasts: fixed bottom-right, slide-in animation, auto-dismiss 4s
- RTL: all layout uses logical properties (margin-inline-start, padding-inline-end)

### Task 15: Dashboard Page (Redesign)

**Goal:** Replace current AnalyticsDashboard with a clean KPI dashboard.

Layout:
- Top row: 4 KPI cards (Total Points Awarded, Active Members, Badges Earned, Challenges Completed) with sparkline trend
- Second row: 2 cards — "Recent Activity" feed (last 10 events) + "Quick Actions" (Award Points, Create Challenge, View Leaderboard)
- Third row: "Top Members This Week" mini-leaderboard (5 rows)
- Period selector: 7d / 30d / 90d / All
- If standalone center mode: show "Connected Sites" card with site_id list and event counts

### Task 16: Settings Page (Redesign)

**Goal:** Card-based settings with toggle switches, inline descriptions, AJAX save.

Tabs: Points | Levels | Features | Integrations | API Keys

- Points tab: card per action category, toggle + point value per action
- Levels tab: sortable level cards with name, threshold, icon preview
- Features tab: toggle grid for all optional engines (from FeatureFlags)
- Integrations tab: status cards per integration (active/inactive based on plugin detection)
- API Keys tab: key management (from Task 0.5)
- AJAX save + toast notification

### Task 17: Badge Library (Redesign)

**Goal:** Grid of badge cards with built-in icon picker.

- Badge grid: cards showing icon, name, earned count, status pill
- Create/edit modal with: name, description, icon picker, auto-award conditions
- Icon picker: grid of 50+ built-in SVGs + "Upload Custom" option
- Auto-award conditions: simple dropdowns — "When user reaches [X] points" / "When user earns [action] [N] times"

### Task 18: Challenge Manager (New Admin Page)

**Goal:** Admins can create/manage challenges without code. Plug-and-play.

- List view: card per challenge with title, action, target, progress bar, status pill, dates
- Create form: Title, Action (dropdown), Target Count, Start Date, End Date, Bonus Points
- Smart defaults: start=now, end=+7days, target=10, bonus=50
- Status management: Active/Paused/Completed with one-click toggle

### Task 19: Toast Notification System

**Goal:** Instant feedback when users earn points, badges, or complete challenges.

- NotificationBridge stores pending toasts in user transient on award
- Frontend JS polls `/members/me/notifications` every 30s (or on page focus)
- Toast slides in from bottom-right: icon + message + point count, auto-dismiss 4s
- Badge earned: special toast with badge icon
- Challenge complete: "Challenge Complete! +[X] bonus points"

---

## Phase 3: Pro Plugin Scaffold

### Task 20: Create wb-gamification-pro Plugin

- Scaffold plugin with PSR-4 autoload under `WBGamPro\`
- Require wb-gamification (free) as dependency
- Hook into `wb_gam_engines_booted` from free plugin
- Move Pro engines: CohortEngine, RecapEngine, RedemptionEngine, CosmeticEngine, WeeklyEmailEngine, LeaderboardNudge, StatusRetentionEngine, CommunityChallengeEngine, SiteFirstBadgeEngine, TenureBadgeEngine, BadgeSharePage
- Move contrib integrations: memberpress, lifterlms, the-events-calendar, givewp
- EDD SDK with placeholder product ID

### Task 21: Pro Admin Pages

- Redemption Store admin: product-card layout, create reward items, set point costs, stock management
- Community Challenges admin: create group challenges, set targets, view progress
- Cohort Settings: enable/disable leagues, set tier names, promotion/demotion percentages
- All pages use the premium CSS design system from Task 14

---

## Phase 4: Build & Release

### Task 22: Grunt Build Pipeline (Both Plugins)

- RTL CSS generation, CSS/JS minification, .pot file generation
- Dist task: clean → copy → compress to versioned zip
- Free zip: `wb-gamification-1.0.0.zip`
- Pro zip: `wb-gamification-pro-1.0.0.zip`

### Task 23: EDD SDK Integration

- Free: preset key `wbcomfree...` pattern, auto-activate on first load
- Pro: EDD SL SDK with product ID (placeholder until EDD product created)

### Task 24: Version Bump + Final Checks

- Bump all version references to 1.0.0
- Run WPCS via MCP tool on both plugins
- Generate .pot files
- Build zips to Desktop
- Commit + push + tag v1.0.0

---

## Execution Order (Updated)

| Phase | Tasks | Status | Estimated |
|-------|-------|--------|-----------|
| Phase 0: REST & AI Foundation | Tasks 0.1-0.7 | In progress | 4-5 hours |
| Phase 1: Core Cleanup | Tasks 1-13 | **DONE** | ~8 hours |
| Phase 2: Premium UX | Tasks 14-19 | Pending | 6-8 hours |
| Phase 3: Pro Scaffold | Tasks 20-21 | Pending | 3-4 hours |
| Phase 4: Build & Release | Tasks 22-24 | Pending | 2 hours |
| **Total** | **31 tasks** | | **~24-28 hours** |

---

## Deployment Modes

### Mode 1: Local Plugin
```
WordPress site installs wb-gamification
  → Plugin hooks WordPress actions (publish_post, bp_activity, etc.)
  → Engine::process() awards points locally
  → Same DB, same site
```

### Mode 2: Standalone Gamification Center
```
Dedicated WordPress site runs wb-gamification
  → Remote sites authenticate with API keys (X-WB-Gam-Key header)
  → Remote sites POST events to /wb-gamification/v1/events
  → Center processes points, badges, leaderboards
  → Remote sites GET leaderboard/badges/member data from center
  → Events tagged with site_id for per-site reporting
```

### Mode 3: AI Agent Integration
```
AI agent discovers capabilities via:
  → WP Abilities API (WP 6.9+) or /wb-gamification/v1/abilities
  → Reads: leaderboard, member profiles, badges, challenges
  → Writes: award points, submit events, give kudos
  → Full JSON Schema on all endpoints for structured output
```

---

## What Ships in 1.0.0

### Free Plugin
- Points, Badges, Levels, Streaks, Leaderboard, Challenges, Kudos, PersonalRecord
- 4 integrations (BuddyPress, WooCommerce, LearnDash, bbPress)
- **API key authentication for cross-site usage**
- **Capabilities discovery endpoint for AI agents**
- **WP Abilities API registration**
- **Full REST schemas on all 15+ controllers**
- **CORS support for cross-origin requests**
- Premium admin UX (dashboard, settings, badge library, challenge manager, API keys)
- Toast notifications
- 10 blocks + 10 shortcodes
- Full REST API + WP-CLI
- Public SDK (12 functions)
- RTL support
- Object cache everywhere, async pipeline, leaderboard snapshots
- Scales to 100K members

### Pro Plugin
- Cohort leagues, Recap, Redemption store, Cosmetics
- Weekly emails, Leaderboard nudge, Status retention
- Community challenges, Site-first badges, Tenure badges, Badge share
- Extra integrations (MemberPress, LifterLMS, Events Calendar, GiveWP)
- Premium admin pages (redemption, community challenges, cohort settings)

### Deferred
- 1.1.0: Advanced kudos, contrib integration polish
- 1.2.0: Dark mode, Multi-site network support
