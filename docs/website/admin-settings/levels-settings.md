# Levels Configuration

The **Levels** tab at **Gamification → Settings → Levels** defines the ladder members climb as they accumulate points.

## Level Table

| Column | Description |
|---|---|
| Level | Level number (read-only) |
| Name | Display name shown on profiles and in blocks |
| Min Points | Minimum cumulative points required to reach this level |

## Editing Levels

Click any Name or Min Points field and type a new value. Click **Save Levels** to apply.

Changes are immediate — members are re-evaluated against the new thresholds on their next page load.

## Default Ladder

| Level | Name | Min Points |
|---|---|---|
| 1 | Newcomer | 0 |
| 2 | Regular | 100 |
| 3 | Contributor | 300 |
| 4 | Established | 600 |
| 5 | Active | 1,000 |
| 6 | Veteran | 2,000 |
| 7 | Senior | 3,500 |
| 8 | Expert | 5,500 |
| 9 | Elite | 7,500 |
| 10 | Legend | 10,000 |

## Adding or Removing Levels

The admin UI manages a fixed 10-level structure. To use more or fewer levels, use the `wb_gamification_level_definitions` filter in a custom plugin or your theme's `functions.php`:

```php
add_filter( 'wb_gamification_level_definitions', function( $levels ) {
    // Replace with a custom 5-level structure.
    return [
        [ 'level' => 1, 'name' => 'Seedling',   'min_points' => 0 ],
        [ 'level' => 2, 'name' => 'Sprout',      'min_points' => 200 ],
        [ 'level' => 3, 'name' => 'Sapling',     'min_points' => 600 ],
        [ 'level' => 4, 'name' => 'Tree',        'min_points' => 1500 ],
        [ 'level' => 5, 'name' => 'Ancient Oak', 'min_points' => 4000 ],
    ];
} );
```

## Level-Up Notifications

When a member reaches a new level, they receive:

- A BuddyPress notification (if BuddyPress is active) linking to their profile
- A WordPress notification (fallback on non-BuddyPress sites)

The notification text includes the new level name. To customise the message, hook into `wb_gamification_level_up`.
