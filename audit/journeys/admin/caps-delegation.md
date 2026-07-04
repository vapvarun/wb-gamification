---
journey: caps-delegation
plugin: wb-gamification
priority: high
roles: [administrator, editor]
covers: [BC-10061740753, capabilities, delegation, access-control]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "An Editor-role test user exists (e.g. qa_editor)"
estimated_runtime_minutes: 6
---

# Staff-permission delegation — grant plugin caps without a role-editor plugin

The plugin registers granular caps but, before 1.6.2, they were ungrantable
without a third-party role editor, and the member roster / streak / kudos pages
hardcoded `manage_options` so a delegate couldn't reach them. This journey
locks the delegation UI (Settings › Access → Staff permissions) and the new
`wb_gam_manage_members` cap (BC 10061740753).

## Setup

- Access tab: `$SITE_URL/wp-admin/admin.php?page=wb-gamification&tab=access#access` (admin autologin `&autologin=1`)
- Editor login: `?autologin=qa_editor`

## Steps

### 1. Matrix renders
- **Action**: as admin, open Settings › Access.
- **Expect**: a `.wb-gam-caps-matrix` with one row per plugin cap (from Capabilities::labels()) and one column per editable role; an Administrator column whose checkboxes are checked + disabled (locked on).
- **On fail**: `SettingsPage::render_staff_permissions_card`.

### 2. Grant a cap and it persists
- **Action**: tick "Manage members" and "Manage badges" for Editor, Save Access Settings. Reload.
- **Expect**: both boxes are still checked (the matrix reads `WP_Role::has_cap`, so a checked box means the cap was granted). Webhooks (not ticked) stays unchecked.
- **On fail**: `SettingsPage::save_staff_permissions` (grant path), or the `wb_gam_caps_form` marker missing so the block was skipped.

### 3. Delegate sees only the granted surfaces
- **Action**: log in as the Editor (`?autologin=qa_editor`), open the Members page.
- **Expect**: the Members roster renders (no permission error). The Gamification submenu shows ONLY Members, Streaks, Kudos Moderation, Badges — NOT API Keys, Redemption Store, Webhooks, Point Types, or Settings sub-items.
- **On fail**: page menu cap (`add_submenu_page` cap arg) or `Capabilities::user_can` in the page's render.

### 4. Admin-only surfaces stay denied
- **Action**: as the Editor, visit `?page=wb-gam-api-keys`.
- **Expect**: WordPress capability error (denied) — API Keys is intentionally `manage_options`-only.

### 5. Admin never loses access
- **Action**: as admin, confirm Members / Streaks / Kudos Moderation are still in the menu.
- **Expect**: present — `Capabilities::sync()` grants `wb_gam_manage_members` to administrator on the CAPS_VERSION bump (1.4 → 1.5), so migrating those pages to the granular cap does not hide them from admins.
- **On fail**: `Capabilities::sync` not hooked, or CAPS_VERSION not bumped.

### 6. Reset to defaults
- **Action**: as admin, tick "Reset to defaults" in the matrix, Save.
- **Expect**: every non-admin role loses all plugin caps (all Editor boxes clear); Administrator column stays on.
- **On fail**: `SettingsPage::save_staff_permissions` (reset branch).

## Pass criteria

1. Matrix renders (caps × roles, Administrator locked).
2. Granting caps persists across reload.
3. A delegated Editor accesses exactly the granted surfaces and no more.
4. Admin-only pages deny the Editor.
5. Admins retain the migrated pages (sync grants the new cap).
6. Reset-to-defaults clears non-admin caps.

## Fail diagnostics

| Symptom | Likely cause | File |
|---|---|---|
| Matrix absent | render not called | `SettingsPage::render_access_section` |
| Grant doesn't persist | marker/guard or save loop | `SettingsPage::save_staff_permissions` |
| Admin lost the Members menu | sync didn't grant new cap | `Capabilities::sync` / `CAPS_VERSION` |
| Editor sees admin-only pages | page still on granular cap it shouldn't be | the page's `add_submenu_page` cap arg |
| Editor denied a granted page | menu cap vs render cap mismatch | page `add_submenu_page` + render `user_can` |
