# Release verification — 2026-05-05 follow-up

**Plugin version:** 1.2.0
**Zip under test:** `dist/wb-gamification-1.2.0.zip` (originally built 2026-05-03; rebuilt 2026-05-05 after fixes)
**Closes from 2026-05-03 RELEASE-CLOSE-OUT:** the two release-day deferrals — `wp plugin check` on the built zip and Tier 6 host-integration smoke.

## Outcome

| Item | Result |
|---|---|
| Plugin Check on original zip — round 1 | ❌ 13 errors (4 security, 9 i18n) |
| Source fixes applied to `src/Blocks/*/render.php` | ✅ 13/13 |
| Rebuild via `npm run build` + `bash bin/build-release.sh` | ✅ Zip regenerated (~2.84 MB) |
| Plugin Check on **rebuilt** zip — round 2 | ✅ **0 errors** |
| Post-rebuild smoke: 15 blocks register, 47 REST routes, `POST /points/award` → 201, leaderboard renders, no fatals | ✅ PASS |
| Tier 6 partial — BuddyPress 14.4.0 + WooCommerce 10.7.0 + LearnDash 5.0.4 | ✅ PASS |
| bbPress + Elementor (defensive gating only) | ✅ PASS |

**Verdict:** rebuilt zip is **shippable** subject to one pre-existing open item in §3 (Astra theme test). The two integration-smoke flags from the initial run (xprofile + bbPress/Elementor absence) are now resolved.

## 1. Plugin Check on the built zip

Run procedure:
1. `wp plugin install plugin-check --activate` (Plugin Check 1.9.0)
2. Extract `dist/wb-gamification-1.2.0.zip` to `/tmp/wb-gam-pcheck-2026-05-05/`
3. Folder-swap: live source `wb-gamification/` → `wb-gamification-LIVE-SOURCE/`, extracted zip → `wb-gamification/`
   (Plugin Check uses folder name as slug; running under any other slug produced 100+ false-positive `TextDomainMismatch` errors. Swap reversed at end.)
4. `wp plugin check wb-gamification --severity=error` → captured to `plugin-check-errors.txt`
5. Folder swap reversed; live plugin reactivated; verified `WB_GAM_VERSION=1.2.0` and 47 REST routes / 15 blocks / 15 shortcodes register cleanly.

### Errors — 13 total in `build/Blocks/*/render.php`

| Sniff | Count | Action |
|---|---|---|
| `WordPress.WP.I18n.MissingTranslatorsComment` | 9 | Add `/* translators: %s: ... */` comment above each placeholder-bearing `__()`/`esc_html__()`/`_n()` call |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | 4 | Wrap output in `esc_html()` / `esc_attr()` / `esc_url()` |

### Errors by file

| File | Errors |
|---|---|
| `build/Blocks/year-recap/render.php` | 4 |
| `build/Blocks/redemption-store/render.php` | 3 |
| `build/Blocks/points-history/render.php` | 1 |
| `build/Blocks/member-points/render.php` | 1 |
| `build/Blocks/level-progress/render.php` | 1 |
| `build/Blocks/leaderboard/render.php` | 1 |
| `build/Blocks/hub/render.php` | 1 |
| `build/Blocks/cohort-rank/render.php` | 1 |

> The errors live in `build/Blocks/*/render.php` — but those are 1:1 copies of `src/Blocks/*/render.php` (verified with `diff -q`). Fixed in source.

### Round-2 (post-fix) result

After source fixes + `npm run build` + `bin/build-release.sh`, the rebuilt zip was extracted, folder-swapped, and `wp plugin check wb-gamification --severity=error` re-run.

**Result: 0 errors.** Same 10 pre-existing warnings remain (none of them block release). Raw output: [`plugin-check-round2-clean.txt`](plugin-check-round2-clean.txt).

### Source fixes applied

