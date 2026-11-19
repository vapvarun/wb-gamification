# The Events Calendar + Event Tickets: QA checklist

3 actions. Integration file: `integrations/contrib/the-events-calendar.php`. Code checked against 6.18.2 + 5.30.3.

**Setup:** Use Tickets Commerce for tickets, with a Stripe or PayPal sandbox. Enable "Can't go" on one RSVP. Tickets sold through Event Tickets Plus are not covered.

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

### RSVP to an event (`tec_rsvp_registered`)

Awarded when a member RSVPs as going, once per event.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Answering Can't go pays nothing.
- [ ] Booking 2 seats pays once.
- [ ] A logged-out RSVP pays nobody and causes no error.

Developer result: Fixed: the old hook never fired. Going with 2 seats paid once in a real browser RSVP on the current RSVP version. The older RSVP version paid once through its own code, and Can't go paid nothing.

### Purchase an event ticket (`tec_ticket_purchased`)

Awarded when a member completes a ticket order, once per event.

Default: **20 points**. Limit: once per item.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Saving or editing a ticket as admin pays nobody.
- [ ] A pending order pays nothing until it completes.
- [ ] A second order for the same event pays nothing. An order for another event pays.
- [ ] An attendee an admin adds by hand pays nobody.

Developer result: Fixed: the old hook fired when a ticket was saved and paid whoever saved it. Tested through Tickets Commerce order code. A real paid checkout in the browser not run.

### Check in to an event (`tec_event_checked_in`)

Awarded when an attendee checks in at the event.

Default: **15 points**. Limit: once per item.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Check in a ticket attendee and an RSVP attendee: both pay the attendee, not the staff member checking in.
- [ ] Undo a check-in and check in again: nothing more.

Developer result: Fixed: RSVP check-ins fire a different hook and were missed, and repeat check-ins paid again. Both tested through Event Tickets code, not in the browser.
