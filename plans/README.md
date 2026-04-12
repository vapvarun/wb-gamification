# WB Gamification — Plans & Specs

> **Single source of truth.** All plans, specs, and checklists live here. No duplicates elsewhere.

## Status Overview (Updated 2026-04-12)

| Phase | Status | Plan |
|-------|--------|------|
| Phase 0: REST & AI Foundation | **DONE** | [v1-master-plan.md](v1-master-plan.md) |
| Phase 1: Core Cleanup & Scalability | **DONE** | [v1-master-plan.md](v1-master-plan.md) |
| Phase 2: Premium Admin UX | **DONE** | [v1-master-plan.md](v1-master-plan.md) |
| Phase 2 extras: BadgeShare + RankAutomation | **DONE** | [phase2-completion-plan.md](phase2-completion-plan.md) |
| First-Run UX Polish | **5/6 done** (skip button text pending) | [first-run-ux-polish-spec.md](first-run-ux-polish-spec.md) |
| **Phase 2.5: Frontend UX Audit** | **NEXT** | [v1-master-plan.md](v1-master-plan.md) Tasks 25-30 |
| **Frontend Hub & Connected Flow** | **NEXT** | [frontend-hub-flow-spec.md](frontend-hub-flow-spec.md) |
| Phase 3: Pro Plugin Scaffold | Pending | [v1-master-plan.md](v1-master-plan.md) |
| Phase 4: Build & Release | Pending | [v1-master-plan.md](v1-master-plan.md) |

---

## Documents

### The Master Plan

| Document | What it covers |
|----------|---------------|
| [v1-master-plan.md](v1-master-plan.md) | **Start here.** All phases 0-4, 37 tasks, execution order, what ships in 1.0.0 |

### Active Specs (work to do)

| Document | What it covers |
|----------|---------------|
| [frontend-hub-flow-spec.md](frontend-hub-flow-spec.md) | Hub page, card grid, slide-in panels, smart nudge engine, auto-page creation, color system |
| [first-run-ux-polish-spec.md](first-run-ux-polish-spec.md) | Setup wizard skip text (only remaining item) |

### Reference (completed work, kept for context)

| Document | What it covers |
|----------|---------------|
| [PRODUCT-VISION.md](PRODUCT-VISION.md) | Product vision, architecture philosophy, competitive analysis, free/pro split |
| [ADMIN-DESIGN-SYSTEM.md](ADMIN-DESIGN-SYSTEM.md) | Wbcom shared admin UX — sidebar + card layout pattern |
| [QA-CHECKLIST.md](QA-CHECKLIST.md) | 200+ checkpoint QA checklist for free + pro |
| [100k-scalability-cleanup-plan.md](100k-scalability-cleanup-plan.md) | Scalability work (completed in Phase 1) |
| [phase2-completion-plan.md](phase2-completion-plan.md) | BadgeShare + RankAutomation (completed) |
| [universal-engine-audit-spec.md](universal-engine-audit-spec.md) | Engine audit spec (completed) |

---

## What's Next (in order)

1. **Frontend Hub & Connected Flow** — build the hub page from [frontend-hub-flow-spec.md](frontend-hub-flow-spec.md)
2. **Frontend UX Audit** — Tasks 25-30 in master plan (modals, mobile 390px, empty states, interactivity, admin consistency)
3. **First-run skip button text** — last item from [first-run-ux-polish-spec.md](first-run-ux-polish-spec.md)
4. **Phase 3: Pro Plugin Scaffold** — split pro engines to separate plugin
5. **Phase 4: Build & Release** — Grunt, EDD SDK, version bump, zip

---

## Free vs Pro Scope

### Free Plugin (wb-gamification)

| Feature | Status |
|---------|--------|
| Points engine (event-sourced, 30+ actions) | Done |
| Badge system (30 default + custom, auto-award conditions) | Done |
| Level progression (5 default levels, configurable) | Done |
| Leaderboard (all/month/week/day, group scope, snapshot cache) | Done |
| Challenges (individual, time-bound, bonus points) | Done |
| Streaks (daily tracking, grace period, milestones) | Done |
| Peer kudos (daily limits, receiver + giver points) | Done |
| 11 Gutenberg blocks + 11 shortcodes | Done |
| REST API (38 endpoints, 16 controllers) | Done |
| WP Abilities API (12 abilities) | Done |
| BuddyPress integration (profiles, directory, activity feed) | Done |
| 9 integration manifests (BP, bbPress, WC, LD, LLMS, MP, GiveWP, TEC) | Done |
| Setup wizard (5 templates) | Done |
| Admin settings (sidebar + card layout) | Done |
| Analytics dashboard | Done |
| WP-CLI commands (6 commands incl. doctor) | Done |
| Toast notifications (Interactivity API + REST polling) | Done |
| Privacy (GDPR export/erasure) | Done |
| Rank automation rules | Done |
| **Gamification Hub page** | **Next** |

### Pro Plugin (wb-gamification-pro)

| Feature | Flag Key | Status |
|---------|----------|--------|
| Cohort leagues | `cohort_leagues` | Done (in free, moves to pro in Phase 3) |
| Community challenges | `community_challenges` | Done |
| Weekly recap emails | `weekly_emails` | Done |
| Leaderboard nudge emails | `leaderboard_nudge` | Done |
| Cosmetics / profile frames | `cosmetics` | Done |
| Tenure badges | `tenure_badges` | Done |
| Site-first badges | `site_first_badges` | Done |
| Status retention engine | `status_retention` | Done |
| Badge share pages (OG, LinkedIn, OpenBadges 3.0) | `badge_share` | Done |
| Redemption store | N/A | Done |
| Outbound webhooks | N/A | Done |
| API key authentication | N/A | Done |
| EDD license management | N/A | Done |
