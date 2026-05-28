---
journey: buddypress-integration
plugin: wb-gamification
priority: critical
roles: [subscriber, member]
covers: [buddypress-integration, integrations-manifest, bp-hooks, registry, points-engine, profile-rank-line]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Plugin activated"
  - "(Part B only) BuddyPress installed + active"
estimated_runtime_minutes: 8
---

# BuddyPress integration end-to-end

BuddyPress is the plugin's headline integration — the marketing copy is
literally "Complete gamification for WordPress and BuddyPress."
If the BP integration regresses, the plugin's primary use case breaks
silently: members do BP things (post activity, accept friend requests,
join groups), the engine never fires, points never appear. This
journey covers BOTH the always-runnable surface (manifest exists,
BP actions registered, degrades cleanly when BP is absent) AND the
live BP flow (run only when BP is installed + active).

## Setup

- Site: `$SITE_URL`
- Test users:
  - `alice` (Part B sender — posts BP activity, sends friend request, joins group)
  - `bob`   (Part B receiver — accepts friend request, sees BP profile rank line)
  - Both autologin via `?autologin=<name>` (dev-auto-login mu-plugin)
- Fixture: at least 1 BP group exists (Part B). Create via wp-cli if needed:
  ```bash
  wp eval 'if (function_exists("groups_create_group")) {
    groups_create_group(array("creator_id" => 1, "name" => "Journey Test Group", "status" => "public"));
  }'
  ```
- DB clean (Part B, between runs):
  ```sql
  DELETE FROM wp_wb_gam_events WHERE action_id IN (
    'bp_activity_post','bp_friends_accepted','bp_groups_join','bp_groups_create'
  ) AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);
  ```

## Steps

### Part A — Always-runnable (no BP required)

#### A1. Confirm the BuddyPress integration manifest is registered
- **Action**: `wp eval 'echo file_exists(WB_GAM_PATH . "integrations/buddypress.php") ? "ok\n" : "missing\n";'`
- **Expect**: stdout `ok`
- **On fail**: file was removed or path changed. Check the integrations/ directory tree.

#### A2. Verify BP actions are loaded into the Registry
- **Action**: query the actions registry filtered by category:
  ```bash
  wp eval '$ids = array_keys(WBGam\Engine\Registry::get_actions_by_category("buddypress")); echo implode(", ", $ids);'
  ```
- **Expect**: at least these `id` values present —
  `bp_activity_post`, `bp_friends_accepted`, `bp_groups_join`,
  `bp_groups_create`, `bp_publish_post`, `bp_reactions_received`,
  `bp_polls_created`, `bp_media_upload`, `bp_message_sent`,
  `bp_group_cover_upload`.
- **On fail**: `integrations/buddypress.php` manifest not loaded by
  `ManifestLoader::scan`, or `Registry::init` hasn't run, or the category
  metadata is wrong.

#### A3. Plugin loads cleanly when BuddyPress is NOT active
- **Action**: `wp eval 'echo function_exists("buddypress") ? "bp-active" : "bp-absent";'`
- **Action**: visit `$SITE_URL/wp-admin/admin.php?page=wb-gamification`
- **Expect**: no fatal error on the page, dashboard renders. BP-active
  status shown in the Integrations panel (`Active: ...` or `Not installed: BuddyPress`).
- **On fail**: A guard in `src/Hooks/BPHooks.php` or `integrations/buddypress.php`
  is calling a BP function before `function_exists()` check. Look for
  raw `bp_*` calls outside conditional guards.

#### A4. BPHooks::init doesn't fatal on BP-absent boot
- **Action**: `wp eval 'WBGam\Hooks\BPHooks::init(); echo "ok";'`
- **Expect**: stdout `ok`, no fatal. The class defensively checks
  `function_exists("buddypress")` at registration time.
- **On fail**: hooks registered against BP filters that don't exist
  fatal at runtime. `src/Hooks/BPHooks.php` should early-return when
  BP isn't loaded.

### Part B — BuddyPress active flows (skip if A3 returned `bp-absent`)

#### B1. Capture alice's starting points total
- **Action**: `wp eval '$u=get_user_by("login","alice"); echo \WBGam\Engine\PointsEngine::get_total($u->ID),"\n";'`
- **Capture**: `ALICE_BEFORE`

#### B2. Alice posts a BP activity update
- **Action**: Playwright → log in as alice (`?autologin=alice`), navigate
  to `/members/alice/`, click "Post Update", type "BP journey activity"
  in the activity box, click "Post Update" button.
- **Expect**: activity entry visible in the activity stream; alice's
  total points increased by the configured `bp_activity_post` value
  (default 1 — see admin Points table for current value).
- **Capture**: `ALICE_AFTER_ACTIVITY` ← `wp eval '...PointsEngine::get_total($alice_id)'`
- **Pass**: `ALICE_AFTER_ACTIVITY > ALICE_BEFORE` AND the delta equals
  the configured points (or the default if unmodified).
