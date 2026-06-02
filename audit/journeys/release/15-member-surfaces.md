---
journey: member-surfaces
plugin: wb-gamification
priority: high
roles: [member]
covers: [member-surface, buddypress-achievements-tab, woocommerce-account-endpoint, learndash-profile-link]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wb-gamification active"
  - "A Hub page is mapped (wb_gam_hub_page_id set)"
  - "For step group A: BuddyPress active"
  - "For step group B: WooCommerce active"
  - "For step group C: LearnDash active (LEARNDASH_VERSION defined)"
estimated_runtime_minutes: 6
---

# Member achievement surfaces - BuddyPress tab, WooCommerce endpoint, LearnDash link

1.5.2 ships a shared `MemberSurface` renderer that mounts gamification on the
member-facing surfaces of the host platforms, so a member sees their
achievements without an admin building a Hub page by hand. Three mounts:

- BuddyPress: an "Achievements" profile tab (slug `achievements`) with four
  sub-tabs - overview / badges / points / streak - each rendering existing
  blocks scoped to the displayed member.
- WooCommerce: a My Account endpoint at `/my-account/achievements/` that renders
  the full Hub dashboard for the current customer.
- LearnDash: a single opt-in "My Achievements" link on the course profile,
  OFF by default, controlled by `wb_gam_learndash_profile_link`.

Every surface routes through `MemberSurface::render()`, which wraps the inner
block markup in `<div class="wb-gam-bp-achievements">` and appends a "View full
dashboard" link to the MAPPED hub page (never a hardcoded slug). If these
regress, members lose the in-platform entry point to their points/badges and
fall back to a manually built Hub page that may not exist.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Test member: a non-admin member with some points/badges; substitute its
  `username` / `user_id` below
- Hub page mapped: `wp eval 'echo (int) get_option("wb_gam_hub_page_id");'` is non-zero
- No DB cleanup - read-only display journey

## Steps

### A1. BuddyPress "Achievements" tab is registered with 4 sub-tabs
- **Action**: `wp eval` to read the registered nav:
  ```php
  wp eval '
    echo "tab=" . ( WBGam\BuddyPress\ProfileIntegration::class && method_exists("WBGam\\BuddyPress\\ProfileIntegration","setup_nav") ? "class-ok" : "MISSING" ) . "\n";
  '
  ```
- **Action**: `playwright_navigate http://wb-gamification.local/members/<username>/achievements/?autologin=<username>`
- **Wait**: ~2s
- **Action**: `playwright_evaluate`:
  ```js
  const subs = [...document.querySelectorAll('#subnav a, .item-list-tabs#subnav a, #object-nav a')].map(a => a.getAttribute('href')||'');
  const want = ['overview','badges','points','streak'];
  return want.map(s => ({ slug: s, present: subs.some(h => h.includes('/achievements/'+s)) }));
  ```
- **Expect**: all four of overview / badges / points / streak resolve to a sub-nav link under `/achievements/`.
- **On fail**: `src/BuddyPress/ProfileIntegration.php` `setup_nav()` no longer registers the parent nav (`NAV_SLUG = 'achievements'`, `default_subnav_slug = 'overview'`) or one of the four `bp_core_new_subnav_item` calls.

### A2. Each BP sub-tab renders MemberSurface content
For each sub-tab slug in [overview, badges, points, streak]:
- **Action**: `playwright_navigate http://wb-gamification.local/members/<username>/achievements/<slug>/?autologin=<username>`
- **Action**: `playwright_evaluate`:
  ```js
  return {
    hasSurface: !!document.querySelector('.wb-gam-bp-achievements'),
    hasBlock: !!document.querySelector('[class*="wp-block-wb-gamification-"]')
  };
  ```
- **Expect**: `hasSurface: true` and `hasBlock: true` on all four sub-tabs (overview shows member-points + streak; badges shows badge-showcase; points shows points-history; streak shows streak).
- **On fail**: `ProfileIntegration::screen_content()` tag-mapping per sub-tab, or `MemberSurface::render()` wrapper class `wb-gam-bp-achievements`. See `src/BuddyPress/ProfileIntegration.php` + `src/Engine/MemberSurface.php`.

### B1. WooCommerce /my-account/achievements/ endpoint is registered
- **Action**:
  ```bash
  wp eval 'echo in_array("achievements", (array) apply_filters("woocommerce_get_query_vars", []), true) || array_key_exists("achievements", (array) apply_filters("woocommerce_get_query_vars", [])) ? "qv-ok" : "check-render";'
  ```
- **Action**: `playwright_navigate http://wb-gamification.local/my-account/achievements/?autologin=<username>`
- **Action**: `playwright_evaluate`:
  ```js
  const items = [...document.querySelectorAll('.woocommerce-MyAccount-navigation a')].map(a => a.textContent.trim());
  return {
    menuHasAchievements: items.includes('Achievements'),
    hasSurface: !!document.querySelector('.wb-gam-bp-achievements'),
    hasHubBlock: !!document.querySelector('.wb-gam-hub, [class*="wp-block-wb-gamification-"]')
  };
  ```
