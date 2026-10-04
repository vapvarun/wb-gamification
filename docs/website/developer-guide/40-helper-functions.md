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

### `wb_gam_get_action_label( string $action_id ): string`

The label an action id shows everywhere (toasts, points history, REST `label`, analytics): the registered action's label, engine ids such as kudos and manual awards, and ids an integration names through the `wb_gam_action_label` filter, with a readable fallback. Use it in your own points history so it reads the same as the plugin's. Added in 1.6.5.

```php
echo esc_html( wb_gam_get_action_label( 'jetonomy_reply_created' ) ); // "Replied in the forum"
```

To rename a reason on your site, filter it. Keep the words translatable if the site is multilingual:

```php
add_filter( 'wb_gam_action_label', function ( string $label, string $action_id ): string {
	return 'login_bonus' === $action_id ? __( 'Daily check-in', 'my-site' ) : $label;
}, 10, 2 );
```

Members on a translated site can also get new wording from a translation plugin such as Loco Translate, with no code.

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

### `wb_gam_get_leaderboard_page( string $period = 'all', int $limit = 25, string $cursor = '', string $point_type = '' ): array`

One page of a leaderboard, for browsing past the first screen. Added in 1.6.5.

Paging is forward-only and keyset-based: pass the previous page's `next_cursor` back in. There is no page number or offset, on purpose: jumping to "page 3,000" of a 100k-member board would read every row before it. A page deep in the board costs the same as the first.

| Key | Type | Description |
|-----|------|-------------|
| `rows` | array | Same row shape as `wb_gam_get_leaderboard()`. `rank` is absolute, so page two starts where page one ended, and members tied on points keep one rank across the break. |
| `has_more` | bool | Whether another page follows. |
| `next_cursor` | string | Pass this as `$cursor` for the next page. Empty on the last page. |
| `offset` | int | Members before this page. |
| `total` | int | Members ranked on this board (cached for five minutes). |
| `invalid_cursor` | bool | Present and `true` when the cursor is malformed or belongs to another board (a different period or currency). The page is then empty: it never returns stale data. |

Day, week and month boards end at 500 ranks. The all-time board has no limit.

```php
$cursor = '';
do {
    $page = wb_gam_get_leaderboard_page( 'all', 50, $cursor );
    foreach ( $page['rows'] as $row ) {
        printf( "#%d %s\n", $row['rank'], $row['display_name'] );
    }
    $cursor = $page['next_cursor'];
} while ( $page['has_more'] );
```

`wb_gam_get_leaderboard()` is unchanged and still returns only the rows.

### `wb_gam_get_leaderboard_total( string $period = 'all', string $point_type = '' ): int`

How many members a board holds, for a "page X of Y" line. Cached for five minutes, and counted with the same eligibility as the rows (opted-out, excluded and deleted members are not counted). Added in 1.6.5.

---

## Feature Flags

### `wb_gam_is_feature_enabled( string $feature ): bool`

Check whether a feature flag is currently enabled. Reads from `WBGam\Engine\FeatureFlags`.

```php
if ( wb_gam_is_feature_enabled( 'cohort_leagues' ) ) {
    // Show cohort league UI.
}
```

The optional-engine feature flags are: `cohort_leagues`, `weekly_emails`, `leaderboard_nudge`, `status_retention`, `community_challenges`, and `badge_share`. Every flag defaults to on and is toggled from **Settings > Modules**.

---

## Partner Plugin Helpers

Since 1.6.5. Use these instead of calling the engine classes (`WBGam\Engine\*`), which can change between releases. Stored times they return (`created_at`, `earned_at`) are UTC; show them with `wp_date()` or `get_date_from_gmt()`.

| Function | Returns |
|---|---|
| `wb_gam_is_action_enabled( string $action_id ): bool` | Whether the owner has the action switched on. |
| `wb_gam_get_action_points( string $action_id ): int` | The owner's points setting, else the action default; 0 for an unregistered id. |
| `wb_gam_format_points( int $amount, string $slug = '', bool $signed = false ): string` | An amount with the site's name for it, singular for exactly one: "1 Point", "250 Points", "+10 Karma". Use it wherever a number of points is shown. |
| `wb_gam_get_point_type_label( string $slug = '' ): string` | The point type's display name ("Points", "Coins"); `''` means the default type. |
| `wb_gam_get_category_label( string $slug ): string` | A readable category heading ("member-blog" reads "Member Blog"). |
| `wb_gam_is_module_enabled( string $slug ): bool` | Whether a module on **Settings > Modules** is on (`kudos`, `badges`, ...). |
| `wb_gam_get_points_history( int $user_id, int $limit = 20, ?string $point_type = null ): array` | Recent transactions, newest first. |
| `wb_gam_get_user_rank( int $user_id, string $period = 'all', string $point_type = '' ): array` | `rank`, `points`, `points_to_next`. |
| `wb_gam_get_next_level( int $user_id ): ?array` | The next level, or `null` at the top. |
| `wb_gam_get_earned_points( int $user_id ): int` | Points earned (balance plus points spent through `wb_gam_spend_points()`). Levels and leaderboards follow this: compute "points to the next level" from it, not the balance. |
| `wb_gam_is_level_climb( ?array $new_level, ?array $old_level ): bool` | For a `wb_gam_level_changed` listener: true only when the member moved up. Announce climbs; apply drops quietly. |
| `wb_gam_get_contribution_data( int $user_id, int $days = 365 ): array` | Points per site-calendar day, for a heatmap. |
| `wb_gam_get_all_badges_for_user( int $user_id = 0 ): array` | Every badge, each with `earned` and `earned_at`. |
| `wb_gam_get_shared_badges( int $user_id ): array` | Badge ids the member shared publicly. |
| `wb_gam_get_badge_share_url( string $badge_id, int $user_id ): string` | The badge's public share page. |
| `wb_gam_send_kudos( int $giver_id, int $receiver_id, string $message = '' ): bool\|WP_Error` | Sends kudos with every rule the Give Kudos form applies. |
| `wb_gam_can_send_kudos( int $giver_id ): bool` | Whether the member may send kudos now. |
| `wb_gam_has_recent_kudos( int $giver_id, int $receiver_id, int $cooldown_seconds ): bool` | Whether the sender already gave this member kudos within the window. |
| `wb_gam_get_kudos_received( int $user_id, int $limit = 20 ): array` | Kudos received, newest first. |
| `wb_gam_get_kudos_received_count( int $user_id ): int` | How many kudos the member received. |
| `wb_gam_leaderboard_deferred_to_jetonomy(): bool` | Whether the owner handed the leaderboard to Jetonomy. Hide your own leaderboard page or menu item when true, so members see one ranking. |

```php
if ( function_exists( 'wb_gam_get_user_rank' ) ) {
    $rank = wb_gam_get_user_rank( $user_id );
    printf( '#%d with %d %s', $rank['rank'], $rank['points'], wb_gam_get_point_type_label() );
}
```
