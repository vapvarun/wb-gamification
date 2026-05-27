# Data-flow audit: Admin / REST surface (2026-05-27)

## Pipeline summary

Tier 0 migration is intact: 17 admin pages route mutations through 28 REST controllers via `assets/js/admin-rest-form.js` (`data-wb-gam-rest-form="..."`), 0 `admin_post_*` and 0 `wp_ajax_*` handlers remain. SettingsPage is the one legacy holdout — still uses `admin_init` + `check_admin_referer`. 87 `permission_callback` declarations across `src/API/`, 21 of them `__return_true` (all within the Coding-Rule-2 allowlist). Findings below cluster around capability drift (one cap dormant, one re-introduced after the rules-controller fix, one over-broad), uninstall residue, and a public endpoint that leaks per-user badge data without consulting `Privacy::can_view_public_profile`.

## Findings

### G1: `wb_gam_manage_levels` cap is dormant — never registered
- **Severity**: medium (privilege model claim vs reality drift)
- **File:line**: `src/API/LevelsController.php:171` references `Capabilities::user_can( 'wb_gam_manage_levels' )`, but `src/Engine/Capabilities.php:41-59` does NOT include `wb_gam_manage_levels` in `CAPS`
- **What's missing/broken**: granting `wb_gam_manage_levels` to a community-manager role has zero effect — `user_can()` returns false for non-admins because the cap is never added to any role. Only `manage_options` actually unlocks the LevelsController write surface, contradicting the controller's own docblock and the granular-cap promise in `audit/ROLE_MATRIX.md`.
- **Reproduction**: create a role with `wb_gam_manage_levels`; call `POST /wb-gamification/v1/levels` → 403 despite the cap being granted.
- **Likely fix**: add `'wb_gam_manage_levels' => array( 'administrator' )` to `Capabilities::CAPS`, bump `CAPS_VERSION` to `1.3`. Document the new cap row in `ROLE_MATRIX.md`.

### G2: `uninstall.php` only removes 1 of 7 plugin caps
- **Severity**: medium (data residue after uninstall)
- **File:line**: `uninstall.php:138-152`
- **What's missing/broken**: hardcoded `$plugin_caps = array( 'wb_gam_award_manual' )` removes one cap; `wb_gam_manage_badges`, `wb_gam_manage_rules`, `wb_gam_manage_challenges`, `wb_gam_manage_rewards`, `wb_gam_manage_webhooks`, `wb_gam_view_analytics` survive uninstall and remain on the `administrator` role row forever. `Capabilities::unregister()` already iterates `array_keys(self::CAPS)` correctly but uninstall.php never calls it.
- **Reproduction**: install → activate → admin role gains 7 caps → delete plugin → inspect `wp_options.wp_user_roles` → 6 caps still present.
- **Likely fix**: replace the hardcoded list with `\WBGam\Engine\Capabilities::unregister()`, or sync the array to `\WBGam\Engine\Capabilities::all()`.

### G3: `uninstall.php` misses 3 tables + 2 user_meta + 4 options
- **Severity**: medium (per WP plugin guidelines, uninstall must remove all plugin data)
- **File:line**: `uninstall.php:23-46` (tables), `uninstall.php:57-69` (options), `uninstall.php:157-165` (user meta)
- **What's missing/broken**:
  - Tables not dropped: `wb_gam_user_totals` (materialised totals, CLAUDE.md L3), `wb_gam_submissions` (UGC queue, 1.0.0), `wb_gam_leaderboard_cache` (CLAUDE.md schema list).
  - Options not deleted: `wb_gam_email_level_up`, `wb_gam_email_badge_earned`, `wb_gam_email_challenge_completed`, `wb_gam_profile_public_enabled` — all written by `SetupWizard::apply_defaults_from_form()` (SetupWizard.php:282-295) and `EmailSettingsController::handle_save()`.
  - User meta not deleted: `wb_gam_setup_seen` (SetupWizard.php:152), `wb_gam_profile_public` (per-user privacy toggle, written by ProfilePage, queried by Privacy::can_view_public_profile).
