---
journey: submittable-tick
plugin: wb-gamification
priority: high
roles: [administrator, subscriber]
covers: [submissions, submittable, action-overrides, basecamp-10459347341]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "A page with the Submit Achievement block; a subscriber account"
estimated_runtime_minutes: 8
---

# Submissions: members can only claim actions the owner ticked

Before 1.6.8 the Submit Achievement form listed every action, so a member could claim "Publish a blog
post" by hand and be paid twice. From 1.6.8 nothing is submittable until the owner ticks **Members can
submit** on the Points screen. The tick is stored as `submittable` inside the existing
`wb_gam_action_overrides` option (no new option).

## Steps

### 1. Strict default
- **Action**: fresh site or after update: `wp option get wb_gam_action_overrides --format=json`;
  `GET /wp-json/wb-gamification/v1/actions`.
- **Expect**: no row carries `submittable: true`; every action in the REST list has `submittable: false`.
  As a subscriber, `POST /submissions` with `action_id=wp_publish_post` returns 403 `wb_gam_not_submittable`.
- **On fail**: `Registry::is_submittable()` (Lane A), `SubmissionService::submit()`.

### 2. Owner ticks an action
- **Action**: admin, **WB Gamification > Points**, open WordPress, tick **Members can submit** on
  "Leave a comment". Watch the request and reload.
- **Expect**: `POST /actions/wp_leave_comment/overrides` with `{"submittable":true}` returns 200 and
  `"submittable": true`; the box flashes green; after reload it is still ticked; the option row is
  `{"submittable":true}` and keeps any cooldown or caps already set.
- **On fail**: `assets/js/admin-action-overrides.js` checkbox branch, `ActionsController::update_overrides()`.

### 3. Member sees only that action
- **Action**: subscriber opens the Submit Achievement page.
- **Expect**: the action list contains only "Leave a comment"; a submission for it returns 200/201.

### 4. Untick keeps the queue
- **Action**: untick it; as admin approve the pending submission from step 3.
- **Expect**: the subscriber's form shows the empty state; the approval still pays the points.

### 5. Failed save does not lie
- **Action**: in DevTools block `*/overrides`, tick a box.
- **Expect**: the box gets the red error ring and goes back to unticked.

### 6. 390 px
- **Action**: Points screen at 390 wide.
- **Expect**: the table scrolls inside its card; the new column header and tick are reachable by keyboard
  and announced as "Members can submit {action} for review".

## Pass criteria

1. Nothing is submittable by default; the API refuses with 403.
2. The tick round-trips through the option and survives reload.
3. Pending submissions for an unticked action can still be approved.
