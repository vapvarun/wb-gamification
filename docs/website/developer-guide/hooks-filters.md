# Hooks & Filters

WB Gamification provides actions and filters for customising behaviour without modifying plugin files.

## Actions

### `wb_gamification_points_awarded`

Fires after points are successfully written to the database.

```php
do_action( 'wb_gamification_points_awarded', int $user_id, int $points, string $action_id, int $event_id );
```

| Parameter | Type | Description |
|---|---|---|
| `$user_id` | int | The member who received points |
| `$points` | int | Points awarded (negative for deductions) |
| `$action_id` | string | Action identifier (e.g. `publish_post`) |
| `$event_id` | int | Row ID in `wb_gam_points` |

**Example:** Trigger a Slack notification for large awards.

```php
add_action( 'wb_gamification_points_awarded', function( $user_id, $points, $action_id ) {
    if ( $points >= 50 ) {
        my_slack_notify( $user_id, $points, $action_id );
    }
}, 10, 3 );
```

---

### `wb_gamification_level_up`

Fires when a member reaches a new level.

```php
do_action( 'wb_gamification_level_up', int $user_id, array $new_level, array $old_level );
```

| Parameter | Type | Description |
|---|---|---|
| `$user_id` | int | Member who levelled up |
| `$new_level` | array | `{ level, name, min_points }` |
| `$old_level` | array | Previous level definition |

---

### `wb_gamification_badge_awarded`

Fires when a badge is awarded.

```php
do_action( 'wb_gamification_badge_awarded', int $user_id, int $badge_id, string $badge_slug );
```

---

### `wb_gamification_badge_revoked`

Fires when a badge is revoked.

```php
do_action( 'wb_gamification_badge_revoked', int $user_id, int $badge_id );
```

---

### `wb_gamification_streak_updated`

Fires when a member's streak count changes.

```php
do_action( 'wb_gamification_streak_updated', int $user_id, int $new_streak, int $prev_streak );
```

---

### `wb_gamification_log_action`

Fire this action to award points for a custom action you've added to the manifest.

```php
do_action( 'wb_gamification_log_action', int $user_id, string $action_id, array $context );
```

**Example:**

```php
// After a member completes a custom quiz:
do_action( 'wb_gamification_log_action', get_current_user_id(), 'quiz_complete', [] );
```

---

## Filters

### `wb_gamification_action_manifest`

Modify the full action manifest before it is registered. Add, remove, or change any action.

```php
apply_filters( 'wb_gamification_action_manifest', array $actions );
```

**Example:** Add a LearnDash quiz completion action.

```php
add_filter( 'wb_gamification_action_manifest', function( $actions ) {
    $actions['ld_quiz_complete'] = [
        'label'      => __( 'Completed a Quiz', 'my-plugin' ),
        'points'     => 25,
        'repeatable' => true,
        'cooldown'   => 0, // no cooldown — each quiz awards independently
        'category'   => 'learning',
    ];
    return $actions;
} );

// Hook into LearnDash to fire the action:
add_action( 'learndash_quiz_completed', function( $data ) {
    do_action( 'wb_gamification_log_action', $data['user']->ID, 'ld_quiz_complete', [] );
} );
```

---

### `wb_gamification_level_definitions`

Replace or extend the level ladder.

```php
apply_filters( 'wb_gamification_level_definitions', array $levels );
```

Each entry: `[ 'level' => int, 'name' => string, 'min_points' => int ]`.

---

### `wb_gamification_leaderboard_scope_user_ids`

Resolve a custom scope type to a list of user IDs for leaderboard filtering.

```php
apply_filters( 'wb_gamification_leaderboard_scope_user_ids', array $user_ids, string $scope_type, int $scope_id );
```

BuddyPress group scopes (`bp_group`) are handled internally. Use this filter for custom scopes.

**Example:** LearnDash course-scoped leaderboard.

```php
add_filter( 'wb_gamification_leaderboard_scope_user_ids', function( $ids, $scope_type, $scope_id ) {
    if ( 'ld_course' === $scope_type ) {
        return learndash_get_course_users_access_from_meta( $scope_id );
    }
    return $ids;
}, 10, 3 );

// In your template: [wb_gam_leaderboard scope_type="ld_course" scope_id="101"]
```

---

### `wb_gamification_can_award_badge`

Return `false` to prevent a badge from being awarded.

```php
apply_filters( 'wb_gamification_can_award_badge', bool $can, int $user_id, int $badge_id );
```

---

### `wb_gamification_points_multiplier`

Apply a multiplier to points before they are recorded. Return a float.

```php
apply_filters( 'wb_gamification_points_multiplier', float $multiplier, int $user_id, string $action_id );
```

**Example:** Double points events on weekends.

```php
add_filter( 'wb_gamification_points_multiplier', function( $multiplier, $user_id, $action_id ) {
    if ( in_array( gmdate( 'N' ), [ '6', '7' ], true ) ) {
        return 2.0;
    }
    return $multiplier;
}, 10, 3 );
```

---

## Capabilities

| Capability | Default Holder | Description |
|---|---|---|
| `manage_options` | Administrators | Access all Gamification admin pages |
| `wb_gam_award_manual` | Administrators | Award or revoke badges and points manually |

To grant `wb_gam_award_manual` to another role:

```php
add_action( 'init', function() {
    $role = get_role( 'editor' );
    if ( $role ) {
        $role->add_cap( 'wb_gam_award_manual' );
    }
} );
```