| File | Lines | Change |
|---|---|---|
| `src/Blocks/year-recap/render.php` | 163 | `max( ... )` → `absint( max( ... ) )` for the percentile printf arg |
| `src/Blocks/year-recap/render.php` | 203, 219 | `$wb_gam_count` → `(int) $wb_gam_count` at output site |
| `src/Blocks/leaderboard/render.php` | 113 | Inlined `esc_url( bp_core_get_user_domain( $wb_gam_uid ) )` at the printf call site instead of via intermediate variable (sniffer can't trace the assignment) |
| `src/Blocks/cohort-rank/render.php` | 134-138 | Moved `/* translators: */` comment inside `printf(` immediately above `esc_html__` |
| `src/Blocks/points-history/render.php` | 94-98 | Same — comment moved inside `printf(` |
| `src/Blocks/year-recap/render.php` | 199-204 | Same |
| `src/Blocks/level-progress/render.php` | 147-152 | Same |
| `src/Blocks/redemption-store/render.php` | 138-144, 213-220, 244-250 | Same — three multi-line printf sites |
| `src/Blocks/member-points/render.php` | 126-132 | Same |
| `src/Blocks/hub/render.php` | 288 | Inline `wp_kses_post( sprintf( __( ... ) ) )` expanded to multi-line so the translators comment sits directly above `__()` |

Pattern that triggered the i18n flags: `/* translators: ... */ \n printf( \n   esc_html__( '...%s...', 'wb-gamification' ), ...);` — Plugin Check's sniff requires the comment immediately preceding the gettext call, not the wrapping `printf(`. Moving the comment inside the multi-line printf resolves it.

### Warnings — informational only

10 warnings flagged, all pre-existing & accepted by previous releases:
- `composer.json` missing alongside `vendor/` (intentional — dev-only file excluded from zip)
- `wb-gamification.php`: 2× `WordPress.DB.DirectDatabaseQuery.*`, 1× `load_plugin_textdomain` discouraged-since-4.6
- `uninstall.php`: 4× direct DB query / interpolated table name (ok — uninstall context, table name is `$wpdb->prefix . $table`)
- `build/Blocks/top-members/render.php`: 3× direct DB query / unfinished prepare placeholder
- `readme.txt`: upgrade_notice >300 chars; short_description >150 chars

Raw output: [`plugin-check-errors.txt`](plugin-check-errors.txt).

## 2. Tier 6 host-integration smoke

### Hosts active on this run

| Plugin | Version | Verdict |
|---|---|---|
| BuddyPress | 14.4.0 | ✅ |
| WooCommerce | 10.7.0 | ✅ |
| LearnDash (sfwd-lms) | 5.0.4 | ✅ |
| bbPress | absent | n/a — defensive gating verified |
| Elementor | absent | n/a — defensive gating verified |

### Surface registration with all hosts active

| Surface | Result |
|---|---|
| `WB_GAM_VERSION` constant | `1.2.0` |
| WBGam classes loaded | 69 |
| Blocks registered | **15/15** |
| Shortcodes registered | **15/15** |
| REST routes registered | **47** under `/wb-gamification/v1/*` |

### Integration hook wiring (`$wp_filter` priorities hooked)

| Host | Hook | Hooked |
|---|---|---|
| WooCommerce | `woocommerce_order_status_completed` | 2 |
| WooCommerce | `woocommerce_order_status_processing` | 2 |
| BuddyPress | `bp_activity_add` | 1 |
| BuddyPress | `xprofile_updated_profile` | 1 (verified — see §2a) |
| BuddyPress | `friends_friendship_accepted` | 1 |
| LearnDash | `learndash_course_completed` | 2 |
| LearnDash | `learndash_lesson_completed` | 1 |
| LearnDash | `learndash_quiz_submitted` | 1 |

### 2a. BP profile-completion verification

The original report flagged `bp_xprofile_updated_profile_data` as showing 0 listeners. That was a misread: the integration uses BuddyPress's per-save `xprofile_updated_profile` hook (per `integrations/buddypress.php:121`), not the per-field `_data` variant. The correct hook IS wired:

```
xprofile_updated_profile  hooked=1  prio=10 Closure
```

End-to-end fire on a fresh user:

| Step | Result |
|---|---|
| Selected user: `alice` (ID 2), prior `bp_profile_complete` count | 0 |
| `do_action( 'xprofile_updated_profile', 2, [], [] )` | fired |
| Points before / after | 613 → 628 (Δ **+15**, matching `default_points => 15`) |
| Action count after | 1 (non-repeatable, will dedup on subsequent fires) |

No fix needed.

### End-to-end earning loop (REST → engine → REST readback)

| Step | Result |
|---|---|
| Points before (user 1, `PointsEngine::get_total`) | 1312 |
| `POST /wb-gamification/v1/points/award` (user_id=1, points=25, reason=tier6-smoke-test) | **201** `{"awarded":true,"debited":false,...}` |
| Points after | **1337** (Δ +25 ✓) |
| `GET /wb-gamification/v1/members/1/points` | 200, `total=1337`, history row inserted with `event_id` UUID + `action_id=manual_award` |

### Page-level fatals (curl + grep for `Fatal|Parse|Uncaught`)

| URL | HTTP | Fatals |
|---|---|---|
| `/` | 200 | 0 |
| `/wp-admin/admin.php?page=wb-gamification` (admin) | 200 | 0 |
| `/shop/` (WooCommerce) | 200 | 0 |
| `/members/admin/` | 404 | 0 (no permalink configured — not a regression) |

## 3. To-do before ship

1. ~~Fix 13 Plugin Check errors~~ ✅ Done — rebuilt zip is clean.
2. ~~Confirm `bp_xprofile_updated_profile_data` is intentionally unhooked~~ ✅ Done — was a misread; the integration uses the canonical `xprofile_updated_profile` hook and end-to-end fire awards +15 pts on a fresh user (§2a).
3. **Astra-theme test** — deferred from 2026-05-03; still open. Third theme not installed locally.
4. **bbPress / Elementor**: only verified as gracefully absent. If shipping integration support for these, exercise on a staging site that has them active.

## Artefacts

- [`plugin-check-errors.txt`](plugin-check-errors.txt) — round-1 raw output (13 errors)
- [`plugin-check-round2-clean.txt`](plugin-check-round2-clean.txt) — round-2 raw output (0 errors)
- This file
