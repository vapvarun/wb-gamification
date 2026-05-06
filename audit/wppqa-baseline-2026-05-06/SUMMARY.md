# wppqa baseline — 2026-05-06 (post-multi-currency)

Run AFTER multi-currency Phase 1–5 implementation, currency-conversion v1, hub-convert UI, member-facing currency labels, CLI `--type` flag, code-flow audit (cache-key cleanup + Privacy/REST `points_by_type` extension).

A pre-sprint-1 baseline run earlier in the day is preserved at `SUMMARY.pre-sprint1.md` for diffing.

## Per-check results

| Check | Pass | Fail | Skip | Duration |
|---|---:|---:|---:|---:|
| `wppqa_check_plugin_dev_rules` | 9 | **0** | 0 | 41ms |
| `wppqa_check_rest_js_contract` | 3 | **0** | 0 | 17ms |
| `wppqa_check_wiring_completeness` | 0 | 0 | 1 | 0ms |

**Failed = 0 across all checks.** Plugin is release-ready per the wppqa gate.

## Notes

- `wppqa_check_wiring_completeness` skipped because the plugin uses `templates/` only for redemption-store, BP, etc. — settings flow through admin pages → REST → block render.php, not classic shortcode templates. Skill heuristic doesn't match the architecture and yields a "skipped" rather than a real-bug "fail." Stable across runs.
- `wppqa_check_rest_js_contract` clean — every REST envelope shape matches its consumer JS. The new `points_by_type` field added to `GET /members/{id}` is consumed only by future code (none yet); no drift introduced. The new `POST /point-types/{from}/convert` endpoint and its consumer (`assets/js/hub-convert.js`) round-trip cleanly.
- `wppqa_check_plugin_dev_rules` clean — alert/confirm ban, nonce-cap pairing, lifecycle hooks, 40px tap targets, 3-breakpoint discipline all pass.

## What this baseline gates

- Convert UI submission flow (REST POST `/point-types/{from}/convert` + page reload) — no contract drift.
- CLI `--type=` flag on PointsCommand + MemberCommand — no rule violations.
- Privacy export multi-currency expansion — no rule violations.
- `MembersController::get_item` schema extension with `points_by_type` — clean.
- Stale single-currency cache-key invalidations removed from `Engine::process` and `RedemptionEngine::redeem` — `PointsEngine::insert_point_row`/`debit` already invalidate the correct per-type key.

## Code-flow audit highlights (manual, supplements wppqa)

| Surface | Resolution path | Status |
|---|---|---|
| Action manifest fires → ledger | `Registry::resolve_action_point_type` → `metadata['point_type']` → `Engine::persist_event` + `PointsEngine::insert_point_row` | OK — single source of truth |
| Rate-limit checks | Same `Registry::resolve_action_point_type` helper | OK — same resolution as award path |
| Manual award (admin / CLI) | `PointsEngine::award($user, $action, $points, 0, $type)` → `metadata['point_type']` if non-null → `Engine::process` | OK |
| Conversion (debit + credit) | `PointTypeConversionService::convert` → shared `event_id` → `PointsEngine::debit($from)` + `PointsEngine::award($to)` | OK — atomic, FOR-UPDATE locked |
| Redemption (debit) | `RedemptionEngine::redeem` → `PointsEngine::debit($user, $cost, 'redemption', $event_id, $type)` | OK |
| WooCommerce refund | `RefundHandler` resolves type → `PointsEngine::debit` | OK — refund debits same currency awarded |
| Cache invalidation | `cache_key_total($user, $type)` — every read/write site uses single helper | OK after this audit (legacy keys removed) |
| Levels / Badges / Status / Nudges | Read primary balance only via bare `get_total($user)` | EXPECTED — Phase 4 deferred items per `plan/MULTI-POINT-TYPES-PLAN.md` |
| Hub block tiles | Loops `PointsEngine::get_totals_by_type` → renders one tile per active currency | OK |
| points-history block | Per-row label from `$row['point_type']` (cached label map, single service call) | OK |
| Member-points / Leaderboard / Top-members | Resolve type via `PointTypeService::resolve()` → label drives copy + aria-labels | OK |
| Privacy export | One summary row per currency + per-row Currency in history | OK |
| REST `GET /members/{id}` | Adds `points_by_type` field (additive, schema-declared) | OK |
| REST `GET /members/{id}/points?type=...` | Type-scoped total + history | OK |
| REST `GET /members/{id}/level` | Primary points only (matches Phase 4 deferred scope) | EXPECTED |
