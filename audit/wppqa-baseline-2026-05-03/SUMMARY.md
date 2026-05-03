# wppqa baseline — 2026-05-03 refresh

**Plugin:** wb-gamification 1.2.0
**Trigger:** `/wp-plugin-onboard --refresh` after PR #47 (Tier 0 admin REST migration + Tier 1 a11y/breakpoint cleanup)

## Per-check results

| Check | Passed | Failed | Skipped | Verdict |
|---|---|---|---|---|
| `wppqa_check_plugin_dev_rules` | 9 | **0** | 0 | green |
| `wppqa_check_rest_js_contract` | 2 | **0** | 0 | green |
| `wppqa_check_wiring_completeness` | 0 | 0 | 1 | n/a (no settings-form coverage) |
| `wppqa_check_a11y` | 12 | **0** | 0 | green |

**Overall:** every check `failed=0`. Plugin is release-clean per static analysis.

## Remaining warnings (non-blocking)

| Severity | Count | Category | Note |
|---|---|---|---|
| medium | 1 | inline `onclick=` | `blocks/year-recap/render.php:213` — DEAD legacy code, disconnected from registration since Phase G.4 (live block lives at `src/Blocks/year-recap/render.php` with zero onclick). Safe to delete the legacy `blocks/` dir in a follow-up cleanup. |
| medium | 3 | img missing alt (legacy) | `blocks/badge-showcase/render.php:88`, `blocks/level-progress/render.php:59`, `blocks/member-points/render.php:65` — all inside the same dead legacy `blocks/` dir. The active versions in `src/Blocks/` already have alt attributes (collapsed onto same line for the linter). |
| low | 2 | tap-target 16px | `assets/css/admin-premium.css:782` — false positive on the `::after` loading spinner inside a 40px button. The button itself is the tap target. |

All 6 remaining warnings are **legacy-dir false positives or `::after`-pseudo false positives** — none affect the active code paths.

## Compared to last baseline

The pre-PR-#47 wppqa run (captured under `audit/release-runs/2026-05-03/tier-1/wppqa-plugin-dev-rules.txt`) reported:

- 12 high-severity errors (nonce-without-cap × 12) — all closed via Tier 0.C migration
- 9 medium inline-onclick warnings — 8 closed via admin handler removal + 1 left in dead legacy `blocks/` dir
- 1 medium 6-breakpoint-proliferation warning — closed via #57 consolidation (now 3 distinct breakpoints)
- 2 low tap-target warnings — same false positive class

**Delta:** -12 high → 0 high. -8 medium → 1 medium. -1 medium-breakpoint → 0. Tap targets unchanged (false positive).

## Real bugs surfaced + fixed during the verification cycle (recorded for the manifest)

1. **Webhooks event enum mismatch** — admin's `available_events()` listed `badge_awarded` while REST schema enforced `badge_earned`. Aligned admin to REST canonical list. (Tier 0.C — Webhooks page migration.)
2. **Manual Award debit regression** — `POST /points/award` stripped negative sign via `absint()`. Now uses signed-integer + zero rejected with 400 + negative routes to debit. (Tier 4 — earning journey.)
3. **Hub/community-challenges/cohort-rank editor "doesn't include support"** — legacy `register_blocks()` shadowed the Registrar. Emptied legacy list. (Phase G.4, pre-Tier 0.)
4. **block-card.css self-introduced a11y regressions** — 4 high-severity outline-without-replacement errors I introduced; caught + fixed in the same session before commit. (Phase G.2 verification loop.)

## Decision

`wppqa_audit_plugin failed=0` ✅ — proceed with manifest refresh in Phase 2.
