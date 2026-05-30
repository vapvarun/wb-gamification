# Daily Login Bonus

The Daily Login Bonus rewards members for showing up consistently. The longer a member's active streak, the bigger the bonus they earn each day.

## How It Works

When a member visits the site for the first time on a given calendar day, they automatically earn a login bonus. The bonus increases at five tier milestones:

| Streak day | Bonus points |
|---|---|
| Day 1 | 10 |
| Day 3 | 20 |
| Day 7 | 50 |
| Day 14 | 100 |
| Day 30 | 250 |

Between milestones, the previous tier's value applies. So Day 8 earns 50 (the Day 7 tier), Day 25 earns 100 (the Day 14 tier), and so on. Day 30+ permanently earns 250.

The bonus fires **once per calendar day** in the member's own timezone. A second visit on the same day does not earn an extra bonus.

## What Counts as a Login

A member is considered to have "logged in" the moment any tracked action fires for them on a day where they have not yet earned the bonus. This includes:

- Logging in via the WordPress login form
- Returning to the site with an active session cookie (returning visitor)
- Performing any tracked action (commenting, posting, viewing the hub page)

The bonus is awarded **before** any other points from that action, so members see two toast notifications stacked: the login bonus first, then the per-action points.

## Display Surface

The **Daily Bonus block** (Gutenberg + frontend) shows the member their current streak and the points they will earn today, plus a preview of upcoming tier rewards.

Place the block on the Hub page, on member dashboards, or anywhere members land after login.

## Configuration

Settings → Daily Login Bonus.

| Setting | Default |
|---|---|
| Enabled | On |
| Tier ladder | `[1=>10, 3=>20, 7=>50, 14=>100, 30=>250]` |
| Reset on missed day | Yes |
| Award timezone | Member's WP profile timezone, falls back to site default |

The tier ladder is editable as a JSON array. Add custom milestones or change the values to match your reward economy.

## Streak vs Login Streak

The login bonus uses its own counter, separate from the gamification streak engine. A member can have a 7-day login streak and a 3-day activity streak at the same time — they're distinct mechanics.

If you want a single unified streak, disable the login bonus and rely on the standard streak engine alone.

## Privacy

The login bonus does not store IP addresses or session identifiers. It tracks only the date of the most recent bonus award, stored in user meta as `wb_gam_last_login_bonus_date`. This is included in GDPR export and erasure.

## See Also

- **[Streaks](08-streaks.md)** — independent activity streak (any earning action) with milestones
- **[Points](01-points.md)** — how the bonus integrates with the points ledger
- **[Notifications](18-notifications.md)** — how the toast for the bonus appears
