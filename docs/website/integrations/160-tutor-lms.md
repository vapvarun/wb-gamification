# Tutor LMS Integration

The Tutor LMS integration rewards learning progress: enrolling, completing lessons and courses, passing quizzes, reviewing a course and asking questions. The manifest loads automatically when Tutor LMS is active.

## Actions

| Action ID | What it rewards | Default Points |
|---|---|---|
| `tutor_course_completed` | Complete a Tutor LMS course | 100 |
| `tutor_lesson_completed` | Complete a Tutor LMS lesson | 10 |
| `tutor_quiz_passed` | Pass a Tutor LMS quiz | 25 |
| `tutor_course_enrolled` | Enroll in a Tutor LMS course | 10 |
| `tutor_review_posted` | Review a Tutor LMS course | 10 |
| `tutor_question_asked` | Ask a question in a Tutor LMS course | 5 |

## Requirements

- Tutor LMS active

## How it works

A student earns once per course, lesson and quiz, so retaking a quiz or watching a lesson video again earns nothing.

- `tutor_lesson_completed` counts both the **Mark as Complete** button and a video that plays to the end.
- `tutor_quiz_passed` needs a passing result. A quiz with answers an instructor must review earns when the review gives a pass.
- `tutor_course_enrolled` earns when the enrollment is completed. A paid enrollment still waiting on payment earns nothing.
- `tutor_review_posted` earns once per course. Editing a review earns nothing.
- `tutor_question_asked` earns for a new question, up to five a day. Replies earn nothing.

Tutor LMS Pro assignments and certificates are not covered.
