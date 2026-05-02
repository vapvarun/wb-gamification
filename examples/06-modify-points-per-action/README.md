# Example 06 — Modify Points Per Action (filter usage)

The `wb_gamification_points_for_action` filter lets you transform the points awarded for any action just before they're written to the ledger. No engine fork, no override — pure filter.

## Use cases

- **Tier multipliers** — "VIPs earn 2× points on everything"
- **Context-aware bonuses** — "Posts in the Tutorials category award 2× points"
- **Time-windowed campaigns** — "Double points week starts June 1"
- **Anti-gaming vetos** — "Suspected spam accounts earn 0 points"
- **Per-user adjustment** — "Beta testers earn 50% bonus during beta"

## Files in this example

- [`your-plugin.php`](your-plugin.php) — four chained filter patterns demonstrating composability via priorities.

## Hook signature

```php
apply_filters(
    'wb_gamification_points_for_action',
    int $points,        // Default points from action config
    string $action_id,  // The action being awarded for
    int $user_id        // Who's earning
);
```

Return the modified int. The engine clamps negative values to 0; no need to floor manually.

## Priority composition

When multiple filters chain, the lowest priority runs first. The example file uses:

- Priority **5** — anti-spam veto (run early, can short-circuit to 0)
- Priority **10** — VIP tier multiplier
- Priority **20** — per-action context bonus (Tutorials category)
- Priority **30** — time-windowed double-points campaign

So a VIP user posting a Tutorials article during double-points week gets:

```
25 (default for wp_publish_post)
× 2.0 (VIP multiplier)         = 50
+ 25 (Tutorials bonus)          = 75
× 2 (campaign double-points)    = 150
```

A spam-flagged user gets 0 (anti-spam runs first, returns 0, terminates the chain).

## Where in the pipeline

`wb_gamification_points_for_action` runs INSIDE `PointsEngine::award_for_action` after:
- ✓ Rate limiter has approved the award
- ✓ `wb_gamification_before_evaluate` filter has approved the event
- ✓ The action's configured `default_points` has been resolved (admin override applied)

…and before:
- The points row is inserted into `wb_gam_points`
- The `wb_gamification_points_awarded` action fires

So your filter sees the final-pre-award value and gets the last word.

## Debugging

Hook `wb_gamification_points_awarded` to log the FINAL value (after all your filters). The example file includes a debug-mode logger.

```bash
# Set YOURPLUGIN_DEBUG = true in wp-config.php
# Then watch debug.log:
tail -f wp-content/debug.log
```

## Related filters

If you want to veto the entire event rather than just modify points:

- `wb_gamification_before_evaluate` — runs BEFORE points are computed; return modified event payload (or null to skip)
- `wb_gamification_should_award_badge` — final veto on badge awards specifically (see [Example 05](../05-custom-badge-condition/))
- `wb_gamification_event_metadata` — augment metadata before storage

## Don't do this

Don't use `wb_gamification_points_for_action` to OVERRIDE manual admin awards — admin manual awards (via `POST /points/award` or `wp wb-gamification points award`) bypass this filter intentionally. They use `reason='manual_award'` and skip the rule pipeline. If you want to gate manual awards too, hook `wb_gamification_before_points_awarded` instead (vetoable action, fires for both rule-driven and manual paths).

## Performance note

This filter fires on every event. Keep your callback fast — no DB queries per call if you can avoid it. The example file has a `get_posts()` lookup in the Tutorials-bonus filter; for production, cache the post→category mapping in the originating event payload via `wb_gamification_event_metadata` so this filter doesn't have to re-query.
