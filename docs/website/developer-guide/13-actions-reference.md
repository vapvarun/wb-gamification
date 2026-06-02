# Actions Reference

Every action hook (`do_action`) in WB Gamification, grouped by domain. Register a listener with `add_action()` and match the parameter count to the signature shown.

See [Hooks and Filters Overview](12-hooks-overview.md) for how to add a listener, and the [Filters reference](14-filters-reference.md) for value-modifying hooks.

## Points and awards

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_before_points_awarded` | Before points are written to the ledger. All checks have passed (enabled, rate limits, gate filter, multipliers). Last chance to inspect or log before it becomes permanent. | `int $user_id`, `Event $event`, `int $points` |
| `wb_gam_points_awarded` | After points are written to the ledger. The most-used hook; it triggers badge evaluation, level check, streak update, and notifications. | `int $user_id`, `Event $event`, `int $points` |
| `wb_gam_points_awarded_batch` | After a bulk award writes the same action to many users in one operation. | `array $user_ids`, `string $action_id`, `int $points`, `string $point_type`, `int $total` |
| `wb_gam_points_revoked` | When an admin revokes (deletes) a point award via the REST API. | `int $row_id`, `array $row`, `int $admin_id` |
| `wb_gam_points_redeemed` | When a member redeems points for a reward in the redemption store. | `int $redemption_id`, `int $user_id`, `array $item`, `?string $coupon` |
| `wb_gam_point_type_converted` | After a member converts one point currency into another. Debit and credit ledger rows share an `event_id`. | `int $user_id`, `string $from`, `string $to`, `int $debit_amount`, `int $credit_amount`, `array $rule` |
| `wb_gam_award_skipped` | When the engine intentionally skips an award (cooldown, cap reached, self-action, veto). Use it to surface a contextual hint so silent skips do not feel broken. | `int $user_id`, `string $action_id`, `string $reason`, `array $context` |
| `wb_gam_points_decayed` | After each daily inactivity point-decay sweep (Settings > Points > Point expiry; off by default). `$count` is the number of members decayed this run. Added in 1.5.3. | `int $count` |

### `wb_gam_award_skipped` reason taxonomy (closed set)

| Reason | Fired from | Common context keys |
|--------|------------|---------------------|
| `cooldown` | `PointsEngine::passes_rate_limits()` cooldown branch | `cooldown_seconds`, `point_type` |
| `non_repeatable` | `PointsEngine::passes_rate_limits()` non-repeatable branch | `point_type` |
| `daily_cap` | `PointsEngine::passes_rate_limits()` daily-cap branch | `daily_cap_used`, `daily_cap_max`, `point_type` |
| `weekly_cap` | `PointsEngine::passes_rate_limits()` weekly-cap branch | `weekly_cap_used`, `weekly_cap_max`, `point_type` |
| `self_action` | `Registry` hook callback when `user_callback` returns 0 | (none) |
| `sandboxed` | Jetonomy adapter, `wb_gam_sandboxed` user meta veto | `delta`, `adapter` |
| `pre_change_veto` | Adapter `*_pre_change` filters that return 0 for non-sandbox reasons | adapter-specific |
| `insufficient_balance` | Future debit-balance check | `requested`, `balance` |

## Badges and levels

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_badge_awarded` | After a badge is awarded to a member. | `int $user_id`, `array $badge_def`, `string $badge_id` |
| `wb_gam_after_badge_award` | After a badge is awarded (legacy alias, lighter signature). | `int $user_id`, `string $badge_id` |
| `wb_gam_credential_expired` | When a badge credential passes its `expires_at` date during the daily expiry check. | `int $user_id`, `string $badge_id`, `string $expires_at` |
| `wb_gam_level_assigned` | When a member is assigned a starter level (initial assignment). Toast/level-up overlays do not listen here, so new members are not congratulated for being a Newcomer. | `int $user_id`, `array $new_level` |
| `wb_gam_level_changed` | When a member moves to a different level (up or down). Does not fire on initial assignment. | `int $user_id`, `array\|null $new_level`, `array\|null $old_level` |
| `wb_gam_streak_milestone` | When a member reaches a streak milestone (7, 14, 30, 60, 100, 180, or 365 days). | `int $user_id`, `int $streak_days` |
| `wb_gam_streak_broken` | When a member's streak is reset to 1 after exceeding the grace period. | `int $user_id`, `int $old_streak`, `int $gap_days` |
| `wb_gam_personal_record` | When a member sets a new personal points record for a period. | `int $user_id`, `string $period`, `int $current`, `int $previous`, `string $message` |

