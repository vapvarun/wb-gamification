# `plans/` — human-authored design docs and roadmaps

> **Owner:** humans + work-execution agents (wp-builder, wp-fixer). Hand-authored. Bound to specific releases or sprints.

## What goes here vs elsewhere

| Type of document | Goes in |
|---|---|
| Release plans, sprint specs, design docs, roadmaps | `plans/` (this folder) |
| Machine-generated inventory, audit reports | `audit/` |
| Customer-facing documentation | `docs/website/` |
| Integration code samples for third parties | `examples/` |

This folder must **not** contain machine-generated artefacts. The wp-plugin-onboard skill specifically enforces this — its outputs go to `audit/`, never `plans/`.

## Status (2026-04-13)

v1.0.0 shipped. All 45 tasks from `v1-master-plan.md` complete. Subsequent work tracks against `INTEGRATION-GAPS-ROADMAP.md` and the per-PR commit log.

| Phase | Status |
|-------|--------|
| Phase 0: REST & AI Foundation | **DONE** |
| Phase 1: Core Cleanup & Scalability | **DONE** |
| Phase 2: Premium Admin UX | **DONE** |
| Phase 2.5: Frontend UX + Hub Page | **DONE** |
| Phase 2.75: Developer Platform | **DONE** |
| Phase 3: All Free (no pro split) | **DONE** |
| Phase 4: Build & Release | **DONE** |
| **Post-v1.0.0 audit + integration polish** (2026-05-02 campaign) | **DONE** — 11 PRs merged. See [`audit/CLOSE-OUT-2026-05-02.md`](../audit/CLOSE-OUT-2026-05-02.md). |

## Documents in this folder

| File | Status | Purpose |
|------|---|------|
| [`v1-master-plan.md`](v1-master-plan.md) | Historical | All 45 tasks across all phases — preserved as reference. |
| [`PRODUCT-VISION.md`](PRODUCT-VISION.md) | Active | Product philosophy, architecture rationale, competitive analysis. |
| [`frontend-hub-flow-spec.md`](frontend-hub-flow-spec.md) | Shipped | Hub page design spec (now live). |
| [`QA-CHECKLIST.md`](QA-CHECKLIST.md) | Active | 200+ pre-release QA checkpoints. Used per release. |
| [`PRE-RELEASE-CHECKLIST.md`](PRE-RELEASE-CHECKLIST.md) | Active | Build + release steps. |
| [`2026-04-12-hub-page-implementation.md`](2026-04-12-hub-page-implementation.md) | Historical | Implementation plan for the Hub page (shipped). |
| [`INTEGRATION-GAPS-ROADMAP.md`](INTEGRATION-GAPS-ROADMAP.md) | **Active** | 7 known gaps in the third-party integration story, with severity + workaround + scoping. The current "what's next" backlog. |

## What's next (Post-v1.0.0)

Most v1 follow-ups are done. Open backlog (in priority order):

1. **G2 — pluggable email templates** (½ day, broad customer benefit). See `INTEGRATION-GAPS-ROADMAP.md`.
2. **G1 — block extension slots** (~2-3 days, unlocks partner integrations).
3. **G4 — event replay CLI** (~1 day, support quality-of-life).
4. **Default badges** — ship a curated set of badge definitions with the plugin.
5. **1.1.0 planning** — advanced kudos, dark mode, multisite support.

Anything not listed above is either done or explicitly deferred — see `INTEGRATION-GAPS-ROADMAP.md` for the full list of known gaps with rationale.