- **On fail**: `integrations/buddypress.php` manifest entry for
  `bp_activity_post` has the wrong hook name, OR the `wb_gam_points_bp_activity_post`
  option is set to 0, OR Engine::process() rejected the event (check
  `wb_gam_before_evaluate` filter for veto behaviour).

#### B3. Alice sends bob a friend request, bob accepts
- **Action**: `wp eval 'friends_add_friend(<alice_id>,<bob_id>);'` — this
  fires `friends_friendship_requested`. Then `wp eval 'friends_add_friend(<bob_id>,<alice_id>);'`
  to accept (BP's API uses the same function from the receiver to confirm).
- **Expect**: both alice + bob get the configured `bp_friends_accepted`
  points. Standard award is to BOTH parties because the manifest
  resolves the receiver-side user via the BP filter.
- **Capture**: `ALICE_AFTER_FRIEND`, `BOB_AFTER_FRIEND`
- **Pass**: at least one of alice/bob's total increased (whichever the
  manifest's `user_callback` resolves to). Both ideally.
- **On fail**: BPHooks listener priority means another plugin's listener
  vetoed before ours fired. Check `add_action('friends_friendship_accepted', ...)`
  priority in BPHooks vs. other plugins.

#### B4. Bob joins the test group
- **Action**: `wp eval 'groups_join_group(<group_id>, <bob_id>);'`
- **Expect**: bob's total points increased by `bp_groups_join` configured value
- **Capture**: `BOB_AFTER_GROUP`
- **Pass**: `BOB_AFTER_GROUP > BOB_AFTER_FRIEND`

#### B5. BuddyPress profile shows the rank line
- **Action**: Playwright → log in as varun (admin), navigate to
  `/members/bob/`. Look in the profile header area for the rank line:
  `Level <name> · <N> Points · <M> Badges` (or similar — exact format
  depends on theme).
- **Expect**: rank line visible, contains bob's current level name AND
  current point total AND badge count.
- **On fail**: `src/Hooks/BPHooks.php` `bp_member_profile_data` hook
  isn't rendering, OR theme is suppressing BP profile filters.

#### B6. BP activity stream shows gamification activity entries
- **Action**: Visit `/activity/` as any logged-in user
- **Expect**: see at least one activity entry from BPHooks integration
  (e.g. "alice earned 1 point for posting"), with the configurable
  context label.
- **Pass**: activity is visible AND filtering by gamification entries
  works (BP activity filter labels — Badge earned / Level up / Kudos
  sent / Challenge complete — per the wb_gam_activity_context_label
  filter shipped in 1.4.0).

## Pass criteria

**Part A (mandatory):**
1. Manifest file exists (A1)
2. All 10 BP action IDs present in the actions registry (A2)
3. Site loads cleanly with no fatal whether BP is active or not (A3)
4. BPHooks::init is idempotent + BP-absent-safe (A4)

**Part B (when BP is active):**
5. Activity post earns the configured points (B2)
6. Friend-accepted fires `bp_friends_accepted` and awards at least one party (B3)
7. Group join awards `bp_groups_join` points (B4)
8. BP profile shows the rank line (B5)
9. Gamification activity entries surface in `/activity/` (B6)

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---------|--------------|-----------------|
| Part A action category empty | ActionsController doesn't filter by category, OR the manifest registration is wrong | `src/Engine/Registry::get_actions_by_category()`, `integrations/buddypress.php` |
| Fatal "call to undefined function buddypress()" | A bp_* call outside `function_exists()` guard | `src/Hooks/BPHooks.php`, `integrations/buddypress.php`, look for unguarded bp_* |
| Part B activity post: no points awarded | `wb_gam_points_bp_activity_post` option is 0, OR the action is disabled (`wb_gam_enabled_bp_activity_post` = false) | `wp option get wb_gam_points_bp_activity_post && wp option get wb_gam_enabled_bp_activity_post` |
| Profile rank line missing (B5) | BPHooks render hook not bound, OR theme override of BP profile area | `src/Hooks/BPHooks.php` (or the theme's BP profile template overrides) |
| Activity stream entries missing (B6) | `ActivityIntegration::register_activity_types` not fired (1.4.0 split), or filter `wb_gam_activity_context_label` returned empty | `src/Integrations/ActivityIntegration.php` (if it exists; otherwise the legacy hook path) |

## Notes

- **Why this journey isn't in `release/`**: BP isn't a release-blocker in
  the strict sense — the plugin ships and runs without it. But for sites
  that DO use BP, it's the most-used surface. Belongs in customer/ so it
  runs on every release pass, not just tier audits.
- **Part B requires BP**: when the journey-runner finds `function_exists("buddypress")`
  returns false, Part B should be marked SKIPPED (not failed). Part A still
  runs unconditionally.
- **No DB rollback for Part A**: A1-A4 are read-only checks. Part B writes
  events; the DB-clean block at the top of the journey handles repeatable runs.
