---
journey: admin-shell-uniformity
plugin: wb-gamification
priority: high
roles: [administrator]
covers: [admin-shell-uniformity, canonical-page-shell, settings-card-dialect, analytics-rebuild, button-vocabulary, column-priority-tables, rich-empty-states, reduced-motion, settings-toast]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Logged in as administrator (autologin via ?autologin=1)"
  - "Plugin source tree available at the plugin root (for the grep assertions)"
estimated_runtime_minutes: 4
---

# Every admin screen converges on the canonical wbgam- page shell

Owner decision (branch `fix/admin-shell-uniformity`): there is ONE admin shell, and every screen + Settings subtab uses it. The canonical reference is `src/Admin/ManualAwardPage.php` / `src/Admin/WebhooksAdminPage.php`: outer wrapper `.wrap.wbgam-wrap`, header `.wbgam-page-header` with `__main/__title/__desc/__actions`, section cards `.wbgam-card` with `.wbgam-card-header > .wbgam-card-title` + `.wbgam-card-body`, data listings `.wbgam-table`, buttons `.wbgam-btn` (+ `--secondary/--sm/--danger`), tab navs `.wbgam-tabs.nav-tab-wrapper`.

Three bespoke dialects were retired in this convergence and MUST NOT come back:
- `wbgam-settings-card` (+ `__head/__title/__desc/__body`) — the Settings card dialect.
- `wb-gam-analytics__panel` — the Analytics section dialect.
- bare WP-core `class="button button-primary"` / `button button-link` / `button-link-delete` on our own admin screens.

This journey is the structural regression lock. It is mostly grep-able (source assertions) plus a thin live pass that confirms the rendered DOM carries the canonical classes and zero dialect classes.

## Setup

- Site: `$SITE_URL`
- Admin login: `?autologin=1`
- Plugin root: the directory containing `wb-gamification.php`
- The SetupWizard (`src/Admin/SetupWizard.php`) is the ONE intentional exception — it uses `wb-gam-wizard-*` and is excluded from every assertion below.

## Steps

### 1. No `wbgam-settings-card` dialect anywhere in src/Admin
- **Action**: `grep -rn "wbgam-settings-card" src/Admin/`
- **Expect**: zero matches.
- **On fail**: `src/Admin/SettingsPage.php` — a card was re-introduced with the old dialect. Migrate to `wbgam-card / wbgam-card-header / wbgam-card-title / wbgam-card-body`.

### 2. No `wb-gam-analytics__panel` section dialect
- **Action**: `grep -rn "wb-gam-analytics__panel" src/Admin/`
- **Expect**: zero matches.
- **On fail**: `src/Admin/AnalyticsDashboard.php` — a panel was added back. Use `wbgam-card wbgam-stack-block` with `wbgam-card-header > h3.wbgam-card-title` + `wbgam-card-body`.

### 3. No bare WP-core buttons on our admin screens
- **Action**: `grep -rnE 'class="button button-(primary|link)"|button-small button-link-delete' src/Admin/ | grep -v SetupWizard.php`
- **Expect**: zero matches. (`submit_button()` calls are the WP-standard helper and are allowed; this asserts only literal hardcoded `class="button ..."` attributes.)
- **On fail**: the offending file — swap to `wbgam-btn` / `wbgam-btn--secondary` / `wbgam-btn--sm wbgam-btn--danger`. If JS writes the className, update the JS selector in the same change (`assets/js/admin-levels.js`).

### 4. No `widefat striped` data tables on our admin screens
- **Action**: `grep -rn "widefat striped" src/Admin/ | grep -v SetupWizard.php`
- **Expect**: zero matches.
- **On fail**: convert the table to `class="wbgam-table"` (add `wbgam-table-reset` when it wraps a form-table inside a card).

### 5. The legacy analytics stylesheet is retired
- **Action**: `ls assets/css/admin-analytics.css 2>/dev/null; grep -rn "admin-analytics.css\|wb-gam-admin-analytics" src/Admin/ audit/manifest.json`
- **Expect**: file absent; zero source/manifest references. The page loads only `assets/css/admin/pages/analytics.css`.
- **On fail**: re-fold any still-used rules into `analytics.css`, drop the second `wp_enqueue_style` in `AnalyticsDashboard::enqueue`, and remove the manifest `frontend_assets` entry.

### 6. Every admin screen's outer wrapper is .wrap.wbgam-wrap (live DOM)
- **Action**: for each admin page below, `playwright_navigate $SITE_URL/wp-admin/admin.php?page=<slug>&autologin=1` (Settings: also click each sidebar section) and read `document.querySelector('.wrap').className`:
  - `wb-gamification` (Settings — click through Dashboard, Points, Levels, Kudos, Integrations, Rules, Access, Modules, Tools, Realtime, Emails)
  - `wb-gamification-analytics`
  - `wb-gam-point-types`
  - `wb-gamification-badges` (and `&action=new`)
