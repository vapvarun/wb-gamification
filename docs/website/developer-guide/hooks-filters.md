# Hooks & Filters Reference

Complete reference for all action hooks and filter hooks in WB Gamification. Use these to extend the engine, add custom logic, or integrate with third-party systems.

**Convention:** Hooks prefixed `wb_gamification_` are stable public API. Hooks prefixed `wb_gam_` are internal (may change between versions).

**Pro label:** Hooks marked **[Pro]** fire only when the Pro add-on is active. They are safe to hook into from any code — they simply never fire without Pro.

---

## Action Hooks

### Points

#### `wb_gamification_before_points_awarded`

Fires **before** points are written to the ledger. All checks have passed (enabled, rate limits, gate filter, multipliers). Last chance to inspect or log before it becomes permanent.

```php
add_action( 'wb_gamification_before_points_awarded', function( int $user_id, $event, int $points ) {
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

#### `wb_gamification_points_awarded`

Fires **after** points are written to the ledger. The most-used hook — triggers badge evaluation, level check, streak update, and notifications.

```php
add_action( 'wb_gamification_points_awarded', function( int $user_id, $event, int $points ) {
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

#### `wb_gamification_points_revoked`

Fires when an admin revokes (deletes) a point award via the REST API.

```php
add_action( 'wb_gamification_points_revoked', function( int $row_id, array $row, int $admin_id ) {
    // Notify the user their points were revoked.
}, 10, 3 );
```

---

#### `wb_gamification_points_redeemed`

Fires when a member redeems points for a reward in the redemption store.

```php
add_action( 'wb_gamification_points_redeemed', function( int $redemption_id, int $user_id, array $item, ?string $coupon ) {
    // Send a confirmation email with the coupon code.
}, 10, 4 );
```

---

### Badges

#### `wb_gamification_badge_awarded`

Fires after a badge is awarded to a member.

```php
add_action( 'wb_gamification_badge_awarded', function( int $user_id, array $badge_def, string $badge_id ) {
    // Post to Slack when someone earns a special badge.
    if ( 'founding_member' === $badge_id ) {
        slack_notify( "User {$user_id} earned Founding Member!" );
    }
}, 10, 3 );
```

---

#### `wb_gamification_credential_expired` **[Pro]**

Fires when a badge credential passes its `expires_at` date during the daily expiry check.

```php
add_action( 'wb_gamification_credential_expired', function( int $user_id, string $badge_id, string $expires_at ) {
    // Notify the user to renew their credential.
}, 10, 3 );
```

---

### Levels

#### `wb_gamification_level_changed`

Fires when a member moves to a different level (up or down). Does NOT fire on initial assignment to "Newcomer".

```php
add_action( 'wb_gamification_level_changed', function( int $user_id, int $old_level_id, int $new_level_id ) {
    // Assign a WordPress role based on level.
    if ( $new_level_id >= 5 ) {
        $user = get_user_by( 'id', $user_id );
        $user->set_role( 'contributor' );
    }
}, 10, 3 );
```

---

### Streaks

#### `wb_gamification_streak_milestone`

Fires when a member reaches a streak milestone (7, 14, 30, 60, 100, 180, or 365 days).

```php
add_action( 'wb_gamification_streak_milestone', function( int $user_id, int $streak_days ) {
    if ( $streak_days >= 30 ) {
        \WBGam\Engine\BadgeEngine::award_badge( $user_id, 'dedicated_30' );
    }
}, 10, 2 );
```

---

#### `wb_gamification_streak_broken`

Fires when a member's streak is reset to 1 after exceeding the grace period.

```php
add_action( 'wb_gamification_streak_broken', function( int $user_id, int $old_streak, int $gap_days ) {
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

#### `wb_gamification_challenge_completed`

Fires when a member completes a challenge (reaches the target count).

```php
add_action( 'wb_gamification_challenge_completed', function( int $user_id, array $challenge ) {
    my_analytics_track( 'challenge_completed', [
        'user_id'      => $user_id,
        'challenge_id' => $challenge['id'],
        'title'        => $challenge['title'],
    ] );
}, 10, 2 );
```

---

#### `wb_gamification_challenge_created`

Fires when an admin creates a new challenge.

#### `wb_gamification_challenge_updated`

Fires when an admin updates an existing challenge.

#### `wb_gamification_challenge_deleted`

Fires when an admin deletes a challenge.

#### `wb_gamification_community_challenge_completed` **[Pro]**

Fires when a community (team) challenge reaches its global target.

---

### Kudos

#### `wb_gamification_kudos_given`

Fires after kudos are successfully recorded.

```php
add_action( 'wb_gamification_kudos_given', function( int $giver_id, int $receiver_id, string $message, int $kudos_id ) {
    // Post to BuddyPress activity stream.
}, 10, 4 );
```

---

### Pro Engine Hooks

#### `wb_gamification_weekly_email_sent` **[Pro]**

Fires after a weekly recap email is sent.

#### `wb_gamification_weekly_nudge` **[Pro]**

Fires when a leaderboard nudge email is sent.

#### `wb_gamification_cosmetic_granted` **[Pro]**

Fires when a cosmetic/frame is granted to a member.

#### `wb_gamification_cohort_outcome` **[Pro]**

Fires when a cohort league season ends with promotion/demotion results.

```php
add_action( 'wb_gamification_cohort_outcome', function( int $user_id, int $old_tier, int $new_tier, string $outcome, int $points ) {
    // $outcome is 'promoted', 'demoted', or 'stayed'.
}, 10, 5 );
```

#### `wb_gamification_retention_nudge` **[Pro]**

Fires when a re-engagement nudge is sent to an inactive member.

#### `wb_gamification_personal_record`

Fires when a member sets a personal record.

---

### System Hooks

#### `wb_gam_engines_booted`

Fires after all engines have initialized. Pro plugin hooks here to register additional engines.

#### `wb_gamification_register`

Fires after the Registry is initialized. Last chance to register actions via the manual API.

#### `wb_gamification_rank_automation_action`

Fires when a rank automation rule is triggered.

#### `wb_gamification_log_pruned`

Fires after the daily log pruner runs.

#### `wb_gamification_user_data_erased`

Fires after all gamification data for a user is erased (GDPR).

---

## Filter Hooks

### `wb_gamification_points_for_action`

Modify points before they are written. Called after admin option lookup, before multipliers.

```php
// Double points on weekends.
add_filter( 'wb_gamification_points_for_action', function( int $points, string $action_id, int $user_id, $event ) {
    if ( in_array( gmdate( 'l' ), [ 'Saturday', 'Sunday' ], true ) ) {
        return $points * 2;
    }
    return $points;
}, 10, 4 );
```

---

### `wb_gamification_before_evaluate`

Gate filter — return `false` to silently block an event from being processed.

```php
// Block all gamification for suspended users.
add_filter( 'wb_gamification_before_evaluate', function( bool $proceed, $event ) {
    if ( get_user_meta( $event->user_id, 'is_suspended', true ) ) {
        return false;
    }
    return $proceed;
}, 10, 2 );
```

---

### `wb_gamification_event_metadata`

Enrich event metadata before rule evaluation.

```php
add_filter( 'wb_gamification_event_metadata', function( array $metadata, $event ) {
    if ( isset( $metadata['content'] ) ) {
        $metadata['word_count'] = str_word_count( wp_strip_all_tags( $metadata['content'] ) );
    }
    return $metadata;
}, 10, 2 );
```

---

### `wb_gamification_should_award_badge`

Gate filter — return `false` to prevent a specific badge from being awarded.

```php
// Only award "Top Contributor" to users with 6+ months membership.
add_filter( 'wb_gamification_should_award_badge', function( bool $should, int $user_id, string $badge_id, array $def ) {
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

### `wb_gamification_streak_grace_days`

Override the grace period (days before streak breaks) per user.

```php
// Pro members get 3 grace days instead of 1.
add_filter( 'wb_gamification_streak_grace_days', function( int $days, int $user_id ) {
    if ( user_can( $user_id, 'premium_member' ) ) {
        return 3;
    }
    return $days;
}, 10, 2 );
```

---

### `wb_gamification_before_kudos`

Validate or block kudos before they are recorded. Return a `WP_Error` to reject.

```php
// Require minimum account age to give kudos.
add_filter( 'wb_gamification_before_kudos', function( $result, int $giver_id, int $receiver_id, string $message ) {
    $registered = strtotime( get_userdata( $giver_id )->user_registered );
    if ( time() - $registered < WEEK_IN_SECONDS ) {
        return new WP_Error( 'too_new', 'Your account must be at least 7 days old to give kudos.' );
    }
    return $result;
}, 10, 4 );
```

---

### `wb_gamification_leaderboard_results`

Modify leaderboard data before it is returned to blocks, shortcodes, or the REST API.

```php
// Add badge count to leaderboard entries.
add_filter( 'wb_gamification_leaderboard_results', function( array $results, array $raw_rows ) {
    foreach ( $results as &$entry ) {
        $entry['badge_count'] = count( wb_gam_get_user_badges( $entry['user_id'] ) );
    }
    return $results;
}, 10, 2 );
```

---

### `wb_gamification_toast_data`

Modify toast notification content before it is queued. Return empty array to suppress.

```php
// Suppress point toasts for minor actions.
add_filter( 'wb_gamification_toast_data', function( array $event, int $user_id ) {
    if ( 'points' === ( $event['type'] ?? '' ) && ( $event['points'] ?? 0 ) < 5 ) {
        return []; // Suppress — too small to notify.
    }
    return $event;
}, 10, 2 );
```

---

### `wb_gamification_credential_document`

Modify the OpenBadges 3.0 JSON-LD credential before it is returned.

### `wb_gamification_recap_data` **[Pro]**

Modify the year-in-review recap data before display.

### `wb_gamification_rank_automation_rules`

Modify rank automation rules before they are evaluated.

### `wb_gamification_should_send_weekly_nudge` **[Pro]**

Control whether a weekly nudge email should be sent to a specific user.

---

## Quick Reference Table

### Actions (28 total)

| Hook | File | Free/Pro |
|------|------|----------|
| `wb_gamification_before_points_awarded` | Engine.php | Free |
| `wb_gamification_points_awarded` | Engine.php | Free |
| `wb_gamification_points_revoked` | PointsController.php | Free |
| `wb_gamification_points_redeemed` | RedemptionEngine.php | Free |
| `wb_gamification_badge_awarded` | BadgeEngine.php | Free |
| `wb_gamification_credential_expired` | CredentialExpiryEngine.php | Pro |
| `wb_gamification_level_changed` | LevelEngine.php | Free |
| `wb_gamification_streak_milestone` | StreakEngine.php | Free |
| `wb_gamification_streak_broken` | StreakEngine.php | Free |
| `wb_gamification_challenge_completed` | ChallengeEngine.php | Free |
| `wb_gamification_challenge_created` | ChallengeManagerPage.php | Free |
| `wb_gamification_challenge_updated` | ChallengeManagerPage.php | Free |
| `wb_gamification_challenge_deleted` | ChallengeManagerPage.php | Free |
| `wb_gamification_community_challenge_completed` | CommunityChallengeEngine.php | Pro |
| `wb_gamification_kudos_given` | KudosEngine.php | Free |
| `wb_gamification_rank_automation_action` | RankAutomation.php | Free |
| `wb_gamification_personal_record` | PersonalRecordEngine.php | Free |
| `wb_gamification_weekly_email_sent` | WeeklyEmailEngine.php | Pro |
| `wb_gamification_weekly_nudge` | LeaderboardNudge.php | Pro |
| `wb_gamification_cosmetic_granted` | CosmeticEngine.php | Pro |
| `wb_gamification_cohort_outcome` | CohortEngine.php | Pro |
| `wb_gamification_retention_nudge` | StatusRetentionEngine.php | Pro |
| `wb_gamification_log_pruned` | LogPruner.php | Free |
| `wb_gamification_events_pruned` | LogPruner.php | Free |
| `wb_gamification_user_data_erased` | Privacy.php | Free |
| `wb_gam_engines_booted` | FeatureFlags.php | Free |
| `wb_gamification_register` | Registry.php | Free |

### Filters (11 total)

| Hook | File | Free/Pro |
|------|------|----------|
| `wb_gamification_points_for_action` | Engine.php | Free |
| `wb_gamification_before_evaluate` | Engine.php | Free |
| `wb_gamification_event_metadata` | Engine.php | Free |
| `wb_gamification_should_award_badge` | BadgeEngine.php | Free |
| `wb_gamification_streak_grace_days` | StreakEngine.php | Free |
| `wb_gamification_before_kudos` | KudosEngine.php | Free |
| `wb_gamification_leaderboard_results` | LeaderboardEngine.php | Free |
| `wb_gamification_toast_data` | NotificationBridge.php | Free |
| `wb_gamification_credential_document` | CredentialController.php | Free |
| `wb_gamification_recap_data` | RecapEngine.php | Pro |
| `wb_gamification_rank_automation_rules` | RankAutomation.php | Free |
| `wb_gamification_should_send_weekly_nudge` | LeaderboardNudge.php | Pro |
