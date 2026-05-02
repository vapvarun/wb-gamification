# Example 05 — Custom Badge Condition

Define a badge whose award condition is arbitrary PHP — not just "earn N points" or "do X N times".

## When to use this

Out of the box, badges are awarded based on rules in `wb_gam_rules` (point thresholds, level reached, action count). For more complex conditions:

- **Time-based**: "Night Owl" badge for commenting between midnight and 4 AM
- **Streak-based**: "Comment Streak" for commenting 7 different days in a row
- **State-based**: "Polyglot" for commenting in 3+ languages
- **Cross-feature**: "Explorer" for using 5 different plugin features
- **External API**: "GitHub Star" for getting a star on the project's GitHub repo

## How it works

`wb_gamification_register_badge_trigger()` (defined in `src/Extensions/functions.php:64`) registers a hook + condition pair. When the hook fires, your closure runs. If it returns `true`, the engine awards the badge whose `id` you specified.

You're responsible for two things:

1. **Creating the badge definition** in WP Admin → Gamification → Badges (icon, name, description, share image). The trigger only handles the condition; the badge itself is admin-managed.
2. **Writing the condition closure** that returns `true` when the user qualifies.

## Files in this example

- [`your-plugin.php`](your-plugin.php) — two complete badge triggers (Night Owl + Comment Streak) plus a `should_award_badge` filter to demo the veto layer.

## Two patterns demonstrated

### Pattern A: stateless condition (Night Owl)

The hook (`comment_post`) is the trigger. The condition reads the comment timestamp and checks "is the hour between 0 and 4?". No state storage; pure function of inputs.

### Pattern B: stateful streak (Comment Streak)

We need to know "did this user comment yesterday, and the day before?" — that's state. The condition writes to `_yourplugin_comment_dates` user meta on each call, then checks for 7 consecutive days.

For high-volume use cases, replace user meta with a custom DB table (the engine already does this — see `wb_gam_streaks`).

## The veto layer

After your `condition` returns `true`, the engine fires the `wb_gamification_should_award_badge` filter. This is your last chance to deny the award based on additional context (subscriber-only, country restriction, anti-gaming check, etc.).

```php
add_filter( 'wb_gamification_should_award_badge', function ( $should, $badge_id, $user_id ) {
    if ( 'night_owl' === $badge_id && yourplugin_is_anti_gaming_flagged( $user_id ) ) {
        return false;
    }
    return $should;
}, 10, 3 );
```

The example file demonstrates this pattern.

## Verification

After registering the trigger, simulate the qualifying action and check:

```bash
wp wb-gamification member status --user=42 | grep night_owl
# Or via REST:
curl http://your-site/wp-json/wb-gamification/v1/members/42/badges | jq '.badges[] | select(.id == "night_owl")'
```

Successful awards also fire the `wb_gamification_badge_awarded` action — the BP notification bridge and webhook dispatcher react automatically.

## Related

- For point-threshold badges (the default), no PHP needed — just create the badge in admin and set the rule there.
- For modifying point amounts (e.g. tier multipliers) → [Example 06](../06-modify-points-per-action/)
- For custom challenge mechanics → [Example 07](../07-custom-challenge-type/)
