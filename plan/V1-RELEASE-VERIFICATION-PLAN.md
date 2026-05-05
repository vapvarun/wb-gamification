# V1.0.0 Release Verification Plan

**Status:** active
**Owner:** Claude / Varun
**Driving principle:** customers must not see anything half-cooked. Every surface must work end-to-end before 1.0.0 ships.

This plan is the gate between "it compiles" and "ship it." Tier N blocks Tier N+1. A tier is **not done** until every checkbox in its acceptance row turns green and the run artefacts land under `audit/release-runs/<run-date>/tier-N/`.

> **Why this exists:** the v1.0.0 push hit half-cooked surfaces multiple times — editor showing "doesn't include support" for hub/community-challenges/cohort-rank, streak overlay non-dismissable, badge images NULL, earning-guide shortcode fatal, QA pages cluttering nav. Each of those was a missing verification step. This plan replaces "build and hope" with a deterministic gate.

---

## Tier ladder

```
Tier 0  REST readiness migration      ──┐  (architectural — blocks Tier 5 + 9)
Tier 1  Automated foundations         ──┤
Tier 2  Editor surface                  │
Tier 3  Frontend surface                ├─→ blocks tier 4+
Tier 4  Earning journey end-to-end    ──┤
Tier 5  Admin surface                   ├─→ blocks tier 8+
Tier 6  Integration matrix              │
Tier 7  Mobile + a11y deep dive       ──┤
Tier 8  Theme conflict matrix         ──┤
Tier 9  Release engineering gate      ──┘
```

A tier marked `BLOCKED` cannot ship; the dependent tiers don't even start.

---

## Tier 0 — REST readiness migration (architectural prerequisite)

**Why:** wp-plugin-development standard mandates "Admin UI uses REST internally, not form-posts. Max 2 AJAX/form-post exceptions per plugin." Today wb-gamification has **17 admin-post form-post handlers** across 9 admin pages — 8.5× over the limit. The mobile app already published via REST sees a *different* API surface than the admin UI does. That bifurcation is the root architectural defect; every Tier 5 fix on form-posts is a band-aid on a deprecated path.

**Decision principle:** the admin UI must dogfood the same REST endpoints a 3rd-party app would consume. After this tier, admin write-paths flow exclusively through `/wp-json/wb-gamification/v1/...`.

### REST coverage audit (already complete)

| Admin form action | REST controller status | Migration cost |
|---|---|---|
| save_badge / delete_badge | ✅ Badges (CRUD complete) | JS-only refactor |
| save_challenge / delete_challenge | ✅ Challenges (CRUD complete) | JS-only refactor |
| save_community_challenge / delete_community_challenge | ⚠️ Verify Challenges shared route or fork | Decision + JS refactor |
| save_reward / delete_reward | ✅ Redemption (CRUD complete) | JS-only refactor |
| manual_award | ✅ Points CREATABLE | JS-only refactor |
| webhook_save / webhook_delete | ✅ Webhooks (CRUD complete) | JS-only refactor |
| **save_levels / delete_level** | ❌ Levels has zero write methods | **New endpoints** + JS refactor |
| **save_cohort_settings** | ❌ No CohortSettings controller | **New controller + endpoint** + JS refactor |
| **create / revoke / delete api_key** | ❌ No ApiKeys controller | **New controller + 3 endpoints** + JS refactor |

### 0.A — Build 6 missing REST endpoints (blocks 0.C)

For each new endpoint:

- WP_Error(401) permission_callback (uses `Capabilities::user_can`)
- JSON schema declared (`schema` callback)
- before_/after_ hooks: `do_action( 'wb_gam_before_{action}', $args )`, `do_action( 'wb_gam_after_{action}', $result )`
- `wb_gam_rest_prepare_{resource}` filter on response
- Contract tests in `tests/Integration/API/`
- Mark each as documented in `docs/REST-API.md`

Endpoints to add:

```
POST   /wb-gamification/v1/levels            — LevelsController::create_or_update
DELETE /wb-gamification/v1/levels/{id}        — LevelsController::delete
POST   /wb-gamification/v1/cohort-settings    — new CohortSettingsController
POST   /wb-gamification/v1/api-keys           — new ApiKeysController::create
DELETE /wb-gamification/v1/api-keys/{id}      — new ApiKeysController::delete
PATCH  /wb-gamification/v1/api-keys/{id}/revoke — new ApiKeysController::revoke
```

### 0.B — Verify Challenges covers community-challenges (blocks 0.C)

