# WB Gamification — QA Manual Test Plan (v1.1)

> **For the QA team.** Walk through this document linearly on a fresh-install Local site OR a v1.0 → v1.1 upgrade site. Every step is human-walkable: click here, see that, change this, save, verify the next state.

**Plugin under test**: WB Gamification v1.1 (current `main`)
**Last updated**: 2026-05-03
**Estimated walkthrough time**: 4-6 hours for a single tester. ~2 hours per parallel tester if split across personas.

## How to use this document

1. Read **Setup** below; spin up the test site exactly as described.
2. Walk through each **persona** in order. Some later personas depend on data created in earlier ones (a Member can't earn points if no actions are configured).
3. For each step, tick the checkbox in `[ ]` if it passes. If it fails, file a bug using the template at the bottom of this document AND keep going — don't stop on the first issue.
4. At the end, fill out the **Verdict** section.

## Setup (do this once before starting)

### A) Test environment

- Fresh Local-by-Flywheel (or equivalent) site running WordPress 6.4+, PHP 8.1+.
- Activate the Twenty Twenty-Five theme (or any modern block theme).
- Plugins: just **WB Gamification** v1.1 from this PR. Install BuddyPress optionally to test BP integrations.

### B) Fixture users

Create these via Admin → Users → Add New:

| Login | Role | Email | Notes |
|---|---|---|---|
| `qa_admin` | Administrator | `qa+admin@example.test` | Walks through Personas 1, 2, 6 |
| `qa_editor` | Editor | `qa+editor@example.test` | Walks through Persona 3 (granular cap test) |
| `qa_member` | Subscriber | `qa+member@example.test` | Walks through Persona 4 (Member journey) |
| `qa_external` | (none — uses API key) | — | Walks through Persona 5 (External Developer) |

### C) Auto-login mu-plugin (optional, for faster persona switching)

Drop this in `wp-content/mu-plugins/dev-auto-login.php`:

```php
<?php
add_action( 'init', function () {
    if ( ! isset( $_GET['autologin'] ) || is_user_logged_in() ) return;
    $user = get_user_by( 'login', sanitize_text_field( $_GET['autologin'] ) );
    if ( $user ) {
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );
        wp_safe_redirect( remove_query_arg( 'autologin' ) );
        exit;
    }
}, 1 );
```

Then `?autologin=qa_admin` etc. switches user without typing passwords.

---

## Persona 1 — First-time admin (greenfield install)

**Goal**: A site owner installs WB Gamification for the first time. They run the setup wizard, pick a starter template, configure point values, create a badge, view analytics. ~30 min.

### 1.1 Setup wizard

- [ ] Activate the plugin from Plugins → Installed Plugins. Expected: redirect to **Gamification Setup** wizard.
- [ ] Wizard heading reads "Welcome to WB Gamification" with 5 starter template cards (Blog/Publisher, Community Engagement, Online Course, Coaching Platform, Nonprofit/Mission).
- [ ] Click **"Use this template"** on Community Engagement. Expected: redirect away from wizard, banner says template applied.
- [ ] DB check: `wp option get wb_gam_wizard_complete` → `true` or `1`. `wp option get wb_gam_template` → `community`.

### 1.2 Top-level menu structure

After setup, navigate to **Gamification** in the sidebar.

- [ ] Top-level menu icon is the trophy (`dashicons-awards`), position ~56.
- [ ] Submenu items in this order: Gamification, Analytics, Badges, Challenges, Award Points, API Keys, Redemption Store, Community Challenges, Cohort Leagues, **Webhooks**.
- [ ] Click each submenu item — every page loads without a fatal error or PHP notice.

### 1.3 Configure point values (Settings → Points)

- [ ] Sidebar nav shows tabs: Dashboard, Points, Levels (Core); Challenges, Badges, Kudos (Engagement); Rules (Automation); API Keys, Integrations (Advanced).
- [ ] Points tab shows table with header: On / Action / Points / Repeat / Daily cap. WordPress section shows 8 rows (Join site, First login, Complete profile, Post receives a comment, Publish blog post, Publish first post, Leave a comment, Comment approved from moderation).
- [ ] Each row has a checkbox (enabled), a number input (points). Each input has a screen-reader-friendly aria-label (right-click → Inspect, confirm `aria-label="Enable Join the site"` etc.).
- [ ] Change "Publish a blog post" points from 25 to 50, click Save Changes, reload. Value persists at 50.
- [ ] DB check: `wp option get wb_gam_points_wp_publish_post` → `50`.

### 1.4 Create a custom badge

- [ ] Navigate to **Gamification → Badges**. Page heading "Badge Library".
- [ ] Click **"+ Create New Badge"**. Form modal/page opens with fields: Badge ID, Name, Description, Icon, Category, Is Credential, Award Type, Conditions.
- [ ] Fill: ID `qa_test_badge`, Name `QA Test Badge`, Description `Created during QA walkthrough`, Category `general`, Award Type `auto`, Condition: Points threshold ≥ 10. Save.
- [ ] Badge appears in the grid view. "0 earned · AUTO-AWARD" pill visible.
- [ ] DB check: `wp_wb_gam_badge_defs` table has the row.

### 1.5 Run the analytics dashboard

- [ ] Navigate to **Gamification → Analytics**. Switch the period selector between 7 days / 30 days / 90 days.
- [ ] 6 KPI cards present: Points Awarded · Active Members · Badges Earned · Challenges Completed · Active Streaks · Kudos Given.
- [ ] At this point KPIs read 0 / 0 / 0 / 0 / 0 / 0 (no member activity yet).
- [ ] No console errors when switching periods.

---

## Persona 2 — Returning admin (operational tasks)

**Goal**: An admin grants points manually, manages challenges, configures the redemption store, sets up an outbound webhook, manages API keys. ~45 min.

### 2.1 Manual point award (Admin → Award Points)

- [ ] Navigate to **Gamification → Award Points**. Form has User combobox, Points spinbutton, Reason / Note textbox, "Award Points" submit button.
- [ ] Select `qa_member` from the User picker. Enter Points `25`. Reason: `QA test award`. Submit.
- [ ] Page reloads with success notice. Recent Manual Awards table shows one row: `qa_member` · 25 pts · QA test award · today's date.
- [ ] DB check: `wp_wb_gam_events` has one new row with `action_id='manual_award'`, `user_id=` qa_member's ID. `wp_wb_gam_points` has one row with `points=25`.
- [ ] CLI check: `wp wb-gamification points list --user=qa_member` shows the 25-pt award.

### 2.2 Create individual challenge (Admin → Challenges)

- [ ] Navigate to **Gamification → Challenges**. Click **"+ New Challenge"**.
- [ ] Form fields: Title, Action, Target Count, Bonus Points, Start Date, End Date, Active toggle.
- [ ] Fill: Title `QA Comment Streak`, Action `wp_post_receives_comment`, Target Count `3`, Bonus Points `15`, Start now, End in 7 days. Save.
- [ ] Challenge appears in the active-challenges list with progress bar at 0/3.

### 2.3 Create community challenge (Admin → Community Challenges)

- [ ] Navigate to **Gamification → Community Challenges**. Click **"+ New Community Challenge"**.
- [ ] Fill: Title `QA Group Goal`, Description `Test challenge`, Target Action `wp_publish_post`, Target Count `5`, Bonus Points `100`, Active. Save.
- [ ] Challenge appears in the list with `0/5` progress and "active" status.

### 2.4 Configure cohort leagues (Admin → Cohort Leagues)

- [ ] Navigate to **Gamification → Cohort Leagues**. Form has Cohort Enabled toggle, 4 tier name fields (Bronze/Silver/Gold/Platinum by default), Promote % and Demote % spinboxes, Duration dropdown.
- [ ] Toggle Cohort Enabled ON. Set Tier 1 = Bronze, Tier 2 = Silver, Promote% = 30, Demote% = 30, Duration = weekly. Save.
- [ ] Page reloads with success notice.

### 2.5 Add a redemption-store reward (Admin → Redemption Store)

- [ ] Navigate to **Gamification → Redemption Store**. Click **"+ Add Reward"**.
- [ ] Fill: Name `QA Test Reward`, Description `Test`, Point Cost `100`, Stock `5`, Reward Type `Custom Reward`, Status `Active`. Save.
- [ ] Reward appears in the catalog list.

### 2.6 Add an outbound webhook (Admin → Webhooks) ★ NEW IN v1.1

- [ ] Navigate to **Gamification → Webhooks**. Page heading "Webhooks".
- [ ] Form: Endpoint URL, Secret, Events checkboxes (9 events listed: points_awarded, points_revoked, badge_awarded, level_changed, streak_milestone, streak_broken, kudos_given, challenge_completed, community_challenge_completed).
- [ ] Fill: URL `https://example.com/qa-webhook`, leave Secret blank (auto-generates), check `points_awarded` and `badge_awarded`. Submit.
- [ ] Webhook appears in the Configured Webhooks table with a generated secret.
- [ ] DB check: `wp_wb_gam_webhooks` table has the row with non-empty `secret` column.
- [ ] Click **Delete** on the row. Confirm dialog appears. Confirm deletion. Row removed from list.

### 2.7 Generate an API key (Admin → API Keys)

- [ ] Navigate to **Gamification → API Keys**. Click **"+ Generate Key"**.
- [ ] Fill: Label `QA External Service`, Site ID `qa-test`. Generate.
- [ ] Key shown ONCE in plaintext (copy this — needed for Persona 5). Listing shows the key with a partially-masked display.

---

## Persona 3 — Editor with granular cap (delegation test) ★ NEW IN v1.1

**Goal**: Verify the granular permission system. An Editor with `wb_gam_manage_badges` should be able to manage badges via REST/admin without being an admin.

### 3.1 Grant the cap

Run as `qa_admin` via WP-CLI (or use a role-manager plugin):

```bash
wp role list --field=name              # confirm 'editor' role exists
wp cap add editor wb_gam_manage_badges
```

- [ ] Confirm: `wp cap list editor | grep wb_gam_manage_badges` returns the cap.

### 3.2 Verify the editor can hit `BadgesController` via REST

Login as `qa_editor` (`?autologin=qa_editor`).

- [ ] Open browser dev tools → Network tab. Visit any page on the site.
- [ ] In dev tools console, run:
   ```js
   fetch('/wp-json/wb-gamification/v1/badges/qa_test_badge/award', {
     method: 'POST',
     credentials: 'include',
     headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
     body: JSON.stringify({ user_id: 1 })
   }).then(r => r.json()).then(console.log);
   ```
- [ ] Response: `{ awarded: true, ... }` (or "already_earned" if the user has it). Status 200, NOT 403.

### 3.3 Verify the editor CAN'T touch other surfaces

- [ ] In dev tools console, fetch `POST /wb-gamification/v1/points/award` with valid body. Expected: 403 `rest_forbidden` `"You do not have permission to manage points."`
- [ ] Same for `POST /wb-gamification/v1/webhooks` with valid body. Expected: 403 `rest_forbidden` `"You do not have permission to manage webhooks."`

### 3.4 Verify subscriber is fully gated

Login as `qa_member` (a Subscriber).

- [ ] Visit `/wp-admin/admin.php?page=wb-gamification`. Expected: WordPress "you don't have permission" page.
- [ ] Try `POST /wb-gamification/v1/points/award` from console. Expected: 403.

---

## Persona 4 — Member (frontend journey)

**Goal**: A member earns points, sees their hub, views the leaderboard, joins a challenge, gives kudos, sees their cohort. ~45 min.

Login as `qa_member` (`?autologin=qa_member`).

### 4.1 The Member Hub

- [ ] Navigate to the auto-installed hub page (URL: home → "Gamification" menu item, or `?page_id=` of the page identified in `wp option get wb_gam_hub_page_id`).
- [ ] See: 4 stat cards (Total Points · Current Level · Badges Earned · Day Streak) + 6 widget cards (My Badges · Challenges · Leaderboard · How to Earn · Kudos · Activity).
- [ ] Total Points shows 25 (from Persona 2.1 manual award).
- [ ] Current Level shows "Newcomer" (0–99 range from default seeds).
- [ ] Day Streak shows 1 (today is the user's first activity day).
- [ ] Click the Leaderboard widget — modal/drawer or new view appears showing the leaderboard with `qa_member` ranked.

### 4.2 Earn points by leaving a comment

- [ ] Navigate to any blog post that allows comments. Leave a comment. Submit. Wait for moderation/approval if needed (admin: approve from `/wp-admin/edit-comments.php`).
- [ ] Wait ~10 seconds (Action Scheduler async).
- [ ] Navigate back to the Hub. Total Points increased to 30 (5 pts for `wp_leave_comment` per default seed; +5 if admin pre-approved via comment_approved action).
- [ ] Run: `wp wb-gamification member status --user=qa_member`. Confirm point increase.

### 4.3 View the leaderboard block in standalone

If the Local site has the "Gamification Test" page (auto-installed if Hub installer ran), navigate there.

- [ ] Page renders Member Points block (showing 30 pts), Level Progress block (with bar to next level), Points History block (most recent 5–10 events), Leaderboard block (qa_member ranked), Streak block (1-day streak).
- [ ] No console errors. Mobile (390px viewport): all blocks responsive — no horizontal scroll, content readable.

### 4.4 Community challenge block ★ NEW IN v1.1

- [ ] Insert the **Community Challenges** block on a test page (Admin → Pages → Edit → block inserter → search "community challenges").
- [ ] Save and view as `qa_member`. Block renders with the QA Group Goal challenge from Persona 2.3, showing 0/5 progress bar + bonus points + time remaining.
- [ ] Empty state: temporarily deactivate the challenge in admin, reload page. Block shows "No active community challenges right now. Check back soon!"

### 4.5 Cohort rank block ★ NEW IN v1.1

- [ ] Insert the **Cohort Rank** block on a test page.
- [ ] First-time view: block shows "You have not been assigned to a cohort yet" (cohorts are formed by weekly cron).
- [ ] Manually trigger cohort assignment: `wp cron event run wb_gam_cohort_assign`.
- [ ] Reload the page. Block now shows the user's tier (Bronze/Silver/etc.) and rank within cohort.
- [ ] Self row (qa_member) is highlighted with `wb-gam-cohort-rank__item--self` class (right-click → Inspect to confirm).

### 4.6 Give kudos

- [ ] On the BuddyPress profile of another user (or via the kudos REST endpoint), give a kudos with message `Great work!`.
- [ ] As `qa_member`, navigate to the kudos feed (block inserted on test page). Latest kudos visible. Daily budget counter decremented.

### 4.7 Year recap

- [ ] Insert the **Year Recap** block on a page. View as `qa_member`.
- [ ] If user has activity: shows year-in-review with points totals, top actions, badges earned. If insufficient activity: shows "Your year in review will appear here once you start earning points."

---

## Persona 5 — External developer (REST / CLI / webhook consumer)

**Goal**: An external service authenticates via API key, fires events, reads state. ~30 min.

### 5.1 OpenAPI discovery

- [ ] `curl https://your-site.test/wp-json/wb-gamification/v1/openapi.json | jq '.info'`
- [ ] Returns title `WB Gamification API`, version `1.0` or higher, `paths` count of 39.
- [ ] Spec validates: `npx @apidevtools/swagger-cli validate <path-to-saved-spec.json>` (optional power-user step).

### 5.2 Public catalog endpoints (no auth required)

For each, expect 200 + JSON body:

- [ ] `GET /wb-gamification/v1/actions` — array of registered actions (≥ 8 entries from default seeds).
- [ ] `GET /wb-gamification/v1/badges` — at least the QA Test Badge from Persona 1.4.
- [ ] `GET /wb-gamification/v1/levels` — 5 default levels (Newcomer, Member, Contributor, Regular, Champion).
- [ ] `GET /wb-gamification/v1/challenges` — the QA Comment Streak from Persona 2.2.
- [ ] `GET /wb-gamification/v1/leaderboard?period=all_time&limit=10` — `rows` array with `qa_member` ranked.
- [ ] `GET /wb-gamification/v1/openapi.json` — full OpenAPI spec.
- [ ] `GET /wb-gamification/v1/capabilities` — discovery payload.
- [ ] `GET /wb-gamification/v1/abilities` — WP Abilities API surface.
- [ ] `GET /wb-gamification/v1/redemptions/items` — QA Test Reward from Persona 2.5.
- [ ] `GET /wb-gamification/v1/kudos` — public kudos feed.

### 5.3 Auth-required endpoints (anonymous → 401, authenticated → 200)

Anonymous (no cookie, no key):

- [ ] `GET /wb-gamification/v1/leaderboard/me` → 401 `rest_not_logged_in`.
- [ ] `POST /wb-gamification/v1/redemptions` body `{"item_id":1}` → 401.
- [ ] `POST /wb-gamification/v1/challenges/1/complete` → 401.

Authenticated (with `qa_member` cookie):

- [ ] Same routes return 200.

### 5.4 Admin-required endpoints (anonymous → 403)

Send fully-valid bodies (so we test cap-gate, not param-gate):

- [ ] `POST /wb-gamification/v1/points/award` body `{"user_id":1,"points":1,"reason":"test"}` (anonymous) → 403 `"You do not have permission to manage points."`
- [ ] `POST /wb-gamification/v1/badges/qa_test_badge/award` body `{"user_id":1}` (anonymous) → 403 `"You do not have permission to award badges."`
- [ ] `POST /wb-gamification/v1/webhooks` body `{"url":"https://example.com","events":["points_awarded"]}` (anonymous) → 403 `"You do not have permission to manage webhooks."`

### 5.5 API key auth (X-WB-Gam-Key header)

Use the key generated in Persona 2.7. Replace `KEY` below.

- [ ] `curl -H "X-WB-Gam-Key: KEY" https://your-site.test/wp-json/wb-gamification/v1/members/{qa_member_id}/points` → 200 with member's points + history.
- [ ] `curl -H "X-WB-Gam-Key: KEY" -H "Content-Type: application/json" -X POST -d '{"action_id":"wp_post_receives_comment","user_id":{qa_member_id}}' https://your-site.test/wp-json/wb-gamification/v1/events` → 200 with new event_id.

### 5.6 WP-CLI commands

Run each as a shell user with file access to the install:

- [ ] `wp wb-gamification points list --user=qa_member` — shows recent point events.
- [ ] `wp wb-gamification points award --user=qa_member --points=10 --message="cli test"` — awards points.
- [ ] `wp wb-gamification member status --user=qa_member` — shows total points + level.
- [ ] `wp wb-gamification actions list` — lists all registered actions.
- [ ] `wp wb-gamification logs prune --before=6months --dry-run` — dry-run report.
- [ ] `wp wb-gamification export user --user=qa_member` — JSON export of the user's gamification data.
- [ ] `wp wb-gamification doctor` — health report (cron status, schema status, queue depth).
- [ ] `wp wb-gamification replay user --user=qa_member --dry-run` ★ NEW — re-evaluates badge rules; reports what WOULD be awarded.
- [ ] `wp wb-gamification replay all --limit=5 --dry-run` ★ NEW — same for first 5 users.

### 5.7 Webhook delivery (full round-trip)

- [ ] Set up a webhook receiver locally: `python3 -m http.server 8765` and use `webhook.site` for proper inspection.
- [ ] Add a webhook in admin (Persona 2.6) pointing at the receiver URL with `points_awarded` subscription.
- [ ] Award points via admin (Persona 2.1) or REST (5.5).
- [ ] Within ~30 sec (Action Scheduler), the receiver should get a POST with: header `X-WB-Gam-Signature: sha256=...`, body JSON with `event=points_awarded`, `user_id`, `data`, `delivery_id`.

---

## Persona 6 — Site owner doing v1.0 → v1.1 upgrade ★ NEW

**Goal**: Verify upgrade path is clean. Cosmetics tables get dropped. Granular caps preserved. No data loss for points/badges/etc.

### 6.1 Pre-upgrade (start here on a v1.0 install with data)

If you have a v1.0 site with data, capture the state BEFORE installing v1.1:

```bash
wp option get wb_gam_db_version            # expect 1.0.0 or earlier
wp db query "SHOW TABLES LIKE 'wp_wb_gam_%'" # 20 tables
wp wb-gamification member status --user=1  # current totals
```

### 6.2 Upgrade

- [ ] Replace `wp-content/plugins/wb-gamification/` with v1.1 release zip OR pull the new code.
- [ ] Visit `wp-admin/`. The DbUpgrader fires automatically on `admin_init`.
- [ ] `wp option get wb_gam_db_version` now returns `1.1.0`.

### 6.3 Verify cosmetic tables dropped

- [ ] `wp db query "SHOW TABLES LIKE 'wp_wb_gam_cosmetics'"` → empty result.
- [ ] `wp db query "SHOW TABLES LIKE 'wp_wb_gam_user_cosmetics'"` → empty result.

### 6.4 Verify granular caps registered for administrator

- [ ] `wp cap list administrator | grep wb_gam_` should show: `wb_gam_award_manual`, `wb_gam_manage_badges`, `wb_gam_manage_challenges`, `wb_gam_manage_rewards`, `wb_gam_manage_webhooks`, `wb_gam_view_analytics`.

### 6.5 Verify no data loss

Compare to the snapshot from 6.1:

- [ ] Member totals match (or have grown — never shrunk).
- [ ] Badge counts unchanged.
- [ ] Active challenges unchanged.
- [ ] Webhooks (if any) unchanged.
- [ ] Settings page values (point overrides, kudos limits, etc.) unchanged.

---

## Coverage matrix — every surface has a test step

This table proves no surface goes untested. Each row maps a surface to the persona that exercises it.

| Surface | Persona | Step |
|---|---|---|
| **Top-level menu** | 1 | 1.2 |
| **Setup Wizard** | 1 | 1.1 |
| **Settings page (Points tab)** | 1 | 1.3 |
| **Settings page (Levels tab)** | 1 | 1.3 (verify levels seed) |
| **Settings page (a11y aria-labels)** | 1 | 1.3 |
| **Badges admin page** | 1 | 1.4 |
| **Analytics dashboard** | 1, 2 | 1.5 + recheck after 4.2 |
| **Manual Award page** | 2 | 2.1 |
| **Challenge Manager page** | 2 | 2.2 |
| **Community Challenges admin** | 2 | 2.3 |
| **Cohort Settings page** | 2 | 2.4 |
| **Redemption Store admin** | 2 | 2.5 |
| **Webhooks admin** ★ | 2 | 2.6 |
| **API Keys page** | 2 | 2.7 |
| **Granular cap delegation** ★ | 3 | 3.1–3.3 |
| **Cap fully gated for subscribers** | 3 | 3.4 |
| **Member Hub block** | 4 | 4.1 |
| **Async event pipeline** | 4 | 4.2 |
| **Member Points block** | 4 | 4.3 |
| **Level Progress block** | 4 | 4.3 |
| **Points History block** | 4 | 4.3 |
| **Leaderboard block** | 4 | 4.3 |
| **Streak block** | 4 | 4.3 |
| **Community Challenges block** ★ | 4 | 4.4 |
| **Cohort Rank block** ★ | 4 | 4.5 |
| **Kudos Feed block** | 4 | 4.6 |
| **Year Recap block** | 4 | 4.7 |
| **Earning Guide block** | 4 (via Hub drawer) | 4.1 |
| **Top Members block** | 4 (via Hub) | 4.1 |
| **Badge Showcase block** | 4 (via Hub) | 4.1 |
| **OpenAPI spec** | 5 | 5.1 |
| **All 10 public REST routes** | 5 | 5.2 |
| **Auth-required REST** | 5 | 5.3 |
| **Admin-required REST** | 5 | 5.4 |
| **API Key auth** | 5 | 5.5 |
| **6 existing WP-CLI commands** | 5 | 5.6 |
| **`replay` CLI** ★ | 5 | 5.6 |
| **Outbound webhook delivery** | 5 | 5.7 |
| **DbUpgrader 1.0 → 1.1** ★ | 6 | 6.2 |
| **Cosmetic table drop migration** ★ | 6 | 6.3 |
| **Capability registration on activation** ★ | 6 | 6.4 |
| **No data loss on upgrade** | 6 | 6.5 |

★ = NEW or CHANGED in v1.1

## Bug-report template

When something fails a step above, file a bug using THIS template (paste into Basecamp / Linear / GitHub Issues / your tracker):

```
Title: [v1.1-QA] <short description>

## Step that failed
Persona X.Y — copy the step text from QA-MANUAL-TEST-PLAN.md

## Environment
- Site URL:
- WordPress version:
- PHP version:
- Active theme:
- Other active plugins:
- Browser + version:
- Tester login: (qa_admin / qa_editor / qa_member / qa_external)

## What you did (reproducible steps)
1.
2.
3.

## What you expected
(quote the "Expected:" text from the step)

## What actually happened
(literal output / screenshot / browser console log if applicable)

## Severity
- [ ] Critical (blocks release — data loss, fatal, security)
- [ ] High (feature visibly broken, no workaround)
- [ ] Medium (feature visibly broken, workaround exists)
- [ ] Low (cosmetic, accessibility, copy)

## Attachments
- Screenshot:
- Network tab export (HAR / individual request) if REST issue:
- error_log excerpt if PHP issue:
```

## Verdict — fill out at the end

After walking through all 6 personas:

- [ ] **PASS — ship-ready**: every step ticked or only Low-severity bugs remain.
- [ ] **CONDITIONAL PASS**: some Medium-severity bugs exist; they're acceptable for the release IF they're documented in the changelog as known issues.
- [ ] **FAIL — do not ship**: any Critical or unresolved High-severity bug.

Sign-off:

- QA Lead: _____________ · Date: _____________
- Engineering Lead: _____________ · Date: _____________

## Related documents

- [`audit/manifest.json`](../audit/manifest.json) — canonical inventory the steps above are derived from.
- [`audit/journeys/`](../audit/journeys/) — agent-executable versions of 4 of the critical journeys (for automation; the manual walkthrough above is the authoritative human test).
- [`audit/ROLE_MATRIX.md`](../audit/ROLE_MATRIX.md) — RBAC reference; useful for Persona 3.
- [`audit/FEATURE-COMPLETENESS-2026-05-02.md`](../audit/FEATURE-COMPLETENESS-2026-05-02.md) — feature×surface matrix; useful when verifying coverage.
- [`plans/ARCHITECTURE-DRIVEN-PLAN.md`](ARCHITECTURE-DRIVEN-PLAN.md) — why each surface exists (context for QA team if they wonder "why does this engine have no admin UI?").

## Maintenance

When a new feature ships, this document needs:

1. A new persona OR a new step in an existing persona (whichever fits the surface).
2. A new row in the **Coverage matrix** above.
3. A note in the v1.X column (★) of any cross-referenced rows.

Reviewer of any user-facing PR is responsible for ensuring this document still covers reality.