## Challenges and kudos

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_challenge_completed` | When a member completes a challenge (reaches the target count). | `int $user_id`, `array $challenge` |
| `wb_gam_community_challenge_completed` | When a community (team) challenge reaches its global target. | `int $challenge_id`, `int $bonus_points`, `int $contributor_count` |
| `wb_gam_community_challenge_created` | When an admin creates a community challenge via the REST API. | `int $id`, `array $data` |
| `wb_gam_community_challenge_updated` | When an admin updates a community challenge via the REST API. | `int $id`, `array $updates` |
| `wb_gam_community_challenge_deleted` | When an admin deletes a community challenge via the REST API. | `int $id` |
| `wb_gam_kudos_given` | After kudos are successfully recorded. | `int $giver_id`, `int $receiver_id`, `string $message`, `int $kudos_id` |

## Submissions

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_submission_created` | After a member submits a user-generated-content achievement to the moderation queue. | `int $submission_id`, `int $user_id`, `string $action_id` |
| `wb_gam_submission_approved` | After an admin approves a queued submission. Approval routes through `PointsEngine::award` so badges/levels stay consistent. | `int $submission_id`, `int $user_id`, `string $action_id`, `int $reviewer_id` |
| `wb_gam_submission_rejected` | After an admin rejects a queued submission. | `int $submission_id`, `int $user_id`, `string $action_id`, `int $reviewer_id`, `string $notes` |

## Login bonus

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_login_bonus_claimed` | After a member claims a daily login bonus for the day's streak tier. | `int $user_id`, `int $streak`, `int $bonus` |

## Integrations

These optional-engine hooks fire only when their feature flag is enabled in `wb_gam_features` (defaults to `true`).

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_weekly_email_sent` | After a weekly recap email is sent. | `int $user_id`, `array $data` |
| `wb_gam_weekly_nudge_sent` | When a leaderboard nudge has been delivered to a member (after the BuddyPress notification and optional email). Renamed from `wb_gam_weekly_nudge` in 1.4.1. | `int $user_id`, `int $rank`, `int $points`, `?int $points_to_next`, `string $message` |
| `wb_gam_cohort_outcome` | When a cohort league season ends with promotion or demotion results. `$outcome` is `promoted`, `demoted`, or `stayed`. | `int $user_id`, `int $old_tier`, `int $new_tier`, `string $outcome`, `int $points` |
| `wb_gam_retention_nudge` | When a status-retention nudge is dispatched to a member at risk of disengaging. | `int $user_id`, `array $level`, `array $next`, `int $pts_needed`, `string $message` |
| `wb_gam_rank_automation_action` | When a custom rank automation action type is executed. | `int $user_id`, `array $action`, `string $type` |

## Admin CRUD (REST)

