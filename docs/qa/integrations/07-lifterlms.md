# LifterLMS: QA checklist

7 actions. Integration file: `integrations/contrib/lifterlms.php`. Code checked against 10.3.2.

**Setup:** Own site: see Tutor LMS note.

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

### Complete a LifterLMS course (`llms_course_completed`)

Awarded when a student completes any LifterLMS course.

Default: **100 points**. Limit: once per item.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Run on a test site through LifterLMS's own code. Paid once.

### Complete a LifterLMS lesson (`llms_lesson_completed`)

Awarded when a student completes any lesson.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Mark Complete clicked in a browser as a student. Paid once.

### Pass a LifterLMS quiz (`llms_quiz_passed`)

Awarded when a student achieves a passing grade on a quiz.

Default: **25 points**. Limit: once per item.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Pass or fail result set by hand, not by answering questions. Needs a real quiz.

### Enroll in a LifterLMS course (`llms_course_enrolled`)

Awarded once per course when a student is enrolled.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Run on a test site through LifterLMS's own code. Paid once.

### Join a LifterLMS membership (`llms_membership_joined`)

Awarded once per membership when a student joins it.

Default: **20 points**. Limit: once per item.

- [ ] Doing it pays 20 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Earn a LifterLMS achievement (`llms_achievement_earned`)

Awarded when a student earns any LifterLMS achievement.

Default: **30 points**. No limit by default.

- [ ] Doing it pays 30 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Earn a LifterLMS certificate (`llms_certificate_earned`)

Awarded when a student earns a course completion certificate.

Default: **50 points**. No limit by default.

- [ ] Doing it pays 50 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
