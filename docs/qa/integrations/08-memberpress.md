# MemberPress: QA checklist

3 actions. Integration file: `integrations/contrib/memberpress.php`. Code checked against 1.14.1.

**Setup:** Create one free membership and one recurring paid membership. Offline gateway is enough for the pending check. A renewal needs a gateway test mode (Stripe or PayPal sandbox).

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

### Activate a MemberPress membership (`mp_membership_activated`)

Awarded the first time a member activates each membership.

Default: **50 points**. Limit: once per item.

- [ ] Doing it pays 50 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Pending offline payment pays nothing until an admin marks it complete.
- [ ] A renewal payment does not pay this action again.
- [ ] Leave and rejoin the same membership: nothing. Join a different membership: pays.
- [ ] A lapsed member dropped to a group fallback membership: nothing.

Developer result: Fixed: the old hook never fired. Paid through a real browser signup on a free membership, and through MemberPress transaction code for a recurring one. Pending and fallback paid nothing. Paid gateway checkout not run.

### Renew a MemberPress membership (`mp_membership_renewed`)

Awarded on each renewal payment of a recurring membership.

Default: **30 points**. No limit by default.

- [ ] Doing it pays 30 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] The first payment of a recurring membership does not pay this.
- [ ] Each later payment pays once.

Developer result: Fixed: the old hook never fired. Two renewals paid twice through MemberPress receipt code. A real gateway renewal not run.

### Join as a member for the first time (`mp_first_membership`)

Awarded once when a user activates their very first membership.

Default: **100 points**. Limit: once ever.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.
- [ ] A second membership does not pay this again.
- [ ] Label says Join as a member for the first time: a free membership counts too.

Developer result: Fixed: the old hook never fired. Paid once in a real browser signup.
