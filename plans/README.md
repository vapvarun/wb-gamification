# WB Gamification — Plans

> **Single source of truth.** All plans and specs live here. No duplicates.

## Status (2026-04-12)

| Phase | Tasks | Status |
|-------|-------|--------|
| Phase 0: REST & AI Foundation | 0.1-0.7 | **DONE** |
| Phase 1: Core Cleanup & Scalability | 1-13 | **DONE** |
| Phase 2: Premium Admin UX | 14-19 | **DONE** |
| **Phase 2.5: Frontend UX + Hub Page** | **25-31** | **NEXT** |
| **Phase 2.75: Developer Platform** | **32-38** | **After 2.5** |
| Phase 3: Pro Plugin Scaffold | 20-21 | Pending |
| Phase 4: Build & Release | 22-24 | Pending |

## Documents

| File | Purpose |
|------|---------|
| [v1-master-plan.md](v1-master-plan.md) | **Start here.** 45 tasks, all phases. Source of truth. |
| [frontend-hub-flow-spec.md](frontend-hub-flow-spec.md) | Hub page design spec (Task 25). Card grid, panels, nudge engine. |
| [PRODUCT-VISION.md](PRODUCT-VISION.md) | Product philosophy, architecture, competitive analysis. |
| [QA-CHECKLIST.md](QA-CHECKLIST.md) | 200+ pre-release QA checkpoints. |

## What's Next (in order)

**Phase 2.5 — Frontend UX (Tasks 25-31):**
1. Hub page with connected flow, smart nudge, slide-in panels
2. Modal/overlay accessibility, mobile 390px audit
3. First-run UX, empty states, interactivity polish, admin consistency

**Phase 2.75 — Developer Platform (Tasks 32-38):**
4. Hook contract audit — stable, documented, versioned `wb_gam_` hooks
5. Manifest spec — formalize auto-discovery for third-party integrations
6. Public PHP API audit — `wb_gam_*` functions tested and documented
7. OpenAPI 3.0 spec export — machine-readable REST API for external consumers
8. Webhook system polish — retry logic, delivery log, Zapier/Make/n8n docs
9. JS SDK — `@wbcom/wb-gamification` npm package
10. Developer portal — 3-path guide (WP dev, theme dev, app dev)

**Then:** Pro scaffold (Phase 3) → Build & release 1.0.0 (Phase 4)
