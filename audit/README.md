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
├── CLOSE-OUT-2026-05-02.md        ← single-page summary of the audit/fix campaign
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
└── wppqa-runs/                    ← All wppqa MCP audit runs, dated
    ├── 2026-05-02-baseline/       ← First run — partial 3-tool baseline
    ├── 2026-05-02-full/           ← Second run — full audit + triage
    └── 2026-05-02-final-REPORT.md ← Verbatim final audit (post-fix)
```

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

- **Release plans, sprint specs, design docs** → `plans/`
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
