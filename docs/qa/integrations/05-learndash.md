# LearnDash: QA checklist

8 actions. Integration file: `integrations/learndash.php`. Code checked against 5.2.2.1.

**Setup:** Own site: no other LMS active.

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

### Complete a LearnDash course (`ld_course_completed`)

Awarded when a learner completes any LearnDash course.

Default: **100 points**. Limit: once per item.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete a LearnDash lesson (`ld_lesson_completed`)

Awarded when a learner completes any lesson.

Default: **15 points**. Limit: once per item.

- [ ] Doing it pays 15 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete a LearnDash topic (`ld_topic_completed`)

Awarded when a learner completes any topic.

Default: **5 points**. Limit: once per item.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Pass a LearnDash quiz (`ld_quiz_passed`)

Awarded when a learner passes a quiz.

Default: **25 points**. Limit: once per item.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Get an assignment approved (`ld_assignment_approved`)

Awarded when an instructor approves a submitted assignment.

Default: **20 points**. Limit: once per item.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Enroll in a LearnDash course (`ld_course_enrolled`)

Awarded once per course when a learner gets access to it.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Submit a LearnDash assignment (`ld_assignment_uploaded`)

Awarded once per lesson or topic when a learner uploads an assignment.

Default: **5 points**. Limit: once per item.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete a LearnDash group (`ld_group_completed`)

Awarded when a learner completes every course in a group.

Default: **100 points**. Limit: once per item.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