Investigate whether `ChallengesController` serves both `wb_gam_challenges` and `wb_gam_community_challenges` tables, or only the former. Three possible outcomes:

- **Shared route with a `type` param** — keep, document the param, JS sends `type: "community"`
- **Different routes already exist** — JS targets the right one; document
- **Only individual challenges supported** — fork into `CommunityChallengesController` with full CRUD

Pick one, persist the decision in `docs/REST-API.md`.

### 0.C — Migrate 9 admin pages to REST + JS

Per page workflow:

1. Read the existing `<form action="admin-post.php">` template — note all field names, hidden values, redirect targets, success states
2. Replace form submission with JS handler:
   - Intercept submit → preventDefault
   - Build JSON body from form fields (no FormData → URLSearchParams; this is JSON to REST)
   - `fetch('/wp-json/wb-gamification/v1/...', { method, headers: { 'X-WP-Nonce': wpApiSettings.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(body) })`
   - On 2xx: toast success → refresh list table via REST GET → no full-page reload
   - On 4xx/5xx: toast error with `data.message`, leave form values intact
3. List tables: replace `$wpdb->get_results()` server-side render with REST GET on page load; render via JS template
4. Delete the `add_action( 'admin_post_*', ... )` hook AND the `handle_X()` method
5. Verify `wp_ajax_*` and `admin_post_*` counts: target = ≤2 plugin-wide
6. Browser-verify each page at 1280px AND 390px:
   - Form submit succeeds without redirect
   - List updates in-place
   - Toast shows
   - Network tab shows REST round-trip (not admin-post.php)
   - 0 PHP errors in `wp-content/debug.log`
7. Capture screenshots → `audit/release-runs/<date>/tier-0/<page>-{1280,390}.png`

Pages (9):

- BadgeAdminPage (save + delete)
- ChallengeManagerPage (save + delete)
- CommunityChallengesPage (save + delete)
- CohortSettingsPage (save)
- ManualAwardPage (award)
- RedemptionStorePage (save + delete)
- ApiKeysPage (create + revoke + delete)
- WebhooksAdminPage (save + delete)
- SettingsPage levels tab (save_levels + delete_level)

### Tier 0 acceptance

- [ ] 6 new REST endpoints registered, schema-validated, contract-tested
- [ ] Community-challenges REST decision persisted
- [ ] All 9 admin pages migrated; admin_post_* count ≤ 2 plugin-wide
- [ ] Each migrated page browser-verified at 1280 + 390
- [ ] `bin/coding-rules-check.sh` extended with Rule 3: "admin_post_* count ≤ 2"
- [ ] CLAUDE.md "Recent Changes" updated; ARCHITECTURE.md "Request lifecycle" updated
- [ ] `docs/REST-API.md` lists every new endpoint with auth + envelope + curl example
- [ ] `audit/manifest.json` regenerated to reflect new admin shape (`/wp-plugin-onboard --refresh`)

Tier 0 is **not done** until every checkbox above is green. Tier 5 + Tier 9 stay BLOCKED until then. Tiers 2/3/4/6/7/8 proceed in parallel since they don't touch admin write-paths.

---

## Tier 1 — Automated foundations

**Why:** if the static gates fail, no human verification can save the build.

| Check | Tool | Acceptance |
|---|---|---|
| PHP lint (8.1/8.2/8.3) | `composer ci:quick` (lint stage) | 0 errors |
| WordPress Coding Standards | `mcp__wpcs__wpcs_check_directory` | 0 errors (warnings allowed) |
| Static analysis | `composer phpstan` | 0 net new errors over baseline |
| Plugin Dev Rules | `mcp__wp-plugin-qa__wppqa_check_plugin_dev_rules` | failed=0 |
| REST↔JS contract | `mcp__wp-plugin-qa__wppqa_check_rest_js_contract` | failed=0 |
| Wiring completeness | `mcp__wp-plugin-qa__wppqa_check_wiring_completeness` | failed=0 |
| UX guidelines | `mcp__wp-plugin-qa__wppqa_check_ux_guidelines` | failed=0 |
| Visual regression | `mcp__wp-plugin-qa__wppqa_check_visual_regression` | failed=0 |
| Full audit | `mcp__wp-plugin-qa__wppqa_audit_plugin` | failed=0 |
| Unit + Integration tests | `composer test` | 0 failures, 0 errors |
| Coding-rules-check | `bin/coding-rules-check.sh` | 0 violations |
| Block standard compliance | `bin/check-block-standard.sh` | 15/15 pass |
| Bundle size — JS | `wc -c build/Blocks/*/index.js` | each ≤ 20 KB gzipped |
| Bundle size — CSS | `wc -c build/Blocks/*/*.css` | each ≤ 30 KB gzipped |
| Manifest freshness | `audit/manifest.json` newer than last source change | yes |

