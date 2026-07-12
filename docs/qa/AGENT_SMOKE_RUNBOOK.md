# Agent Smoke Runbook — WB Gamification Pre-Release

**Audience:** a browser-capable agent (Claude Sonnet) with Playwright MCP + WP-CLI Bash + MySQL access, OR a human QA person with the same access. Both should be able to execute every step of this runbook.

## How to read this runbook

Each **C** and **E** step describes a **customer contract**: what the feature promises, why it matters, the surfaces it touches, and what "working" looks like in customer terms. It does NOT prescribe Playwright selectors, REST paths, or DB queries. Read the relevant plugin code, pick the right mechanism, and verify the contract.

Where a **journey file** already exists under `audit/journeys/`, the step lists it as the **deterministic skeleton** — execute the journey verbatim first; if it passes, the contract is satisfied. If the journey passes but the contract still feels broken (a corner the journey doesn't cover), record the deviation as a failure.

**D** (regression guards) stays specific — those are repros of past incidents; the exact fixture IS the contract.

Infrastructure sections (preconditions, output contract, debug-log protocol, fixture cleanup, failure protocol) stay specific because they are the stable machinery the walk rides on.

## Global preconditions

- Working directory: `/Users/varundubey/Local Sites/wb-gamification/app/public`
- Site URL: `http://wb-gamification.local`
- WP path: `/Users/varundubey/Local Sites/wb-gamification/app/public`
- WP-CLI template: `wp --path="$WP_PATH" <cmd>`
- Admin auto-login: `?autologin=1` on any front-end URL (admin = user 1)
- Per-user auto-login: `?autologin=<user_login>` (e.g. `?autologin=qa_member`)
- Playwright: reuse one Chromium session. Restart with `browser_close` + `browser_navigate` if it dies.
- Debug log: `wp-content/debug.log`
- Release target: value of the `WB_GAM_VERSION` constant (read with `wp eval 'echo WB_GAM_VERSION;'`)
- Plugin path: `wp-content/plugins/wb-gamification`
- Manifest: `audit/manifest.json` — canonical inventory of REST routes, blocks, tables, hooks
- Role matrix: `audit/ROLE_MATRIX.md` — which routes are public, member-only, admin-only

## Agent output contract

At the end of the walk, write exactly one JSON file to
`wp-content/plugins/wb-gamification/docs/qa/.last-smoke-pass.json`:

```json
{
  "release_version": "<WB_GAM_VERSION>",
  "ran_at": "<ISO 8601 UTC>",
  "site": "http://wb-gamification.local",
  "agent": "sonnet",
  "sections": {
    "A_fresh_install":     { "pass": N, "fail": N, "skipped": N },
    "B_upgrade":           { "pass": N, "fail": N, "skipped": N },
    "C_core_flows":        { "pass": N, "fail": N, "skipped": N },
    "D_regression_guards": { "pass": N, "fail": N, "skipped": N },
    "E_integrations":      { "pass": N, "fail": N, "skipped": N },
    "F_cross_browser":     { "pass": N, "fail": N, "skipped": N }
  },
  "journey_runs": [
    {
      "slug": "<journey-slug>",
      "path": "audit/journeys/customer/01-earn-points-via-rest.md",
      "outcome": "PASS | FAIL",
      "duration_seconds": N,
      "failure_step": null,
      "expected": null,
      "actual": null
    }
  ],
  "failures": [
    {
      "id": "C.member.earn-points",
      "origin": "from | for",
      "triage_note": "one line on why you classified it that way",
      "expected": "...",
      "actual": "...",
      "url": "...",
      "screenshot": "...",
      "journey": "<slug or null>"
    }
  ],
  "debug_log_issues": [
    {
      "section": "C.member.earn-points",
      "level": "fatal | warning | notice | deprecated",
      "origin": "from | for",
      "line": "<verbatim debug.log line>",
      "file": "<path>:<lineno>"
    }
  ],
  "manual_required": [
    "Firefox Desktop: composer time picker on community-challenges block",
    "Safari iOS 390px: leaderboard period switcher"
  ]
}
```

Also emit a Basecamp draft for every failure using the template in the **Failure protocol** section. The calling Opus session files them as real cards in the Bugs column of project `47162271`.

## Fixture cleanup (before every walk)

Delete leftover test data from prior runs. Exact WP-CLI eval is permitted here because this is infrastructure, not a feature check.

Cleanup is keyed on the **fixture users**, not on a marker string in the row.

`wb_gam_points` is `(id, event_id, user_id, action_id, points, point_type, object_id,
created_at)` — it has no `reason` and no `metadata` column, so there is nothing in a
ledger row to pattern-match on. (Until 1.6.4 this block tried to `DELETE ... WHERE reason
LIKE '%journey_smoke%'`, which matched nothing, cleaned nothing, and logged a
`Unknown column 'reason'` database error on every single run.)

Do NOT "fix" that by deleting ledger rows whose `event_id` no longer resolves. Event
retention prunes `wb_gam_events` on a live site while the points stay — orphan-deleting
would destroy real member balances.

```bash
# Set WP_PATH to whichever site the plugin is installed in on THIS machine.
WP_PATH="/Users/vapvarun/Local Sites/buddynext-dev/app/public"
wp --path="$WP_PATH" eval '
global $wpdb;

// Every table this walk writes to is user-scoped, so the fixture users are the key.
$logins = array( "qa_admin", "qa_editor", "qa_member", "qa_member2", "qa_external" );
$ids    = array();
foreach ( $logins as $login ) {
    $u = get_user_by( "login", $login );
    if ( $u ) {
        $ids[] = (int) $u->ID;
    }
}

if ( $ids ) {
    $in = implode( ",", array_map( "intval", $ids ) );

    // These are all keyed on user_id.
    $tables = array(
        "wb_gam_points",
        "wb_gam_events",
        "wb_gam_user_totals",
        "wb_gam_user_badges",
        "wb_gam_streaks",
        "wb_gam_notifications_queue",
    );
    foreach ( $tables as $t ) {
        $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t} WHERE user_id IN ({$in})" );
    }

    // Kudos is NOT keyed on user_id — it is (giver_id, receiver_id). Clean both sides.
    $wpdb->query( "DELETE FROM {$wpdb->prefix}wb_gam_kudos WHERE giver_id IN ({$in}) OR receiver_id IN ({$in})" );
}

wp_cache_flush();
echo "fixtures cleaned for " . count( $ids ) . " user(s)\n";
'
```

If `qa_admin` / `qa_editor` / `qa_member` / `qa_external` users do not exist, seed them (per `plan/QA-MANUAL-TEST-PLAN.md` § Setup B):

```bash
for u in "qa_admin:administrator" "qa_editor:editor" "qa_member:subscriber"; do
  login="${u%%:*}"
  role="${u##*:}"
  wp --path="$WP_PATH" user create "$login" "$login@example.test" --role="$role" --user_pass="$login" 2>/dev/null || true
done
```

## Debug log protocol

Enable WP_DEBUG + WP_DEBUG_LOG + WP_DEBUG_DISPLAY=false for the entire walk. Baseline `wp-content/debug.log` byte count. After every section, diff new lines and record `Fatal error:` / `Warning:` / `Notice:` / `Deprecated:` entries into `debug_log_issues[]`. Treat any new non-info line as a failure unless explicitly whitelisted.

Whitelist (`origin: for`, do not block):
- BuddyPress / WooCommerce / bbPress notices that fire whether or not gamification is loaded
- Theme deprecations from Reign / Twenty-Twenty-Five
- Action Scheduler "Scheduled action ... was unable to be triggered" only if the schedule was deleted by fixture cleanup

```bash
BASELINE_SIZE=$(wc -c < "$WP_PATH/wp-content/debug.log" 2>/dev/null || echo 0)
# after each section:
tail -c +$((BASELINE_SIZE + 1)) "$WP_PATH/wp-content/debug.log" 2>/dev/null \
  | grep -vE "^\s*$|^\[cli\]" \
  | grep -E "PHP (Fatal|Warning|Notice|Deprecated)"
```

At walk end, archive the diff window to `docs/qa/.debug-log-<release_version>-<ran_at>.txt`.

## Journey-aware execution model

This runbook leverages the existing journey corpus under `audit/journeys/`. For each C / E step that lists a `**Journey:**` pointer:

1. **Run the journey verbatim** — read the markdown, execute every step against `$SITE_URL`, write `audit/journey-runs/<run-id>/<slug>.json`. Append the result to `journey_runs[]` in the smoke report.
2. **If the journey passes**, the contract is satisfied for the steps the journey covers.
3. **Cross-check the contract** for surfaces the journey doesn't touch (UI presence, mobile breakpoint, dark-mode contrast). Record any deviations as failures with `journey: <slug>` set.

For C / E steps without a journey pointer, walk the contract from scratch — exercise the UI as a user would AND confirm the server-side effect (DB row, REST response, email queued, action scheduler queued).

---

## A — Fresh install (skip on live dev sites)

### A1 — Activation routing
**What to verify:** after a clean activation, the plugin's REST routes respond 200 on the very first request, the hub page exists, and the rewrite rules contain the plugin's routes.
**Why it matters:** activation hooks not firing means a customer's first impression after install is a 404.
**Acceptance:** `GET /wp-json/wb-gamification/v1/openapi.json` returns 200 on first request after activating; `wp option get wb_gam_hub_page_id` returns a numeric ID; `rewrite_rules` option contains `wb-gamification`.

### A2 — Database schema in place
**What to verify:** all 22 expected custom tables (per manifest.json) exist and the stored db-version option matches `DbUpgrader::TARGET`.
**Acceptance:** `wp wb-gamification doctor` reports schema OK; `SHOW TABLES LIKE 'wp_wb_gam_%'` returns 22 rows.

### A3 — Setup wizard renders + auto-redirects
**What to verify:** the activation hook arms a persistent redirect signal (option `wb_gam_pending_setup_redirect`); the next admin page load consumes it and lands the admin on **Gamification Setup**; the wizard renders 5 starter template cards; selecting one persists `wb_gam_template` and exits to the overview with `wb_gam_wizard_complete = true`. The wizard URL stays reachable after completion (admins can re-run with a different template).
**Why it matters:** broken first-run = customer leaves before configuring anything; broken re-run = admins can't switch templates without WP-CLI.
**Acceptance:** after `deactivate → activate → load any /wp-admin/* URL`, user lands on `?page=wb-gamification-setup` with 5 cards in DOM. `wb_gam_pending_setup_redirect` is deleted post-redirect. After picking a template, `wb_gam_template` matches choice and `wb_gam_wizard_complete = true`. Re-visiting the wizard URL after completion still renders, with a "you've already completed setup" notice and a "Re-apply this template" button on the current card. The "welcome — run setup" admin notice appears on plugin admin pages until completion, never after.

---

## B — Upgrade from previous version (skip if no prior version)

### B1 — Migration runs quietly, existing data still works
**What to verify:** bumping from the prior stable version to this build completes with zero debug.log entries during the activation HTTP request; pre-existing events / points / badges / challenges still render and function; denormalized totals (`wb_gam_user_totals`) remain in sync.
**Acceptance:** new debug.log lines during upgrade = 0; sample three pre-existing users and confirm `MembersController::get_points` returns the same total as the sum of their ledger rows.

### B2 — v1.0 → v1.1 cosmetic-table drop
**What to verify:** the `upgrade_to_1_1_0()` migration drops `wb_gam_cosmetics` and `wb_gam_user_cosmetics` tables AND no orphan references survive.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 6.3.
**Acceptance:** `SHOW TABLES LIKE 'wp_wb_gam_cosmetic%'` returns 0 rows on a v1.0 → v1.1 site.

### B3 — Granular-cap registration on upgrade
**What to verify:** the upgrade registers the eight `wb_gam_*` capabilities on the administrator role.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 6.4.
**Acceptance:** `wp eval 'print_r((array) get_role("administrator")->capabilities);' | grep wb_gam_` returns 8 lines.

---

## C — Core customer flows

Persona ladder: **Anonymous → Member → Editor (granular cap) → Admin**. Pick a real test user from each persona. Cover desktop 1280px and mobile 390px where relevant. Cover light + dark theme where the surface themes (every block does).

Each step is a contract — exercise it from the UI AND confirm the server-side effect. Where a journey is listed, run it first.

### C.anon.hub-renders
**Customer contract:** the Member Hub page is publicly viewable, lists the configured tiles (leaderboard preview, recent badges, top members), and offers a clear login path for un-authed visitors.
**Why it matters:** first-impression surface; broken means no signup conversion.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 4.1.
**Acceptance:** hub URL returns 200 anonymously, hub page renders top-3 leaderboard preview, login CTA visible.

### C.anon.leaderboard-block
**Customer contract:** the leaderboard block renders for logged-out visitors, shows real ranks, and the period selector switches the dataset without page reload.
**Journey:** `audit/journeys/customer/02-view-leaderboard-block.md`
**Acceptance:** journey passes; AND on mobile 390px the period switcher remains tappable; AND in dark mode the contrast ratio of rank text vs background ≥ 4.5:1.

### C.anon.public-rest-allowlist
**Customer contract:** the 10 documented `__return_true` endpoints serve anonymously; the 7 auth-required endpoints reject anonymously; the 6 admin-required endpoints reject both anonymous and subscriber.
**Journey:** `audit/journeys/security/01-rest-public-allowlist.md`
**Why it matters:** silent permission drift on these endpoints exposes the entire engine to abuse.
**Acceptance:** journey passes with 0 failures.

### C.anon.member-private-allowlist
**Customer contract:** member-private routes (`/members/me/...`, `/leaderboard/me`, `/kudos/me`, `/redemptions/me`, `/challenges/<id>/complete`) reject anonymous AND reject reads-of-other-users from a logged-in non-admin.
**Journey:** `audit/journeys/security/02-rest-member-private-allowlist.md`
**Acceptance:** journey passes.

### C.member.earn-points
**Customer contract:** a logged-in member triggering a registered action (e.g. publish a post, leave a comment) earns the action's configured points within one async cycle. The total reflects on `/members/<id>/points` and on the Hub page.
**Journey:** `audit/journeys/customer/01-earn-points-via-rest.md`
**Why it matters:** if this regresses, every other engine (badges, levels, streaks, leaderboard, recap) silently breaks because they all read off the same ledger.
**Acceptance:** journey passes; AND a manual UI walk (publish a real post as `qa_member`) produces the same +N points within 5s; AND the Hub page reflects it on next reload.

### C.member.first-badge-unlock
**Customer contract:** a member's first qualifying action unlocks the relevant badge within 60s, the toast notification fires, and the badge appears on their profile + on the public badge share URL.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 4.2.
**Acceptance:** badge row appears in `wb_gam_user_badges`; toast renders client-side; share URL `/?wb-gam-share=<badge_slug>` returns 200 with OG tags.

### C.member.kudos
**Customer contract:** a member can give kudos to another member with a message; the recipient's kudos feed updates; the cooldown blocks duplicate kudos within the configured window.
**Acceptance:** POST `/kudos` returns 201 with `id`; second POST within cooldown returns 429 with code `wb_gam_kudos_cooldown`; kudos block on recipient's profile shows the new entry.

### C.member.streak
**Customer contract:** a member who completes a daily-tagged action on consecutive days sees their streak counter increment; missing a day resets the counter; the streak block renders the right state across desktop and mobile.
**Acceptance:** seed two consecutive day events; `wb_gam_streaks` shows `current_streak = 2`; streak block renders "🔥 2 day streak"; skipping a day, the next event resets to 1.

### C.member.challenge-complete
**Customer contract:** a member working on an individual challenge sees their progress update after each qualifying event; on completion, the reward (points / badge) is granted and the challenge moves to "completed" state on the challenges block.
**Acceptance:** challenge progress increments after each event; completion fires the `wb_gamification_challenge_completed` action; reward grant verifiable via the points ledger.

### C.member.community-challenge
**Customer contract:** a community challenge with a group target shows aggregate progress on the community-challenges block; once the target hits, every contributing member gets the configured reward and the challenge transitions to "won".
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 4.4.
**Acceptance:** seed three users contributing to one community challenge; aggregate progress equals the sum of their contributions; reward grants land in each user's ledger.

### C.member.cohort-rank
**Customer contract:** the cohort-rank block on a member's profile shows their rank within their cohort (signup-week / role / custom group), with an honest empty state if the cohort has fewer than 3 members.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 4.5.
**Acceptance:** block renders rank for a populated cohort; renders the configured empty state for a cohort of 1.

### C.member.year-recap
**Customer contract:** the year recap block produces a shareable summary (top earned action, top badges, longest streak) for any member who has any events in the year.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 4.7.
**Acceptance:** recap renders for `qa_member` after the earlier earning steps; share URL works; empty state renders for users with zero events.

### C.member.redemption
**Customer contract:** a member with sufficient points can redeem a configured reward; their balance debits exactly once; the redemption shows on `/redemptions/me`; insufficient balance produces a clean error, not a fatal.
**Acceptance:** POST `/redemptions` with sufficient balance returns 201; balance decrements by `points_cost`; insufficient-balance attempt returns 400 with code `wb_gam_redemption_insufficient`.

### C.member.bp-achievements-tab
**Customer contract:** with BuddyPress active, a logged-in member's profile shows an "Achievements" tab (nav slug `achievements`) with four sub-tabs - Overview, Badges, Points, Streak. Each sub-tab renders the matching member surface (Overview = points + streak summary, Badges = earned + locked badges, Points = points history, Streak = streak block) reusing existing blocks through `WBGam\Engine\MemberSurface`, with no duplicated display logic.
**Why it matters:** this is the primary member-facing surface on BuddyPress sites; broken means members can't see their own progress.
**Acceptance:** visit a member's BP profile - the Achievements tab is present with `item_css_id` `wb-gam-achievements`; navigating to `/members/<user>/achievements/badges/`, `/points/`, `/streak/` each renders its surface without a PHP Notice/Warning/Fatal; the default sub-action is `overview`. Confirm the rendered HTML passes through the `wb_gam_member_surface_html` filter (the same markup appears on the mapped Hub page surface).

### C.member.wc-account-achievements
**Customer contract:** with WooCommerce active, a logged-in customer sees an "Achievements" item in the My Account nav that resolves at `/my-account/achievements/` and renders the self-scoped Hub surface plus a link to the mapped Hub page. The endpoint exists ONLY when WooCommerce is active.
**Why it matters:** stores running WooCommerce without BuddyPress still need a member-facing gamification surface.
**Acceptance:** with WooCommerce active and permalinks flushed, `/my-account/achievements/` returns 200 for the logged-in account owner and renders the member surface; the "Achievements" menu item appears in the My Account nav; with WooCommerce deactivated the endpoint is absent (no `achievements` query var registered, the URL 404s) and no fatal fires anywhere.

### C.member.learndash-profile-link
**Customer contract:** the LearnDash profile "My Achievements" hub link is OFF by default - it only appears when a site returns true from the `wb_gam_learndash_profile_link` filter. When enabled it links to the mapped Hub page.
**Why it matters:** the opt-in default prevents an unwanted nav item on every LearnDash install; the contract is "silent unless asked for".
**Acceptance:** with LearnDash active and no filter override, the LearnDash profile shows NO "My Achievements" link. After `add_filter( 'wb_gam_learndash_profile_link', '__return_true' )`, the link appears on the LearnDash profile and points at the Hub page. No fatal in either state.

### C.member.public-profile-default-on
**Customer contract:** a member's public profile at `/u/{username}` is viewable by default - it returns the profile page (200), NOT a 404. A member opts OUT by setting the per-user privacy flag to `'0'`; only that explicit value makes the profile private. The `wb_gam_profile_publicly_visible` filter can override per user.
**Why it matters:** before 1.5.2 every `/u/` profile 404'd because the gate required a flag nobody ever wrote (`D.public-profile-default-on`); the share/recap/OG surfaces all depend on this page resolving.
**Acceptance:** `/u/<existing_user_login>` returns 200 anonymously and renders the member profile with OG + Schema.org JSON-LD; setting that user's privacy pref to `'0'` makes `/u/<user>` 404 for anonymous visitors while the owner viewing their own page still gets 200; an unset/empty pref resolves to visible.

### C.member.toast-placement
**Customer contract:** toast notifications appear in the corner configured by the admin in Settings > Realtime (option `wb_gam_toast_position`, default `bottom-right`); the slide-in direction is corner-aware; the toast names what the points were for and never overlaps the theme header.
**Why it matters:** a toast pinned under a sticky header is invisible feedback; the placement setting lets each site avoid its own chrome.
**Acceptance:** Settings > Realtime exposes a placement selector with the four documented positions (`top-right`, `top-center`, `bottom-right`, `bottom-left`); changing it persists `wb_gam_toast_position`; triggering a points award renders the toast in the selected corner with award context text and no overlap of the theme's top nav/sticky header (verify at the top-* positions specifically). The `wb_gam_toast_position` filter overrides the saved option.

### C.editor.granular-cap
**Customer contract:** an editor with `wb_gam_manage_badges` granted can create/list badges via REST, but is gated out of every other admin endpoint (rules, webhooks, manual award, redemption-store admin).
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 3.
**Acceptance:** with cap granted, POST `/badges` returns 201 for editor; without cap, 403; cap holder still 403 on `/points/award`, `/rules`, `/webhooks`.

### C.admin.manual-award
**Customer contract:** an admin can manually award points to a member from the Award Points admin page AND via REST; a subscriber attempting the same is rejected (cap-drift sentinel).
**Journey:** `audit/journeys/admin/01-manual-award-points.md`
**Acceptance:** journey passes; AND the admin UI page renders without notice; AND the form's "user picker" autocomplete returns 200 with results.

### C.admin.13-pages-render
**Customer contract:** every Gamification admin page renders without a PHP Notice / Warning / Fatal AND every tab/AJAX surface on those pages loads without console error.
**Journey:** `audit/journeys/release/05-admin-9-pages-rest.md` (covers 9 of 13; the four remaining — Webhooks, Cohort Settings, Submissions, Point Type Conversions — are walked manually).
**Acceptance:** journey passes; AND a manual visit to each remaining admin page returns 200 + no debug.log entries.

### C.admin.api-keys
**Customer contract:** an admin can mint an API key, revoke it, and see usage stats; calls to `/events` with `X-WB-Gam-Key: <key>` succeed for active keys and 401 for revoked ones.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 5.5.
**Acceptance:** key creation persists to `wb_gam_api_keys`; revoked-key requests return 401 with code `wb_gam_invalid_api_key`.

### C.admin.webhooks
**Customer contract:** an admin can register an outbound webhook bound to one or more events; triggering the event fires a real HTTP POST to the configured URL with the documented JSON shape; the test-fire button delivers a parseable payload.
**Reference:** `plan/QA-MANUAL-TEST-PLAN.md` § Persona 2.6.
**Acceptance:** stand up a `webhook.site` URL; trigger `wb_gamification_points_awarded`; the webhook receives a POST with `event_id`, `user_id`, `action_id`, `points`.

### C.admin.recount
**Customer contract:** running `wp wb-gamification doctor --recompute-leaderboard` (or the admin UI button) restores any drift between the cached leaderboard snapshot and the live-query value.
**Acceptance:** corrupt the cache (`TRUNCATE wb_gam_leaderboard_cache`); recount restores it to a value matching the live SUM.

### C.notifications
**Customer contract:** every notification-triggering event (badge unlock, kudos received, challenge completion, level up) produces a BuddyPress notification (if BP active) AND/OR an email per the user's preference. The bell badge count updates without page reload.
**Acceptance:** trigger one of each event type for `qa_member`; verify a row in `bp_notifications` and an email in Mailpit (or the configured trap).

### C.cron
**Customer contract:** after activation, every expected cron / Action Scheduler event is registered; orphaned events are absent after deactivation; cron actually executes when triggered manually.
**Acceptance:** `wp cron event list | grep wb_gam_` returns the expected schedules; `wp action-scheduler run` processes any pending async events without fataling.

### C.realtime-transport-default
**Customer contract:** the shipped realtime transport is WP Heartbeat, NOT the SSE long-poll. Heartbeat ticks at 15s steady-state, bursts to 5s for ~30s right after an action, and near-suspends (120s) while the tab is backgrounded. SSE is opt-in only - it activates solely when a site returns true from the `wb_gam_sse_allowed` filter (default false).
**Why it matters:** the SSE long-poll pinned a PHP-FPM worker per logged-in page and did not scale to 100k users; the Heartbeat default is the scale fix for 1.5.2 (`D.heartbeat-default-not-SSE`).
**Acceptance:** on a logged-in front-end page, the realtime layer loads `assets/js/heartbeat.js` and the network trace shows Heartbeat admin-ajax ticks (no persistent `text/event-stream` SSE connection); `SSEController` permission resolves to false by default (`wb_gam_sse_allowed` returns false). After an award, ticks accelerate to ~5s briefly then settle back to 15s; backgrounding the tab drops the tick rate. Returning true from `wb_gam_sse_allowed` is the only way the SSE stream opens.

---

## D — Known-regression guards

Each row repros a past bug that caused customer pain. These rows stay specific on purpose: the exact fixture IS the contract.

| ID | Bug | Fixture + assertion |
|----|-----|---------------------|
| D.activation-rewrite | Activation hook didn't flush rewrite rules; first `/wp-json/wb-gamification/v1/leaderboard` request 404'd on a fresh site | Clean reactivate; first REST request returns 200 without manual permalink flush |
| D.cap-drift-manual-award | A change registered `wb_gam_award_manual` and granted it to subscribers, letting any logged-in user mint points | As `qa_member`, POST `/points/award` returns 403 with `code: rest_forbidden` |
| D.cosmetic-orphan-tables | v1.0 → v1.1 migration left `wb_gam_cosmetics` rows referenced by foreign-key from `wb_gam_user_badges` | After upgrade, `wb_gam_user_badges` has no rows referencing the dropped cosmetic tables |
| D.leaderboard-cache-drift | Cached leaderboard ranking diverged from the live SUM after an event burst | Snapshot cache; `TRUNCATE wb_gam_leaderboard_cache`; recompute returns identical ordering |
| D.streak-tz-offset | Streak counter reset across midnight in the user's local TZ, not site TZ — daily streak broke for east-coast users at 9pm site-time | Seed an event at 11:55 site-TZ; seed another at 00:05 site-TZ next day; `wb_gam_streaks.current_streak` increments to 2, does not reset |
| D.zero-points-rejected | POST `/points/award` with `points: 0` silently no-op'd instead of returning 400 | POST with `{points:0}` returns 400 code `rest_points_zero` |
| D.negative-points-debit | POST `/points/award` with negative points wrote a positive row instead of debiting | POST `{points:-50}` returns 201 with `debited:true`; ledger SUM decreases by 50 |
| D.events-no-points-column | The events table briefly grew a `points` column that drifted from the points ledger | `DESCRIBE wp_wb_gam_events` does not list a `points` column |
| D.kudos-cooldown-bypass | Sending two kudos in quick succession via parallel requests skipped the cooldown check | Two parallel POSTs to `/kudos` from the same user — exactly one returns 201, the other 429 |
| D.openapi-stale | OpenAPI spec endpoint returned an older schema after a routes change because the spec was cached | `paths` count from `/openapi.json` ≥ 30 and includes routes added in the current branch |
| D.dark-mode-block-contrast | Block tokens didn't re-derive in dark mode, producing low-contrast text on every block | With `body.wb-gam-dark`, every block's primary text computed-style passes WCAG AA (≥ 4.5:1) against its computed background |
| D.mobile-tabs-clipped | Hub tabs at 390px scrolled off-screen with the active tab hidden | At 390px on `/wb-gam-hub`, the active tab is within the viewport on initial load |
| D.cli-replay-idempotent | `wp wb-gamification replay` re-awarded points for events already awarded | Run replay twice on the same event; ledger SUM is identical after both runs |
| D.public-allowlist-drift | A new admin route shipped without a `permission_callback`, accidentally going public | All routes outside the documented allowlist reject anonymous (covered by the security journey) |
| D.action-scheduler-orphan | Deactivation didn't unregister cron events; reactivation duplicated them | After deactivate-then-activate, `wp cron event list \| grep -c wb_gam_` matches the documented schedule count exactly |
| D.granular-cap-merge | An update overwrote the administrator's `capabilities` array instead of merging, dropping unrelated caps | After upgrade, `get_role("administrator")->capabilities` contains every pre-existing cap PLUS the eight `wb_gam_*` caps |
| D.wizard-redirect-30s-too-short | Activation set a 30s `wb_gam_do_redirect` transient; any WP-CLI activate followed by minutes-later browser navigation silently dropped the redirect — admins never saw the wizard | After `wp plugin deactivate wb-gamification && wp plugin activate wb-gamification`, wait 5 minutes (or any duration > 30s), then load `/wp-admin/`. Expect HTTP 302 to `?page=wb-gamification-setup`. Verify `wb_gam_pending_setup_redirect` option is set immediately after activation and deleted after the redirect fires. The legacy `wb_gam_do_redirect` transient must NOT exist. |
| D.wizard-skip-applies-toggles | Old wizard's "Skip & configure manually" button still wrote the email + privacy toggle options, surprising admins who expected skip to mean "leave defaults alone" | Click Skip with all toggles unchecked; verify `wb_gam_email_level_up`, `wb_gam_email_badge_earned`, `wb_gam_email_challenge_completed`, `wb_gam_profile_public_enabled` options remain unset (engine ship-defaults stay in force) |
| D.wizard-rerun-blocked | Once `wb_gam_wizard_complete` was true, the wizard submenu was never registered, so visiting `?page=wb-gamification-setup` 404'd — no way to switch templates without a WP-CLI option-delete dance | After completing the wizard, visit `/wp-admin/admin.php?page=wb-gamification-setup`. Page renders with the "you've already completed setup" notice; current template is highlighted with a "Current" badge; submitting overwrites `wb_gam_template` to the new choice. |
| D.kudos-double-fire-fatal | `KudosEngine::send()` fired `wb_gam_kudos_given` twice — once with 4 args, once with 3 args. `NotificationBridge::on_kudos_given` registered `accepted_args=4`; the 3-arg fire triggered `TypeError: missing $kudos_id` on every kudos send | Send a kudos via `KudosEngine::send( $giver, $receiver, 'msg' )`. Expect single `do_action('wb_gam_kudos_given', ...)` call with all 4 params, no PHP fatal in `wp-content/debug.log` |
| D.leaderboard-is-eventually-consistent (rewritten 1.6.4) | **This row asserted the OPPOSITE contract until 1.6.4, and asserting it again would reintroduce the bug.** The old model bumped a `wb_gam_leaderboard_invalidated_at` option on every award, and `read_from_snapshot()` bailed to a live aggregate whenever the snapshot was older than that option. On any site with steady traffic the option was *always* newer than the snapshot, so the materialised leaderboard was bypassed on essentially every request — the plugin advertised a snapshot and shipped a full live `GROUP BY` over the ledger. That is a 100k-member outage, not a cache miss. The option and both its call sites were deleted in 1.6.4. | The board is **deliberately eventually-consistent**: it is served from the `wb_gam_leaderboard_cache` snapshot, rebuilt on a 5-minute recurring Action Scheduler job (`wb_gam_leaderboard_snapshot`). Award points via REST, then immediately GET `/leaderboard` — the ranking is **expected NOT to move yet**, and that is a pass, not a failure. Then run `wp wb-gamification doctor --recompute-leaderboard` and re-fetch: the new total is reflected. Per-user rank (`/leaderboard/me`) is computed live and DOES update instantly — check that it does. Assert the option `wb_gam_leaderboard_invalidated_at` **does not exist**: `wp option get wb_gam_leaderboard_invalidated_at` must return nothing. If a future change makes the whole board update instantly on award, that is a regression to investigate, not a fix. |
| D.kudos-cooldown-status | Kudos cooldown errors returned HTTP 422 with code `wb_gam_kudos_limit`; per-receiver cooldown was never implemented (giver could spam-kudos one receiver) | POST `/kudos` past the daily limit → expect HTTP 429 + code `wb_gam_kudos_cooldown` (not 422 / `wb_gam_kudos_limit`). Send a kudos to receiver A; immediately try again to A → expect HTTP 429 + code `wb_gam_kudos_cooldown` (per-receiver gate, default 1-hour window via filter `wb_gam_kudos_per_receiver_cooldown_seconds`) |
| D.leaderboard-mobile-overflow | `.wb-gam-leaderboard__name` was a flex child without `min-width: 0`, so a long display-name pushed the row past 390px viewport — horizontal scroll on mobile | At 390px viewport on a page with the leaderboard block, every row stays inside the container (no horizontal scroll) even when names exceed 20 characters; `.wb-gam-leaderboard__name` has `min-width: 0` and `text-overflow: ellipsis` resolves correctly. The `@media (max-width: 640px)` block in `src/Blocks/leaderboard/style.css` tightens the row spacing and font-size |
| D.level-changed-double-fire | `LevelEngine::on_user_level_changed()` fired `wb_gam_level_changed` twice — once with `(user_id, int, int)`, once with `(user_id, ?array, ?array)`. Listeners typed for the array signature (`WebhookDispatcher::on_level_changed`, `TransactionalEmailEngine::on_level_up`, `NotificationBridge::on_level_changed`) crashed with `TypeError: Argument #2 must be of type ?array, int given` on every level-up | Award enough points to a user to cross a level threshold. Expect single `do_action('wb_gam_level_changed', $uid, ?array $new, ?array $old)`; no PHP fatal in `wp-content/debug.log` |
| D.leaderboard-snapshot-survives-rebuild (rewritten 1.6.4) | `write_snapshot()` stamped its rows with `current_time('mysql')` (site-local) and then deleted rows older than a `NOW()`-based cutoff (database UTC). On any site whose timezone is **ahead of UTC**, every row it had just written looked "old" and was deleted immediately — the rebuild wiped the snapshot it had just built, leaving the board permanently empty and every request falling through to a live aggregate. | Set the site to a timezone ahead of UTC (`wp option update timezone_string Asia/Kolkata`), run `wp wb-gamification doctor --recompute-leaderboard`, then assert `SELECT COUNT(*) FROM wp_wb_gam_leaderboard_cache` is **> 0** and `[wb_gam_leaderboard]` renders populated. Both the write stamp and the retention cutoff must come from the same clock (`SELECT NOW()`), never from `current_time()`. Restore the original timezone afterwards. |
| D.level-changed-listener-int-sig | After fixing `D.level-changed-double-fire` (engine side), the `TransactionalEmailEngine::on_level_up` and `NotificationBridge::on_level_changed` listeners still expected `int $old_level_id, int $new_level_id` — the legacy signature. Every level-up event triggered `TypeError: Argument #2 must be of type int, array given` in those listeners | Both listeners now accept `?array $new_level, ?array $old_level` matching the engine's canonical fire. Award enough points to cross a level threshold; expect zero PHP fatal in `wp-content/debug.log` from any of the three downstream listeners (WebhookDispatcher, TransactionalEmailEngine, NotificationBridge) |
| D.kudos-cooldown-bypass | Two parallel POST `/kudos` requests with the same `(giver, receiver)` pair both passed the `has_recent_kudos_to_receiver` read-check before either had written its row, then both INSERTed — bypassing the per-receiver cooldown gate via TOCTOU race | KudosEngine acquires an atomic `wp_cache_add()` lock keyed `kudos_lock_<giver>_<receiver>` for the cooldown window before the INSERT. Object cache `add` is atomic on Redis/Memcached; second concurrent caller gets `false` from `wp_cache_add` and returns the cooldown error. Test: send two parallel POSTs to `/kudos` (same giver+receiver); exactly one returns 201, the other 429 with code `wb_gam_kudos_cooldown` |
| C.member.redemption-error-code | All redemption failures returned the generic `redemption_failed` error code — runbook contract specifies `wb_gam_redemption_insufficient` for insufficient-balance, `wb_gam_redemption_out_of_stock` for stock-zero, etc. Functional behavior was correct; the API contract was not | `RedemptionController::redeem()` now maps the engine's `result['reason']` (or matched substring of `result['error']`) to specific codes: `wb_gam_redemption_insufficient`, `wb_gam_redemption_out_of_stock`, `wb_gam_redemption_inactive`. Unknown reasons keep `redemption_failed` so future engine errors don't surprise clients |
| D.public-profile-default-on (v1.5.2) | Public profiles at `/u/{username}` 404'd for everyone - the visibility gate required a per-user flag that nothing ever wrote, so every member profile returned not-found | `ProfilePage::is_publicly_visible()` defaults ON: only an explicit `'0'` privacy pref makes a profile private. Test: `/u/<existing_user_login>` returns 200 (not 404) anonymously; setting the user's pref to `'0'` makes it 404 for anonymous while the owner still gets 200; unset/empty pref resolves to visible. Filter `wb_gam_profile_publicly_visible` can override per user |
| D.jetonomy-leaderboard-defer (v1.5.2) | On a Jetonomy site wb-gam's leaderboard is a duplicate ranking (wb-gam mirrors Jetonomy reputation 1:1 into points), so two competing leaderboards showed the same members in the same order | With `JETONOMY_VERSION` defined, `DisplayDefer` suppresses the `wb-gamification/leaderboard` + `wb-gamification/top-members` blocks and the `wb_gam_leaderboard` + `wb_gam_top_members` shortcodes (both render blank), AND the Hub block drops its `leaderboard` card (`render.php` `unset($wb_gam_cards['leaderboard'])`). Badges are NOT deferred - the badge-showcase block and badge surfaces still render. Override via `wb_gam_defer_leaderboard_to_jetonomy`. Test: with Jetonomy active, a page with the leaderboard block renders blank where the leaderboard was, the Hub has no Leaderboard card, but badge blocks/surfaces still render |
| D.toast-no-header-overlap (v1.5.2) | Toast notifications overlapped the theme's sticky header / top nav, hiding the points feedback | Admin-set placement via Settings > Realtime (option `wb_gam_toast_position`, default `bottom-right`, corner-aware slide-in, filter same name). Test: set placement to a top-* corner; trigger a points award; the toast renders in that corner, names what the points were for, and does NOT overlap the theme's top nav / sticky header |
| D.heartbeat-default-not-SSE (v1.5.2) | The SSE long-poll was the default realtime transport - each connection pinned a PHP-FPM worker for its lifetime, which does not scale on a standard pool (100k-user sites exhausted workers) | Default transport is WP Heartbeat (`assets/js/heartbeat.js`): 15s steady, 5s burst for ~30s after an action, 120s near-suspend on hidden tabs. SSE is opt-in only - `SSEController` permission resolves to false unless `wb_gam_sse_allowed` returns true (default false). Test: on a logged-in front-end page the network trace shows Heartbeat admin-ajax ticks and NO persistent `text/event-stream` connection; SSE stream opens only after the filter returns true |

Every customer-visible fix ships a matching D row in the same PR. After 2 clean releases of a D row, promote it into the main C/E flow.

---

## E — Integrations and graceful degradation

WB Gamification ships eight integrations. Each must satisfy two contracts: **(1)** the integration adds value when its host plugin is active, and **(2)** the integration is silent when its host plugin is absent.

### E.buddypress.activity-and-profile
**What to verify:** with BuddyPress active, posting an activity update (or receiving a comment on it) fires the configured `bp_activity_*` action; the user's profile shows the gamification tab with badges + points.
**Reference:** `audit/journeys/release/06-integration-graceful-degradation.md`
**Acceptance:** activity → event → points round-trip; profile tab renders.

### E.buddypress.directory
**What to verify:** the BP members directory ranks members by points when the gamification ranking is enabled; falls back to default ordering when disabled.
**Acceptance:** members directory list order matches leaderboard order.

### E.buddypress.notifications
**What to verify:** badge unlocks, kudos receives, level-ups appear in the BP notifications dropdown with correct deeplinks.
**Acceptance:** trigger each event class for `qa_member`; expected rows land in `bp_notifications`.

### E.woocommerce.purchase-event
**What to verify:** with WooCommerce active, a completed order fires the configured purchase action; refunding the order debits the awarded points (`RefundHandler`).
**Acceptance:** create + complete an order; ledger increments by configured points; refund the order; ledger decrements by the same amount.

### E.learndash.course-and-quiz
**What to verify:** completing a LearnDash lesson / quiz fires the configured action and grants points; grades respect any cap configured.
**Acceptance:** complete a sandbox lesson as `qa_member`; ledger reflects the grant; daily cap (if set) blocks further awards once hit.

### E.bbpress.topics-and-replies
**What to verify:** creating a topic / reply on a bbPress forum fires the configured action; the events show in the user's points history.
**Acceptance:** create topic + reply; both events land in `wb_gam_events` with correct `action_id`.

### E.elementor.widget
**What to verify:** the Elementor widget renders any of the 17 blocks inside an Elementor page; the widget settings round-trip on save.
**Acceptance:** drop the widget on a sandbox page; render verifies; save+reload preserves config.

### E.acf.field-context
**What to verify:** if ACF is active, the gamification rule editor offers ACF field options; absent ACF, the rule editor renders the same surface without field options and without errors.
**Acceptance:** with ACF active, the rule editor's "field" select lists ACF keys; without ACF, the select renders a "no fields detected" message.

### E.jetonomy.leaderboard-defer-and-mirror
**What to verify:** with Jetonomy active (`JETONOMY_VERSION` defined), wb-gam mirrors every Jetonomy reputation delta 1:1 into its points ledger (`JetonomyIntegration` on `jetonomy_reputation_changed`), AND defers its own leaderboard display to Jetonomy's reputation ranking - `DisplayDefer` blanks the `leaderboard` + `top-members` blocks/shortcodes and drops the Hub Leaderboard card. Badges are deliberately KEPT (the two badge sets are complementary). Jetonomy Pro's `jetonomy_pro_badge_earned` action awards wb-gam points via the manifest in `integrations/jetonomy-pro.php`.
**Why it matters:** showing two identical leaderboards confuses members; the deferral keeps a single source of truth while preserving wb-gam's broader badge system.
**Acceptance:** with Jetonomy active, a page with the `wb-gamification/leaderboard` block renders blank, the `[wb_gam_leaderboard]` shortcode renders blank, and the Hub block shows no Leaderboard card; badge blocks (`badge-showcase`) and badge surfaces still render; a Jetonomy reputation delta produces a matching wb-gam points ledger row. Returning false from `wb_gam_defer_leaderboard_to_jetonomy` re-enables the wb-gam leaderboard even when Jetonomy is active. With Jetonomy absent, the leaderboard renders normally and no fatal fires.

### E.graceful-degradation
**Customer contract:** deactivating ANY of the integrations leaves the plugin functional with no fatals on any admin or front-end page.
**Journey:** `audit/journeys/release/06-integration-graceful-degradation.md`
**Acceptance:** journey passes for each of the eight host plugins independently.

---

## F — Cross-browser, RTL, accessibility, mobile

### F.chromium
Already covered by Sections A–E. Chromium is the default Playwright engine.

### F.firefox-desktop and F.safari-ios
Playwright MCP is Chromium-only. These cannot be walked by the agent. Populate `manual_required[]` with the critical flows a human must spot-check:
- Composer time picker on community-challenges block (Firefox)
- Leaderboard period switcher dropdown on Safari iOS at 390px
- Hub tabs horizontal scroll on Safari iOS
- Toast notification stacking on Safari iOS (no overflow off-screen)
- Any flow that relies on a browser-native control whose behavior diverges between engines.

### F.rtl
**What to verify:** on an RTL locale (e.g. `ar`), the 17 blocks render right-to-left without horizontal overflow, text flows correctly, icons mirror where appropriate (arrow glyphs) and stay fixed where they should not (badge images, brand logos).
**Acceptance:** switch site locale to `ar`; visit a page with the leaderboard + earning-guide + badge-showcase blocks; no horizontal scroll on body, all text right-aligned, rank numerals stay LTR (numerals are bidi-neutral).

### F.a11y
**What to verify:** the 17 blocks + 13 admin pages + setup wizard all pass: visible keyboard focus ring (not suppressed by theme), logical tab order, icon-only buttons have `aria-label`, modals trap focus + close on ESC.
**Journey:** `audit/journeys/release/07-a11y-and-mobile.md`
**Acceptance:** journey passes; AND a manual axe-core scan on `/wb-gam-hub` reports 0 critical issues.

### F.mobile-390px
**What to verify:** every block + every admin page renders cleanly at 390px viewport — no horizontal scroll, no clipped buttons, no off-screen tabs, no overlapping toasts.
**Reference:** `audit/journeys/release/07-a11y-and-mobile.md`
**Acceptance:** spot-check 6 blocks (leaderboard, hub, member-points, kudos-feed, challenges, redemption-store) and 4 admin pages (Dashboard, Award Points, Badges, Webhooks) at 390px; record any clipping in `failures[]` with `origin: from`.

### F.theme-matrix
**What to verify:** every block renders correctly under the four supported theme contexts: Twenty-Twenty-Five (block theme), Reign (classic), Astra (classic + customizer overrides), BuddyX-Pro (block + BP-aware).
**Journey:** `audit/journeys/release/08-theme-matrix.md`
**Acceptance:** journey passes for each of the four themes.

---

## G — Post-release monitoring (first 24h after tag)

Runs on the production host, not this runbook. Watch for:

- New debug.log entries on customer sites (via the support inbox / Sentry feed if configured)
- Action Scheduler queue depth — sustained growth indicates async backlog
- Cron orphans — any `wb_gam_*` schedule that exists in `cron_options` without a matching listener
- "Broke after update" support tickets in the support queue
- Activity-signal drops — daily new-event count falls below 70% of the trailing-7-day baseline

Any red signal opens a `<version>.1` patch cycle.

---

## Failure protocol

1. On ANY failure, `browser_take_screenshot({ filename: "fail-<id>.png", fullPage: false })` and store the file under `audit/journey-runs/<run-id>/screenshots/`.
2. **Triage origin: `from` vs `for` our plugin.**
   - `from` = our code is at fault (our REST, our JS, our SQL, our CSS, our template). Always ours to fix. Blocks the release.
   - `for` = failure surfaces while our plugin runs but root cause is elsewhere (theme override, host plugin behavior change, browser limitation, legacy imported data, hosting quirk). Warrants a judgement call. Does not block by default; calling Opus session decides.
3. Record in `failures[]` with `{ id, origin, triage_note, expected, actual, url, screenshot, journey }`.
4. **Never halt.** Collect all failures in one pass.
5. Emit a Basecamp draft per failure:

```
### Bug: <id>
**Origin:** from | for our plugin
**Environment:** WB Gamification <version>, Chromium, <viewport>px, <theme>
**Expected:** <contract from the runbook>
**Actual:** <measured behavior>
**URL:** <tested URL>
**Screenshot:** <filename>
**Journey:** <slug or none>
**Steps to reproduce:** <minimal repro>
**Triage note:** <one line on the from/for call>
```

Triage is Sonnet's job; the fix/no-fix decision is the calling Opus session's job. Cards land in the **Bugs** column of project `47162271` only after Opus re-verifies.

## Step ID format

`<Section>.<persona>.<feature>` e.g. `C.member.earn-points`, `C.admin.manual-award`. D rows use `D.<descriptor>`. E rows use `E.<integration>.<scope>`.

## Maintenance rule

Every customer-visible bug fix ships with:
1. A matching **D** row in this runbook (fixture + assertion).
2. If the flow was not already journey-covered, a new file under `audit/journeys/<category>/` (the journey IS the regression test going forward).
3. If the flow needs an explicit contract (not just a regression fixture), a **C** or **E** entry.
4. All four land in the same PR as the fix.

After 2 clean releases of a D row, promote it into the main C/E flow and mark the row `graduated`.

## Section drift check

Every six months, compare this runbook's section list to Jetonomy's `wp-content/plugins/jetonomy/docs/qa/AGENT_SMOKE_RUNBOOK.md`. Each plugin's runbook shape should look the same so a QA person can move between portfolio plugins without re-learning the structure. Justify or close any drift.
