---
journey: submittable-actions
plugin: wb-gamification
priority: high
roles: [administrator, subscriber]
covers: [strict-default, owner-tick, manifest-opt-in, pending-still-approvable]
prerequisites:
  - "Site reachable at $SITE_URL; a Submit Achievement block on a page"
estimated_runtime_minutes: 5
---

# Members submit only what the owner opened (card 10459347341)

## Steps

### 1. Closed by default
- **Action**: as a member, `POST /wb-gamification/v1/submissions` with `action_id=wp_publish_post`.
- **Expect**: 403 `wb_gam_not_submittable`; the block lists no actions and shows its empty state.
- **On fail**: `SubmissionService::submit()`, `Registry::is_submittable()`.

### 2. The owner opens one
- **Action**: tick "Members can submit this" for one action (Gamification > Settings > Points); submit it.
- **Expect**: 200; the block lists only that action. Untick it: 403 again, even for an action whose
  manifest says `submittable => true` (the owner's choice wins both ways).

### 3. Pending submissions survive closing
- **Action**: submit while open, close the action, approve the pending submission as admin.
- **Expect**: approval succeeds and awards the points.