**Artefacts:** `audit/release-runs/<date>/tier-1/{wpcs.json, phpstan.txt, wppqa.json, phpunit.xml, sizes.txt}`.

**Acceptance:** every row green. One yellow → block.

---

## Tier 2 — Editor surface (15 blocks × inserter + sidebar)

**Why:** Customers configure blocks in the editor first. Broken inserter or non-persisting attributes are a dealbreaker.

For each of the 15 blocks: leaderboard, member-points, badge-showcase, level-progress, challenges, streak, top-members, kudos-feed, year-recap, points-history, earning-guide, hub, redemption-store, community-challenges, cohort-rank.

**Steps per block:**

1. New post → click block inserter → search by name → confirm block appears
2. Click to insert → confirm canvas renders (no "doesn't include support" message)
3. Sidebar — Layout panel: change padding (Desktop/Tablet/Mobile), margin, alignment
4. Sidebar — Style panel: change accent color, card background, border, shadow toggle, border radius
5. Sidebar — Visibility panel: toggle hideOnDesktop / hideOnTablet / hideOnMobile
6. Sidebar — Hover panel: change accent hover color
7. Save draft → reload → confirm every changed setting persists
8. Switch device preview (desktop/tablet/mobile) → confirm responsive values apply in canvas
9. Capture screenshot → `audit/release-runs/<date>/tier-2/<slug>-editor.png`

**Acceptance per block:** all 9 steps green. Any failure blocks ship for that block.

**Acceptance overall:** 15/15 green.

---

## Tier 3 — Frontend surface (15 blocks × 1280 + 390)

**Why:** Customer's site visitors see this. Half-cooked = customer churn.

For each of the 15 blocks at **both** 1280px AND 390px viewports:

1. Navigate to `/wb-gamification-qa-<slug>/` → confirm 200 OK
2. Capture full-page screenshot → `audit/release-runs/<date>/tier-3/<slug>-{1280,390}.png`
3. DOM check: block markup present, NO `Fatal error|Parse error|Uncaught` strings
4. Console check: 0 JS errors, 0 unhandled promise rejections (warnings allowed)
5. Network check: 0 4xx/5xx for plugin assets
6. **Visual diff vs hub stat-card baseline** (assets/css/hub.css `.gam-card`):
   - Card surface: white, `1px solid #e2e3ed` border, `10px` radius, `0 1px 3px rgba(0,0,0,0.06)` shadow, 24px padding
   - Title: 15-16px / weight 600 / color `#1a1a2e`
   - Description/meta: 13px / color `#555770`
   - Accent: `#5b4cdb` for links + emphasis; hover lift `0 4px 16px rgba(0,0,0,0.08)`
7. **Hover/focus/visited states** on every `<a>` inside the block — record computed colors
8. **Empty state** — visit the page as a brand new user (zero data) → confirm friendly empty state, NOT a broken layout
9. **Error state** — temporarily 503 a backing REST endpoint via filter → confirm graceful fallback message, NOT a fatal
10. **Keyboard nav** — Tab through interactive elements → confirm focus ring visible, no traps

**Acceptance per block:** all 10 steps green at both viewports.

**Acceptance overall:** 15/15 × 2 viewports green.

---

## Tier 4 — Earning journey end-to-end

**Why:** "Customer earns first badge in <60s of activation" is the v1.0.0 promise. If the earn loop is broken, nothing else matters.

**Setup:** fresh WP user → autologin via mu-plugin (`?autologin=fresh-user`).

**Walk:**

