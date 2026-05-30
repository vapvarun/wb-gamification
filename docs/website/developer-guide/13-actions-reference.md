# Actions Reference

Every action hook (`do_action`) in WB Gamification, grouped by domain. Register a listener with `add_action()` and match the parameter count to the signature shown.

See [Hooks and Filters Overview](12-hooks-overview.md) for how to add a listener, and the [Filters reference](14-filters-reference.md) for value-modifying hooks.

## Points and awards

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_before_points_awarded` | Before points are written to the ledger. All checks have passed (enabled, rate limits, gate filter, multipliers). Last chance to inspect or log before it becomes permanent. | `int $user_id`, `Event $event`, `int $points` |
| `wb_gam_points_awarded` | After points are written to the ledger. The most-used hook; it triggers badge evaluation, level check, streak update, and notifications. | `int $user_id`, `Event $event`, `int $points` |
| `wb_gam_points_revoked` | When an admin revokes (deletes) a point award via the REST API. | `int $row_id`, `array $row`, `int $admin_id` |
| `wb_gam_points_redeemed` | When a member redeems points for a reward in the redemption store. | `int $redemption_id`, `int $user_id`, `array $item`, `?string $coupon` |
| `wb_gam_award_skipped` | When the engine intentionally skips an award (cooldown, cap reached, self-action, veto). Use it to surface a contextual hint so silent skips do not feel broken. | `int $user_id`, `string $action_id`, `string $reason`, `array $context` |

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
| `wb_gam_credential_expired` | When a badge credential passes its `expires_at` date during the daily expiry check. | `int $user_id`, `string $badge_id`, `string $expires_at` |
| `wb_gam_level_changed` | When a member moves to a different level (up or down). Does not fire on initial assignment to "Newcomer". | `int $user_id`, `int $old_level_id`, `int $new_level_id` |
| `wb_gam_streak_milestone` | When a member reaches a streak milestone (7, 14, 30, 60, 100, 180, or 365 days). | `int $user_id`, `int $streak_days` |
| `wb_gam_streak_broken` | When a member's streak is reset to 1 after exceeding the grace period. | `int $user_id`, `int $old_streak`, `int $gap_days` |

## Challenges and kudos

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_challenge_completed` | When a member completes a challenge (reaches the target count). | `int $user_id`, `array $challenge` |
| `wb_gam_challenge_created` | When an admin creates a new challenge. | (none) |
| `wb_gam_challenge_updated` | When an admin updates an existing challenge. | (none) |
| `wb_gam_challenge_deleted` | When an admin deletes a challenge. | (none) |
| `wb_gam_community_challenge_completed` | When a community (team) challenge reaches its global target. | (none) |
| `wb_gam_kudos_given` | After kudos are successfully recorded. | `int $giver_id`, `int $receiver_id`, `string $message`, `int $kudos_id` |
| `wb_gam_personal_record` | When a member sets a personal record. | (none) |

## Submissions

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_points_redeemed` | When a member redeems points for a reward in the redemption store (see Points and awards for the full signature). | `int $redemption_id`, `int $user_id`, `array $item`, `?string $coupon` |

## Integrations

These optional-engine hooks fire only when their feature flag is enabled in `wb_gam_features` (defaults to `true`).

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_weekly_email_sent` | After a weekly recap email is sent. | (none) |
| `wb_gam_weekly_nudge_sent` | When a leaderboard nudge has been delivered to a member (after the BuddyPress notification and optional email). Renamed from `wb_gam_weekly_nudge` in 1.4.1. | `int $user_id`, `int $rank`, `int $points`, `?int $points_to_next`, `string $message` |
| `wb_gam_cosmetic_granted` | When a cosmetic or frame is granted to a member. | (none) |
| `wb_gam_cohort_outcome` | When a cohort league season ends with promotion or demotion results. `$outcome` is `promoted`, `demoted`, or `stayed`. | `int $user_id`, `int $old_tier`, `int $new_tier`, `string $outcome`, `int $points` |
| `wb_gam_retention_nudge` | When a re-engagement nudge is sent to an inactive member. | (none) |
| `wb_gam_rank_automation_action` | When a rank automation rule is triggered. | (none) |

## Lifecycle

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_engines_booted` | After all engines have initialized. Third-party extensions hook here to register additional engines. | (none) |
| `wb_gam_register` | After the Registry is initialized. Last chance to register actions via the manual API. | (none) |
| `wb_gam_log_pruned` | After the daily log pruner runs. | (none) |
| `wb_gam_user_data_erased` | After all gamification data for a user is erased (GDPR). | (none) |

## Block extension actions

Every server-rendered block fires two universal action hooks (on all 15 blocks) for HTML injection.

| Hook | When it fires | Parameters |
|------|---------------|------------|
| `wb_gam_block_before_render` | Immediately before a block emits any HTML. Use to inject UI above the block, log impressions, or short-circuit via output capture. | `string $slug`, `array $attributes`, `array $context` |
| `wb_gam_block_after_render` | Immediately after the block finishes its HTML. Use to append UI (share button, CTA), inject analytics beacons, or react to the render. | `string $slug`, `array $attributes`, `array $context` |
