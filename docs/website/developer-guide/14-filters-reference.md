# Filters Reference

Every filter hook (`apply_filters`) in WB Gamification, grouped by domain. Register a listener with `add_filter()`, match the parameter count to the signature shown, and always return a value (return it unchanged when your condition does not apply).

See [Hooks and Filters Overview](12-hooks-overview.md) for how to add a listener, and the [Actions reference](13-actions-reference.md) for event hooks.

## Points and awards

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_points_for_action` | Points before they are written. Called after admin option lookup, before multipliers. | `int $points`, `string $action_id`, `int $user_id`, `Event $event` | `int` final points |
| `wb_gam_before_evaluate` | Gate filter. Return `false` to silently block an event from being processed. | `bool $proceed`, `Event $event` | `bool` whether to proceed |
| `wb_gam_event_metadata` | Event metadata before rule evaluation. Enrich it with computed fields. | `array $metadata`, `Event $event` | `array` metadata |
| `wb_gam_leaderboard_results` | Leaderboard data before it is returned to blocks, shortcodes, or the REST API. | `array $results`, `array $raw_rows` | `array` results |
| `wb_gam_toast_data` | Toast notification content before it is queued. Return an empty array to suppress. | `array $event`, `int $user_id` | `array` toast data (empty to suppress) |

## Badges and levels

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_should_award_badge` | Gate filter. Return `false` to prevent a specific badge from being awarded. | `bool $should`, `int $user_id`, `string $badge_id`, `array $def` | `bool` whether to award |
| `wb_gam_streak_grace_days` | The grace period (days before a streak breaks) per user. | `int $days`, `int $user_id` | `int` grace days |
| `wb_gam_credential_document` | The OpenBadges 3.0 JSON-LD credential before it is returned. | (credential document) | modified credential document |

## Challenges and kudos

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_before_kudos` | Validate or block kudos before they are recorded. Return a `WP_Error` to reject. | `mixed $result`, `int $giver_id`, `int $receiver_id`, `string $message` | `$result` unchanged, or a `WP_Error` to reject |

## Submissions

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_recap_data` | The year-in-review recap data before display. | (recap data) | modified recap data |

## Integrations

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_as_retention_days` | Number of days the daily Action Scheduler cleanup keeps `actionscheduler_actions` rows for, regardless of status. Default `7`, minimum `1`. Added in 1.4.0. | (none) | `int` retention days |
| `wb_gam_activity_context_label` | The BuddyPress activity context-group label for a gamification activity type. Default is the per-type human label. Added in 1.4.0. | `string $context`, `string $key` | `string` context label |
| `wb_gam_rank_automation_rules` | Rank automation rules before they are evaluated. | (rules) | modified rules |
| `wb_gam_should_send_weekly_nudge` | Whether a weekly nudge email should be sent to a specific user. | (per-user context) | `bool` whether to send |

## Block data filters

Every block exposes a per-block data filter that fires on the data the block is about to render, so extensions can reorder, remove, or add fields without forking the render PHP. Each filter is named `wb_gam_block_<slug>_data` (or `_currencies` for the hub).

| Filter | Block | What it filters | Return |
|--------|-------|-----------------|--------|
| `wb_gam_block_leaderboard_data` | leaderboard | Array of `{rank, user_id, display_name, points}` rows | `array` rows |
| `wb_gam_block_top_members_data` | top-members | Same shape as leaderboard | `array` rows |
| `wb_gam_block_points_history_data` | points-history | Array of `{action_id, points, point_type, created_at}` rows | `array` rows |
| `wb_gam_block_member_points_data` | member-points | `{points, label, level, next_level, progress_pct}` map | `array` map |
| `wb_gam_block_hub_currencies` | hub | Array of `{slug, label, icon, balance, is_default, convert_rules}` tiles | `array` tiles |
| `wb_gam_block_badge_showcase_data` | badge-showcase | Array of `{id, name, icon_url, earned, ...}` badges | `array` badges |
| `wb_gam_block_challenges_data` | challenges | Array of active challenges for the user | `array` challenges |
| `wb_gam_block_cohort_rank_data` | cohort-rank | Array of cohort standings rows | `array` rows |
| `wb_gam_block_community_challenges_data` | community-challenges | Array of active community challenges | `array` challenges |
| `wb_gam_block_earning_guide_data` | earning-guide | Category-keyed action map `[ category => [{label,icon,points}, ...] ]` | `array` map |
| `wb_gam_block_kudos_feed_data` | kudos-feed | Array of recent kudos rows | `array` rows |
| `wb_gam_block_level_progress_data` | level-progress | `{points, level, next, pct}` map | `array` map |
| `wb_gam_block_redemption_store_data` | redemption-store | Array of reward items | `array` items |
| `wb_gam_block_streak_data` | streak | `{streak, heatmap}` map | `array` map |
| `wb_gam_block_year_recap_data` | year-recap | Yearly recap aggregates map | `array` map |

## Lifecycle

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_template_path` | The resolved template path before a plugin-shipped template is loaded. Return your own path to override a template entirely. | `string $path`, `string $relative`, `array $ctx` | `string` template path |
