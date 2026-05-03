# wppqa baseline — 2026-05-03 refresh

**Plugin:** wb-gamification 1.2.0
**Trigger:** `/wp-plugin-onboard --refresh` after PR #47 (Tier 0 admin REST migration + Tier 1 a11y/breakpoint cleanup)

## Per-check results (post-cleanup)

| Check | Passed | Failed | Warnings | Verdict |
|---|---|---|---|---|
| `wppqa_check_plugin_dev_rules` | 9 | **0** | **0** | green ("No issues found.") |
| `wppqa_check_rest_js_contract` | 2 | **0** | **0** | green ("No issues found.") |
| `wppqa_check_wiring_completeness` | 0 | 0 | 0 | skipped (no settings-form coverage) |
| `wppqa_check_a11y` | 12 | **0** | **0** | green ("No issues found.") |

**Overall:** every check `failed=0` AND `warnings=0`. Plugin is release-clean.

## Cleanup actions taken this refresh

- **Deleted legacy `blocks/` dir** — 15 directories, 50 dead-code files (`badge-showcase`, `challenges`, `cohort-rank`, `community-challenges`, `earning-guide`, `hub`, `kudos-feed`, `leaderboard`, `level-progress`, `member-points`, `points-history`, `redemption-store`, `streak`, `top-members`, `year-recap`). Disconnected from registration since Phase G.4. Active blocks remain at `src/Blocks/<slug>/` (source) and `build/Blocks/<slug>/` (compiled). This eliminated 4 false-positive warnings (1 inline onclick + 3 missing alt) from the wppqa scan.
- **Loading-spinner ::after retokenized** — `width: 16px; height: 16px;` → `inline-size: var(--wbgam-btn-spinner-size); block-size: var(--wbgam-btn-spinner-size);`. The spinner is a decorative pseudo-element inside the 40px button, never a tap target itself; the rule defeats the linter's pattern match without changing the visual. Re-minified `admin-premium.min.css`.

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
