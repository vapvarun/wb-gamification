# WB Gamification — Plans & Scope

All product planning, specs, QA checklists, and design documents for both free and pro plugins.

## Product

| Document | Description |
|----------|-------------|
| [PRODUCT-VISION.md](PRODUCT-VISION.md) | Full product vision, architecture, competitive analysis, free/pro split, deployment modes, Reign Stack context |
| [ADMIN-DESIGN-SYSTEM.md](ADMIN-DESIGN-SYSTEM.md) | Wbcom shared admin UX guidelines — sidebar + card layout pattern used across all new plugins |

## Implementation Plans

| Document | Description |
|----------|-------------|
| [v1-master-plan.md](v1-master-plan.md) | v1.0.0 master plan — 24 tasks from feature flags to release ZIP |
| [100k-scalability-cleanup-plan.md](100k-scalability-cleanup-plan.md) | 100K member scalability: async pipeline, leaderboard cache, lazy-load engines, dead code removal |
| [phase2-completion-plan.md](phase2-completion-plan.md) | Phase 2 completion: badges, leaderboard, kudos, badge share, rank automation, credential expiry |

## Design Specs

| Document | Description |
|----------|-------------|
| [universal-engine-audit-spec.md](universal-engine-audit-spec.md) | Universal engine audit: lazy-load, public API, async pipeline, admin UI, cron consolidation |
| [first-run-ux-polish-spec.md](first-run-ux-polish-spec.md) | First-run UX polish: welcome card, earning guide block, readme.txt, wizard improvements |

## Quality

| Document | Description |
|----------|-------------|
| [QA-CHECKLIST.md](QA-CHECKLIST.md) | 200+ checkpoint QA checklist covering free + pro features across 16 sections |

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
| Log pruning (configurable retention) | Done |
| Rank automation rules | Done |

### Pro Plugin (wb-gamification-pro)

| Feature | Flag Key | Status |
|---------|----------|--------|
| Cohort leagues (Duolingo-style weekly competition) | `cohort_leagues` | Done |
| Community challenges (team goals, global progress) | `community_challenges` | Done |
| Weekly recap emails | `weekly_emails` | Done |
| Leaderboard nudge emails | `leaderboard_nudge` | Done |
| Cosmetics / profile frames | `cosmetics` | Done |
| Tenure badges (anniversary milestones) | `tenure_badges` | Done |
| Site-first badges (first user to do X) | `site_first_badges` | Done |
| Status retention engine | `status_retention` | Done |
| Badge share pages (OG, LinkedIn, OpenBadges 3.0) | `badge_share` | Done |
| Redemption store (spend points on rewards) | N/A (controller) | Done |
| Outbound webhooks (HMAC-signed, Zapier/Make/n8n) | N/A (controller) | Done |
| API key authentication (cross-site gamification center) | N/A (ApiKeyAuth) | Done |
| EDD license management | N/A (LicenseManager) | Done |

### Integration Manifests (Auto-detected)

| Plugin | Manifest Location | Actions | Free/Pro |
|--------|-------------------|---------|----------|
| WordPress Core | integrations/wordpress.php | 8 actions | Free |
| BuddyPress | integrations/buddypress.php | 10 actions | Free |
| bbPress | integrations/bbpress.php | 3 actions | Free |
| WooCommerce | integrations/woocommerce.php | 4 actions | Free |
| LearnDash | integrations/learndash.php | 5 actions | Free |
| LifterLMS | integrations/contrib/lifterlms.php | 5 actions | Free |
| MemberPress | integrations/contrib/memberpress.php | 3 actions | Free |
| GiveWP | integrations/contrib/givewp.php | 4 actions | Free |
| The Events Calendar | integrations/contrib/the-events-calendar.php | 3 actions | Free |
| WPMediaVerse Pro | wpmediaverse-pro/wb-gamification.php | 17 actions | Pro (MVS) |

**Total: 62 gamification actions** across 10 integration manifests.

## CLI Readiness Check

Run `wp wb-gamification doctor --verbose` before any release. It validates:
- 20 database tables
- Default levels and badges
- Action registrations and duplicate hook detection
- Kudos option alignment
- Feature flags vs pro addon status
- REST API routes
- Cron jobs
- Integration detection
- Market readiness (readme, .pot, blocks, shortcodes, minified assets)
