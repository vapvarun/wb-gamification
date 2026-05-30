# Hooks and Filters Overview

WB Gamification exposes action hooks and filter hooks so you can extend the engine, add custom logic, or integrate with third-party systems without forking the plugin.

## What hooks are

A hook is a named extension point the plugin fires while it runs. There are two kinds:

- **Actions** let you run your own code when something happens (points awarded, badge earned, streak broken). You react to the event but do not change it.
- **Filters** let you modify a value before the engine uses it (the points for an action, a leaderboard result set, a toast payload). You receive a value and return a value.

Every hook is a no-op when nobody listens, so there is zero overhead by default.

## Naming convention

Hooks prefixed `wb_gam_` are the gamification hooks you extend. A small number of lifecycle hooks use the longer `wb_gamification_` prefix and are treated as stable public API.

Most hooks fire regardless of configuration. A few hooks only fire when their optional engine's feature flag is enabled in `wb_gam_features` (every flag defaults to `true`); those are called out in the reference pages.

## How to add a listener

Register an action with `add_action()` and a filter with `add_filter()`. Match the parameter count to the hook signature in the reference pages (the fourth argument to `add_action` / `add_filter`).

```php
// Action: react after points are written to the ledger.
add_action( 'wb_gam_points_awarded', function ( int $user_id, $event, int $points ) {
    // Sync points to an external CRM.
    my_crm_update_points( $user_id, $points, $event->action_id );
}, 10, 3 );

// Filter: double points on weekends before they are written.
add_filter( 'wb_gam_points_for_action', function ( int $points, string $action_id, int $user_id, $event ) {
    if ( in_array( gmdate( 'l' ), array( 'Saturday', 'Sunday' ), true ) ) {
        return $points * 2;
    }
    return $points;
}, 10, 4 );
```

For an action you do not need to return anything. For a filter you must always return a value (return the value unchanged when your condition does not apply).

## Reference index

| Page | Covers |
|------|--------|
| [Actions reference](13-actions-reference.md) | Every `do_action` hook: when it fires and the parameters it passes. |
| [Filters reference](14-filters-reference.md) | Every `apply_filters` hook: what it filters, the parameters, and the value to return. |

Both reference pages group hooks by domain: Points and awards, Badges and levels, Challenges and kudos, Submissions, Integrations, and Lifecycle.
