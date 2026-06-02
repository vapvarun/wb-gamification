# Filters Reference

Every filter hook (`apply_filters`) in WB Gamification, grouped by domain. Register a listener with `add_filter()`, match the parameter count to the signature shown, and always return a value (return it unchanged when your condition does not apply).

See [Hooks and Filters Overview](12-hooks-overview.md) for how to add a listener, and the [Actions reference](13-actions-reference.md) for event hooks.

## Points and awards

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_points_for_action` | Points before they are written. Called after admin option lookup, before multipliers. | `int $points`, `string $action_id`, `int $user_id`, `Event $event` | `int` final points |
| `wb_gam_before_evaluate` | Gate filter. Return `false` to silently block an event from being processed. | `bool $proceed`, `Event $event` | `bool` whether to proceed |
| `wb_gam_event_metadata` | Event metadata before rule evaluation. Enrich it with computed fields. | `array $metadata`, `Event $event` | `array` metadata |
| `wb_gam_rule_condition` | Resolves a custom rule condition type the core engine does not recognize. Return `true` to mark the condition met. | `bool $met`, `array $condition`, `Event $event` | `bool` whether the condition is met |
| `wb_gam_leaderboard_results` | Leaderboard data before it is returned to blocks, shortcodes, or the REST API. | `array $results`, `array $raw_rows` | `array` results |
| `wb_gam_leaderboard_scope_user_ids` | The set of user IDs included in a scoped leaderboard. Return an explicit list to define a custom scope. | `array $user_ids`, `string $scope_type`, `int $scope_id` | `array` user IDs |
| `wb_gam_toast_data` | Toast notification content before it is queued. Return an empty array to suppress. | `array $event`, `int $user_id` | `array` toast data (empty to suppress) |
| `wb_gam_heartbeat_payload` | The realtime heartbeat payload before it is returned to the browser. | `array $out`, `int $user_id`, `array $boards` | `array` payload |

## Badges and levels

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_should_award_badge` | Gate filter. Return `false` to prevent a specific badge from being awarded. | `bool $should`, `int $user_id`, `string $badge_id`, `array $def` | `bool` whether to award |
| `wb_gam_badge_condition` | Resolves a custom badge condition type the core badge engine does not recognize. Return `true` to mark the condition met. | `bool $met`, `string $type`, `array $config`, `int $user_id`, `Event $event`, `int $total` | `bool` whether the condition is met |
| `wb_gam_badge_share_respects_privacy` | Whether the public badge-share page should honor the member's privacy setting. Default `false`. | `bool $respects_privacy` | `bool` |
| `wb_gam_streak_grace_days` | The grace period (days before a streak breaks) per user. | `int $days`, `int $user_id` | `int` grace days |
| `wb_gam_credential_document` | The OpenBadges 3.0 JSON-LD credential before it is returned. | `array $credential`, `string $badge_id`, `int $user_id` | `array` credential document |

## Challenges and kudos

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_before_kudos` | Validate or block kudos before they are recorded. Return a `WP_Error` to reject. | `mixed $result`, `int $giver_id`, `int $receiver_id`, `string $message` | `$result` unchanged, or a `WP_Error` to reject |
| `wb_gam_kudos_per_receiver_cooldown_seconds` | Cooldown (seconds) before the same giver can send kudos to the same receiver again. Default one hour; return `0` to disable. | `int $seconds`, `int $giver_id`, `int $receiver_id` | `int` cooldown seconds |

## Submissions

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_submission_daily_cap` | The per-user daily cap on user-generated-content submissions. Default `5`. | `int $cap` | `int` daily cap |

