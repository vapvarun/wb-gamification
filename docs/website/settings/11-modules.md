# Modules

Go to **WB Gamification > Settings > Modules** in your admin sidebar.

Not every community wants every mechanic. This section turns optional engagement modules on or off so you only ship what your members will actually use. Added in 1.5.3.

## Optional Modules

| Module | What it covers |
|---|---|
| Kudos | Peer kudos - the kudos-feed and give-kudos blocks/shortcodes |
| Streaks | Daily and weekly streak tracking - the streak block/shortcode |
| Challenges | Individual challenges - the challenges block/shortcode and the Challenges admin page |
| Community challenges | Group challenges - the community-challenges block/shortcode and the Community Challenges admin page |
| Cohort leagues | Cohort-based leaderboard leagues - the cohort-rank block/shortcode |
| Redemption store | Rewards store - the redemption-store block, its shortcodes, and the Redemption admin page |

The toggle map is stored in the `wb_gam_modules` option as `slug => '1' | '0'`. A module is on by default; only an explicit `'0'` turns it off.

## What Disabling a Module Does

When a module is off:

- **Its blocks render nothing.** A page that still contains a disabled module's block outputs nothing for that block.
- **Its shortcodes render nothing.** A post still containing a disabled module's shortcode outputs nothing for that shortcode.
- **Its admin submenu page is removed** (for modules that have one - Challenges, Community challenges, Redemption store).
- **All data is preserved.** Disabling hides a module; it does not delete kudos, streaks, challenge definitions, redemption items, or any history. Turn it back on and everything returns.

## Always-On Core

These are never optional and have no toggle:

- **Points**
- **Badges**
- **Levels**
- **Leaderboards**

They are the foundation every other module builds on, so they always run.

## Developer Hook

Each module's enabled state passes through a filter, so code can force a module on or off regardless of the saved setting:

```php
add_filter(
	'wb_gam_module_enabled',
	static function ( bool $enabled, string $slug ): bool {
		// Force the redemption store off everywhere.
		return 'redemption' === $slug ? false : $enabled;
	},
	10,
	2
);
```

Full signature in the [Filters reference](../developer-guide/14-filters-reference.md).

## See Also

- **[Member Access](10-member-access.md)** - exclude roles or accounts from earning.
- **[Admin Tools](12-tools.md)** - import/export settings, rebuild leaderboard, reset member progress.
- **[Filters reference](../developer-guide/14-filters-reference.md)** - `wb_gam_module_enabled`.
