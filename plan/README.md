# `plan/` — human-authored design docs and roadmaps

> **Owner:** humans + work-execution agents (wp-builder, wp-fixer). Hand-authored. Bound to specific releases or sprints.

## What goes here vs elsewhere

| Type of document | Goes in |
|---|---|
| Release plans, sprint specs, design docs, roadmaps | `plan/` (this folder) |
| Machine-generated inventory, audit reports | `audit/` |
| Customer-facing documentation | `docs/website/` |
| Integration code samples for third parties | `examples/` |

This folder must **not** contain machine-generated artefacts. The wp-plugin-onboard skill enforces this — its outputs go to `audit/`, never `plan/`.

## Status (2026-05-06 — post-v1.0 onboarding sprint + audit campaign)

v1.0.0 is **release-candidate, inhouse-only**. Tagged commits are intentionally suppressed until QA wraps the verification cards on Basecamp (per the 2026-05-06 directive). The four critical v1.0 gaps have shipped, plus 7 onboarding gaps, the privacy-model rollout, and 14 code-flow audit flag closures.

| Phase | Status |
|---|---|
| Phase 0: REST + AI Foundation | DONE |
| Phase 1: Core cleanup + scalability | DONE |
| Phase 2 / 2.5 / 2.75: Premium admin UX, frontend, dev platform | DONE |
| Phase 3: All free (no pro split) | DONE |
| Phase 4: Build + release infra | DONE |
| **v1.0.0 critical gaps** (transactional emails, public profile pages, login bonus, UGC submissions) | DONE — see `v1.0-release-plan.md` |
| **First-time admin onboarding sprint** (success banner, welcome card, defaults, test event, member welcome toast) | DONE 2026-05-06 |
| **Privacy model rollout** (T1/T2/T3 tiers + helper) | DONE — see `PRIVACY-MODEL.md` + `audit/CODE-FLOW-RED-FLAGS.md` |
| **Code-flow audit fixes** (F1–F14, including API key hashing) | DONE — see `audit/CODE-FLOW-RED-FLAGS.md` |
| QA verification (Basecamp Ready-for-Testing column) | IN PROGRESS — gates the v1.0.0 git tag |

## Documents in this folder

### Active references — read these now

| File | Purpose |
|---|---|
| [`PRODUCT-VISION.md`](PRODUCT-VISION.md) | Product philosophy, why we exist, competitive framing. |
| [`TECH-STACK.md`](TECH-STACK.md) | Tech-stack rationale (PHP/JS/DB/AI/Mobile/Privacy) + 5-year roadmap + decision log. |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Layered architecture map. The "where does X live" reference. |
| [`PRIVACY-MODEL.md`](PRIVACY-MODEL.md) | **T1/T2/T3 data classification** + per-surface policy + role matrix. Every privacy decision cites this. |
| [`COMPETITIVE-ANALYSIS.md`](COMPETITIVE-ANALYSIS.md) | What competitors ship vs us. |
| [`QA-MANUAL-TEST-PLAN.md`](QA-MANUAL-TEST-PLAN.md) | **Primary QA document.** 6-persona walkthrough covering every surface. Use for every release. |
| [`PRE-RELEASE-CHECKLIST.md`](PRE-RELEASE-CHECKLIST.md) | Build + release steps. |

### Active backlogs — what's next

| File | Status |
|---|---|
| [`v1.1-release-plan.md`](v1.1-release-plan.md) | **Active.** v1.1 backlog (visual rule builder, web push, Slack/Discord, time-bound campaigns, referrals, etc). |
| [`INTEGRATION-GAPS-ROADMAP.md`](INTEGRATION-GAPS-ROADMAP.md) | Active. Known gaps in 3rd-party integration story; superseded in part by v1.0 sprint shipping the email pipeline + UGC. |
| [`UX-ADMIN-AUDIT-2026-05-03.md`](UX-ADMIN-AUDIT-2026-05-03.md) | Mostly closed — most gaps shipped in onboarding sprint. Re-walk before public release. |

