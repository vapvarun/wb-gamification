# WB Gamification — QA Layer Map

This directory holds the **agent-executable** QA layer for pre-release verification. It sits on top of pre-existing QA infrastructure rather than replacing it. Read this file first to understand which document does what.

## The four QA layers (lowest → highest)

```
Layer 1  Static / unit gates    (composer ci, WPCS, PHPStan, PHPUnit, coding-rules)
Layer 2  Journey contracts      (audit/journeys/**/*.md — deterministic, executable)
Layer 3  Agent smoke runbook    (docs/qa/AGENT_SMOKE_RUNBOOK.md — this dir)
Layer 4  Human walkthrough      (plan/QA-MANUAL-TEST-PLAN.md — 6-persona pass)
```

Every layer is independently runnable. A release green-lights only when ALL four are green.

| Layer | Owner | Runs how | Authoritative file |
|---|---|---|---|
| 1 | dev | `composer ci` (auto, every push) | `bin/local-ci.sh` |
| 2 | journey-aware agent | `bash bin/run-journeys.sh` | `audit/journeys/**/*.md` |
| 3 | Sonnet via generic `wp-plugin-smoke` skill | dispatched by Opus pre-release; reads `docs/qa/qa-config.json` for plugin variables | `docs/qa/AGENT_SMOKE_RUNBOOK.md` |
| 4 | human QA tester | walked once per release | `plan/QA-MANUAL-TEST-PLAN.md` |

### QA config file

The `wp-plugin-smoke` skill reads **`docs/qa/qa-config.json`** (hyphen, v1 schema: `slug`, `name`, `version_constant`, `main_file`, `base_url`, `wp_path`, `autologin_param`, `rest_namespace`, `front_routes`, `pair`, `basecamp`, `modes`, `report_path`, `supplements_path`). It also carries the legacy operational fields (`personas`, `integrations`, `fixture_cleanup_sql`, `debug_log_whitelist`, `outputs`) as extension keys so the runbook and build pipeline keep working from one file.

- **Single mode, no Pro.** wb-gamification ships as one free plugin - `modes` is `["single"]` and `pair.pro_slug` is `null` / `pair.lockstep` is `false`. There is no free/pro combo walk.
- The older **`docs/qa/qa.config.json`** (dot name, pre-v1 schema) is **kept in place** because `bin/build-release.sh`, `composer.json` (`smoke` alias), and `CHANGELOG.md` still reference it by name. Both files exist until those references migrate to the hyphen name; `qa-config.json` is the source of truth for the smoke skill.

## Where each Jetonomy QA concept lives in this plugin

The Jetonomy QA model defines a vocabulary every Wbcom plugin should share. Here's how each Jetonomy concept maps onto WB Gamification's existing + added artefacts:

| Jetonomy concept | WB Gamification equivalent |
|---|---|
| `docs/qa/AGENT_SMOKE_RUNBOOK.md` (Sections A–G) | `docs/qa/AGENT_SMOKE_RUNBOOK.md` ← this dir |
| `docs/qa/PRE_RELEASE_SMOKE.md` (90-min human walk) | `plan/QA-MANUAL-TEST-PLAN.md` (6-persona walk) |
| `docs/qa/UX_AUDIT.md` (per-template surface check) | `plan/UX-ADMIN-AUDIT-2026-05-03.md` + Tier-7 a11y journey |
| `docs/qa/QA_RELEASE_CHECKLIST.md` (release gate) | `plan/PRE-RELEASE-CHECKLIST.md` + Tier-9 release journey |
| `.last-smoke-pass.json` (green-light gate JSON) | `docs/qa/.last-smoke-pass.json` ← written by skill |
| `.claude/skills/<slug>-smoke/SKILL.md` (per-plugin, retired) | one Claude-level `~/.claude/skills/wp-plugin-smoke/` + per-plugin `docs/qa/qa-config.json` (data, not skill) |
| `bin/build-release.sh` smoke gate | `bin/build-release.sh` (gate injected) |
| Customer-contract phrasing | runbook Sections C / E |
| Persona ladder (Anonymous → Member → Mod → Admin) | runbook Section C personas |
| Viewport × theme matrix (1280px / 390px, light / dark) | runbook Section C "viewport" rule |
| Debug-log diff protocol | runbook "Debug log protocol" section |
| Failure `from \| for` triage | runbook "Failure protocol" |
| Fixture cleanup before walks | runbook "Fixture cleanup" |
| Step-ID format `<Section>.<persona>.<feature>` | runbook "Step ID format" |
| Section D regression guards (fixture = contract) | runbook Section D |
| Sonnet-only dispatch + verification-only constraints | `~/.claude/skills/wp-plugin-smoke/SKILL.md` (Claude-level, generic) + `docs/qa/qa-config.json` (plugin variables) |

## When to use which document

- **Coding a fix** → run `composer ci:quick` locally; full `composer ci` before push.
- **Wrote a customer-visible fix** → add a row in Section D AND, if missing, a new journey under `audit/journeys/`.
- **About to tag a release** → invoke `/wp-plugin-smoke` (the generic Claude-level skill auto-detects this plugin from CWD, dispatches Sonnet to walk Layer 3); then walk Layer 4 with the QA team.
- **Customer reports a regression** → reproduce by re-running the matching journey from Layer 2, or, if no journey covers it, add one.

## How the layers feed the build gate

`bin/build-release.sh` refuses to package the release zip unless:

1. Layer 1 is green (it runs the gate inline)
2. Layer 2 has no failed journeys in the most recent run
3. Layer 3 produced a `docs/qa/.last-smoke-pass.json` whose:
   - `release_version` matches `WB_GAM_VERSION`,
   - `failures[]` (any with `origin: from`) is empty,
   - `debug_log_issues[]` (any with `origin: from`) is empty,
   - `ran_at` is within the last 24 hours.
4. Layer 4 sign-off is recorded as a comment on the release ticket (manual gate, not enforced by the script).

Emergency bypass: `bin/build-release.sh --skip-browser-smoke` (logs a warning, only for hotfixes that don't touch customer-visible surfaces).

## Anti-patterns

- **Don't run the agent walk in the calling Opus session.** Always dispatch via the skill (Sonnet). Opus's job is reviewing the report and deciding to ship.
- **Don't write Playwright test code for journeys.** Journey markdown + Sonnet's natural-language interpretation outlives selector rot.
- **Don't skip the debug-log diff.** Silent warnings are the bugs that reach customers.
- **Don't graduate a Section D row prematurely.** It needs 2 clean releases first.
- **Don't duplicate `plan/QA-MANUAL-TEST-PLAN.md` in this directory.** That file is the human-walk source of truth; this dir is the agent-walk source of truth.
