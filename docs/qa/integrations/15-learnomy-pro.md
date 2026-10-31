# Learnomy Pro: QA checklist

5 actions. Integration file: `integrations/learnomy-pro.php`. Code checked against 2.1.4.

**Setup:** Needs Learnomy.

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

### Complete a learning path (`learnomy_pro_path_completed`)

Awarded when a member completes a full learning path.

Default: **150 points**. No limit by default.

- [ ] Doing it pays 150 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: A learning path with no certificate template pays nothing: Learnomy Pro only fires the hook in the certificate branch. Card on the Learnomy board. With a template it pays. Run on a test site through the plugin's REST route.

### Enrol in a learning path (`learnomy_pro_path_enrolled`)

Awarded when a member enrols in a learning path. Daily cap prevents farming.

Default: **5 points**. Limit: 5 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Submit an assignment (`learnomy_pro_assignment_submitted`)

Awarded when a member submits an assignment for grading.

Default: **15 points**. Limit: 60s wait.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Join a cohort (`learnomy_pro_cohort_joined`)

Awarded when a member is added to a learning cohort.

Default: **5 points**. No limit by default.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Redeem a course gift (`learnomy_pro_gift_redeemed`)

Awarded when a member redeems a gifted course or membership code.

Default: **5 points**. No limit by default.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.
