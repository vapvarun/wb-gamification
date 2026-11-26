---
journey: setup-from-your-plugins
plugin: wb-gamification
priority: critical
roles: [administrator, member]
covers: [setup, plugin-off, pace, rerun-keeps-values, skip, launch-writes-only-existing-options, ledger, 390, rtl, standalone, buddynext]
prerequisites:
  - "Site on Reign 8.1.2; logged in as an administrator"
estimated_runtime_minutes: 15
---

# Setup starts from the plugins on the site (1.7.0)

Card 10549519268 (absorbs 10521596194). Code: `SetupWizard::render()`, `SetupWizard::plan()` (tested by
`tests/Unit/Admin/SetupPlanTest.php`), `assets/js/admin-setup.js` (`tests/js/admin-setup.test.js`).

## Steps

### 1. What the owner sees
- **Action**: open Gamification > Tools > Setup (or the first-activation redirect).
- **Expect**: four steps, one at a time. Step 1 lists every plugin with actions on the site (a Pro
  add-on is one card with its plugin, "+ Pro"), its count and three example amounts; WordPress basics
  always included; "Also works with" names supported plugins that are not installed. Nothing says
  "Unavailable".

### 2. Choose and launch
- **Action**: keep every plugin except one (for example WB Member Blog); pace Balanced; Launch.
- **Expect**: Overview with "Finish setting up"; `wb_gam_enabled_*` is 1 for the kept plugins' actions
  and 0 for the switched-off plugin's. Choosing a plugin whose actions were all off starts them on.

### 3. The ledger follows the choice
- **Action**: publish a post as a member or the owner.
- **Expect**: one `wp_publish_post` row in `wp_wb_gam_points`. Re-run Setup, untick "Publish a blog post",
  Launch, publish again: no new row.

### 4. Re-run keeps the owner's values
- **Expect**: the re-run opens with the site's values (the switched-off plugin unticked, amounts as set),
  pace "Keep my limits" selected; launching with it leaves `wb_gam_action_overrides` unchanged.

### 5. Skip
- **Action**: on a site that has not finished Setup, press "Skip setup, I'll configure it myself".
- **Expect**: no action switch, amount or limit changes; Overview opens; members earn with each
  plugin's own values, as the copy says.

### 6. Phone and RTL
- **Expect**: at 390 no sideways scroll, 12px gutters, 40px controls; RTL mirrors.
