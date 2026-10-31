# Jetonomy: QA checklist

4 actions. Integration file: `integrations/jetonomy.php`. Code checked against 2.0.7.

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

### Join a space (`jetonomy_space_joined`)

Awarded once when a member joins a Jetonomy community space. Daily cap prevents bulk-join farming.

Default: **5 points**. Limit: 60s wait, 5 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.
- [ ] It stops paying after 5 in one day.

Developer result: Joining a BuddyNext space with a linked forum also pays Join a space there, so one join pays 5 + 5 until the BuddyNext fix. Card on the BuddyNext board. Run on a test site through the plugin's REST route.

### Get approved into a gated space (`jetonomy_join_request_approved`)

Awarded once when a member is approved into a request-to-join space.

Default: **10 points**. Limit: 300s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Get promoted to a higher trust level (`jetonomy_trust_level_up`)

Awarded when a member is promoted to a higher Jetonomy trust level (TL0 -> TL5). Demotions never award.

Default: **50 points**. No limit by default.

- [ ] Doing it pays 50 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Activate a membership (`jetonomy_membership_activated`)

Awarded once per plan when a paid membership becomes active for the member (RCP / PMPro / MemberPress / WooCommerce Subscriptions / Sensei / LearnDash / MasterStudy / Tutor / LifterLMS).

Default: **25 points**. No limit by default.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] WooCommerce Subscriptions: buying a subscription pays 25 once. Renewing or going on hold and back pays nothing more.
- [ ] WooCommerce Memberships: expected to fail until the Jetonomy Pro fix. A first purchase of a plan pays nothing today.

Developer result: Tested with WooCommerce Subscriptions 9.3.0 and Memberships 1.30.2 through real checkouts. A subscription pays 25 once. A new membership pays nothing, because Jetonomy Pro only listens for a status change and a new membership has none. The fix belongs in Jetonomy Pro and has a card on its board. The other membership and course plugins named here were not run.
