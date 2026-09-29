---
journey: admin-menu-and-delegated-staff
plugin: wb-gamification
priority: high
roles: [administrator, staff-with-plugin-caps]
covers: [submenu-order, dashboard-label, delegated-levels, delegated-submissions, table-scroll-390, narrow-input-help]
prerequisites:
  - "Site reachable at $SITE_URL with seeded levels, streaks and members"
  - "WP-CLI available to create and remove one temporary user"
estimated_runtime_minutes: 10
---

# Admin menu: workflow order, and staff who hold only the caps they need

An owner should find pages in the order they work, and a staff member granted one capability must
reach the page that capability unlocks. If this regresses, the menu goes back to load order, a
delegated level editor or submissions moderator sees an empty menu, or a table pushes the page
sideways on a phone.

## Setup

- Site: `$SITE_URL`, admin `?autologin=1`.
- Temporary user (delete afterwards): `qa_staff` with role subscriber plus the caps
  `wb_gam_manage_levels` and `wb_gam_manage_submissions`.

## Steps

### 1. Submenu order and label
- **Action**: as admin, read the Gamification submenu.
- **Expect**: Dashboard, Import, Badges, Levels, Challenges, Redemption Store, Point Types, Conversions,
  Point Multipliers, Analytics, Members, Award Points, Streaks, Kudos Moderation, Submissions, Webhooks,
  API Keys (a switched-off module's page is absent). The first entry reads "Dashboard", not "Gamification".
  A page this plugin does not list stays after the listed ones.
- **On fail**: `SettingsPage::order_submenu()`, `MENU_ORDER`, `tests/Unit/Admin/MenuOrderTest.php`.

### 2. A staff member sees exactly the pages their caps unlock
- **Action**: log in as `qa_staff`, open `?page=wb-gam-levels`, add a level through the form, delete it;
  open `?page=wb-gam-submissions`; open `?page=wb-gamification` and `?page=wb-gamification-import`.
- **Expect**: the menu lists only Levels and Submissions; Levels renders and add and delete succeed;
  Submissions renders; Settings and Import answer 403.
- **On fail**: `LevelsPage::add_submenu()`, `SubmissionsPage::register_page()` capability argument.

### 3. Levels live on their own page, not in Settings
- **Action**: as admin open Settings.
- **Expect**: the sidebar "Levels" item links to `?page=wb-gam-levels`; there is no `#levels` section;
  the setup checklist "Add levels" link goes to the same page.
- **On fail**: `SettingsPage` nav and checklist `action_url`.

### 4. Tables stay tables at 390px
- **Action**: at 390px open Award Points, Redemption Store, Streaks, Kudos Moderation and Levels with data.
- **Expect**: no horizontal page scroll; every `.wbgam-table` sits inside `.wbgam-table-scroll`; streak
  and kudos rows keep one height with the member and action cells aligned; level names are readable.
- **On fail**: `assets/css/admin/components.css` `.wbgam-table-scroll`, the page CSS.

### 5. Help text sits under a short input
- **Action**: open Settings > Engagement (Habits & Profiles) at 1440 and 390.
- **Expect**: the description under Grace days and Streak milestone bonus starts below the input.
- **On fail**: `assets/css/admin/pages/settings.css` `.wb-gam-input-narrow + .description`.
