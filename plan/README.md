# `plan/` — Evergreen design + the single roadmap

> **Owner:** humans. Machine-generated artefacts go to `audit/`. Customer-facing docs go to `docs/website/`. Integration samples go to `examples/`.

## What's here (5 files, all evergreen)

| File | Purpose |
|---|---|
| [`MASTER-CHECKLIST.md`](MASTER-CHECKLIST.md) | **Single roadmap.** 100-item check of what's shipped vs pending. Updated after every wave of work. Replaces all dated release-plan / bug-sweep / migration / audit markdown files that previously lived here. |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Layered architecture + design rationale. Not version-stamped — updated when architecture changes. |
| [`PRODUCT-VISION.md`](PRODUCT-VISION.md) | Strategic positioning, target personas, competitive landscape. Hand-edited rarely. |
| [`TECH-STACK.md`](TECH-STACK.md) | Stack decisions (PHP / JS / DB / build / hosting). Updated when a stack choice changes. |

## What's NOT here anymore

Dated artefacts (release plans, bug-sweep specs, UX audits, migration plans, version-specific roadmaps) were folded into `MASTER-CHECKLIST.md` on 2026-05-28. The accumulated drift made it impossible to tell which plan was current. The single checklist + per-commit story in `git log` is the new source of truth.

If you need the historical detail for a specific shipped item:
- Per-commit context → `git log --oneline` + commit messages
- Per-feature inventory → `audit/FEATURE_AUDIT.md`
- Per-route shape → `audit/manifest.json`
- Recovered specifics → `git log --diff-filter=D --follow plan/<path>` finds the deletion commit

## Pre-commit rule

Don't add new dated planning docs here. If the work is short-term (one wave), it belongs in commit messages + the MASTER-CHECKLIST. If it's long-lived strategy or architecture, update the evergreen docs in place.