These fire from the REST controllers behind the admin CRUD screens. Each carries the `WP_REST_Request` so listeners can inspect the originating call.

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_after_create_badge` | After a badge is created via the REST API. | `array $created`, `WP_REST_Request $request` |
| `wb_gam_after_update_badge` | After a badge is updated via the REST API. | `int $badge_id`, `array $data`, `WP_REST_Request $request` |
| `wb_gam_after_create_level` | After a level is created via the REST API. | `array $row`, `WP_REST_Request $request` |
| `wb_gam_after_update_level` | After a level is updated via the REST API. | `array $fresh`, `array $current`, `WP_REST_Request $request` |
| `wb_gam_before_delete_level` | Before a level is deleted via the REST API. | `array $current`, `WP_REST_Request $request` |
| `wb_gam_after_delete_level` | After a level is deleted via the REST API. | `array $current`, `WP_REST_Request $request` |
| `wb_gam_after_create_community_challenge` | After a community challenge is created via the REST API. | `array $row`, `WP_REST_Request $request` |
| `wb_gam_after_update_community_challenge` | After a community challenge is updated via the REST API. | `array $fresh`, `array $current`, `WP_REST_Request $request` |
| `wb_gam_before_delete_community_challenge` | Before a community challenge is deleted via the REST API. | `array $current`, `WP_REST_Request $request` |
| `wb_gam_after_delete_community_challenge` | After a community challenge is deleted via the REST API. | `array $current`, `WP_REST_Request $request` |
| `wb_gam_after_create_api_key` | After an API key is created via the REST API. | `int $id`, `array $row`, `WP_REST_Request $request` |
| `wb_gam_after_revoke_api_key` | After an API key is revoked via the REST API. | `int $id`, `array $row`, `WP_REST_Request $request` |
| `wb_gam_after_delete_api_key` | After an API key is deleted via the REST API. | `int $id`, `array $row`, `WP_REST_Request $request` |
| `wb_gam_after_save_cohort_settings` | After cohort settings are saved via the REST API. | `array $settings`, `bool $enabled`, `WP_REST_Request $request` |
| `wb_gam_cohort_settings_saved` | After cohort settings are saved (lighter signature, no request). | `array $settings`, `bool $enabled` |

## Event pipeline and engine internals

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_event_processed` | After an event has been normalized and run through rule evaluation. | `array $metadata`, `int $user_id` |
| `wb_gam_unknown_action` | When an incoming event references an action slug that no manifest registered. `$suggestions` holds the closest known slugs. | `string $action_id`, `Event $event`, `array $suggestions` |
| `wb_gam_leaderboard_cache_invalidated` | After the leaderboard snapshot cache is cleared. | (none) |
| `wb_gam_manifests_loaded` | After all action manifests are auto-discovered. `$loaded_actions` is the full registry of discovered actions. | `array $loaded_actions` |
| `wb_gam_as_cleaned` | After the Action Scheduler cleaner sweeps completed/failed rows. | `array $results`, `string $cutoff`, `bool $panic_mode` |
| `wb_gam_as_runaway_detected` | When the Action Scheduler cleaner detects a runaway queue and enters panic mode. | `array $payload` |

## Site-owner controls

Added in 1.5.3 (Settings > Tools).

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_progress_reset` | After an admin resets all member progress (Settings > Tools danger zone). The progress tables and per-user progress meta are wiped while all configuration and definitions are kept. Adapters can clear their own derived state (transients, etc.) here. Added in 1.5.3. | (none) |

## Lifecycle

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_engines_booted` | After all engines have initialized. Third-party extensions hook here to register additional engines. | (none) |
| `wb_gam_register` | After the Registry is initialized. Last chance to register actions via the manual API. | (none) |
| `wb_gamification_setup_wizard_started` | When the setup wizard begins applying a starter template. | `string $template` |
| `wb_gamification_setup_wizard_completed` | After the setup wizard finishes applying a starter template. | `string $template` |
| `wb_gam_log_pruned` | After the daily points-ledger pruner runs (one fire per cron tick). | `int $deleted`, `string $cutoff` |
| `wb_gam_events_pruned` | After the daily event-log pruner runs (one fire per cron tick). | `int $deleted`, `string $cutoff` |
| `wb_gam_user_data_erased` | After all gamification data for a user is erased (GDPR). | `int $user_id` |

## Block extension actions

Every server-rendered block fires two universal action hooks (on all 15 blocks) for HTML injection.

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_block_before_render` | Immediately before a block emits any HTML. Use to inject UI above the block, log impressions, or short-circuit via output capture. | `string $slug`, `array $attributes`, `array $context` |
| `wb_gam_block_after_render` | Immediately after the block finishes its HTML. Use to append UI (share button, CTA), inject analytics beacons, or react to the render. | `string $slug`, `array $attributes`, `array $context` |
