# LifterLMS Integration

The LifterLMS integration rewards learning progress: completing courses and lessons, passing quizzes, and earning achievements and certificates. The manifest loads automatically when LifterLMS is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `llms_course_completed` | Complete a LifterLMS course | 100 |
| `llms_lesson_completed` | Complete a LifterLMS lesson | 10 |
| `llms_quiz_passed` | Pass a LifterLMS quiz | 25 |
| `llms_achievement_earned` | Earn a LifterLMS achievement | 30 |
| `llms_certificate_earned` | Earn a LifterLMS certificate | 50 |

## Requirements

- LifterLMS active

## How it works

This integration is auto-detected. No configuration is needed. As soon as LifterLMS is active, points fire automatically whenever a student completes a course or lesson, earns an achievement, or is issued a certificate.

`llms_quiz_passed` only awards points when the student reaches a passing grade on the attempt. A failed attempt earns nothing. `llms_certificate_earned` rewards the final milestone in any course that issues certificates.
