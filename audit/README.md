# `audit/` — machine-generated inventory

> **Owner:** the `wp-plugin-onboard` skill (and only it). Refresh via `/wp-plugin-onboard --refresh` after non-trivial code changes. Hand-editing individual entries here is wrong — re-run the skill instead.

## What's in here

```
audit/
├── manifest.json                  ← canonical inventory (REST, hooks, tables, caps, ...)
├── manifest.summary.json          ← ≤3 KB index; loaded by CLAUDE.md READ-FIRST
├── FEATURE_AUDIT.md               ← human view of features
├── CODE_FLOWS.md                  ← traced pipelines (earn points, leaderboard read, ...)
├── ROLE_MATRIX.md                 ← per-route + per-page RBAC
├── graph.html                     ← interactive Cytoscape graph viewer
├── STABILITY-2026-05-27.md        ← active stability-gate doc (referenced by CLAUDE.md)
│
├── derived/                       ← Per-sub-check cache for 16 static-analysis findings
│
├── journeys/                      ← Customer-flow regression contracts
│   ├── README.md
│   ├── .template.md
│   ├── customer/
│   ├── admin/
│   └── security/
│
├── journey-runs/                  ← Per-execution evidence (gitignored)
│
├── wppqa-baseline-2026-05-27/     ← Latest wppqa baseline (failed=0)
│
├── action-async-baseline.txt      ← Stage 2.9 baseline (refresh via --update-baseline)
├── css-orphan-baseline.txt        ← Stage 2.8 baseline
├── plugin-check-warning-baseline.txt ← Stage 2.12 baseline
└── qa-coverage.json               ← Manifest-driven coverage state
```

> Older dated artefacts (CLOSE-OUT-2026-05-02.md, FEATURE-COMPLETENESS-2026-05-02.md, DATA-FLOW-*-2026-05-27.md, PERF-DIAG-2026-05-27.yaml, ISSUES-2026-05-27.yaml, CODE-FLOW-RED-FLAGS.md, SITE-OWNER-READINESS-AUDIT.md, CSS-USAGE-MAP-2026-05-27.json, wppqa-baseline-2026-05-{03,06,07}, release-runs/, wppqa-runs/) were removed on 2026-05-28 — every lesson they captured was either absorbed into the code, the manifest, or git history. Recover from `git log --diff-filter=D --follow audit/<path>` if needed.

## What each file is for

### Inventory (`manifest.json`, summaries, audit reports)

`manifest.json` is the canonical, machine-readable inventory. Every consumer (this file, `audit/FEATURE_AUDIT.md`, `audit/CODE_FLOWS.md`, `audit/ROLE_MATRIX.md`, `audit/graph.html`, the local-CI gate's manifest stage, future tooling) reads from `manifest.json`.

`manifest.summary.json` is a ≤3 KB version of the same — counts + index — loaded by Claude Code's `CLAUDE.md` auto-load on every session.

The three `.md` audit reports are human views of the same data, structured for reading rather than parsing.

`graph.html` is a Cytoscape-based interactive graph viewer. Open with:

```bash
cd audit && python3 -m http.server 8765
# then open http://localhost:8765/graph.html
```

### Journeys (`journeys/`, `journey-runs/`)

`journeys/` holds customer-flow regression contracts — each `.md` file is a self-executable contract that an agent runs against a live site. See `journeys/README.md` for the schema.

`journey-runs/` holds per-execution results (gitignored — runs are evidence, not source of truth).

### wppqa runs (`wppqa-runs/`)

Dated archive of every `wppqa_audit_plugin` execution. Each subfolder contains the raw audit report + a triaged SUMMARY.md explaining what's real vs heuristic noise. Useful for tracking score progression over time.

### CLOSE-OUT documents (`CLOSE-OUT-{date}.md`)

Single-page summary of an audit/fix campaign. Each campaign produces one of these. Future campaigns add their own dated close-out.

## Refresh policy

| Trigger | Action |
|---|---|
| 1-3 file edit in `src/` | Update `manifest.json` Recent Changes only (or skip — manifest stays valid for a while) |
| New REST endpoint / AJAX handler / admin page | `/wp-plugin-onboard --refresh` to update the affected category |
| 5+ structural changes | Full `/wp-plugin-onboard --refresh` |
| Major refactor, namespace renames, removed features | Full `/wp-plugin-onboard` from scratch |

## What goes elsewhere

- **Release plans, sprint specs, design docs** → `plan/`
- **Customer-facing documentation** → `docs/website/`
- **Integration code samples for third parties** → `examples/`
- **Per-run journey results** → `audit/journey-runs/` (gitignored)
- **Anything hand-curated** → not here. This folder is machine-output. Hand-edits get overwritten on the next refresh.

## How to verify

```bash
# Validate the manifest as JSON
jq . audit/manifest.json > /dev/null && echo "valid"

# Check freshness (older than 30 days = consider refresh)
jq -r '.generated.at' audit/manifest.json
```
