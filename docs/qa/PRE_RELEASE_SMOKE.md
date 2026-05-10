# WB Gamification — Pre-Release Smoke Checklist

> **Run this before every tagged release. Every row must pass.**
> Any failure → file a Basecamp card in Bugs (project `47162271`, column `9860020654`) and **halt the release**.
> Target time: 90 minutes end-to-end.

**Matrix:** 4 personas × 3 browsers × 2 viewports × 2 theme modes.

- Personas: Anonymous visitor, Member (subscriber), Editor with `wb_gam_manage_badges`, Admin (`manage_options`)
- Browsers: Chrome Desktop, Firefox Desktop, Safari iOS (sim or real)
- Viewports: 1440px desktop, 390px mobile
- Theme modes: Light, Dark (every block re-derives tokens for `body.wb-gam-dark`)

> **The deeper, persona-walked equivalent is [`plan/QA-MANUAL-TEST-PLAN.md`](../../plan/QA-MANUAL-TEST-PLAN.md)** — 4–6 hour walk per release-candidate. This document is the 90-minute pre-flight pass; that document is the cycle-end deep walk. Run both per release.

**Environment:**
- Clean Local site with `wb-gamification` already on the previous stable version for the upgrade test
- A second clean Local site for the fresh-install test
- Access to `wp-content/debug.log` and DevTools Network tab
- Mailpit/Mailhog open for transactional email rows
- Auto-login mu-plugin active (`?autologin=1` for admin, `?autologin=qa_member` for member, etc.)

---

## A — Fresh install (10 min)

- [ ] Activate `wb-gamification` → no fatal, no PHP warning in `wp-content/debug.log`
- [ ] All 22 custom DB tables created: `wp db tables --all-tables | grep -c '^wp_wb_gam_'` returns 22
- [ ] `wp option get wb_gam_db_version` equals `DbUpgrader::TARGET`
- [ ] First REST request after activation returns 200 — no manual permalink flush needed: `curl -fsS http://wb-gamification.local/wp-json/wb-gamification/v1/openapi.json` (regression guard against `D.activation-rewrite`)
- [ ] After activation, loading any `/wp-admin/` URL auto-redirects to `?page=wb-gamification-setup` (option `wb_gam_pending_setup_redirect` armed by activation hook, consumed on first admin load — survives any activation-to-admin gap, no 30s ceiling)
- [ ] Setup wizard renders with 5 starter template cards; selecting one persists `wb_gam_template` + sets `wb_gam_wizard_complete`
- [ ] "Skip & configure manually" exits without writing any email/privacy toggles (engine defaults stay in force)
- [ ] After completion, the wizard URL still renders (re-runnable) with the current template highlighted "Current" + a "you've already completed setup" notice
- [ ] Admin notice "Welcome — run setup wizard" appears on plugin admin pages until completion, never after, never on non-plugin pages
- [ ] Hub page exists: `wp option get wb_gam_hub_page_id` returns a numeric page ID
- [ ] Deactivate → reactivate → no duplicate tables, no re-run migrations, schedule count for `wb_gam_*` cron events stable

## B — Upgrade from previous release (5 min)

- [ ] Drop the new zip → update via WP → no fatal during the upgrade HTTP request
- [ ] `wp option get wb_gam_db_version` updates to the new constant
- [ ] Pre-existing events / points / badges / challenges still render and function (sample three users; their `MembersController::get_points` totals match `SUM` of their ledger rows)
- [ ] On v1.0 → v1.1 sites: cosmetic tables dropped (`SHOW TABLES LIKE 'wp_wb_gam_cosmetic%'` returns 0 rows)
- [ ] Granular caps registered for administrator: `wp eval 'print_r((array) get_role("administrator")->capabilities);' | grep -c wb_gam_` returns 8
- [ ] No new debug.log warnings during the upgrade request

## C — Core user flows (25 min)

### C1 — Anonymous visitor
- [ ] Hub page renders publicly with leaderboard preview + login CTA — no console errors
- [ ] Public REST allowlist intact: `/wp-json/wb-gamification/v1/leaderboard`, `/badges`, `/challenges`, `/levels`, `/openapi.json`, `/abilities`, `/actions`, `/capabilities`, `/redemptions/items`, `/kudos` all return 200 (10 endpoints — see `audit/journeys/security/01-rest-public-allowlist.md`)
- [ ] Auth-gated routes (e.g. `/leaderboard/me`, `/kudos` POST) reject anonymously — never silent 200

### C2 — Member (subscriber)
- [ ] Log in as `qa_member` (or any subscriber) via `?autologin=qa_member`
- [ ] Trigger a tracked action (publish a comment) → ledger increments by the action's configured points within 5s; Hub page reflects on next reload
- [ ] First-badge unlock: a member's first qualifying action unlocks the configured badge within 60s; toast fires; badge appears on profile + share URL
- [ ] Give kudos to another member → 201; second kudos within cooldown returns 429
- [ ] Mobile 390px: Hub page tabs all reachable, no horizontal overflow, leaderboard period switcher tappable

