# Example 08 — Leaderboard Customization

Annotate, filter, or restructure leaderboard results without forking the leaderboard block. Pure filter usage.

## What you can do

- **Add columns** — country flag, trending indicator, custom badge counts
- **Filter rows** — per-country leaderboards, per-cohort, custom segments
- **Re-rank** — apply different sort criteria after the engine's default sort
- **Inject metadata** — anything keyed on user_id

## What you can NOT do (yet)

- Add visual UI to the rendered block — see [G1 in INTEGRATION-GAPS-ROADMAP.md](../../plans/INTEGRATION-GAPS-ROADMAP.md). Today, columns added via this filter are present in the JSON response but the leaderboard block's render template doesn't display them. To show them, either fork the block or build a competing block that reads the same REST endpoint.

## Files in this example

- [`your-plugin.php`](your-plugin.php) — three chained filter patterns: country annotation, country-scoped filter, trending indicator.

## Hook signature

```php
apply_filters(
    'wb_gamification_leaderboard_results',
    array $rows,    // Sorted rows from the engine
    array $args     // Original query args (period, scope, limit, offset)
);
```

Each row has shape:
```php
[
    'rank'         => 1,
    'user_id'      => 42,
    'display_name' => 'Alice',
    'avatar_url'   => '...',
    'points'       => 1500,
]
```

You can add fields freely. They'll appear in the REST response (and in any block render that consumes the filtered structure).

## The args parameter

`$args` is the original query — useful when your filter should only apply in certain contexts:

```php
[
    'period' => 'all_time',         // 'daily'|'weekly'|'monthly'|'all_time'
    'scope'  => [
        'type' => 'group',          // ''|'group'|'country'|'cohort'|...
        'id'   => 42,
    ],
    'limit'  => 10,
    'offset' => 0,
]
```

Pattern: branch on `$args['scope']['type']` to enable scope-specific behaviour (e.g. country leaderboards, cohort leaderboards).

## Cache vs live

`wb_gamification_leaderboard_results` runs INSIDE `LeaderboardController::get_leaderboard` — the on-the-fly response. The cron-cached snapshot at `wb_gam_leaderboard_cache` is written by `LeaderboardEngine::write_snapshot` and uses a separate filter (`wb_gamification_leaderboard_snapshot`). For consistent behaviour between cache and live, filter both. The example file demonstrates this.

If you only filter `wb_gamification_leaderboard_results`, the cache lag (up to 5 min) means viewers might see different leaderboards depending on whether the cache was hit. For most use cases that's acceptable; for SLA-critical scoring, hook both filters.

## Performance

Filter runs once per request, on every row. Three rules:

1. **No N+1 queries.** If your filter needs DB data per row, batch it: build a single SQL with `IN (user_id_list)` and join in PHP, instead of one query per row.
2. **Cache where possible.** Trending status doesn't need to be computed on every request — pre-compute it via cron and store in user meta.
3. **Test at scale.** Test the filter with a 100-row leaderboard, not 5. Performance regressions hide at small scale.

## Related filters

- `wb_gamification_recap_data` — annotate year-in-review data (same pattern, different surface)
- `wb_gamification_event_metadata` — augment event metadata at storage time
- `wb_gamification_credential_document` — modify OpenBadges 3.0 credential JSON before signing

## Related examples

- For modifying point values per action → [Example 06](../06-modify-points-per-action/)
- For listening to events from outside → [Example 04](../04-listen-via-webhook/)
