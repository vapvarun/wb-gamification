# Fresh-Eyes Admin UX Audit — 2026-05-03

**Question asked:** *"As fresh audit, do we have complete flow ready as production-level plugin? At which point will the site admin get confused?"*

**Answer (one sentence):** The product is feature-complete but **not production-ready as a self-serve admin experience** — there are 3 Critical, 5 High, and 7 Medium friction points where a brand-new site owner would either get stuck, build the wrong thing, or never discover a working feature. Fix the Criticals + Highs (≈2 days of work) and the plugin is shippable to a non-WBcom customer.

---

## How this audit was done

- **Walker:** site admin (`varundubey`, ID 1, `manage_options`) on a fresh-ish single-site Local install
- **Method:** Playwright MCP — navigated every entry point a stranger would click, took screenshots, recorded the moment a question formed in my head ("wait, where do I…?", "is this the same thing as that?", "what does this do?")
- **Cross-reference:** [`ARCHITECTURE-DRIVEN-PLAN.md`](ARCHITECTURE-DRIVEN-PLAN.md) for "what was supposed to be built", actual file system + manifest for "what was built"
- **Screenshots:** [`ux-admin-2026-05-03/screenshots/`](ux-admin-2026-05-03/screenshots/) — 11 PNGs referenced inline below

This audit is intentionally fresh-eyes; it does NOT re-litigate any item already in [`audit/FEATURE-COMPLETENESS-2026-05-02.md`](../audit/FEATURE-COMPLETENESS-2026-05-02.md) or [`QA-MANUAL-TEST-PLAN.md`](QA-MANUAL-TEST-PLAN.md). It reports only points-of-confusion observed during a live walk.

---

## Severity tiers (matches QA-MANUAL-TEST-PLAN bug-report template)

| Tier | Meaning | Ship gate |
|---|---|---|
| **Critical** | Admin lands on a 403, dead end, or actively-broken feature | Block release |
| **High** | Admin builds the wrong mental model, ships a misconfigured site, or a feature is invisible to members | Block release |
| **Medium** | Admin succeeds but with a "wait, what?" tax — first task takes 2× longer than it should | Fix in next minor |
| **Low** | Polish — wording, default values, copy clarity | Backlog |

---

## Critical — release-blocking (3)

### C1 · Admin creates a Reward, but there is **no way for a member to see it**
**Where:** Gamification → Redemption Store ([`screenshots/05-redemption.png`](ux-admin-2026-05-03/screenshots/05-redemption.png))
**The trap:** Admin fills the form, clicks "Add Reward", goes home, member visits the site — there is **no `redemption-store` block** and **no `[wb_gam_redemption_store]` shortcode**. Members cannot browse or redeem rewards from anywhere except direct REST calls (`/wp-json/wb-gamification/v1/redemption/items`).
**Evidence:**
- `blocks/` lists 14 blocks, none for redemption (`ls blocks/`)
- `src/Engine/ShortcodeHandler.php` registers 14 shortcodes, none for redemption (`grep add_shortcode src/Engine/ShortcodeHandler.php`)
- `plan/ARCHITECTURE-DRIVEN-PLAN.md` Phase 3 explicitly listed `redemption-store` block as required to close RedemptionEngine's frontend tier — PR #16 built `community-challenges` and `cohort-rank` blocks but missed this one
**Why "Critical":** This is the headline feature for any community using points (the "what do I do with my points?" question). Without it, the entire engine has no consumer surface.
**Fix:** Build `blocks/redemption-store/{block.json,render.php}` plus `wb_gam_redemption_store` shortcode. Reuse RedemptionEngine::list_active() and RedemptionController response shape. ~3 hours.

### C2 · Tab routing inside Settings doesn't sync to URL — admin **can't bookmark or share** a specific config tab
**Where:** Gamification → (lands on Points tab, even though the inner sidebar shows "Dashboard" first) ([`screenshots/01-settings-default.png`](ux-admin-2026-05-03/screenshots/01-settings-default.png), [`screenshots/11-settings-dashboard-tab.png`](ux-admin-2026-05-03/screenshots/11-settings-dashboard-tab.png))
**The trap:**
1. Default landing tab is **Points**, not **Dashboard** — admin's first impression is a config form, not a "welcome / overview".
2. Navigating to `?page=wb-gamification#dashboard` does **not** open the Dashboard tab (verified — the screenshot at `#dashboard` still shows Points active).
3. Two different tab routers coexist — `assets/js/admin-settings.js` reads `#hash` for `.wbgam-tab` items, but the inner sidebar (`.wbgam-settings__nav` items) is rendered server-side and does not participate in hash routing.
**Why "Critical":** Documentation can never link to the right tab. Support tickets devolve into "click Settings, then look for Levels in the sidebar, no the OTHER sidebar". Two routers in one screen is a long-term maintenance landmine.
**Fix:** (a) Make Dashboard the default landing tab (`SettingsPage::get_active_tab()` falls back to `'dashboard'`, not `'points'`). (b) Unify the two routers — the inner sidebar should also write `?tab=…` to the URL and the page should honour the query param on load. ~2 hours.