- **Expect**: the `.wrap` element carries `wbgam-wrap` and NOT `wb-gam-analytics` (the analytics page wrapper modifier was dropped).
- **On fail**: the page's render method — restore `class="wrap wbgam-wrap"`.

### 7. Settings header uses the canonical page-header children (live DOM)
- **Action**: on `wb-gamification`, read the header markup.
- **Expect**: `.wbgam-page-header__main`, `.wbgam-page-header__title`, `.wbgam-page-header__desc`, `.wbgam-page-header__actions` all present; zero `wbgam-settings-topbar__brand/__title/__desc/__actions` BEM children. The version badge is a `.wbgam-pill`.
- **On fail**: `src/Admin/SettingsPage.php` header block — the topbar BEM tree was reintroduced.

### 8. Analytics period picker is a tab nav (live DOM)
- **Action**: on `wb-gamification-analytics`, inspect the period selector.
- **Expect**: `nav.wbgam-tabs.nav-tab-wrapper` with `a.nav-tab` children, the active period carrying `nav-tab-active` + `aria-current="page"`. No `class="button"` period links.
- **On fail**: `src/Admin/AnalyticsDashboard.php` — restore the `wbgam-tabs nav-tab-wrapper` markup.

### 9. Column-priority mechanism is present and breakpoint-disciplined
- **Action**: `grep -rn "wbgam-col--optional" src/Admin/ assets/js/admin-members.js`; then confirm the CSS rule lives inside a 640px block: `grep -n "wbgam-col--optional" assets/css/admin/components.css` and verify it is within a `@media (max-width: 640px)` block; then confirm the breakpoint set is still exactly {640, 782, 1024}: `grep -rhoE "max-width: [0-9]+px|min-width: [0-9]+px" assets/css/admin/components.css assets/css/admin/utilities.css | sort -u`.
- **Expect**: `.wbgam-col--optional` present on the tagged pages (PointTypes, ApiKeys, Submissions, Webhooks, RedemptionStore, Challenges, CommunityChallenges) + `admin-members.js`; the hide rule sits inside a 640px media block; no breakpoint outside {640, 782, 1024} introduced.
- **On fail**: the offending file — a `<th>`/`<td>` pair lost its `wbgam-col--optional` class, or a new breakpoint was added. Re-tag the cell or fold the breakpoint back to the canonical three.

### 10. Tagged tables show <=5 columns AND no horizontal scroll at 390px (live DOM)
- **Action**: for each tagged page, `playwright_navigate` at a 390px viewport, then for the priority table read `table.querySelectorAll('thead th:not(.wbgam-col--optional)').length` (or count visible `th` via `getComputedStyle(th).display !== 'none'`) and assert `table.scrollWidth <= table.clientWidth`. Repeat at 1280px and assert the optional columns are visible again.
- **Expect**: at 390px each tagged table renders <=5 visible columns and does not horizontally scroll; at 1280px the optional columns return.
- **On fail**: the page's table — either too many columns remain non-optional (tag more), or `.wbgam-table--priority` is missing so the 640px min-width floor still forces scroll.

### 11. Rich empty states on every upgraded listing
- **Action**: visit each upgraded surface (PointTypes, PointTypeConversions, Webhooks, RedemptionStore txns, Settings recent-earnings + actions-log, Analytics top-actions + top-earners, Members no-results, Submissions) with the listing empty and confirm the `.wbgam-empty` component (`.wbgam-empty-icon` + `.wbgam-empty-title` + `<p>`) renders; then `grep -rnE '<p[^>]*>No .* yet' src/Admin/ | grep -v 'wbgam-empty-row'`.
- **Expect**: the rich component renders on each surface; the grep returns 0 (no bare "No ... yet" paragraphs, excluding the in-table `.wbgam-empty-row`).
- **On fail**: the offending file — a bare `<p>No ... yet</p>` was reintroduced. Swap to the `.wbgam-empty` component (icon-font glyph from `assets/fonts/lucide.css` + `.wbgam-empty-title` + body).

### 12. Reduced-motion guard present and effective
- **Action**: `grep -n "prefers-reduced-motion" assets/css/admin/utilities.css`; then live with Playwright `emulateMedia({ reducedMotion: 'reduce' })`, trigger a toast (or load the Members roster) and read `getComputedStyle(node).animationDuration` / `transitionDuration`.
- **Expect**: the universal `@media (prefers-reduced-motion: reduce)` guard is present in `utilities.css`; under reduced-motion emulation the toast/skeleton animation-duration is ~0 (0.01ms).
- **On fail**: `assets/css/admin/utilities.css` — the guard was removed or scoped under `.wrap` (the toast container lives outside `.wrap`, so the guard must stay universal).

