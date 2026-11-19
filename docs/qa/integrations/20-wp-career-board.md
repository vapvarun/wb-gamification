# WP Career Board: QA checklist

6 actions. Integration file: `integrations/wp-career-board.php`. Code checked against 1.8.2.

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

### Post a job (`wcb_job_posted`)

Awarded to the employer when a new job listing is created.

Default: **10 points**. Limit: 60s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 60 seconds pays nothing. After the wait it pays.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Get a job approved (`wcb_job_approved`)

Awarded to the employer when their job listing is approved and published.

Default: **5 points**. No limit by default.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Apply to a job (`wcb_application_submitted`)

Awarded to the candidate when they submit a job application. Daily cap prevents spam applications.

Default: **8 points**. Limit: 10 a day.

- [ ] Doing it pays 8 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 10 in one day.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Get hired (`wcb_candidate_hired`)

Awarded to the candidate when their application status changes to hired.

Default: **100 points**. Limit: once per item.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.
- [ ] Hired, back to reviewing, then hired again pays once.

Developer result: Fixed: hired, back to reviewing, hired again paid twice. Now once per application; this repeat was checked by firing the hook by hand. Run on a test site through the plugin's REST route.

### Join as a candidate (`wcb_candidate_registered`)

Awarded once when a member registers a candidate profile.

Default: **15 points**. Limit: once ever.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.

### Join as an employer (`wcb_employer_registered`)

Awarded once when a member registers an employer account.

Default: **15 points**. Limit: once ever.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Run on a test site through the plugin's REST route. Paid the right member.