- **Reproduction**: complete wizard → toggle privacy on user → delete plugin → check `wp_users` meta + `wp_options` for residue.
- **Likely fix**: add the 3 tables to the `$tables` array, add the 4 options to `$known_options`, append the 2 meta keys to `$user_meta_keys`.

### G4: `BadgesController::get_items?user_id={any}` leaks per-user earned-badge list publicly
- **Severity**: high (privacy leak — bypasses `Privacy::can_view_public_profile`)
- **File:line**: `src/API/BadgesController.php:86,213-239`; engine call at `src/Engine/BadgeEngine.php:471`
- **What's missing/broken**: route is `__return_true`. The callback accepts arbitrary `user_id` and returns each badge with `earned: bool` + `earned_at: string` for that user. No Privacy gate. Every block render path (`badge-showcase/render.php:66`, `level-progress/render.php:61`, etc.) does call `Privacy::can_view_public_profile()` before rendering — but the underlying REST endpoint does not, so anonymous callers can enumerate any user's badge history by hitting `/wp-json/wb-gamification/v1/badges?user_id=N`.
- **Reproduction**: opt out via `wb_gam_profile_public = '0'` user_meta → `curl /wp-json/wb-gamification/v1/badges?user_id=N` → returns earned/earned_at data anyway.
- **Likely fix**: in `BadgesController::get_items`, if `user_id > 0` AND `user_id !== current_user_id()`, gate on `Privacy::can_view_public_profile($user_id)` and return a 403 (or strip per-user fields and return only the catalog).

### G5: `SubmissionsController` bypasses the granular-cap model
- **Severity**: medium (cap-model inconsistency — admin-only by hardcoded check)
- **File:line**: `src/API/SubmissionsController.php:79-81`
- **What's missing/broken**: `admin_check()` does `return current_user_can( 'manage_options' )` directly. Every other admin controller routes through `Capabilities::user_can('wb_gam_*')` so a delegated role works. ROLE_MATRIX promises non-admin delegation; submissions ignores the promise. No granular cap exists either (`wb_gam_manage_submissions` would be the natural slot).
- **Reproduction**: grant a community-manager role every wb_gam_* cap → they can manage badges/challenges/rewards but cannot approve/reject submissions → must escalate to admin.
- **Likely fix**: add `wb_gam_manage_submissions` to `Capabilities::CAPS`, change the gate to `Capabilities::user_can('wb_gam_manage_submissions')`.

