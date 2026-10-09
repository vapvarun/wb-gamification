# Troubleshooting

Most "something is broken" reports trace back to one of the situations below. Work through the relevant one before assuming a bug.

## How do I check that everything is working?

Run the built-in health check from WP-CLI:

```bash
wp wb-gamification doctor
```

It validates the database tables, default levels, default badges, registered actions, settings, cron jobs, REST API routes, and integration detection, and reports pass / warn / fail for each check. Add `--verbose` for full detail. See [WP-CLI Commands](../developer-guide/100-wp-cli.md) for more.

## A member stopped earning points, with no message

This is almost always a cap doing its job, not a bug. Daily caps, weekly caps, and per-action cooldowns are enforced **silently** - a member who has hit a limit earns nothing more until the window resets, with no error and no notification. That is deliberate: a quiet non-award is better than nagging your most active members.

Check that member's caps and cooldowns under **WB Gamification > Points** first. The skipped awards are still visible to you through the event log and the REST API.

## A badge share link returns 404

Since version 1.6.4, a badge is **private until the member shares it**, so links published before that upgrade stop resolving until the member presses **Share** on their badge board. This is what stops strangers enumerating badge pages by guessing IDs.

If you deliberately run an open community and want the older links working again:

```bash
wp wb-gamification share grandfather   # publish every badge every member currently holds
wp wb-gamification share reset         # make them private again
```

## A block or a whole feature shows nothing

Optional modules can be turned off under **WB Gamification > Settings > Modules**. When a module is disabled, its blocks and shortcodes render nothing and its admin page is removed (existing data is preserved). If a block is blank, confirm its module is enabled there.

## Real-time toasts are not appearing

The default real-time transport is WordPress Heartbeat (it polls a few seconds after an action and idles on hidden tabs). Server-Sent Events (SSE) are **opt-in** and gated behind the `wb_gam_sse_allowed` filter, because a PHP long-poll pins a worker per connection and does not scale on a standard pool. If you switched to SSE and toasts stopped, revert to Heartbeat under **WB Gamification > Settings > Realtime**.

Two behaviours are intended, not faults:

- **No toast on sign-up, onboarding or checkout.** A community plugin can hold toasts on those screens (BuddyNext and BuddyNext Pro do). The toasts are kept and show on the member's next ordinary page.
- **Several awards arrive as one toast.** From four waiting toasts on, they are combined into one summary ("You earned 55 Points") with the badge and a progress link. See [Notifications](../features/160-notifications.md).

## Leaderboard or database errors on activation

The leaderboard uses SQL window functions (`RANK() OVER (...)`), which require **MySQL 8.0 or higher, or MariaDB 10.4 or higher**. On MySQL 5.7 or older MariaDB, the leaderboard query fails. Upgrade the database server (see [Installation requirements](../getting-started/00-installation.md)).