## Emails and nudges

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_email_enabled` | Whether a given transactional email type should be sent to a user. | `bool $enabled`, `string $slug`, `int $user_id` | `bool` whether to send |
| `wb_gam_email_burst_cap` | The maximum number of transactional emails of one type sent in a burst window. Default `5`. | `int $cap`, `string $slug` | `int` burst cap |
| `wb_gam_email_from_header` | The `From` header used for plugin emails. | `string $from`, `string $name`, `string $email`, `string $name_option_key` | `string` from header |
| `wb_gam_weekly_email_body` | The rendered body of the weekly summary email before it is sent. | `string $body`, `WP_User $user`, `array $data` | `string` email body |
| `wb_gam_should_send_weekly_nudge` | Whether a weekly leaderboard nudge should be sent to a specific user. | `bool $should`, `int $user_id`, `array $rank_data` | `bool` whether to send |
| `wb_gam_nudge_message` | The leaderboard nudge message before delivery. | `string $message`, `int $user_id`, `int $rank`, `int $points`, `?int $points_to_next` | `string` message |
| `wb_gam_recap_data` | The year-in-review recap data before display. | `array $recap`, `int $user_id`, `int $year` | `array` recap data |

## Integrations

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_as_retention_days` | Number of days the daily Action Scheduler cleanup keeps `actionscheduler_actions` rows for, regardless of status. Default `7`, minimum `1`. Added in 1.4.0. | `int $days` | `int` retention days |
| `wb_gam_activity_context_label` | The BuddyPress activity context-group label for a gamification activity type. Default is the per-type human label. Added in 1.4.0. | `string $context`, `string $key` | `string` context label |
| `wb_gam_rank_automation_rules` | Rank automation rules before they are evaluated. | `array $rules` | `array` rules |
| `wb_gam_activitypub_activity` | The ActivityPub activity object before it is dispatched for a badge award. | `array $activity`, `int $user_id` | `array` activity object |
| `wb_gam_grant_member_uploads` | Whether to bridge the WordPress `upload_files` capability to logged-in members so the achievement editor's Add Media button works for subscribers/contributors. Default `true`. | `bool $grant`, `WP_User $user` | `bool` whether to grant |
| `wb_gam_wpmediaverse_free_triggers` | The WPMediaVerse free trigger definitions. | `array $free_triggers` | `array` triggers |
| `wb_gam_wpmediaverse_pro_triggers` | The WPMediaVerse Pro trigger definitions (only when WPMediaVerse Pro is active). | `array $pro_triggers` | `array` triggers |
| `wb_gam_wpmediaverse_triggers` | The combined WPMediaVerse trigger definitions. | `array $triggers`, `bool $pro_active` | `array` triggers |
| `wb_gam_defer_leaderboard_to_jetonomy` | Whether wb-gam suppresses its own leaderboard + top-members blocks/shortcodes (and the Hub leaderboard card) so Jetonomy's reputation ranking is the single leaderboard. Default `true` when `JETONOMY_VERSION` is defined, otherwise `false`. Added in 1.5.2. | `bool $defer` | `bool` whether to defer |
| `wb_gam_learndash_profile_link` | Whether to add the opt-in "My Achievements" link to the LearnDash profile (links to the mapped Hub page). Default `false` - return `true` to enable. Added in 1.5.2. | `bool $enabled` | `bool` whether to show the link |
| `wb_gam_member_surface_html` | The wrapped member achievements surface markup (BuddyPress Achievements tab, WooCommerce My Account endpoint, etc.) before output, so a host can wrap or augment the surface without duplicating the renderer. Added in 1.5.2. | `string $html`, `int $user_id` | `string` surface markup |

## Realtime and notifications

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_sse_allowed` | Whether the Server-Sent Events long-poll transport may run on this host. Default `false` - SSE pins a PHP-FPM worker per connection, so it stays off unless the host is provisioned for long-lived streaming. When `false`, realtime falls back to WP Heartbeat. Added in 1.5.2. | `bool $allowed` | `bool` whether SSE is permitted |
| `wb_gam_toast_position` | The on-screen corner reward toasts slide in from. Filters the stored `wb_gam_toast_position` option (Settings > Realtime). One of `bottom-right` (default), `bottom-left`, `top-right`, `top-center`. Added in 1.5.2. | `string $position` | `string` toast position |

## Access and modules

Site-owner controls added in 1.5.3 (Settings > Access and Settings > Modules).

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_user_can_earn` | Whether a user may earn points at all. Fires after the admin earning-exclusion settings (excluded roles, excluded accounts, and the per-user `wb_gam_sandboxed` veto) are applied, so code can extend or override the owner's choices. Enforced at the single award choke point, so it covers both the sync and async award paths. Added in 1.5.3. | `bool $can`, `int $user_id` | `bool` whether the user may earn |
| `wb_gam_module_enabled` | Whether an optional module is enabled. Modules: `kudos`, `streaks`, `challenges`, `community_challenges`, `cohort_leagues`, `redemption`. Default ON; only an explicit `'0'` in the `wb_gam_modules` option disables one. A disabled module's blocks and shortcodes render nothing and its admin page is removed (data is preserved). Added in 1.5.3. | `bool $enabled`, `string $slug` | `bool` whether the module is on |

> **Two related event hooks are actions, not filters.** `wb_gam_progress_reset` (fires after a member-progress reset wipes the progress tables, keeping config) and `wb_gam_points_decayed` (fires after each inactivity point-decay sweep, with the number of members decayed) are documented in the [Actions reference](13-actions-reference.md). Listen with `add_action()`, not `add_filter()`.

