# Hooks & Filters Reference

Complete reference for all action hooks and filter hooks in WB Gamification. Use these to extend the engine, add custom logic, or integrate with third-party systems.

**Convention:** Hooks prefixed `wb_gamification_` are stable public API. Hooks prefixed `wb_gam_` are internal (may change between versions).

**Pro label:** Hooks marked **[Pro]** fire only when the Pro add-on is active. They are safe to hook into from any code — they simply never fire without Pro.

---

## Action Hooks

### Points

#### `wb_gam_before_points_awarded`

Fires **before** points are written to the ledger. All checks have passed (enabled, rate limits, gate filter, multipliers). Last chance to inspect or log before it becomes permanent.

```php
add_action( 'wb_gam_before_points_awarded', function( int $user_id, $event, int $points ) {
    // Log high-value awards to a custom table.
    if ( $points >= 100 ) {
        error_log( "High award: {$points} pts to user {$user_id} for {$event->action_id}" );
    }
}, 10, 3 );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | User who will receive the points |
| `$event` | `Event` | Full event object (`action_id`, `metadata`, `object_id`) |
| `$points` | `int` | Final points after all multipliers |

---

#### `wb_gam_points_awarded`

Fires **after** points are written to the ledger. The most-used hook — triggers badge evaluation, level check, streak update, and notifications.

```php
add_action( 'wb_gam_points_awarded', function( int $user_id, $event, int $points ) {
    // Sync points to an external CRM.
    my_crm_update_points( $user_id, $points, $event->action_id );
}, 10, 3 );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | User who earned the points |
| `$event` | `Event` | Full event object |
| `$points` | `int` | Points awarded |

---

#### `wb_gam_points_revoked`

Fires when an admin revokes (deletes) a point award via the REST API.

```php
add_action( 'wb_gam_points_revoked', function( int $row_id, array $row, int $admin_id ) {
    // Notify the user their points were revoked.
}, 10, 3 );
```

---

#### `wb_gam_points_redeemed`

Fires when a member redeems points for a reward in the redemption store.

```php
add_action( 'wb_gam_points_redeemed', function( int $redemption_id, int $user_id, array $item, ?string $coupon ) {
    // Send a confirmation email with the coupon code.
}, 10, 4 );
```

---

### Badges

#### `wb_gam_badge_awarded`

Fires after a badge is awarded to a member.

```php
add_action( 'wb_gam_badge_awarded', function( int $user_id, array $badge_def, string $badge_id ) {
    // Post to Slack when someone earns a special badge.
    if ( 'founding_member' === $badge_id ) {
        slack_notify( "User {$user_id} earned Founding Member!" );
    }
}, 10, 3 );
```

---

#### `wb_gam_credential_expired` **[Pro]**

Fires when a badge credential passes its `expires_at` date during the daily expiry check.

```php
add_action( 'wb_gam_credential_expired', function( int $user_id, string $badge_id, string $expires_at ) {
    // Notify the user to renew their credential.
}, 10, 3 );
```

---

### Levels

#### `wb_gam_level_changed`

Fires when a member moves to a different level (up or down). Does NOT fire on initial assignment to "Newcomer".

```php
add_action( 'wb_gam_level_changed', function( int $user_id, int $old_level_id, int $new_level_id ) {
    // Assign a WordPress role based on level.
    if ( $new_level_id >= 5 ) {
        $user = get_user_by( 'id', $user_id );
        $user->set_role( 'contributor' );
    }
}, 10, 3 );
```

---

### Streaks

#### `wb_gam_streak_milestone`

Fires when a member reaches a streak milestone (7, 14, 30, 60, 100, 180, or 365 days).

```php
add_action( 'wb_gam_streak_milestone', function( int $user_id, int $streak_days ) {
    if ( $streak_days >= 30 ) {
        \WBGam\Engine\BadgeEngine::award_badge( $user_id, 'dedicated_30' );
    }
}, 10, 2 );
```

---

