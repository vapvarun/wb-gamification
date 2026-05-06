# Helper Functions

All functions are defined in `src/Extensions/functions.php` and available globally once WB Gamification is active. No `use` statement or class prefix is needed.

---

## Action Registration

### `wb_gam_register_action( array $args ): void`

Register a custom action that awards points when a WordPress hook fires. Routes directly to `Registry::register_action()`.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$args['id']` | string | Yes | Unique action identifier |
| `$args['label']` | string | Yes | Human-readable label |
| `$args['description']` | string | No | Optional description |
| `$args['hook']` | string | Yes | WordPress hook name |
| `$args['user_callback']` | callable | Yes | Returns the user ID from hook arguments |
| `$args['default_points']` | int | Yes | Default points awarded |
| `$args['category']` | string | No | Category slug |
| `$args['icon']` | string | No | Dashicon class |
| `$args['repeatable']` | bool | No | Allow multiple awards. Default `true` |
| `$args['cooldown']` | int | No | Seconds between awards. `0` = none |
| `$args['daily_cap']` | int | No | Max awards per day. `0` = unlimited |
| `$args['weekly_cap']` | int | No | Max awards per week. `0` = unlimited |

```php
add_action( 'wb_gam_register', function() {
    wb_gam_register_action( [
        'id'             => 'my_plugin_signup',
        'label'          => 'Signed up via My Plugin',
        'hook'           => 'my_plugin_user_signup',
        'user_callback'  => fn( $user_id ) => $user_id,
        'default_points' => 50,
        'category'       => 'my_plugin',
        'repeatable'     => false,
    ] );
} );
```

### `wb_gam_register_badge_trigger( array $args ): void`

Register a custom badge trigger condition. Routes to `Registry::register_badge_trigger()`.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$args['id']` | string | Yes | Unique trigger identifier |
| `$args['label']` | string | Yes | Human-readable label |
| `$args['hook']` | string | Yes | WordPress hook to listen on |
| `$args['condition']` | callable | Yes | Returns `true` when the badge should be awarded |

### `wb_gam_register_challenge_type( array $args ): void`

Register a custom challenge type. Routes to `Registry::register_challenge_type()`.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$args['id']` | string | Yes | Unique challenge type identifier |
| `$args['label']` | string | Yes | Human-readable label |
| `$args['action_id']` | string | Yes | Action ID this challenge tracks |
| `$args['countable']` | bool | No | Whether progress is tracked by count |

---

## Points Functions

### `wb_gam_get_user_points( int $user_id ): int`

Get the total accumulated points for a user. Reads from the object cache first; falls back to a SUM query on `wb_gam_points`.

```php
$points = wb_gam_get_user_points( get_current_user_id() );
echo "You have {$points} points.";
```

### `wb_gam_award_points( int $user_id, int $points, string $action_id = 'manual', int $object_id = 0 ): bool`

Award points to a user manually. Bypasses cooldown and cap checks. Routes through `Engine::process()` so the event is persisted and all hooks fire normally.

Returns `false` if `$points <= 0` or `$user_id <= 0`.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$user_id` | int | — | WordPress user ID |
| `$points` | int | — | Points to award (must be > 0) |
| `$action_id` | string | `'manual'` | Action ID logged against the points row |
| `$object_id` | int | `0` | Optional related object (e.g. post ID) |

```php
// Award 100 bonus points.
$awarded = wb_gam_award_points( $user_id, 100, 'promo_bonus' );

if ( $awarded ) {
    // Points were written and hooks fired.
}
```

### `wb_gam_get_user_action_count( int $user_id, string $action_id ): int`

Get how many times a specific action has been awarded to a user.

```php
$post_count = wb_gam_get_user_action_count( $user_id, 'publish_post' );
if ( $post_count >= 10 ) {
    // User is a prolific writer.
}
```

---

## Badge Functions

### `wb_gam_has_badge( int $user_id, string $badge_id ): bool`

Check whether a user currently holds a specific badge. Respects expiry — expired badges return `false`.

```php
if ( wb_gam_has_badge( $user_id, 'top_contributor' ) ) {
    // Show a special UI element.
}
```

### `wb_gam_get_user_badges( int $user_id ): array`

Get all badges currently held by a user as an array of badge data rows. Expired badges are excluded.

```php
$badges = wb_gam_get_user_badges( $user_id );
foreach ( $badges as $badge ) {
    echo $badge['name'] . ' — earned ' . $badge['earned_at'];
}
```

---

## Level Functions

### `wb_gam_get_user_level( int $user_id ): ?array`

Get the current level for a user. Returns `null` if no level threshold has been met.

**Return shape:** `array{ id: int, name: string, min_points: int }` or `null`

```php
$level = wb_gam_get_user_level( $user_id );
if ( $level ) {
    echo "Level: " . $level['name'];
}
```

---

## Streak Functions

### `wb_gam_get_user_streak( int $user_id ): array`

Get a user's current streak data.

**Return shape:** `array{ current_streak: int, longest_streak: int, last_active: string }`

```php
$streak = wb_gam_get_user_streak( $user_id );
echo "Current streak: {$streak['current_streak']} days";
echo "Best streak: {$streak['longest_streak']} days";
```

---

## Leaderboard Functions

### `wb_gam_get_leaderboard( string $period = 'all', int $limit = 10 ): array`

Get the leaderboard for a given period. Reads from `wb_gam_leaderboard_cache` for performance.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$period` | string | `'all'` | `'all'`, `'week'`, `'month'`, `'day'` |
| `$limit` | int | `10` | Number of entries to return |

```php
$top_10 = wb_gam_get_leaderboard( 'week', 10 );
foreach ( $top_10 as $row ) {
    printf( "#%d: %s — %d pts\n", $row['rank'], $row['display_name'], $row['points'] );
}
```

---

## Feature Flags

### `wb_gam_is_feature_enabled( string $feature ): bool`

Check whether a feature flag is currently enabled. Reads from `WBGam\Engine\FeatureFlags`.

```php
if ( wb_gam_is_feature_enabled( 'cohort_leagues' ) ) {
    // Show cohort league UI.
}
```

Common feature flags: `cohort_leagues`, `weekly_email`, `cosmetics`, `redemption_store`, `site_first_badges`.
