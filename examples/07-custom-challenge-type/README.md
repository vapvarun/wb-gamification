# Example 07 — Custom Challenge Type

The default `ChallengeEngine` ships with one mechanic: "do action X, N times, before deadline Y." This example shows how to register custom challenge mechanics that don't fit that pattern.

## Built-in mechanic

```
Title:          Comment Champion
Action:         wp_leave_comment
Target Count:   10
Bonus Points:   100
Date Range:     2026-05-01 – 2026-05-31
```

→ Award the bonus when the user leaves their 10th comment in May.

## Custom mechanics in this example

| Type | Mechanic |
|---|---|
| `streak_challenge` | "Do action X on N consecutive days" — missing a day resets progress |
| `diversity_challenge` | "Do M different distinct actions" — same action twice doesn't count |

You can add as many custom types as you like. Each gets its own progress + reset closure.

## How it works

`wb_gam_register_challenge_type()` (in `src/Extensions/functions.php:83`) registers a hook → progress-callback pair. When any event matching the challenge's `target_action` fires:

1. The engine looks up active challenges for this user.
2. For each challenge whose `type` matches a registered custom type, the registered `progress` closure runs.
3. If the closure returns `'completed'`, the engine writes the completion + fires `wb_gam_challenge_completed`.
4. Otherwise the returned int is stored as current progress (visible to the user via `/members/{id}/challenges`).

## Files in this example

- [`your-plugin.php`](your-plugin.php) — `streak_challenge` + `diversity_challenge` registrations + completion listener.

## Closure contract

```php
'progress' => function ( int $user_id, object $challenge, array $event ): int|string {
    // ... your logic ...
    return $newProgress;     // int — current count
    // or:
    return 'completed';      // string literal — challenge is complete
},

'reset' => function ( int $user_id, object $challenge ): void {
    // Clear any state your progress closure stored
},
```

The `$challenge` object has fields:
- `id` (int) — challenge row ID
- `target_count` (int) — admin-configured target
- `target_action` (string) — action_id this challenge tracks
- `type` (string) — your custom type id
- `bonus_points` (int) — points awarded on completion
- `start_at`, `end_at` (DATETIME) — challenge window

The `$event` array has fields:
- `action_id` (string)
- `user_id` (int)
- `metadata` (array)
- `created_at` (DATETIME)

## Admin UX

Custom challenge types appear in the admin's "Type" dropdown when creating a challenge. Site owners pick your type from the list, set target_count + bonus_points + dates, and the challenge runs against your closure.

## State storage

Two patterns:

1. **User meta** (this example) — fine for low-volume challenges. Easy to read/write, no migrations needed.
2. **Custom DB table** — for high-volume / many-user challenges, write to your own table. The engine doesn't constrain how you store progress; it just calls your closure.

For inspiration, look at the engine's own state tables: `wb_gam_streaks`, `wb_gam_challenge_log`, `wb_gam_community_challenge_contributions`.

## Reacting to completion

`wb_gam_challenge_completed` fires for built-in AND custom challenges. The example file demonstrates listening for completions to layer additional rewards on top of `bonus_points`.

## Verification

```bash
# CLI
wp wb-gamification member status --user=42 | grep challenges

# REST
curl http://your-site/wp-json/wb-gamification/v1/members/42 | jq '.challenges'
```

## Related

- For "do action N times" challenges (the default), no custom code needed — just create them in admin.
- Award badges based on custom conditions → [Example 05](../05-custom-badge-condition/)
- Modify points per action → [Example 06](../06-modify-points-per-action/)