#### `wb_gam_streak_broken`

Fires when a member's streak is reset to 1 after exceeding the grace period.

```php
add_action( 'wb_gam_streak_broken', function( int $user_id, int $old_streak, int $gap_days ) {
    // Send a "We miss you" email if they had a long streak.
    if ( $old_streak >= 14 ) {
        wp_mail( get_userdata( $user_id )->user_email, 'Your streak ended', '...' );
    }
}, 10, 3 );
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | User whose streak broke |
| `$old_streak` | `int` | Streak count before reset |
| `$gap_days` | `int` | Days of inactivity that caused the break |

---

### Challenges

#### `wb_gam_challenge_completed`

Fires when a member completes a challenge (reaches the target count).

```php
add_action( 'wb_gam_challenge_completed', function( int $user_id, array $challenge ) {
    my_analytics_track( 'challenge_completed', [
        'user_id'      => $user_id,
        'challenge_id' => $challenge['id'],
        'title'        => $challenge['title'],
    ] );
}, 10, 2 );
```

---

#### `wb_gam_challenge_created`

Fires when an admin creates a new challenge.

#### `wb_gam_challenge_updated`

Fires when an admin updates an existing challenge.

#### `wb_gam_challenge_deleted`

Fires when an admin deletes a challenge.

#### `wb_gam_community_challenge_completed` **[Pro]**

Fires when a community (team) challenge reaches its global target.

---

### Kudos

#### `wb_gam_kudos_given`

Fires after kudos are successfully recorded.

```php
add_action( 'wb_gam_kudos_given', function( int $giver_id, int $receiver_id, string $message, int $kudos_id ) {
    // Post to BuddyPress activity stream.
}, 10, 4 );
```

---

### Pro Engine Hooks

#### `wb_gam_weekly_email_sent` **[Pro]**

Fires after a weekly recap email is sent.

#### `wb_gam_weekly_nudge` **[Pro]**

Fires when a leaderboard nudge email is sent.

#### `wb_gam_cosmetic_granted` **[Pro]**

Fires when a cosmetic/frame is granted to a member.

#### `wb_gam_cohort_outcome` **[Pro]**

Fires when a cohort league season ends with promotion/demotion results.

```php
add_action( 'wb_gam_cohort_outcome', function( int $user_id, int $old_tier, int $new_tier, string $outcome, int $points ) {
    // $outcome is 'promoted', 'demoted', or 'stayed'.
}, 10, 5 );
```

#### `wb_gam_retention_nudge` **[Pro]**

Fires when a re-engagement nudge is sent to an inactive member.

#### `wb_gam_personal_record`

Fires when a member sets a personal record.

---

### System Hooks

#### `wb_gam_engines_booted`

Fires after all engines have initialized. Pro plugin hooks here to register additional engines.

#### `wb_gam_register`

Fires after the Registry is initialized. Last chance to register actions via the manual API.

#### `wb_gam_rank_automation_action`

Fires when a rank automation rule is triggered.

#### `wb_gam_log_pruned`

Fires after the daily log pruner runs.

#### `wb_gam_user_data_erased`

Fires after all gamification data for a user is erased (GDPR).

---

## Filter Hooks

### `wb_gam_points_for_action`

Modify points before they are written. Called after admin option lookup, before multipliers.

```php
// Double points on weekends.
add_filter( 'wb_gam_points_for_action', function( int $points, string $action_id, int $user_id, $event ) {
    if ( in_array( gmdate( 'l' ), [ 'Saturday', 'Sunday' ], true ) ) {
        return $points * 2;
    }
    return $points;
}, 10, 4 );
```

---

### `wb_gam_before_evaluate`

Gate filter — return `false` to silently block an event from being processed.

```php
// Block all gamification for suspended users.
add_filter( 'wb_gam_before_evaluate', function( bool $proceed, $event ) {
    if ( get_user_meta( $event->user_id, 'is_suspended', true ) ) {
        return false;
    }
    return $proceed;
}, 10, 2 );
```

---

### `wb_gam_event_metadata`

Enrich event metadata before rule evaluation.

```php
add_filter( 'wb_gam_event_metadata', function( array $metadata, $event ) {
    if ( isset( $metadata['content'] ) ) {
        $metadata['word_count'] = str_word_count( wp_strip_all_tags( $metadata['content'] ) );
    }
    return $metadata;
}, 10, 2 );
```

---

### `wb_gam_should_award_badge`

Gate filter — return `false` to prevent a specific badge from being awarded.

```php
// Only award "Top Contributor" to users with 6+ months membership.
add_filter( 'wb_gam_should_award_badge', function( bool $should, int $user_id, string $badge_id, array $def ) {
    if ( 'top_contributor' === $badge_id ) {
        $registered = strtotime( get_userdata( $user_id )->user_registered );
        if ( time() - $registered < 6 * MONTH_IN_SECONDS ) {
            return false;
        }
    }
    return $should;
}, 10, 4 );
```

---

### `wb_gam_streak_grace_days`

Override the grace period (days before streak breaks) per user.

```php
// Pro members get 3 grace days instead of 1.
add_filter( 'wb_gam_streak_grace_days', function( int $days, int $user_id ) {
    if ( user_can( $user_id, 'premium_member' ) ) {
        return 3;
    }
    return $days;
}, 10, 2 );
```

---

### `wb_gam_before_kudos`

Validate or block kudos before they are recorded. Return a `WP_Error` to reject.

```php
// Require minimum account age to give kudos.
add_filter( 'wb_gam_before_kudos', function( $result, int $giver_id, int $receiver_id, string $message ) {
    $registered = strtotime( get_userdata( $giver_id )->user_registered );
    if ( time() - $registered < WEEK_IN_SECONDS ) {
        return new WP_Error( 'too_new', 'Your account must be at least 7 days old to give kudos.' );
    }
    return $result;
}, 10, 4 );
```

---

### `wb_gam_leaderboard_results`

Modify leaderboard data before it is returned to blocks, shortcodes, or the REST API.

```php
// Add badge count to leaderboard entries.
add_filter( 'wb_gam_leaderboard_results', function( array $results, array $raw_rows ) {
    foreach ( $results as &$entry ) {
        $entry['badge_count'] = count( wb_gam_get_user_badges( $entry['user_id'] ) );
    }
    return $results;
}, 10, 2 );
```

---

### `wb_gam_toast_data`

Modify toast notification content before it is queued. Return empty array to suppress.

```php
// Suppress point toasts for minor actions.
add_filter( 'wb_gam_toast_data', function( array $event, int $user_id ) {
    if ( 'points' === ( $event['type'] ?? '' ) && ( $event['points'] ?? 0 ) < 5 ) {
        return []; // Suppress — too small to notify.
    }
    return $event;
}, 10, 2 );
```

---

### `wb_gam_credential_document`

Modify the OpenBadges 3.0 JSON-LD credential before it is returned.

### `wb_gam_recap_data` **[Pro]**

Modify the year-in-review recap data before display.

### `wb_gam_rank_automation_rules`

Modify rank automation rules before they are evaluated.

### `wb_gam_should_send_weekly_nudge` **[Pro]**

Control whether a weekly nudge email should be sent to a specific user.

---

## Quick Reference Table

### Actions (28 total)

| Hook | File | Free/Pro |
|------|------|----------|
| `wb_gam_before_points_awarded` | Engine.php | Free |
| `wb_gam_points_awarded` | Engine.php | Free |
| `wb_gam_points_revoked` | PointsController.php | Free |
| `wb_gam_points_redeemed` | RedemptionEngine.php | Free |
| `wb_gam_badge_awarded` | BadgeEngine.php | Free |
| `wb_gam_credential_expired` | CredentialExpiryEngine.php | Pro |
| `wb_gam_level_changed` | LevelEngine.php | Free |
| `wb_gam_streak_milestone` | StreakEngine.php | Free |
| `wb_gam_streak_broken` | StreakEngine.php | Free |
| `wb_gam_challenge_completed` | ChallengeEngine.php | Free |
| `wb_gam_challenge_created` | ChallengeManagerPage.php | Free |
| `wb_gam_challenge_updated` | ChallengeManagerPage.php | Free |
| `wb_gam_challenge_deleted` | ChallengeManagerPage.php | Free |
| `wb_gam_community_challenge_completed` | CommunityChallengeEngine.php | Pro |
| `wb_gam_kudos_given` | KudosEngine.php | Free |
| `wb_gam_rank_automation_action` | RankAutomation.php | Free |
| `wb_gam_personal_record` | PersonalRecordEngine.php | Free |
| `wb_gam_weekly_email_sent` | WeeklyEmailEngine.php | Pro |
| `wb_gam_weekly_nudge` | LeaderboardNudge.php | Pro |
| `wb_gam_cosmetic_granted` | CosmeticEngine.php | Pro |
| `wb_gam_cohort_outcome` | CohortEngine.php | Pro |
| `wb_gam_retention_nudge` | StatusRetentionEngine.php | Pro |
| `wb_gam_log_pruned` | LogPruner.php | Free |
| `wb_gam_events_pruned` | LogPruner.php | Free |
| `wb_gam_user_data_erased` | Privacy.php | Free |
| `wb_gam_engines_booted` | FeatureFlags.php | Free |
| `wb_gam_register` | Registry.php | Free |

### Filters (11 total)

| Hook | File | Free/Pro |
|------|------|----------|
| `wb_gam_points_for_action` | Engine.php | Free |
| `wb_gam_before_evaluate` | Engine.php | Free |
| `wb_gam_event_metadata` | Engine.php | Free |
| `wb_gam_should_award_badge` | BadgeEngine.php | Free |
| `wb_gam_streak_grace_days` | StreakEngine.php | Free |
| `wb_gam_before_kudos` | KudosEngine.php | Free |
| `wb_gam_leaderboard_results` | LeaderboardEngine.php | Free |
| `wb_gam_toast_data` | NotificationBridge.php | Free |
| `wb_gam_credential_document` | CredentialController.php | Free |
| `wb_gam_recap_data` | RecapEngine.php | Pro |
| `wb_gam_rank_automation_rules` | RankAutomation.php | Free |
| `wb_gam_should_send_weekly_nudge` | LeaderboardNudge.php | Pro |

---

## Block extension API

Every server-rendered block fires two action hooks (for HTML injection) and several expose a data filter (for data mutation before render).

### Universal block actions (fire on all 15 blocks)

#### `wb_gam_block_before_render`

Fires immediately before a block emits any HTML. Use to inject UI above the block, log impressions, or short-circuit via output capture.

```php
add_action( 'wb_gam_block_before_render', function( string $slug, array $attributes, array $context ) {
    if ( $slug === 'leaderboard' ) {
        echo '<div class="my-leaderboard-banner">Top players this week →</div>';
    }
}, 10, 3 );
```

#### `wb_gam_block_after_render`

Fires immediately after the block finishes its HTML. Use to append UI (share button, CTA), inject analytics beacons, or react to the render.

```php
add_action( 'wb_gam_block_after_render', function( string $slug, array $attributes, array $context ) {
    if ( $slug === 'kudos-feed' && is_user_logged_in() ) {
        echo '<a class="my-give-kudos-cta" href="#">Send kudos →</a>';
    }
}, 10, 3 );
```

### Per-block data filters

Filters fire on the data the block is about to render — devs can reorder, remove, or add fields. Each filter is named `wb_gam_block_<slug>_data` (or `_currencies` for the hub).

| Filter | Block | Filtered value |
|---|---|---|
| `wb_gam_block_leaderboard_data` | leaderboard | Array of `{rank, user_id, display_name, points}` rows |
| `wb_gam_block_top_members_data` | top-members | Same shape as leaderboard |
| `wb_gam_block_points_history_data` | points-history | Array of `{action_id, points, point_type, created_at}` rows |
| `wb_gam_block_member_points_data` | member-points | `{points, label, level, next_level, progress_pct}` map |
| `wb_gam_block_hub_currencies` | hub | Array of `{slug, label, icon, balance, is_default, convert_rules}` tiles |

```php
// Example: hide certain users from every leaderboard.
add_filter( 'wb_gam_block_leaderboard_data', function( array $rows, array $attrs ) {
    return array_values( array_filter( $rows, fn( $r ) => ! in_array( $r['user_id'], [42, 99], true ) ) );
}, 10, 2 );