## Public profiles

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_profile_publicly_visible` | Whether a member's `/u/{user_login}` profile page is publicly visible. Default ON (opt-out model): a member is visible unless they set the per-user flag to `0`, and the site-wide kill switch still wins. Added in 1.5.2. | `bool $visible`, `int $user_id` | `bool` whether the profile is public |

## Admin CRUD (REST)

These fire from the REST controllers behind the admin CRUD screens. Each carries the `WP_REST_Request` so listeners can inspect or modify the payload before the write.

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_before_create_badge` | The badge row before it is inserted. | `array $row`, `WP_REST_Request $request` | `array` row |
| `wb_gam_before_update_badge` | The badge update payload before it is applied. | `array $data`, `array $def`, `WP_REST_Request $request` | `array` data |
| `wb_gam_before_create_level` | The level payload before it is inserted. | `array $payload`, `WP_REST_Request $request` | `array` payload |
| `wb_gam_before_update_level` | The level update payload before it is applied. | `array $updates`, `array $current`, `WP_REST_Request $request` | `array` updates |
| `wb_gam_before_create_community_challenge` | The community challenge payload before it is inserted. | `array $data`, `WP_REST_Request $request` | `array` data |
| `wb_gam_before_update_community_challenge` | The community challenge update payload before it is applied. | `array $updates`, `array $current`, `WP_REST_Request $request` | `array` updates |
| `wb_gam_before_create_api_key` | The API key payload before it is created. | `array $payload`, `WP_REST_Request $request` | `array` payload |
| `wb_gam_before_save_cohort_settings` | The cohort settings before they are saved. | `array $settings`, `bool $enabled`, `WP_REST_Request $request` | `array` settings |

## Block data filters

Every block exposes a per-block data filter that fires on the data the block is about to render, so extensions can reorder, remove, or add fields without forking the render PHP. Each filter is named `wb_gam_block_<slug>_data` (or `_currencies` for the hub). All per-block data filters also receive the block attributes and the resolving user ID as later arguments where the render needs them.

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
| `wb_gam_block_daily_bonus_data` | daily-bonus | Login-bonus state map merged with `{today_claimed}` | `array` map |
| `wb_gam_block_earning_guide_data` | earning-guide | Category-keyed action map `[ category => [{label,icon,points}, ...] ]` | `array` map |
| `wb_gam_block_kudos_feed_data` | kudos-feed | Array of recent kudos rows | `array` rows |
| `wb_gam_block_level_progress_data` | level-progress | `{points, level, next, pct}` map | `array` map |
| `wb_gam_block_redemption_store_data` | redemption-store | Array of reward items | `array` items |
| `wb_gam_block_streak_data` | streak | `{streak, heatmap}` map | `array` map |
| `wb_gam_block_year_recap_data` | year-recap | Yearly recap aggregates map | `array` map |

### Universal block filters

These fire on every block, not just one slug.

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_block_data` | The resolved data array for any block, after the per-block filter. | `array $data`, `string $slug`, `array $attributes` | `array` data |
| `wb_gam_block_css` | The per-instance scoped CSS emitted for a block instance. | `string $css`, `string $unique_id`, `array $attrs` | `string` CSS |

## Lifecycle

| Filter | What it filters | Parameters | Return |
|--------|-----------------|------------|--------|
| `wb_gam_template_path` | The resolved template path before a plugin-shipped template is loaded. Return your own path to override a template entirely. | `string $path`, `string $relative`, `array $ctx` | `string` template path |
| `wb_gam_manifest_paths` | The directories scanned for `*.php` action manifest files. Add a path to register custom action manifests. | `string[] $paths` | `array` directory paths |
| `wb_gam_block_manifests` | The absolute paths to `block.json` files discovered during block registration. | `array $manifests` | `array` manifest paths |

## Usage examples (1.5.2 filters)

```php
// Force-show wb-gam's own leaderboard even when Jetonomy is active
// (default is to defer to Jetonomy's reputation ranking).
add_filter( 'wb_gam_defer_leaderboard_to_jetonomy', '__return_false' );

// Opt the LearnDash profile "My Achievements" link in (default off).
add_filter( 'wb_gam_learndash_profile_link', '__return_true' );

// Add a heading above every member achievements surface.
add_filter(
	'wb_gam_member_surface_html',
	static function ( string $html, int $user_id ): string {
		return '<h2>' . esc_html__( 'Your progress', 'my-textdomain' ) . '</h2>' . $html;
	},
	10,
	2
);

// Enable the SSE long-poll transport (only on a host built for it).
add_filter( 'wb_gam_sse_allowed', '__return_true' );

// Move reward toasts to the top-right corner for everyone, ignoring the
// admin Settings > Realtime selection.
add_filter(
	'wb_gam_toast_position',
	static function (): string {
		return 'top-right';
	}
);

// Hide a specific member's public /u/ profile regardless of their flag.
add_filter(
	'wb_gam_profile_publicly_visible',
	static function ( bool $visible, int $user_id ): bool {
		return 42 === $user_id ? false : $visible;
	},
	10,
	2
);
```
