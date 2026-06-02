# Point Expiry

Point expiry lets you decay the balance of members who stop earning, to nudge re-engagement. It is **off by default** - turn it on only if your community wants points to lapse with inactivity. Added in 1.5.3.

Configure it under **WB Gamification > Settings > Points**, in the **Point expiry** card.

## Opt-In

Point expiry ships disabled. With it off, balances never decay and the daily job does nothing. You enable it explicitly with the **Enable point expiry** checkbox.

| Setting | Option | Default |
|---|---|---|
| Enable point expiry | `wb_gam_points_decay_enabled` | `0` (off) |
| Inactive for (days) | `wb_gam_points_decay_days` | `90` |
| Decay amount (percent) | `wb_gam_points_decay_percent` | `100` |

## How the Daily Decay Works

When enabled, a daily WP-Cron job (`wb_gam_points_decay`) finds members who:

- have a positive **primary-currency** balance, and
- have had no points activity for at least the configured number of days.

For each of those members, it reduces their primary-currency balance by the configured percent. At the default 90 days / 100 percent, a member who stops earning for 90 days has their balance zeroed; set a smaller percent (for example 25) to taper instead of wipe.

Only the primary currency is decayed. On multi-currency sites, other point types are untouched.

## Applied Once Per Inactivity Streak

Decay fires **once per inactivity streak**, not every day. The job stamps a `wb_gam_decayed_at` marker on each decayed member and will not decay them again while they remain inactive. The moment a member earns again, the marker is behind their new activity, so a future inactivity streak re-arms the decay. In short: one decay per quiet spell, and earning again resets the clock.

## Developer Hook

A site can react to each sweep with the `wb_gam_points_decayed` action, which fires after every run with the number of members decayed:

```php
add_action(
	'wb_gam_points_decayed',
	static function ( int $count ): void {
		// Log or notify on how many members were decayed this run.
	}
);
```

Full signature in the [Actions reference](../developer-guide/13-actions-reference.md).

## See Also

- **[Points](01-points.md)** - how points are earned and stored.
- **[Member Management](24-member-management.md)** - roster, per-member reset, bulk award.
- **[Actions reference](../developer-guide/13-actions-reference.md)** - `wb_gam_points_decayed`.