// Example: add a custom currency tile to the hub.
add_filter( 'wb_gam_block_hub_currencies', function( array $tiles, array $attrs, int $user_id ) {
    $tiles[] = [
        'slug'           => 'external_loyalty',
        'label'          => __( 'Loyalty', 'my-plugin' ),
        'icon'           => 'gem',
        'balance'        => my_external_loyalty_balance( $user_id ),
        'is_default'     => false,
        'convert_rules'  => [],
    ];
    return $tiles;
}, 10, 3 );
```

The remaining 10 blocks (badge-showcase, challenges, cohort-rank, community-challenges, earning-guide, kudos-feed, level-progress, redemption-store, streak, year-recap) only expose the universal actions today; per-block data filters land in 1.0.1. Devs needing data mutation against those blocks before then can use `wb_gam_block_before_render` with output buffering.

---

## Theme template overrides

Plugin-shipped templates can be overridden by themes via the standard `locate_template()` chain. Use `Templates::locate()` from any extension code:

```php
$path = \WBGam\Engine\Templates::locate( 'emails/weekly-recap.php' );
// 1. Filter: wb_gam_template_path  (full programmatic override)
// 2. Theme:  {child-theme}/wb-gamification/emails/weekly-recap.php
// 3. Theme:  {parent-theme}/wb-gamification/emails/weekly-recap.php
// 4. Plugin: wb-gamification/templates/emails/weekly-recap.php
```

### Email templates (always overridable)

Every email the plugin sends routes through `Email::render()` → `Templates::locate()`, so the override path is identical to other plugin templates. Two emails ship today:

| Template | Slug | Sent by | Variables passed |
|---|---|---|---|
| `templates/emails/weekly-recap.php` | `weekly-recap` | `WeeklyEmailEngine` (Pro) | user, name, site_name, points_this_week, total_points, badges_this_week, challenges_this_week, streak, rank, unsub_url |
| `templates/emails/leaderboard-nudge.php` | `leaderboard-nudge` | `LeaderboardNudge` | user, name, site_name, site_url, message, rank, points |

Override either by copying to your theme:

```bash
mkdir -p wp-content/themes/your-theme/wb-gamification/emails/
cp wp-content/plugins/wb-gamification/templates/emails/leaderboard-nudge.php \
   wp-content/themes/your-theme/wb-gamification/emails/
```

Then customise. Variables documented in the `@var` block at the top of each template are extracted into local scope.

Render directly:

```php
echo \WBGam\Engine\Templates::render( 'emails/weekly-recap.php', [
    'user'   => $user,
    'points' => $points,
] );
```

Override a path entirely via filter:

```php
add_filter( 'wb_gam_template_path', function( string $path, string $relative, array $ctx ) {
    if ( $relative === 'emails/weekly-recap.php' ) {
        return MY_PLUGIN_PATH . 'custom-templates/recap.php';
    }
    return $path;
}, 10, 3 );
```

**Note:** Block render PHP (`src/Blocks/<slug>/render.php`) is **not** theme-overridable — Gutenberg's block API doesn't permit it. Use `wb_gam_block_<slug>_data` to mutate input data, or `wb_gam_block_after_render` to inject HTML.