### C3 · Admin menu submenu slug prefix is **split across two conventions**
**Where:** All 11 admin pages (visible side-by-side in any sidebar screenshot, e.g. [`screenshots/04-webhooks-real.png`](ux-admin-2026-05-03/screenshots/04-webhooks-real.png))
**The trap:**
| Page | URL slug |
|------|----------|
| Analytics | `wb-gamification-analytics` |
| Badges | `wb-gamification-badges` |
| Award Points | `wb-gamification-award` |
| Setup Wizard | `wb-gamification-setup` |
| API Keys | **`wb-gam-api-keys`** |
| Challenges | **`wb-gam-challenges`** |
| Cohort Leagues | **`wb-gam-cohort`** |
| Community Challenges | **`wb-gam-community-challenges`** |
| Redemption Store | **`wb-gam-redemption`** |
| Webhooks | **`wb-gam-webhooks`** |

The first 4 use `wb-gamification-*`, the next 6 use `wb-gam-*`. Any external link, doc, deeplink, support reply, or capability check that types `wb-gamification-webhooks` (the "obvious" guess) gets a **403 "Sorry, you are not allowed to access this page"** — this is exactly what the auditor walked into ([`screenshots/03-webhooks-403.png`](ux-admin-2026-05-03/screenshots/03-webhooks-403.png)). The page is fine — the slug name is the bug.
**Why "Critical":** Documentation, screenshots, and customer support links all bake URL slugs in. Half are wrong by accident-of-history. Fix it once before customers do, or live with permanent slug drift.
**Fix:** Pick one convention. Recommendation: `wb-gam-{thing}` (shorter, future-proof). Add 4 redirect rules in `WB_Gamification::handle_admin_redirects()` so pre-rename links keep working. ~1 hour.

---

## High — fix before customer ships (5)

### H1 · "Gamification" submenu items duplicate Settings inner-sidebar tabs
**Where:** Compare [`screenshots/01-settings-default.png`](ux-admin-2026-05-03/screenshots/01-settings-default.png) WP sidebar vs Settings inner sidebar.
- **WP sidebar (10 items):** Gamification, Analytics, Badges, Challenges, Award Points, API Keys, Redemption Store, Community Challenges, Cohort Leagues, Webhooks
- **Settings inner sidebar (9 items):** Dashboard, Points, Levels, Challenges, Badges, Kudos, Rules, API Keys, Integrations

**Three names appear in both** ("Badges", "Challenges", "API Keys"). One links to a config form (Settings inner), the other to a CRUD admin (WP submenu). They look like the same thing. Admin clicks "Badges" in WP sidebar, lands on the library; clicks "Badges" in Settings inner sidebar, lands on the points-config tab. Brain freezes for 5 seconds every time.
**Fix:** Pick a model. Either (a) collapse the inner sidebar entirely — every section gets its own top-level submenu page, or (b) remove the WP-sidebar duplicates and route all "configure X" links through Settings tabs. Recommendation: (a), because that's where every other WP plugin has converged. ~2 hours.

### H2 · The auto-created Hub page is invisible to admin
**Where:** Plugin auto-creates a public Hub page at `/wb-gamification-hub/` on activation, but **nowhere in the admin** does it tell you the URL, link to it, or show a "Preview Hub →" button. Admin doesn't know it exists.
**Evidence:** Searched all admin pages — no string "Hub", no link to the front-end Hub, no admin notice after activation pointing to the slug.
**Fix:** Settings → Dashboard tab should have a top-row "Member Hub: <link to live URL>" card with "Preview" + "Edit page" actions. ~30 min.

