# WP Career Board Pro: QA checklist

1 actions. Integration file: `integrations/wp-career-board-pro.php`. Code checked against 1.8.1.

**Setup:** Needs WP Career Board.

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

### Publish your resume (`wcbp_resume_published`)

Awarded to the candidate when their resume is published and discoverable by employers.

Default: **20 points**. Limit: once ever.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
