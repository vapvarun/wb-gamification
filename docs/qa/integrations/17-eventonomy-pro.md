# Eventonomy Pro: QA checklist

2 actions. Integration file: `integrations/eventonomy-pro.php`.

**Setup:** Needs Eventonomy.

Tick each box when it passes. If one fails, comment on this plugin's Basecamp card with the steps, what you expected, what happened and a screenshot.

## Whole plugin

- [ ] Activate the plugin with WB Gamification. Its actions appear under **WB Gamification > Points**, grouped under the right plugin name.
- [ ] Every action below has a clear label and description, an on switch and a points amount. Repeatable actions also have cooldown, daily and weekly limits.
- [ ] Change one amount and one limit, save and reload. Both stayed.
- [ ] Points screen at 390px: nothing overflows the page.
- [ ] Do the actions as a **Subscriber or member**, not as admin.
- [ ] After testing, **Tools > Site Health** and `debug.log` show no PHP errors and no `Gamification trigger failed` lines.
- [ ] Deactivate the plugin: its actions leave the Points screen, past points stay, no PHP errors.

## Each action

For every action: do it for real, then confirm the points appear in the member's history with the default amount, on the right member. Then switch it off, repeat it and confirm no points, and switch it back on.

### Attend an event (`evnm_event_attended`)

Awarded when a member checks in at an event with a valid ticket. Cancelled or refunded tickets cannot check in, so attendance is only ever rewarded once per ticket.

Default: **30 points**. No limit by default.

- [ ] Doing it pays 30 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Follow an event or organizer (`evnm_follow`)

Awarded when a member follows an event, organizer, venue, or another member to stay up to date. A daily limit keeps the reward proportionate.

Default: **2 points**. Limit: 30s wait, 10 a day.

- [ ] Doing it pays 2 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 10 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