### 13. Settings save surfaces as a toast, not a WP-core notice (live DOM)
- **Action**: on `wb-gamification`, change a setting (e.g. Kudos daily limit) and submit a non-REST settings form; after the redirect read the DOM for `.wb-gam-toast` and for any `settings_errors()`-emitted `.notice`. Also `grep -n "settings_errors( 'wb_gamification' )" src/Admin/SettingsPage.php`.
- **Expect**: the save success renders as a `.wb-gam-toast` (fed from `window.wbGamSettingsToast` via `admin-rest-utils.js`); zero `settings_errors`-emitted `.notice` divs in the DOM; the grep for a `settings_errors( 'wb_gamification' )` render call returns 0 (the `get_settings_errors()` stash read in `handle_save()` is expected and is not a render call).
- **On fail**: `src/Admin/SettingsPage.php` — the `settings_errors()` render call was reintroduced, or `enqueue_emails_form()` stopped localizing `wbGamSettingsToast`, or the bootstrap in `assets/js/admin-rest-utils.js` was removed.

## Pass criteria

ALL of the following hold:
1. `grep wbgam-settings-card src/Admin/` → 0.
2. `grep wb-gam-analytics__panel src/Admin/` → 0.
3. `grep` for literal `class="button button-primary|link"` / `button-link-delete` in `src/Admin/` (excluding SetupWizard) → 0.
4. `grep "widefat striped" src/Admin/` (excluding SetupWizard) → 0.
5. `assets/css/admin-analytics.css` does not exist and is referenced nowhere.
6. Every audited admin `.wrap` carries `wbgam-wrap`; the analytics wrapper has no `wb-gam-analytics` modifier.
7. Settings header + Analytics period picker render the canonical vocabulary in the live DOM.
8. `.wbgam-col--optional` present on the 7 tagged pages + `admin-members.js`; its hide rule sits inside a 640px block; breakpoint set still exactly {640, 782, 1024}.
9. Each tagged table renders <=5 visible columns AND `scrollWidth <= clientWidth` at 390px; optional columns return at 1280px.
10. The rich `.wbgam-empty` component renders on every upgraded listing; `grep -rnE '<p[^>]*>No .* yet' src/Admin/` (excl. `.wbgam-empty-row`) → 0.
11. The `@media (prefers-reduced-motion: reduce)` guard is present in `utilities.css`; toast/skeleton animation-duration ~0 under reduced-motion emulation.
12. Settings save renders as a `.wb-gam-toast`; zero `settings_errors`-emitted `.notice` in the DOM; no `settings_errors( 'wb_gamification' )` render call in source.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| `wbgam-settings-card` grep hit | Settings card dialect reintroduced | `src/Admin/SettingsPage.php` |
| `wb-gam-analytics__panel` grep hit | Analytics panel dialect reintroduced | `src/Admin/AnalyticsDashboard.php` |
| `class="button button-primary"` grep hit | WP-core button on our screen | the offending `src/Admin/*.php` (+ `assets/js/admin-levels.js` if JS-rendered) |
| `widefat striped` grep hit | WP-core table on our screen | the offending `src/Admin/*.php` |
| `admin-analytics.css` present | Legacy stylesheet not retired | `src/Admin/AnalyticsDashboard.php::enqueue`, `audit/manifest.json` |
| Settings header shows `wbgam-settings-topbar__brand` | Topbar BEM tree reintroduced | `src/Admin/SettingsPage.php` header |
| Period picker renders `class="button"` | Period picker not migrated to tab nav | `src/Admin/AnalyticsDashboard.php` |
| Tagged table horizontally scrolls at 390px | `.wbgam-table--priority` missing (640px min-width floor still applies) | the offending `src/Admin/*.php` table + `assets/css/admin/components.css` |
| Tagged table shows >5 columns at 390px | a `<th>`/`<td>` lost its `wbgam-col--optional` class | the offending `src/Admin/*.php` (or `admin-members.js` for Members) |
| `<p>No ... yet</p>` grep hit | bare empty-state text reintroduced | the offending `src/Admin/*.php` — swap to `.wbgam-empty` |
| Toast/skeleton still animates under reduced-motion | reduced-motion guard removed or scoped under `.wrap` | `assets/css/admin/utilities.css` |
| Settings save shows a WP-core `.notice` | `settings_errors()` render call reintroduced, or toast localization/bootstrap removed | `src/Admin/SettingsPage.php`, `assets/js/admin-rest-utils.js` |
| Members roster renders `widefat striped` | last shell holdout regressed | `assets/js/admin-members.js` render() |
