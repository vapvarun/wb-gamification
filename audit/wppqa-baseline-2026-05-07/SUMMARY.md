# wppqa baseline — 2026-05-07 (v1.0.0 release-candidate)

Final pre-release baseline AFTER all four v1.0 critical-gap cards
shipped: #2 transactional emails, #3 public profile pages, #4 daily
login bonus, #5 UGC submission queue. Plus the multi-currency sprint,
scale hardening, audit-fix sprint, hardcoded-label sweep, hook-prefix
unification, and dashicon → Lucide migration.

## Per-check results

| Check | Pass | Fail | Skip | Duration |
|---|---:|---:|---:|---:|
| `wppqa_check_plugin_dev_rules` | 9 | **0** | 0 | 79ms |
| `wppqa_check_rest_js_contract` | 3 | **0** | 0 | 13ms |
| `wppqa_check_wiring_completeness` | 0 | 0 | 1 | 0ms |

**Failed = 0 across all checks.** Plugin meets the v1.0 release gate.

## What this baseline gates

- v1.0 #1 — Multi-point types end-to-end (Phases 1–5)
- v1.0 #2 — Transactional emails (level-up, badge-earned, challenge-completed)
- v1.0 #3 — Public profile pages `/u/{slug}` with privacy gate, OG, JSON-LD
- v1.0 #4 — Daily login bonus (LoginBonusEngine + daily-bonus block)
- v1.0 #5 — UGC submission queue (table + service + REST + admin + member block)
- Scale hardening — materialised user-totals, batched LogPruner, batch award API, leaderboard upsert pattern
- Audit fixes — P0/P1 drift bugs, hook prefix unified to wb_gam_*, hardcoded label sweep
- UI standardisation — dashicons + emojis → Lucide font everywhere

## Architecture deltas vs previous baseline (2026-05-06)

| Surface | Added in v1.0 sprint |
|---|---|
| Tables | wb_gam_point_types, wb_gam_point_type_conversions, wb_gam_user_totals, wb_gam_submissions |
| REST endpoints | /point-types*, /point-type-conversions*, /point-types/{from}/convert, /settings/emails, /submissions, /submissions/{id}/approve, /submissions/{id}/reject |
| Admin pages | Point Types, Conversions, Submissions |
| Blocks | daily-bonus, submit-achievement |
| Engines | TransactionalEmailEngine, LoginBonusEngine, ProfilePage |
| Services | PointTypeService, PointTypeConversionService, SubmissionService |
| Repositories | PointTypeRepository, PointTypeConversionRepository, SubmissionRepository |
| Email templates | level-up.php, badge-earned.php, challenge-completed.php, leaderboard-nudge.php |
| WP-CLI commands | scale (seed/benchmark/teardown), email-test |

## Diff vs previous baseline

Comparison against `audit/wppqa-baseline-2026-05-06/SUMMARY.md`:

| Metric | 2026-05-06 | 2026-05-07 |
|---|---:|---:|
| plugin_dev_rules failed | 0 | 0 |
| rest_js_contract failed | 0 | 0 |
| wiring_completeness failed | 0 | 0 |

No regressions. Four feature additions + one architectural refactor sprint shipped without introducing any rule violations.
