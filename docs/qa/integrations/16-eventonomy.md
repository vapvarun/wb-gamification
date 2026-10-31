# Eventonomy: QA checklist

4 actions. Integration file: `integrations/eventonomy.php`.

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

### Reserve a place at an event (`evnm_rsvp_going`)

Awarded when a member reserves a place at an event. Waitlisted reservations do not award until the member is moved to going, and a daily limit keeps repeat reservations in check.

Default: **10 points**. Limit: 300s wait, 10 a day.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 10 in one day.
- [ ] A waitlisted reservation pays nothing.
- [ ] When a place opens and the member is moved to going, they get paid.

Developer result: Fixed: the description promised this but promotion was never listened to. Tested through RsvpService: going paid, waitlist 0, promotion paid.

### Submit an event (`evnm_event_created`)

Awarded to the author when an event is submitted. A daily limit rewards genuine organizing without encouraging duplicate submissions.

Default: **25 points**. Limit: once per item, 3 a day.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] It stops paying after 3 in one day.
- [ ] Save draft pays nothing. Publishing it pays once.
- [ ] Moving it back to draft and publishing again pays nothing more.

Developer result: Fixed: Save draft paid. Now pays when the event leaves draft, once per event. Run on a test site through the plugin's REST route.

### Complete a ticket order (`evnm_ticket_purchased`)

Awarded when a member completes a paid ticket order. Free and immediately paid orders settle through this event; orders paid later have their own action.

Default: **20 points**. No limit by default.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Pay for a ticket order after checkout (`evnm_ticket_purchased_gateway`)

Awarded when a pending ticket order is paid later, such as a card payment settling or an offline payment being marked as paid.

Default: **20 points**. Limit: once per item.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] A pending order marked as paid pays the buyer, not the admin marking it.
- [ ] An order paid at checkout pays once in total, not from both ticket actions.
- [ ] Works with Eventonomy alone, without Pro.

Developer result: Moved from the Pro file, because offline orders marked as paid never earned without Pro. Tested by firing the hook with the source's arguments, twice for one order, paid once. A real pending order not run.