- **Expect**: `menuHasAchievements: true`, `hasSurface: true`, `hasHubBlock: true` - the endpoint renders the full Hub dashboard for the current customer.
- **On fail**: `src/Integrations/WooCommerce/AccountIntegration.php` - `add_rewrite_endpoint`, the `woocommerce_get_query_vars` filter, the `woocommerce_account_menu_items` item, or the `woocommerce_account_achievements_endpoint` render action. If the page 404s, the rewrite endpoint was not flushed (`wb_gam_wc_account_endpoint_v1` option).

### B2. The "View full dashboard" link points at the MAPPED hub page
- **Action**: on the same My Account endpoint page, `playwright_evaluate`:
  ```js
  const more = document.querySelector('.wb-gam-bp-achievements__more a');
  return more ? more.getAttribute('href') : null;
  ```
- **Action**: compare against `wp eval 'echo get_permalink( (int) get_option("wb_gam_hub_page_id") );'`
- **Expect**: the link href equals the mapped hub page permalink (resolved from `wb_gam_hub_page_id`, never a hardcoded slug).
- **On fail**: `MemberSurface::hub_link()` resolves the wrong option or hardcodes a slug. See `src/Engine/MemberSurface.php`.

### C1. LearnDash link is ABSENT by default
- **Action**:
  ```bash
  wp eval 'echo has_action("learndash_shortcode_profile_before_template", ["WBGam\\Integrations\\LearnDash\\ProfileIntegration","render"]) ? "PRESENT" : "absent";'
  ```
- **Expect**: prints `absent`. `LearnDash\ProfileIntegration::init()` returns early because `wb_gam_learndash_profile_link` defaults to false, so the render action is never added.
- **On fail**: the filter default flipped to true, or `init()` adds the action unconditionally. See `src/Integrations/LearnDash/ProfileIntegration.php`.

### C2. LearnDash link APPEARS when the filter is true
- **Action**:
  ```bash
  wp eval '
    add_filter("wb_gam_learndash_profile_link", "__return_true");
    WBGam\Integrations\LearnDash\ProfileIntegration::init();
    echo has_action("learndash_shortcode_profile_before_template", ["WBGam\\Integrations\\LearnDash\\ProfileIntegration","render"]) ? "PRESENT" : "absent";
  '
  ```
- **Expect**: prints a priority (e.g. `10`), i.e. `PRESENT` - the render action is now wired and `render()` prints one `<a class="wb-gam-ld-link__btn">My Achievements</a>` resolving to the mapped hub page.
- **On fail**: `init()` does not re-evaluate the `wb_gam_learndash_profile_link` filter, or `render()` no longer resolves `wb_gam_hub_page_id`. Same file.

## Pass criteria

ALL of the following hold:

1. BuddyPress registers an "Achievements" tab (slug `achievements`) with all four sub-tabs: overview, badges, points, streak.
2. Each BP sub-tab renders MemberSurface markup (`.wb-gam-bp-achievements`) containing a gamification block for the displayed member.
3. WooCommerce `/my-account/achievements/` resolves, shows an "Achievements" menu item, and renders the Hub dashboard for the current customer.
4. The MemberSurface "View full dashboard" link points at the mapped `wb_gam_hub_page_id` permalink (not a hardcoded slug).
5. The LearnDash profile link is absent by default (`wb_gam_learndash_profile_link` = false).
6. Setting `wb_gam_learndash_profile_link` to true wires the LearnDash render action.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| BP tab missing entirely | `setup_nav()` not hooked, or `ProfileIntegration::init()` not on `bp_loaded` | `src/BuddyPress/ProfileIntegration.php` + `wb-gamification.php` `add_action('bp_loaded', ...)` |
| A BP sub-tab missing | one `bp_core_new_subnav_item` call dropped from the `$subtabs` loop | `src/BuddyPress/ProfileIntegration.php` `setup_nav()` |
| BP sub-tab empty | wrong block tags for the active sub-tab | `ProfileIntegration::screen_content()` switch on `bp_current_action()` |
| Woo endpoint 404s | rewrite endpoint never flushed on upgrade | `AccountIntegration::add_endpoint()` + `wb_gam_wc_account_endpoint_v1` option |
| Woo menu item missing | `woocommerce_account_menu_items` filter not added | `AccountIntegration::add_menu_item()` |
| "View full dashboard" wrong/missing | `hub_link()` reads wrong option or no hub page mapped | `src/Engine/MemberSurface.php` `hub_link()` |
| LearnDash link shows by default | filter default flipped, or `init()` adds action unconditionally | `src/Integrations/LearnDash/ProfileIntegration.php` `init()` |
| LearnDash link never shows even when filtered true | `init()` not re-run after filter, or no hub page mapped | same file + `wb_gam_hub_page_id` |
