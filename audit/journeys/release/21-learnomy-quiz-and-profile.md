---
journey: learnomy-quiz-and-profile
plugin: wb-gamification
priority: normal
roles: [subscriber, student]
covers: [learnomy-quiz-passed-trigger, learnomy-profile-achievements-link]
prerequisites:
  - "Site reachable at $SITE_URL with BOTH wb-gamification and learnomy active"
  - "Learnomy 1.5.x (free) active; a published course with at least one quiz"
  - "A Hub page mapped in wb_gam_hub_page_id"
  - "Filter opt-in: add_filter( 'wb_gam_learnomy_profile_link', '__return_true' )"
estimated_runtime_minutes: 6
---

# Learnomy: quiz-pass points + account "My Achievements" link

WB Gamification ships `integrations/learnomy.php`. Two Learnomy-specific promises must not
regress: (1) passing a Learnomy quiz awards points (the `learnomy_quiz_passed` trigger),
and (2) a logged-in member sees a single "My Achievements" link on their Learnomy account
page (`ProfileIntegration`, mirroring the LearnDash one). If the trigger's arg-index or the
render guard breaks, quizzes stop awarding or the link disappears / renders multiple times.

## Setup

- Site: `$SITE_URL` (dev: `http://lms.local`)
- Test user: a `student`/`subscriber` enrolled in a course with a quiz (autologin via `?autologin=<username>`)
- Fixtures needed: one published course + quiz; one Hub page mapped to `wb_gam_hub_page_id`
- Opt-in: `add_filter( 'wb_gam_learnomy_profile_link', '__return_true' );` (mu-plugin or theme)
- DB clean (per run, replace {ID}):
  ```sql
  DELETE FROM wp_wb_gam_events WHERE user_id = {ID} AND action_id = 'learnomy_quiz_passed';
  DELETE FROM wp_wb_gam_points WHERE user_id = {ID} AND action_id = 'learnomy_quiz_passed';
  ```

## Steps

### 1. Trigger is discovered when Learnomy is active
- **Action**: `wp wb-gamification actions list | grep learnomy_quiz_passed`
- **Expect**: row present, category `learning`, default 25 pts, repeatable, cooldown 30
- **On fail**: `integrations/learnomy.php` (trigger array) or ManifestLoader active-gating

### 2. Passing a quiz awards points
- **Action**: as the student, complete + PASS the quiz in the browser
- **Expect**: points toast appears; `wp_wb_gam_events` has exactly ONE new row
  `action_id = 'learnomy_quiz_passed'`, `user_id = {student}`, metadata carries `quiz_id` + `score`;
  `wp_wb_gam_points` has the matching credit row
- **Capture**: `QUIZ_TOTAL` ← member total after award
- **On fail**: `integrations/learnomy.php` user_callback arg index (must be arg 1) / PointsEngine::award

### 3. Failing a quiz awards nothing
- **Action**: retake and FAIL the quiz (or use a second attempt below the pass mark)
- **Expect**: no new `learnomy_quiz_passed` row; total unchanged from `QUIZ_TOTAL`
- **On fail**: trigger hooked to `learnomy_quiz_submitted`/`_graded` instead of `_passed`

### 4. Cooldown suppresses rapid re-award
- **Action**: pass a quiz twice within 30s
- **Expect**: only ONE award row inside the cooldown window
- **On fail**: `'cooldown' => 30` missing on the trigger / RateLimiter

### 5. Account page shows exactly one "My Achievements" link
- **Action**: `playwright_navigate` to the Learnomy account-details page as the logged-in student
- **Expect**: exactly ONE `a.wb-gam-lrn-link__btn` linking to the mapped Hub permalink
- **On fail**: `src/Integrations/Learnomy/ProfileIntegration.php` hook/guard; boot wiring in `wb-gamification.php`

### 6. Link is opt-in and login-gated
- **Action**: remove the filter opt-in (or view a registration form / logged-out)
- **Expect**: no `.wb-gam-lrn-link` in the DOM
- **On fail**: `wb_gam_learnomy_profile_link` default not false, or missing `is_user_logged_in()` guard

### 7. Graceful degradation without Learnomy
- **Action**: on a site WITHOUT Learnomy, `wp wb-gamification actions list`
- **Expect**: no `learnomy_*` triggers; no fatals; ProfileIntegration::init is a no-op
- **On fail**: manifest guard (`LEARNOMY_VERSION`) / ProfileIntegration::init guard

## Pass criteria

ALL of the following hold:
1. `learnomy_quiz_passed` is discovered only when Learnomy is active, resolving the passing member (arg 1).
2. A pass writes exactly one event + one points row (quiz_id + score in metadata); a fail writes none.
3. Cooldown caps rapid re-awards.
4. The account page renders exactly one Hub link, only when logged in AND the filter is opted in AND a Hub page is mapped.
5. No fatals and no `learnomy_*` triggers on a site without Learnomy.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Quiz pass awards nothing | wrong hook or arg index | `integrations/learnomy.php` (learnomy_quiz_passed) |
| Failed quiz still awards | hooked to submitted/graded, not passed | `integrations/learnomy.php` |
| Link appears 3x | rendered without `is_user_logged_in()` guard | `src/Integrations/Learnomy/ProfileIntegration.php:render` |
| Link never appears | filter default not false / no Hub mapped / boot not wired | `ProfileIntegration::init`, `wb-gamification.php` boot block |
| Fatal on non-Learnomy site | missing active guard | `integrations/learnomy.php` head, `ProfileIntegration::init` |