### H3 · Cohort Leagues uses jargon without examples
**Where:** Gamification → Cohort Leagues ([`screenshots/07-cohort.png`](ux-admin-2026-05-03/screenshots/07-cohort.png))
**The trap:** Admin sees "Promotion %", "Demotion %", "Tier 1 (lowest)", "League Duration: Weekly". Helper text exists but never says **what happens at the cycle boundary** in concrete numbers. "20% promote" — promote *how*? Do members get a notification? Does their leaderboard reset?
**Fix:** Add a "Preview cycle outcome" callout: "With 100 active members and 4 tiers: 25 in Bronze, 25 in Silver, 25 in Gold, 25 in Diamond. At week-end, 20% of each tier (5 members) promote up; 20% demote down. Inactive members skip the cycle." ~30 min.

### H4 · API Keys page shows the secret **once, in plaintext, with no recovery**
**Where:** Gamification → API Keys ([`screenshots/08-api-keys.png`](ux-admin-2026-05-03/screenshots/08-api-keys.png))
**The trap:** Form has Label + Site ID + "Generate Key". The flow needs an explicit warning: "This is the **only time** the secret is shown. Copy it now — it cannot be recovered. Lost it? Generate a new one and revoke the old." That confirmation is not visible on the form (would need to check the post-creation render). Without it, admin generates a key, navigates away, then can't connect their remote site.
**Fix:** Add the warning copy above the Generate button AND on the post-create reveal screen. Add a prominent "Copy to clipboard" button on the reveal. ~30 min.

### H5 · Webhooks page has no test-fire / debug aid
**Where:** Gamification → Webhooks ([`screenshots/04-webhooks-real.png`](ux-admin-2026-05-03/screenshots/04-webhooks-real.png))
**The trap:** Admin pastes their Zapier URL, picks events, saves. Now what? Does it work? There's no "Send test event" button, no "Last delivery" column, no "View recent attempts" link. Admin has to make a real user earn points to find out — and if delivery silently fails, they'll discover it days later.
**Fix:** Add a "Send test event" button next to each saved webhook (POSTs a synthetic `points_awarded` payload). Add a "Last delivery: 2 min ago — 200 OK" column to the configured-webhooks table. ~2 hours.

---

## Medium — first-task friction tax (7)

### M1 · "Stock: 0 = unlimited" is unintuitive
[`screenshots/05-redemption.png`](ux-admin-2026-05-03/screenshots/05-redemption.png) — the helper text says "Set to 0 for unlimited stock". Better: an explicit toggle ("Limited / Unlimited") that hides the stock field when Unlimited.