### C3 — Editor with granular cap
- [ ] Grant `wb_gam_manage_badges` to `qa_editor` → POST `/wb-gamification/v1/badges` returns 201
- [ ] Without the cap → 403
- [ ] Cap holder still 403 on `/points/award`, `/rules`, `/webhooks`, `/redemptions/items` POST (cap is single-purpose)

### C4 — Admin
- [ ] Navigate to all 13 plugin admin pages → all render without PHP `Notice:` / `Warning:` / `Fatal`
- [ ] Award Points page: pick `qa_member`, award 25, save → ledger reflects + admin UI confirms
- [ ] Settings save flow: change a Points value, save, reload, persists
- [ ] API key mint → revoke → revoked key rejected with `wb_gam_invalid_api_key`
- [ ] Webhook test-fire → receiver gets a parseable POST with `event_id` / `user_id` / `action_id` / `points`

## D — Known-regression guards (15 min)

Each row is a permanent fixture against a past customer-pain bug. Walk every one. Full list with fixtures lives in [`AGENT_SMOKE_RUNBOOK.md`](AGENT_SMOKE_RUNBOOK.md) Section D.

- [ ] **D.activation-rewrite** — clean reactivate; first REST request returns 200 without manual permalink flush
- [ ] **D.cap-drift-manual-award** — POST `/points/award` as `qa_member` returns 403
- [ ] **D.leaderboard-cache-drift** — `TRUNCATE wp_wb_gam_leaderboard_cache`; recompute returns identical ordering to live SUM
- [ ] **D.streak-tz-offset** — events at 23:55 + 00:05 site-TZ keep streak counter on track
- [ ] **D.zero-points-rejected** — POST `{points:0}` returns 400 code `rest_points_zero`
- [ ] **D.negative-points-debit** — POST `{points:-50}` returns 201 with `debited:true`; ledger SUM decreases
- [ ] **D.kudos-cooldown-bypass** — two parallel kudos POSTs from same user → exactly one 201, one 429
- [ ] **D.openapi-stale** — `paths` count from `/openapi.json` ≥ 30 and includes routes added on this branch
- [ ] **D.dark-mode-block-contrast** — every block's primary text passes WCAG AA against its background under `body.wb-gam-dark`
- [ ] **D.mobile-tabs-clipped** — at 390px on `/wb-gam-hub`, the active tab is within the viewport on initial load
- [ ] **D.cli-replay-idempotent** — `wp wb-gamification replay` run twice on the same event leaves the ledger SUM identical
- [ ] **D.action-scheduler-orphan** — after deactivate-then-activate, `wp cron event list \| grep -c wb_gam_` matches the documented schedule count exactly
- [ ] **D.granular-cap-merge** — upgrade preserves every pre-existing administrator cap PLUS the eight `wb_gam_*` caps

**Rule:** every customer-visible fix that ships after this document adds a new row here AND a new fixture in `AGENT_SMOKE_RUNBOOK.md` Section D in the same PR.

## E — Integrations (10 min)

For each host plugin, check the integration adds value when present AND is silent when absent.

- [ ] **BuddyPress** — activity action → event → points round-trip; profile gamification tab renders; members directory respects ranking option
- [ ] **WooCommerce** — completed order fires purchase action; refund debits the awarded points
- [ ] **LearnDash** — completing a lesson fires the configured action; daily cap (if set) enforced
- [ ] **bbPress** — creating a topic / reply lands an event with correct `action_id`
- [ ] **Elementor** — widget renders any of the 17 blocks; settings round-trip on save
- [ ] **ACF** — rule editor lists ACF field keys when ACF active; renders "no fields detected" when absent
- [ ] **Graceful degradation** — deactivate ANY of the integrations; no fatal on any admin or front-end page

## F — Cross-browser quick pass (10 min)

Run these 5 pages on **Chrome + Firefox + Safari iOS**:

1. `/wb-gam-hub` — Hub landing
2. Any page embedding the **Leaderboard** block — primary content view
3. Any page embedding the **Submit Achievement** block — creation form
4. `/wp-admin/admin.php?page=wb-gamification` — plugin admin Dashboard
5. `/wp-admin/admin.php?page=wb-gamification#points` — plugin settings

Expectations: no JS errors, no layout breaks, interactive elements work. Items the agent walk can't cover get added to its `manual_required[]`.

## G — Post-release verification (first 24h)

- [ ] `wp-content/debug.log` on the production reference site clean of new warnings / notices / fatals
- [ ] `wp cron event list | grep wb_gam_` — expected events scheduled, no orphans
- [ ] Zoho Desk / Slack `#support` — no "broke after update" tickets in first 24h
- [ ] Activity signal continues (daily new-event count ≥ 70% of the trailing-7-day baseline — flag if it drops)

---

## Failure protocol

1. **Stop.** Do not merge the release branch.
2. File a Basecamp card in **Bugs** (project `47162271`, column `9860020654`) with: failed row verbatim, environment, browser, persona, screenshot.
3. Fix + push to the release branch.
4. Re-walk the failed row AND the section that contains it.
5. Resume only after the failure is resolved.

## Version-specific additions

Append a section below per release with the specific regression guards added that cycle. After 2 clean releases of a row → graduate it into the main flow.

### v1.0.0 release
- (initial baseline; row-by-row history begins with v1.0.1)
