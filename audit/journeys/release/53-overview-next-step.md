---
journey: overview-next-step
plugin: wb-gamification
priority: medium
roles: [administrator]
covers: [overview, checklist-real-state, kpi-next-step, manual-share-note, awarded-by-hand, challenges-module-off, 390, rtl]
prerequisites:
  - "Site on Reign 8.1.2; logged in as an administrator"
estimated_runtime_minutes: 8
---

# The Overview says what to do next (1.7.0)

Card 10549519341. Code: `SettingsPage::render_dashboard_tab()`, `compute_setup_checklist()`,
`hub_in_menu()`, `ManualAwardPage::ACTION_IDS`.

## Steps

### 1. The checklist reads the site
- **Action**: open Gamification > Overview > Dashboard on a fresh install.
- **Expect**: "Award points" and "View as a member" first; "Finish setting up, N of 4" with Choose your
  plugins and rewards, See a toast as a member (button "Send me a test toast"), Add the Gamification
  page to your menu, Create a first challenge.
- **Action**: add the Gamification page to a menu (Appearance > Menus, or the block theme's
  Navigation); create a challenge; press "Send me a test toast".
- **Expect**: after a reload each item shows a tick; when all are done the card is gone.

### 2. Numbers and their next step
- **Expect**: four tiles (points awarded, members earning "of N members", badges earned, challenges
  completed). With no challenge at all the last tile says "Create your first challenge"; with one
  that nobody finished it shows just 0. Challenges module off: tile and checklist item hidden.

### 3. Hand awards apart
- **Action**: award points by hand (Members > Award points, or `wp wb-gamification points award`).
- **Expect**: "Top actions, 30 days" lists earned actions; hand awards are one "Awarded by hand"
  line whichever way they were given (manual, manual_award). At 30% or more of the month a
  "Needs a look" note appears with "See manual awards".

### 4. Phone and RTL
- **Expect**: at 390 no sideways scroll, 40px buttons; RTL mirrors.
