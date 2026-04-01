# Hooks and Filters Reference

Namespace prefix for all hooks: `wb_gamification_`

---

## Action Hooks

### Points Hooks

#### `wb_gamification_points_awarded`

Fires after points are written to the ledger. All award paths fire this hook, including manual awards and async-processed events.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | User who received the points |
| `$event` | `WBGam\Engine\Event` | Full event object. Access `$event->action_id`, `$event->metadata` |
| `$points` | int | Points awarded |

```php
add_action( 'wb_gamification_points_awarded', function( $user_id, $event, $points ) {
    my_analytics()->track( 'points_earned', [
        'user_id'   => $user_id,
        'action_id' => $event->action_id,
        'points'    => $points,
    ] );
}, 10, 3 );
```

#### `wb_gamification_points_revoked`

Fires after an admin deletes a specific points ledger row via `DELETE /points/{id}`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$row_id` | int | Deleted `wb_gam_points` row ID |
| `$row` | array | Deleted row data including `user_id` and `points` |
| `$admin_id` | int | Admin user ID who performed the revocation |

#### `wb_gamification_points_redeemed`

Fires after a member redeems points for a reward item.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$redemption_id` | int | New redemption record ID |
| `$user_id` | int | Member who redeemed |
| `$item` | array | Reward item definition row |
| `$coupon_code` | string | Generated coupon code, or empty string |

---

### Badge Hooks

#### `wb_gamification_badge_awarded`

Fires after a badge is written to `wb_gam_user_badges`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member who earned the badge |
| `$def` | array | Badge definition row from `wb_gam_badge_defs` |
| `$badge_id` | string | Badge identifier string |

```php
add_action( 'wb_gamification_badge_awarded', function( $user_id, $def, $badge_id ) {
    do_action( 'my_plugin_badge_notification', $user_id, $def['name'] );
}, 10, 3 );
```

#### `wb_gamification_credential_expired`

Fires when `CredentialExpiryEngine` removes a badge whose `validity_days` has elapsed.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member whose badge expired |
| `$badge_id` | string | Badge identifier |
| `$expires_at` | string | The `expires_at` DATETIME value |

---

### Level Hooks

#### `wb_gamification_level_changed`

Fires when `LevelEngine::maybe_level_up()` advances a member to a new level.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member who levelled up |
| `$old_level_id` | int | Previous level DB row ID |
| `$new_level_id` | int | New level DB row ID |

```php
add_action( 'wb_gamification_level_changed', function( $user_id, $old_level_id, $new_level_id ) {
    $level = wb_gam_get_user_level( $user_id );
    if ( 'Champion' === ( $level['name'] ?? '' ) ) {
        // Grant a reward when reaching Champion level.
    }
}, 10, 3 );
```

#### `wb_gamification_rank_automation_action`

Fires when RankAutomation executes an automated action (e.g. `grant_badge`, `add_role`).

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Affected member |
| `$action` | array | Automation rule action definition |
| `$type` | string | Action type string |

---

### Streak Hooks

#### `wb_gamification_streak_milestone`

Fires when a member reaches a streak milestone. Built-in milestones: 7, 14, 30, 60, 100, 180, 365 days.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member who reached the milestone |
| `$streak_days` | int | Streak length at the milestone |

```php
add_action( 'wb_gamification_streak_milestone', function( $user_id, $streak_days ) {
    if ( $streak_days >= 30 ) {
        wb_gam_award_points( $user_id, 50, 'streak_30_bonus' );
    }
}, 10, 2 );
```

#### `wb_gamification_personal_record`

Fires when a member beats their personal best points total for a given period.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member who set the new record |
| `$period` | string | Period type (`week`, `month`, etc.) |
| `$current` | int | New personal best |
| `$previous` | int | Previous personal best |
| `$message` | string | Pre-formatted congratulations string |

---

### Challenge Hooks

#### `wb_gamification_challenge_completed`

Fires when a member completes an individual challenge.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member who completed the challenge |
| `$challenge` | array | Full challenge row from `wb_gam_challenges` |

```php
add_action( 'wb_gamification_challenge_completed', function( $user_id, $challenge ) {
    if ( 'hard' === ( $challenge['difficulty'] ?? '' ) ) {
        wb_gam_award_points( $user_id, 25, 'hard_challenge_bonus' );
    }
}, 10, 2 );
```

#### `wb_gamification_community_challenge_completed`

Fires when the community collectively reaches the target count for a community challenge.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$challenge_id` | int | Community challenge DB row ID |
| `$bonus_points` | int | Bonus points awarded to all contributors |
| `$contributor_count` | int | Number of contributing members |

---

### Kudos Hooks

#### `wb_gamification_kudos_given`

Fires after kudos are recorded in `wb_gam_kudos`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$giver_id` | int | Member who gave kudos |
| `$receiver_id` | int | Member who received kudos |
| `$message` | string | Optional message (may be empty string) |
| `$kudos_id` | int | New `wb_gam_kudos` row ID |

```php
add_action( 'wb_gamification_kudos_given', function( $giver_id, $receiver_id, $message, $kudos_id ) {
    my_slack()->post( "kudos: user {$giver_id} to user {$receiver_id}" );
}, 10, 4 );
```