1. **Action: post creation** → publish a post → assert points awarded → assert event row in `wb_gam_events` → assert `wb_gam_points` row → assert leaderboard reflects user → assert level-progress block updates the bar
2. **Action: comment** → comment on a post → assert points awarded → second comment → assert rate-limit gate fires after daily cap
3. **Action: badge unlock** → trigger an action that unlocks a badge → assert badge row in `wb_gam_user_badges` → assert badge-showcase block renders the badge image (not NULL/broken-image) → assert notification fires → assert level-up overlay if threshold crossed → confirm overlay closes via Esc / backdrop / X button
4. **Action: kudos** → give kudos to another user → assert `wb_gam_kudos` row → assert kudos-feed block updates → assert cooldown enforced on second attempt within window
5. **Action: streak** → simulate 3 consecutive days of activity → assert streak block shows current 3, best 3 → break streak → assert reset → second streak → assert "milestone reached" notification at threshold
6. **Refund path** → admin reverses a manual award → assert event marked refunded → assert leaderboard reflects new total → assert badge un-unlocked if it was conditional
7. **Webhook** — register a test webhook → trigger an action → assert webhook fires with correct payload (use webhook.site or local listener)

**Acceptance:** every step in ≤ 1s admin-perceived latency, every assertion green.

---

## Tier 5 — Admin surface (10 pages × 1280 + 390)

**Why:** Site admins manage from these pages. Broken admin = unusable plugin.

**Pages to verify** (confirm via `audit/manifest.json` admin pages list):

1. WB Gamification → Setup Wizard
2. WB Gamification → Settings (each tab)
3. WB Gamification → Members
4. WB Gamification → Badges (library)
5. WB Gamification → Challenges (manager)
6. WB Gamification → Manual Award
7. WB Gamification → API Keys
8. WB Gamification → Analytics
9. WB Gamification → Tools / Health Check
10. WB Gamification → Webhooks

**Per page:**

1. Visit at 1280px → screenshot
2. Visit at 390px → screenshot — confirm no horizontal scroll
3. Walk every form → save → confirm toast (NOT `alert()` / `confirm()`)
4. Walk every CRUD action (create/edit/delete) → confirm REST internal (no admin-post forms outside the documented allowlist)
5. Tail `wp-content/debug.log` → 0 PHP errors during walk
6. Health Check tab → assert every check is green (or amber with documented fix link)
7. Tap targets ≥40×40 on mobile (mobile is for admins on phones, too)

**Acceptance:** 10/10 pages green at both viewports + Health Check all green.

---

## Tier 6 — Integration matrix

**Why:** customers stack multiple plugins. We integrate with these — they MUST keep awarding points.

| Integration | Test | Pass = |
|---|---|---|
| BuddyPress | New activity post by user → assert points + activity feed entry | activity entry shows badge if newly earned |
| BuddyPress | Visit member profile → Gamification tab loads | tab renders with points + level + badges |
| BuddyPress | Member directory loop | each card shows badge count |
| WooCommerce | Order completed | points awarded per `points_per_dollar` |
| LearnDash | Course completed | points awarded |
| bbPress | Topic created | points awarded |
| Elementor | Insert block in Elementor editor | renders identically to Gutenberg + frontend |
| Classic editor | Use shortcode `[wb_gam_leaderboard]` | renders identically to block |
| Page builder (Beaver/Bricks if available) | Insert shortcode | renders correctly |

**Acceptance:** 9/9 green.

---

## Tier 7 — Mobile + accessibility deep dive

**Why:** the WCAG 2.1 AA + 40×40 tap target + screen-reader gate is non-negotiable in 2026.

**Per surface (all 15 frontends + hub overlays + 10 admin pages):**

1. Tap targets ≥ 40×40 px (use Lighthouse target-size audit)
2. Color contrast WCAG 2.1 AA — especially the rank-1 yellow tint on white (currently flagged as low-contrast on the leaderboard QA screenshot)
3. ARIA — every modal has `role="dialog"` + `aria-modal="true"` + `aria-labelledby`
4. Keyboard-only — full hub navigable, every overlay closes via Esc, focus trap during overlay open, focus restored on close
5. Screen-reader (VoiceOver on macOS, NVDA on Windows) — hub block + leaderboard block + level-progress block read out correctly
6. `prefers-reduced-motion: reduce` → all transforms/transitions stop (the hub slide-in panel respects this)
7. `prefers-color-scheme: dark` — verify either: (a) we have a dark mode that doesn't break, or (b) we explicitly opt out via `color-scheme: light only`
8. Lighthouse a11y score ≥ 95 on every QA page
9. Lighthouse performance ≥ 90 on every QA page

**Acceptance:** all 9 rows green per surface.

---

## Tier 8 — Theme conflict matrix

**Why:** every Wbcom support ticket starts with "but it works on my dev site." Theme overrides break us in production.

**Themes to test:** BuddyX (current shipping theme), Twenty Twenty-Five (WP default), Astra (most-installed third-party).

**Per theme:**

