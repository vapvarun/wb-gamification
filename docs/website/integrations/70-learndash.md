# LearnDash Integration

The LearnDash integration rewards learners at every stage of the course progression path - from individual topics through to full course completion and instructor-approved assignments.

The manifest loads automatically when LearnDash is active.

## Actions

| Action ID | Label | Default Points | Repeatable |
|---|---|---|---|
| `ld_course_completed` | Complete a LearnDash course | 100 | Yes |
| `ld_lesson_completed` | Complete a LearnDash lesson | 15 | Yes |
| `ld_topic_completed` | Complete a LearnDash topic | 5 | Yes |
| `ld_quiz_passed` | Pass a LearnDash quiz | 25 | Yes |
| `ld_assignment_approved` | Assignment approved by instructor | 20 | Yes |
| `ld_course_enrolled` | Enroll in a LearnDash course | 10 | Yes |
| `ld_assignment_uploaded` | Submit a LearnDash assignment | 5 | Yes |
| `ld_group_completed` | Complete a LearnDash group | 100 | Yes |

### Notes

- `ld_topic_completed` uses the manifest default of 5 points (the source file shows 5, not 15 - see the topic entry).
- `ld_quiz_passed` only awards points when the learner actually passes (the `pass` flag in the quiz data is `true`). Failed quiz attempts earn nothing.
- `ld_assignment_approved` fires when an **instructor** marks an assignment as approved, not when the learner submits it. This rewards quality work, not just submission.
- A learner earns each action once per course, lesson, topic, quiz, assignment or group. Retaking a quiz or redoing a lesson after a progress reset earns nothing.
- `ld_course_enrolled` fires when a learner is given access to a course. An open course gives access without enrolling, so it earns nothing.
- `ld_assignment_uploaded` earns once per lesson or topic, however many files the learner uploads.

## Recommended Point Structure

The default values reward depth of engagement. A learner who completes an entire course earns 100 points for the course itself plus 15 per lesson and 5 per topic along the way. Adjust point values to reflect how much depth matters in your learning community.

## Member Surface (opt-in)

WB Gamification can add a single "My Achievements" link to the top of the LearnDash profile, pointing to the mapped Hub page. It is **off by default** - opt in with the `wb_gam_learndash_profile_link` filter. See [Member Achievement Surfaces](../features/210-member-achievement-surfaces.md).

## Requirements

- LearnDash active