---

### System Hooks

#### `wb_gamification_register`

Fires after `Registry::init()` at `plugins_loaded` priority 6. Register custom actions here.

```php
add_action( 'wb_gamification_register', function() {
    wb_gamification_register_action( [
        'id'             => 'my_plugin_action',
        'label'          => 'My Custom Action',
        'hook'           => 'my_plugin_event_hook',
        'user_callback'  => fn( $user_id ) => $user_id,
        'default_points' => 10,
        'category'       => 'my_plugin',
    ] );
} );
```

#### `wb_gamification_log_pruned`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$deleted` | int | Rows deleted from `wb_gam_points` |
| `$cutoff` | string | DATETIME cutoff used |

#### `wb_gamification_events_pruned`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$deleted_events` | int | Rows deleted from `wb_gam_events` |
| `$events_cutoff` | string | DATETIME cutoff used |

#### `wb_gamification_user_data_erased`

Fires after GDPR erasure removes all gamification data for a user.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | The erased user's ID |

#### `wb_gamification_cohort_outcome`

Fires at the end of a cohort week when promotion/demotion outcomes are resolved.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member |
| `$old_tier` | int | Previous cohort tier |
| `$new_tier` | int | New cohort tier |
| `$outcome` | string | `promoted`, `demoted`, or `retained` |
| `$week_pts` | int | Points earned during the cohort week |

#### `wb_gamification_cosmetic_granted`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member who received the cosmetic |
| `$cosmetic_id` | string | Cosmetic identifier |

#### `wb_gamification_weekly_email_sent`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Recipient member |
| `$data` | array | Email data payload |

#### `wb_gamification_weekly_nudge`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member |
| `$rank` | int | Current rank |
| `$points` | int | Current points |
| `$points_to_next` | int | Points needed to reach the next rank |
| `$message` | string | Pre-formatted nudge message |

#### `wb_gamification_retention_nudge`

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | int | Member |
| `$level` | array | Current level data |
| `$next` | array | Next level data |
| `$pts_needed` | int | Points needed to advance |
| `$message` | string | Pre-formatted nudge message |

---

## Filter Hooks

### `wb_gamification_event_metadata`

Enrich event metadata before rule evaluation. Runs before the event is persisted, so filters here affect what is stored in `wb_gam_events`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$metadata` | array | Current metadata array |
| `$event` | `Event` | The event being processed |

**Return:** `array`

```php
add_filter( 'wb_gamification_event_metadata', function( $metadata, $event ) {
    if ( 'publish_post' === $event->action_id && $event->object_id ) {
        $post = get_post( $event->object_id );
        $metadata['word_count'] = str_word_count( $post->post_content ?? '' );
    }
    return $metadata;
}, 10, 2 );
```

### `wb_gamification_before_evaluate`

Gate that can abort event processing entirely. Returning `false` stops the event without writing anything to the database.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$proceed` | bool | Whether to proceed (default `true`) |
| `$event` | `Event` | The event about to be evaluated |

**Return:** `bool`

```php
add_filter( 'wb_gamification_before_evaluate', function( $proceed, $event ) {
    // Block points for admin accounts.
    if ( user_can( $event->user_id, 'manage_options' ) ) {
        return false;
    }
    return $proceed;
}, 10, 2 );
```

### `wb_gamification_points_for_action`

Filter base points before `RuleEngine` multipliers are applied. Metadata enriched via `wb_gamification_event_metadata` is available in `$event->metadata`.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$points` | int | Base points from admin option or action default |
| `$action_id` | string | Action identifier |
| `$user_id` | int | User ID |
| `$event` | `Event` | Full event object |

**Return:** `int`

```php
add_filter( 'wb_gamification_points_for_action', function( $points, $action_id, $user_id, $event ) {
    // Double points for posts over 1,000 words.
    if ( 'publish_post' === $action_id && ( $event->metadata['word_count'] ?? 0 ) > 1000 ) {
        return $points * 2;
    }
    return $points;
}, 10, 4 );
```

### `wb_gamification_rank_automation_rules`

Filter the rank automation rules before they are evaluated.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$rules` | array | Rules from the `wb_gam_rank_automation_rules` option |

**Return:** `array`

### `wb_gamification_recap_data`

Filter a member's year-in-review data before it is returned by the API or emailed.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$recap` | array | Recap data array |
| `$user_id` | int | Member user ID |
| `$year` | int | Recap year |

**Return:** `array`

### `wb_gamification_credential_document`

Filter an OpenBadges 3.0 credential document before the REST API returns it.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$credential` | array | The credential document |
| `$badge_id` | string | Badge identifier |
| `$user_id` | int | Member user ID |

**Return:** `array`

```php
add_filter( 'wb_gamification_credential_document', function( $credential, $badge_id, $user_id ) {
    $credential['issuer']['url'] = 'https://mycommunity.example.com';
    return $credential;
}, 10, 3 );
```

### `wb_gamification_should_send_weekly_nudge`

Control whether a weekly leaderboard nudge is sent to a specific member.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$should_send` | bool | Default `true` |
| `$user_id` | int | Member user ID |
| `$rank_data` | array | Member's current rank data |

**Return:** `bool` — Return `false` to suppress the nudge