1. Switch theme via WP-CLI: `wp theme activate <slug>`
2. Run Tier 3 checklist on all 15 frontend QA pages
3. Specifically check `<a>` color/hover/visited — confirm theme link styles don't override block link colors
4. Check Gutenberg editor — confirm theme.json doesn't break block previews
5. Document any theme-specific override needed in `docs/theme-compatibility.md`

**Acceptance:** 15 blocks × 3 themes = 45 confirmations green; any theme-specific override has a documented fix.

---

## Tier 9 — Release engineering gate

**Why:** the working tree contains dev tooling that PCP flags. The released zip MUST be clean.

1. Bump version in plugin header + `readme.txt` + `CHANGELOG.md`
2. `composer install --no-dev --optimize-autoloader`
3. `npm install && npm run build`
4. `bin/build-release.sh` → produces `dist/wb-gamification-1.0.0.zip`
5. Extract to `/tmp/wb-gam-release-test/`
6. `wp plugin check /tmp/wb-gam-release-test/wb-gamification --severity=error` → 0 errors
7. Verify zip excludes: `.git/`, `node_modules/`, `tests/`, `plan/`, `docs/`, `bin/`, `src/`, `dist/`, `*.map`, `composer.json`, `composer.lock`, `package*.json`, `phpstan.neon*`, `phpcs.xml*`, `phpunit.xml*`, `audit/`, `examples/`, `CLAUDE.md`
8. Verify zip includes: `wb-gamification.php`, `readme.txt`, `LICENSE`, `CHANGELOG.md`, `languages/wb-gamification.pot`, `languages/wb-gamification-rtl.css` (if applicable), `assets/`, `build/`, `includes/`, `vendor/`, `templates/`, `uninstall.php`
9. Generate `.pot`: `wp i18n make-pot . languages/wb-gamification.pot --domain=wb-gamification --exclude=node_modules,vendor,build,tests`
10. Generate RTL stylesheets: `npx rtlcss assets/css/frontend.css assets/css/frontend-rtl.css` (and admin.css)
11. Smoke test on a clean WP install — activate plugin → wizard runs → admin redirects → first earning action → first badge unlocks → notification fires → leaderboard reflects → all in <60s
12. Tag `v1.0.0` → push tags → upload zip to distribution channel

**Acceptance:** every step green.

---

## Run history

| Date | Run | Tier results | Notes |
|---|---|---|---|
| 2026-05-03 | initial | _in progress_ | Plan authored after G.4/G.1/G.2/G.3 wrapped; Tier 1 starting |

---

## Per-run artefact layout

```
audit/release-runs/2026-05-03/
├── tier-1/
│   ├── wpcs.json
│   ├── phpstan.txt
│   ├── wppqa-audit.json
│   ├── phpunit.xml
│   ├── sizes.txt
│   └── SUMMARY.md (pass/fail per row)
├── tier-2/
│   ├── leaderboard-editor.png
│   ├── leaderboard-editor-sidebar.png
│   ├── ...
│   └── SUMMARY.md
├── tier-3/
│   ├── leaderboard-1280.png
│   ├── leaderboard-390.png
│   ├── ...
│   └── SUMMARY.md
├── tier-4/
│   └── earning-journey.log
├── tier-5/
│   └── ...
├── tier-6/
│   └── ...
├── tier-7/
│   └── lighthouse-reports/
├── tier-8/
│   └── theme-matrix-screenshots/
└── tier-9/
    ├── built-zip-pcp-report.txt
    └── smoke-test.log
```

## Promotion to journeys

After the first successful end-to-end run, the following must become tracked journeys under `audit/journeys/release/`:

- `release/01-tier-1-foundations.md` — runs all automated gates
- `release/02-editor-15-blocks.md` — Tier 2 walker
- `release/03-frontend-15-blocks-1280.md` — Tier 3 desktop walker
- `release/04-frontend-15-blocks-390.md` — Tier 3 mobile walker
- `release/05-earning-journey.md` — Tier 4 walker
- `release/06-admin-10-pages.md` — Tier 5 walker
- `release/07-integration-matrix.md` — Tier 6 walker
- `release/08-a11y-lighthouse.md` — Tier 7 walker
- `release/09-theme-buddyx.md` / `release/09-theme-twenty-five.md` / `release/09-theme-astra.md` — Tier 8 walkers
- `release/10-release-zip-gate.md` — Tier 9 walker

Once committed, every push runs the foundation tier (1) and dependents block on it. The full release suite runs nightly + on-demand before tagging.
