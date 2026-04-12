# WB Gamification v1.0.0 — Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship wb-gamification 1.0.0 as a stable, scalable, API-first gamification engine for 100K+ member communities. Works as local plugin AND standalone gamification center for cross-site usage. Free + Pro split. REST-ready for mobile apps, AI agents, and headless frontends.

**Architecture:** Event-sourced core (free) with lazy-loaded Pro engines behind license check. API key authentication for cross-site mode. WP Abilities API registration for AI agent discovery. Full REST schemas on all endpoints. Enhanced WordPress native admin UX. Async pipeline for non-critical listeners. Leaderboard snapshot cache.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, Action Scheduler, WP Object Cache API, WP Interactivity API, WP Abilities API

---

## Phase 0: REST API & AI Integration Foundation [COMPLETED]

### Task 0.1: API Key Authentication System [DONE]

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

### Task 0.2: Capabilities Discovery Endpoint [DONE]

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

### Task 0.3: REST Schema Completeness [DONE]

**Goal:** All 14 REST controllers have proper `get_item_schema()` for OpenAPI/Swagger discovery.

All 16 controllers now have `get_item_schema()` — verified via grep.

### Task 0.4: WP Abilities API Registration [DONE]

`src/API/AbilitiesRegistration.php` — registers all gamification capabilities. Fallback REST endpoint `/wb-gamification/v1/abilities` for pre-6.9 WordPress.

### Task 0.5: API Keys Admin Page [DONE]

`src/Admin/ApiKeysPage.php` (9K) — submenu page for key management (create, view, revoke, delete).

### Task 0.6: CORS Support for Cross-Origin Requests [DONE]

CORS headers in `src/API/ApiKeyAuth.php` when API key auth is used.

### Task 0.7: Site ID on Events Table [DONE]

`site_id VARCHAR(100)` column + index on `wb_gam_events`. Installer and DbUpgrader updated.

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

## Phase 2: Premium Admin UX (Free Plugin) [COMPLETED]

### Task 14: Admin CSS Design System [DONE]
`assets/css/admin-premium.css` (1775 lines) — Notion-inspired cards, toggles, status pills, RTL logical properties, responsive breakpoints at 1024/640px.

### Task 15: Dashboard Page (Redesign) [DONE]
`src/Admin/AnalyticsDashboard.php` (16K) — KPI cards, period selector, activity feed, top members.

### Task 16: Settings Page (Redesign) [DONE]
`src/Admin/SettingsPage.php` (50K) — card-based, 5+ tabs including Points/Levels/Features/Integrations/Automation, rank automation rules UI, first-run welcome card.

### Task 17: Badge Library (Redesign) [DONE]
`src/Admin/BadgeAdminPage.php` (24K) — grid cards, wp.media() icon picker, earned counts, credential flag support.

### Task 18: Challenge Manager (New Admin Page) [DONE]
`src/Admin/ChallengeManagerPage.php` (14K) — create/manage challenges with card layout, status management.

### Task 19: Toast Notification System [DONE]
`assets/js/toast.js` (139 lines) — polls `/members/me/toasts` every 30s, 6 notification types (points/badge/challenge/level_up/streak/kudos), `aria-live="polite"`, auto-dismiss 4s.

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

## Phase 2.5: Frontend UX Audit & Polish

> Added 2026-04-12. Covers all user-facing screens — blocks, overlays, modals, admin pages.

### Task 25: Modal/Overlay Accessibility Fixes

**Problem:** Frontend overlay (.wb-gam-overlay) and admin modal (.wbgam-modal) lack ARIA attributes, ESC key handling, and focus traps.

**Fixes needed:**
- Frontend overlay: add `role="alertdialog"`, `aria-modal="true"`, ESC key dismiss handler, focus trap
- Admin modal CSS framework: add `role="dialog"`, `aria-modal="true"`, ESC handler, backdrop click close, focus trap
- Both: ensure `.wb-gam-overlay__dismiss` and `.wbgam-modal-close` have `aria-label`

**Files:** `assets/css/frontend.css`, `assets/interactivity/index.js`, `assets/css/admin-premium.css`

### Task 26: Mobile Responsiveness Audit (390px)

**Problem:** Frontend CSS has only a 480px breakpoint. Per CLAUDE.md rule, every UI change must be verified at 390px.

**Audit scope — verify each at 390px viewport:**
- [ ] All 11 block render outputs
- [ ] Leaderboard table (scrollable or card-based?)
- [ ] Badge showcase grid (single column?)
- [ ] Streak heatmap (readability?)
- [ ] Toast notifications (position, overlap?)
- [ ] Level-up overlay card (padding, width?)
- [ ] Year-recap stats grid
- [ ] Earning guide columns
- [ ] All admin pages at 390px (settings, badges, challenges, manual award, API keys, dashboard)

### Task 27: First-Run UX Completion

**From first-run-ux-polish-spec.md — remaining items:**
- [ ] Fix 1: Setup wizard skip button — add help text ("Default values are already set — you can always change them later")
- [ ] Fix 2: Dashboard welcome card — verify it renders for new installs (SettingsPage.php has code, needs browser test)

### Task 28: Empty States Audit

**Verify every block handles zero-data gracefully:**
- [ ] Leaderboard with 0 members
- [ ] Badge showcase with 0 badges earned
- [ ] Challenges with 0 active challenges
- [ ] Kudos feed with 0 kudos
- [ ] Points history with 0 transactions
- [ ] Streak with 0-day streak
- [ ] Top members with 0 data
- [ ] Year recap with no activity
- [ ] Earning guide with 0 enabled actions

### Task 29: Interactivity Polish

**Quick wins to improve UX feel:**
- [ ] Leaderboard period switching — add JS toggle via Interactivity API (currently server-only)
- [ ] Streak heatmap — add hover tooltips showing day + point count
- [ ] Color-blind safe rank indicators — add text labels alongside gold/silver/bronze colors
- [ ] Locked badges — show unlock condition text (not just greyed-out)

### Task 30: Admin Page Design Consistency Audit

**Ensure all admin pages match the Notion-inspired design system from admin-premium.css:**
- [ ] Dashboard (AnalyticsDashboard.php) — does it use wbgam- card pattern?
- [ ] Settings (SettingsPage.php) — card-based tabs, toggles vs checkboxes?
- [ ] Badge Library (BadgeAdminPage.php) — grid cards consistent?
- [ ] Challenge Manager (ChallengeManagerPage.php) — matches design system?
- [ ] Manual Award (ManualAwardPage.php) — card layout?
- [ ] API Keys (ApiKeysPage.php) — table + form pattern consistent?
- [ ] Setup Wizard (SetupWizard.php) — uses premium CSS?

---

## Execution Order (Updated 2026-04-12)

| Phase | Tasks | Status | Estimated |
|-------|-------|--------|-----------|
| Phase 0: REST & AI Foundation | Tasks 0.1-0.7 | **DONE** | ~5 hours |
| Phase 1: Core Cleanup | Tasks 1-13 | **DONE** | ~8 hours |
| Phase 2: Premium UX | Tasks 14-19 | **DONE** | ~7 hours |
| **Phase 2.5: Frontend UX Audit** | **Tasks 25-30** | **NEXT** | **4-6 hours** |
| Phase 3: Pro Scaffold | Tasks 20-21 | Pending | 3-4 hours |
| Phase 4: Build & Release | Tasks 22-24 | Pending | 2 hours |
| **Total** | **37 tasks** | | **~30-35 hours** |

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
- 11 blocks + 11 shortcodes (including earning-guide)
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
