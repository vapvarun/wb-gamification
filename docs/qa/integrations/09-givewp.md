# GiveWP: QA checklist

3 actions. Integration file: `integrations/contrib/givewp.php`. Code checked against 4.19.1.

**Setup:** Create a campaign with a small amount goal, for example $50. Use the Test Donation gateway. There is no recurring gifts action: it was removed because we do not have the paid add-on to test it.

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

### Complete a donation (`give_donation_completed`)

Awarded each time a donation is successfully processed.

Default: **30 points**. No limit by default.

- [ ] Doing it pays 30 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] A pending donation pays nothing until it completes.
- [ ] Marking a completed donation pending and then complete again pays nothing more.
- [ ] A guest donor pays nobody and causes no error.

Developer result: Fixed: the old hook never fired. Paid through a real browser donation on the Test Donation gateway. Pending, re-complete and guest also checked through GiveWP code.

### Make first donation ever (`give_first_donation`)

Awarded once when a member makes their very first donation.

Default: **75 points**. Limit: once ever.

- [ ] Doing it pays 75 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.
- [ ] A second donation does not pay this.
- [ ] A donor with donations from before this plugin was active does not get it.

Developer result: Fixed: the old hook never fired. Paid once in a real browser donation, and not on the second gift.

### Campaign reaches its goal (`give_campaign_goal_reached`)

Awarded to the donor whose gift takes a campaign to its goal.

Default: **15 points**. No limit by default.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Only the donor whose gift crosses the goal earns it.
- [ ] Donations after the goal pay nothing for this.
- [ ] A form with no goal, or a goal not yet reached, pays nothing.

Developer result: Fixed: GiveWP has no such hook, so the plugin now checks the goal after each donation. Tested on an amount goal through GiveWP code. Donations, donors and subscriptions goal types not run.