### G6: `EmailSettingsController` same pattern — hardcoded `manage_options`
- **Severity**: low (same class as G5 but lower volume — toggles aren't a delegated surface in practice)
- **File:line**: `src/API/EmailSettingsController.php:75-77`
- **What's missing/broken**: `admin_check()` returns `bool`, not `bool|WP_Error` — non-admin gets WP's default 403 rest_forbidden instead of a typed error consistent with the other controllers. Also no `args` schema declared for the `POST` body — `handle_save()` reads only the EVENTS whitelist (level_up/badge_earned/challenge_completed), unknown POST keys are silently ignored which is correct, but the `/openapi.json` consumer cannot infer the shape because the schema is absent.
- **Likely fix**: align the return type to `bool|WP_Error`, declare `args` with `type: boolean` per slug.

### G7: `EmailSettingsController::handle_save` auto-toggles missing keys to OFF
- **Severity**: medium (UX surprise — PATCH semantics broken)
- **File:line**: `src/API/EmailSettingsController.php:93-101`
- **What's missing/broken**: iterates `self::EVENTS` and writes `'0'` for any slug the request didn't send. A REST client that wants to flip ONLY `level_up` will silently disable `badge_earned` + `challenge_completed`. Per HTTP semantics, POST/PATCH should write only what's in the body.
- **Reproduction**: `curl -X POST /settings/emails -d '{"level_up": true}'` → all three options written, `badge_earned` and `challenge_completed` set to '0'.
- **Likely fix**: skip slugs whose param is `null` (use `$request->has_param($slug)` or `null !== $request->get_param($slug)`).

### G8: Setup-wizard option keys never consumed (or consumed inconsistently)
- **Severity**: low
- **File:line**: `src/Admin/SetupWizard.php:282-295`
- **What's missing/broken**: wizard writes `wb_gam_email_*` as string `'1'`/`'0'` (line 292-293). `EmailSettingsController::handle_get` reads with `(bool) get_option(..., false)` — works because `(bool) '0' === false` and `(bool) '1' === true`. But the email engine (search for `get_option('wb_gam_email_level_up')`) may compare with `=== '1'` somewhere — a string-vs-bool drift waiting to happen. Worth pinning the storage shape and casting consistently across writers and readers.
- **Likely fix**: standardise to `(bool)` cast at read sites, store `'1'`/`'0'` at every write; add a quick grep gate.

### G9: SubmissionsPage JS uses inline `style` attribute
- **Severity**: low (coding-rule 3 / UX-foundation drift)
- **File:line**: `assets/js/admin-submissions.js:37,68,73,93`
- **What's missing/broken**: dynamic DOM elements set `err.style.cssText = 'color:#b91c1c;font-size:12px;...'` etc. — raw hex + raw px, breaks dark-mode + ux-foundation guarantees. The plugin-dev-rules-check.sh in `composer ci` would normally catch this but only scans PHP-emitted templates, not JS-generated DOM.
- **Likely fix**: replace inline `style.cssText` with class names (`wb-gam-submission-error`, `wb-gam-submission-reject-input`); declare the rules in `assets/css/admin.css` using `var(--wb-gam-*)` tokens.

### G10: CommunityChallengesController writes `target_action` with no enum validation
- **Severity**: low
- **File:line**: `src/API/CommunityChallengesController.php:413-417`
- **What's missing/broken**: schema says `target_action` is a string sanitised via `sanitize_key`, with no enum check against `Registry::get_actions()`. An admin can save a community challenge against an action ID that doesn't exist — the challenge will then never tick. Mirrors the bug class from the free-shipping reward (#9927682021), which the redemption controller fixed with `VALID_REWARD_TYPES` enum.
- **Likely fix**: in `create_item` / `update_item`, after sanitise call validate `target_action === '*' || array_key_exists($target_action, Registry::get_actions())` and reject with `rest_invalid_action`.

### G11: `ChallengesController::create_item` args missing `description` field
- **Severity**: low
- **File:line**: `src/API/ChallengesController.php:95-125`
- **What's missing/broken**: args schema declares title/action_id/target/bonus_points/starts_at/ends_at — no `description`. Frontend admin forms may post a `description` that REST silently drops because `register_rest_route` enforces declared args only when `validate_callback` is set. If the admin form expects to round-trip the description, the value is lost.
- **Likely fix**: add `description` to the `args` block and the DB insert path (table already has a `description` column per the engine).

### G12: `ChallengesController::register_routes` uses inline closure for `complete` permission_callback
- **Severity**: low (style / Coding-Rule-2 deviation pattern)
- **File:line**: `src/API/ChallengesController.php:206-215`
- **What's missing/broken**: every other authenticated route in this controller (and in the plugin) routes through `array($this, 'require_logged_in')` or similar. The lambda here checks `is_user_logged_in()` inline. Functionally fine, but `bin/coding-rules-check.sh` walks `permission_callback` declarations to enforce Rule 2 (__return_true allowlist) — closures bypass that introspection.
- **Likely fix**: introduce `public function require_logged_in()` (or reuse the inherited helper) and reference it here.

### G13: `PointsController::award` lets admin set arbitrary `point_type` slug — engine accepts any string
- **Severity**: low
- **File:line**: `src/API/PointsController.php:107-112,206`
- **What's missing/broken**: `point_type` is `sanitize_key`'d but not validated against `PointTypeService::resolve` until inside the service. `resolve()` falls back to the primary type for unknown slugs — UX-wise the admin thinks they awarded "gems" when they actually awarded "points" because gems wasn't registered. Silent fallback.
- **Likely fix**: when `point_type` is non-empty and unknown, return `WP_Error('rest_unknown_point_type', …, 400)` instead of falling back silently. Matches the enum-drift gate philosophy.

### G14: `KudosController` allows email-as-recipient → enumeration risk
- **Severity**: low (logged-in only, but still discoverable)
- **File:line**: `src/API/KudosController.php:185-203`
- **What's missing/broken**: any logged-in user can POST `{recipient_login: "foo@bar.com"}` and infer email registration by error code (`rest_user_invalid` vs success). Subscriber-level enumeration of registered emails.
- **Likely fix**: return the same error response whether the user exists or not (per OWASP credential-handling guidance) — `is_email()` lookups already break that symmetry today.

### G15: `BadgeShareController` exposes display_name + avatar of opt-out users
- **Severity**: low (documented behaviour, flagged for visibility)
- **File:line**: `src/API/BadgeShareController.php:11-19,295-309`
- **What's missing/broken**: per the docblock, this is intentional — credential share pages must work even if the member opted out of the leaderboard. But Privacy.php now ALSO governs `wb_gam_profile_public_enabled` (site kill-switch). When the site disables public profiles entirely, this endpoint should arguably 403 too. Currently it keeps returning display_name + avatar + profile_url even when `wb_gam_profile_public_enabled = false`.
- **Likely fix**: add an opt-in filter `wb_gam_badge_share_respects_privacy` (default false for back-compat); when true, return WP_Error if site kill-switch is off.

### G16: `Capabilities::sync()` runs on every `plugins_loaded` — fine but cache-buster on every request when version drifts
- **Severity**: low (perf nit)
- **File:line**: `src/Engine/Capabilities.php:108-112`
- **What's missing/broken**: `sync()` compares `get_option(CAPS_VERSION_OPTION)` to `CAPS_VERSION`; if mismatched, calls `register()` which loops every role × every cap on every page load until the option write completes. Under autoload misconfiguration (`alloptions` cache miss), this can hammer the DB. Add a transient circuit-breaker.
- **Likely fix**: `if (get_transient('wb_gam_caps_sync_check') === self::CAPS_VERSION) return;` after a successful run.

### Sub-areas with no gaps observed

- **Setup wizard idempotency** — `handle_submission` is gated by `check_admin_referer` + `current_user_can('manage_options')`, in that order (correct). Re-running overwrites template options safely; `apply_defaults_from_form` writes a whitelisted option set.
- **REST error code consistency in RedemptionController** — `redeem()` (RedemptionController.php:322-328) now maps every engine `reason` to a dedicated error code via `match`, with a default fallback. No drift remaining.
- **CSRF on REST mutations** — every admin enqueue passes `wp_create_nonce('wp_rest')` and the admin-rest-utils middleware attaches it as `X-WP-Nonce`. The Submissions admin and Settings admin both follow the pattern.
- **Hidden submenu hook detection** — `CommunityChallengesPage::enqueue_assets` correctly handles all 3 hook variants (`admin_page_*`, `gamification_page_*`, slug fallback) post-#9927572402 fix.
- **DbUpgrader** — version map (`0.1.0` → `1.4.0`) uses `<` comparison, missing 0.4.0/1.3.0 entries are intentionally skipped; not a gap.
- **PointsController capability gate** — `wb_gam_award_manual` is registered AND consumed; the drift fix from commit f877744 holds.
- **RulesController capability gate** — `wb_gam_manage_rules` is registered AND consumed; the bug-#9927027149 fix holds.