### Shipped / historical references — don't expect to update

| File | Reason kept |
|---|---|
| [`v1-master-plan.md`](v1-master-plan.md) | Historical — all 45 phase tasks from v0.1 → v1.0 launch. Reference. |
| [`v1.0-release-plan.md`](v1.0-release-plan.md) | Shipped — 4 critical gaps closed; useful as scope record. |
| [`V1-RELEASE-VERIFICATION-PLAN.md`](V1-RELEASE-VERIFICATION-PLAN.md) | Shipped — release journeys ran 2026-05-03; preserved as walkthrough template. |
| [`MULTI-POINT-TYPES-PLAN.md`](MULTI-POINT-TYPES-PLAN.md) | Shipped 2026-05-06 — multi-currency end-to-end. Reference. |
| [`ARCHITECTURE-DRIVEN-PLAN.md`](ARCHITECTURE-DRIVEN-PLAN.md) | Historical — early architectural blueprint that drove Phase 0–4. |
| [`CODEBASE-AUDIT-2026-05-06.md`](CODEBASE-AUDIT-2026-05-06.md) | Historical — used to revise v1.0 cards once we discovered most of the engine was already built. |
| [`frontend-hub-flow-spec.md`](frontend-hub-flow-spec.md) | Shipped — Hub block + auto-page. Reference. |
| [`2026-04-12-hub-page-implementation.md`](2026-04-12-hub-page-implementation.md) | Shipped — implementation record for the Hub page. |
| [`UX-TOKEN-MIGRATION.md`](UX-TOKEN-MIGRATION.md) | Shipped — `--wb-gam-*` design-token migration record. |
| [`ux-admin-2026-05-03/`](ux-admin-2026-05-03/) | Screenshot evidence for the 2026-05-03 admin UX audit. |
| [`WBCOM-BLOCK-STANDARD-MIGRATION.md`](WBCOM-BLOCK-STANDARD-MIGRATION.md) | Shipped — all 17 blocks meet the standard; CI gate live. |
| [`QA-CHECKLIST.md`](QA-CHECKLIST.md) | Archived (v1.0). Sections 5–16 still useful as a complement to the active `QA-MANUAL-TEST-PLAN.md`. |

## Cross-cutting docs in `audit/` worth knowing

`audit/` is machine-generated except for these human-readable companions that are referenced from `plan/` and from CLAUDE.md:

- [`audit/CODE-FLOW-RED-FLAGS.md`](../audit/CODE-FLOW-RED-FLAGS.md) — 14-flag code-flow audit; all closed in commit `4a0ed2c`.
- [`audit/SITE-OWNER-READINESS-AUDIT.md`](../audit/SITE-OWNER-READINESS-AUDIT.md) — fresh-eyes site-owner readiness audit (2026-05-06).
- [`audit/CLOSE-OUT-2026-05-02.md`](../audit/CLOSE-OUT-2026-05-02.md) — pre-v1.0 audit-fix campaign close-out.
- [`audit/FEATURE-COMPLETENESS-2026-05-02.md`](../audit/FEATURE-COMPLETENESS-2026-05-02.md) — pre-v1.0 feature × surface matrix.

## What's next (post-2026-05-06)

QA verification of the Ready-for-Testing cards on Basecamp gates the v1.0.0 tag. After QA wraps:

1. Re-tag `v1.0.0` at the verified commit.
2. Public-release prep — the P0 doc cleanup from `audit/SITE-OWNER-READINESS-AUDIT.md` (drop phantom "Mission Mode" / "Cosmetics" / "Elementor" / "ACF" claims; surface the 4 contrib integrations).
3. Begin v1.1 cycle — `v1.1-release-plan.md` is the seed.

Anything not listed above is either done or explicitly deferred.
