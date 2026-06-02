# Admin Tools

Go to **WB Gamification > Settings > Tools** in your admin sidebar.

Tools are the maintenance and portability controls a site owner reaches for occasionally: move your configuration between sites, rebuild the leaderboard snapshot, and (in the danger zone) wipe all member progress. Added in 1.5.3.

## Settings Import / Export

Export the plugin configuration to a JSON file, then import it on another site to clone your setup. This is the fast way to mirror a staging site onto production, or to seed a new community with a configuration you have already tuned.

- **Export** downloads a JSON document of every `wb_gam_*` configuration option.
- **Import** applies a document produced by export. Only keys that start with `wb_gam_` and are not on the exclusion list are written.

The export and import deliberately **exclude runtime, derived, and schema state** - the database version, feature schema gates, caches, snapshots, flush markers, and wizard flags - so an import never corrupts the target site or drags one site's derived state onto another. Configuration travels; machine state does not.

| Action | REST endpoint |
|---|---|
| Export | `GET wb-gamification/v1/tools/export-settings` |
| Import | `POST wb-gamification/v1/tools/import-settings` |

Both are admin-only (`manage_options`). The engine behind them is `WBGam\Engine\SettingsIO`.

## Rebuild Leaderboard

Recompute the leaderboard snapshot and clear its caches. Use this if a leaderboard ever looks stale - after a bulk import, a manual database change, or a points correction.

This is the admin equivalent of the WP-CLI command:

```bash
wp wb-gamification doctor --recompute-leaderboard
```

| Action | REST endpoint |
|---|---|
| Rebuild leaderboard | `POST wb-gamification/v1/tools/recompute-leaderboard` |

Admin-only (`manage_options`).

## Reset Member Progress (Danger Zone)

> **DANGER: this permanently clears member progress and cannot be undone.** Export your settings first if you want a copy of your configuration, and take a database backup before you run it.

Use this when you want to keep your whole setup - badge definitions, levels, rules, challenge definitions, point types, reward items, member preferences, webhooks, API keys, and every setting - but start every member from zero. A typical case is launching to real members after a testing period.

**What is wiped** (member progress):

- Points and the immutable event log
- User point totals
- Earned badges
- Streaks
- Kudos
- Leaderboard cache
- Challenge logs
- Cohort membership
- Community-challenge contributions (and each community challenge's progress counter is reset to zero, keeping its definition)
- Redemptions
- Submissions
- Per-user progress meta (login streak counters, cached level, league tier, recap bests, onboarding toast flag, notification cursors)

**What is kept** (configuration and definitions):

- Badge definitions, levels, rules, challenge definitions
- Point types and reward items
- Member preferences
- Webhooks and API keys
- All settings

The reset is engineered as a single confirmed operation. The REST endpoint requires an explicit `confirm: true` on top of the admin capability check, so it can never fire by accident:

```bash
curl -X POST https://example.com/wp-json/wb-gamification/v1/tools/reset-progress \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  --cookie "wordpress_logged_in_xxx=..." \
  -d '{ "confirm": true }'
```

| Action | REST endpoint |
|---|---|
| Reset member progress | `POST wb-gamification/v1/tools/reset-progress` (admin-only, requires `confirm: true`) |

The engine behind it is `WBGam\Engine\ProgressReset`. It fires the `wb_gam_progress_reset` action after the wipe so adapters can clear their own derived state.

## See Also

- **[Member Access](10-member-access.md)** - exclude roles or accounts from earning.
- **[Modules](11-modules.md)** - turn optional modules on or off.
- **[Member Management](../features/24-member-management.md)** - the Members roster and bulk award.
- **[Actions reference](../developer-guide/13-actions-reference.md)** - `wb_gam_progress_reset`.
