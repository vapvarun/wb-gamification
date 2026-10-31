# Learnomy: QA checklist

7 actions. Integration file: `integrations/learnomy.php`. Code checked against 2.1.4.

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

### Complete a lesson (`learnomy_lesson_completed`)

Awarded each time a member completes a lesson. Cooldown prevents rapid-fire farming.

Default: **10 points**. Limit: 30s wait.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Pass a quiz (`learnomy_quiz_passed`)

Awarded when a member passes a quiz. Fires only on a passing attempt (failed attempts award nothing); cooldown limits retake farming.

Default: **25 points**. Limit: 30s wait.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 30 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Complete a course (`learnomy_course_completed`)

Awarded when a member finishes every lesson and quiz in a course.

Default: **100 points**. No limit by default.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Enrol in a course (`learnomy_student_enrolled`)

Awarded when a member enrols in a course. Daily cap prevents bulk-enrol farming.

Default: **5 points**. Limit: 5 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Earn a certificate (`learnomy_certificate_issued`)

Awarded when a member earns a course completion certificate.

Default: **50 points**. No limit by default.

- [ ] Doing it pays 50 points to the right member.
- [ ] Switched off, it pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Review a course (`learnomy_review_submitted`)

Awarded when a member writes a course review. Cooldown limits review farming.

Default: **8 points**. Limit: 300s wait.

- [ ] Doing it pays 8 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again inside 300 seconds pays nothing. After the wait it pays.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.

### Apply to teach (`learnomy_instructor_application`)

Awarded once when a member applies to become an instructor.

Default: **5 points**. Limit: once ever.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it a second time pays nothing.

Developer result: Not run yet. The hook exists in the plugin source, which does not prove its arguments match, so this row needs a real run.