### M2 · "Reward Type: Custom Reward" → "fulfilled via hook" is meaningless to non-developers
Same screenshot. Add a "Learn more" link to `examples/redemption-fulfilment/README.md` (which doesn't exist yet — create it as part of the fix).

### M3 · Analytics dashboard renders an empty bar chart on a single-day data set
[`screenshots/06-analytics.png`](ux-admin-2026-05-03/screenshots/06-analytics.png) — "Daily Points Trend" shows one tall purple bar in the corner, a wall of white space everywhere else. Non-zero data but reads as "is this broken?". Add y-axis ticks, x-axis date labels, and an empty-state "Need more data — show 7 days" hint when only one day has events.

### M4 · "Setup Wizard" exists but isn't surfaced anywhere on first activation
The Setup Wizard at `?page=wb-gamification-setup` is registered but hidden. There's no first-run admin notice ("Welcome to WB Gamification — would you like to run the Setup Wizard?"). Most admins will skip it and miss the calibration flow.

### M5 · Badge Library has 12 default badges with `0 earned` and no instruction on how to **trigger** them
[`screenshots/02-badges-top.png`](ux-admin-2026-05-03/screenshots/02-badges-top.png) — Active Member, Blog Publisher, etc. all show "0 earned". Admin has no way to know which badge is auto-awarded, which needs manual action, or what trigger condition activates each one without clicking into the badge details one-by-one.

### M6 · Replay CLI is invisible from the admin UI
The `wp wb-gamification replay` command (PR #16) is genuinely useful for "I just changed a rule, re-evaluate everyone". Admin doesn't know it exists. Add a "Recompute badges" button on the Rules tab that explains what it does and either runs the CLI synchronously (small site) or schedules an Action-Scheduler job (larger).

### M7 · Email templates are pluggable but admin has no way to know that
`templates/emails/weekly-recap.php` is theme-overridable per the integration story, but nothing in the admin UI says "drop your override at `your-theme/wb-gamification/emails/weekly-recap.php`". Add a one-line note + link-to-example in Settings → Integrations.

---

## Low — polish (backlog)

- L1 · Settings → Dashboard / Points / Levels: each tab has a different visual rhythm (Points uses dense table, Levels uses card grid). Standardise.
- L2 · "WORDPRESS" in all-caps with "8 actions in this category" feels like a debug header, not a section title. Make it "WordPress actions (8)" sentence-case.
- L3 · "WB Gamification" in the Settings inner-sidebar header is redundant with the WP page title. Remove.
- L4 · Mobile: at 390px the inner sidebar collapses but the WP submenu still has 10 items — admin scroll-fest.
- L5 · The "Manual Award" Recent Awards table doesn't say *who awarded* (admin name), only the recipient.

---

## Cross-cutting recommendations (architectural, not page-by-page)

### R1 · One canonical menu structure, not two
Settle the "do users navigate via WP submenu or via Settings tabs?" question once. My recommendation: **WP submenu only**. Modern WP plugins (WooCommerce, Yoast) abandoned in-page tab routers because they fight against the WP admin shell. Each Settings tab today maps cleanly to its own page (`wb-gam-points`, `wb-gam-levels`, `wb-gam-rules`, etc.). Killing the inner router removes C2 and H1 in one move.

### R2 · Empty states must answer "why" + "how to get out"
Every empty state today says only "no X yet" (No webhooks, No API keys, No badges earned). Best-in-class empty states answer **why is this empty** ("0 badges earned because no member has triggered an Auto-Award rule yet — try `wp wb-gamification replay all` to backfill from existing activity") and **what's the next click** ("Add your first webhook →").

### R3 · Surface the architectural plan to the admin
The plan is well-thought-out, but admins will never read it. Translate the surface-tier classification into in-product UX: every admin page header should answer "what does this control, and where does it appear for members?" with a one-line `→ Visible in: Member Hub, Profile widget` (or "Internal — no member-facing UI").

---

## Surface gap summary (vs ARCHITECTURE-DRIVEN-PLAN)

The plan classified every engine into a tier (User-facing / Admin-only / Internal-only / Cron-only). PR #16 closed most gaps but **missed `redemption-store` frontend block** (C1 above). Verified gap matrix:

| Engine | Admin tier | REST tier | Frontend tier (block + shortcode) | Status |
|---|---|---|---|---|
| WebhookDispatcher | ✅ (PR #16) | ✅ | n/a (admin-only) | Complete |
| CommunityChallengeEngine | ✅ | ✅ | ✅ (PR #16) | Complete |
| CohortEngine | ✅ | ✅ | ✅ (PR #16) | Complete |
| **RedemptionEngine** | ✅ | ✅ | **❌ MISSING** | **Gap (C1)** |
| PersonalRecordEngine | n/a (internal) | n/a | n/a | Internal-only |
| All other engines | ✅ | ✅ | ✅ | Complete |

After C1 is closed, the plugin's surface coverage matches the architectural plan.

---

## Effort estimate

| Bucket | Items | Effort |
|---|---|---|
| Critical (C1–C3) | 3 | ~6 hours |
| High (H1–H5) | 5 | ~5 hours |
| Medium (M1–M7) | 7 | ~6 hours |
| Cross-cutting (R1–R3) | 3 | ~4 hours (R1 alone is half) |
| **Total to ship-clean** | 18 | **~21 hours / 3 working days** |

Recommend: do C1+C3+H1 (≈4 hours) before any customer release; the rest can land in 1.1.1 / 1.1.2.

---

## What I'm NOT claiming in this audit

- I did not test member-side journeys — that's QA-MANUAL-TEST-PLAN persona 4's job
- I did not run wppqa or rerun feature-completeness — those are separate artefacts and current as of 2026-05-02
- I did not break the plugin during the walk — every screenshot is of the live state on this Local

---

## Verdict

> Feature-complete? Yes.
> Production-ready as a self-serve admin product? **Not yet.** Close C1, C2, C3, and the plugin clears the bar for an external customer who has never met us.

Closing the 3 Criticals + 5 Highs is ~11 hours of focused work. After that, the Mediums become genuine polish rather than ship-blockers.

— Audit walked 2026-05-03 by fresh-eyes admin pass on `audit/ux-admin-fresh-eyes` branch. Screenshots in `ux-admin-2026-05-03/screenshots/`.
