# Member Access (Earning Exclusion)

Go to **WB Gamification > Settings > Access** in your admin sidebar.

This section decides who is allowed to earn. Most communities exclude administrators, staff, support agents, and bots so internal testing and service accounts do not skew the leaderboard. Added in 1.5.3.

## What Exclusion Does

An excluded member:

- **Keeps any points they already earned** - nothing is deleted.
- **Stops accruing new points, badges, levels, and streaks** - every award path is blocked, not just the obvious ones.
- **Is hidden from leaderboards** - they no longer appear in the leaderboard or top-members surfaces.

Exclusion is enforced at the single award choke point in `PointsEngine::user_can_earn()`, so it covers both the synchronous and the asynchronous (Action Scheduler) award paths and every caller. Logged-out visitors never earn.

Manual admin awards and the WP-CLI `points award` command can still grant points to an excluded account on purpose - those paths pass an explicit force flag so an owner can correct a balance even for a sandboxed user.

## Excluded Roles

Tick any roles that should never earn. Any member who holds one of the checked roles is excluded.

The roles list is your site's real role names (Administrator, Editor, Subscriber, plus any custom roles). The selection is stored in the `wb_gam_excluded_roles` option as an array of role slugs.

Most communities exclude **Administrator** and any staff role here.

## Excluded Accounts

Exclude specific accounts regardless of their role. Enter usernames, emails, or user IDs separated by commas or new lines, for example:

```
supportbot, qa@example.com, 42
```

On save, each entry is resolved to a user. Unrecognized entries are dropped, and the saved field shows the resolved usernames. The resolved IDs are stored in the `wb_gam_excluded_users` option as an array of user IDs.

## Per-Member Exclusion

Beyond roles and the accounts list, a single member can be excluded from the **Members** roster page (the Exclude / Include toggle). That toggle writes the per-user `wb_gam_sandboxed` meta, which is a per-user veto checked by the same `user_can_earn()` gate. See [Member Management](../features/24-member-management.md).

## Developer Hook

After the role, account-list, and per-user checks are applied, the result passes through a filter so code can extend or override the owner's choices:

```php
add_filter(
	'wb_gam_user_can_earn',
	static function ( bool $can, int $user_id ): bool {
		// Block everyone whose email is unverified, for example.
		return $can;
	},
	10,
	2
);
```

Full signature in the [Filters reference](../developer-guide/14-filters-reference.md).

## See Also

- **[Member Management](../features/24-member-management.md)** - the Members roster, per-member exclude/include, and bulk award.
- **[Admin Tools](12-tools.md)** - import/export settings, rebuild leaderboard, reset member progress.
- **[Filters reference](../developer-guide/14-filters-reference.md)** - `wb_gam_user_can_earn`.
