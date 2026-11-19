# Tutor LMS: QA checklist

6 actions. Integration file: `integrations/tutor-lms.php`. Code checked against 4.2.0.

**Setup:** Own site: Tutor LMS and LifterLMS both use /courses/, so lesson pages return 404 when both are active. Assignments and certificates are in Tutor LMS Pro and are not covered.

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

### Complete a Tutor LMS course (`tutor_course_completed`)

Awarded when a student completes any Tutor LMS course.

Default: **100 points**. Limit: once per item.

- [ ] Doing it pays 100 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Run on a test site through Tutor LMS's own code. Paid once.

### Complete a Tutor LMS lesson (`tutor_lesson_completed`)

Awarded when a student completes any lesson.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Mark Complete clicked in a browser as a student. Paid once.

### Pass a Tutor LMS quiz (`tutor_quiz_passed`)

Awarded when a student passes a quiz, including one an instructor grades by hand.

Default: **25 points**. Limit: once per item.

- [ ] Doing it pays 25 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Run through Tutor LMS's own quiz code; some steps were called directly. A hand-graded quiz paid only after review.

### Enroll in a Tutor LMS course (`tutor_course_enrolled`)

Awarded once per course when an enrollment is completed. A pending paid enrollment earns nothing.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Free enrolment run through Tutor LMS's own code. Paid checkout and WooCommerce routes not run.

### Review a Tutor LMS course (`tutor_review_posted`)

Awarded once per course when a student posts a review.

Default: **10 points**. Limit: once per item.

- [ ] Doing it pays 10 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] Doing it again on the same item pays nothing. Doing it on a different item pays again.

Developer result: Run on a test site through Tutor LMS's own code or its hook; a real browser run is still needed.

### Ask a question in a Tutor LMS course (`tutor_question_asked`)

Awarded when a student asks a new question, up to five a day. Replies earn nothing.

Default: **5 points**. Limit: 5 a day.

- [ ] Doing it pays 5 points to the right member.
- [ ] Switched off, it pays nothing.
- [ ] It stops paying after 5 in one day.

Developer result: Run on a test site through Tutor LMS's own code or its hook; a real browser run is still needed.
