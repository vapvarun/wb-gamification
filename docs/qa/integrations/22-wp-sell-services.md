# WP Sell Services: QA checklist

2 actions. Integration file: `integrations/wp-sell-services.php`.

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

### Complete a service order (`wpss_order_completed`)

Awarded to the seller when an order they delivered is completed. An order a seller places with themselves earns nothing.

Default: **20 points**. No limit by default.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Review a service (`wpss_review_created`)

Awarded to the buyer when their review is published. A review held for moderation pays when a moderator approves it, and a seller reviewing their own order earns nothing.

Default: **10 points**. Limit: 60s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.
- [ ] With moderation off, publishing a review pays once.
- [ ] With moderation on, a pending review pays nothing and pays on approval.
- [ ] Approve, reject, approve again pays once.

Developer result: Merged the separate moderator action into this one. Run on a test site through the plugin REST route and its moderation handler.
