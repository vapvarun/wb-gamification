---
journey: one-admin-menu
plugin: wb-gamification
priority: high
roles: [administrator, staff-with-award-cap]
covers: [admin-menu-groups, menu-open-on-hidden-screens, every-screen-once, old-slugs-open, module-gates, rtl, 390]
prerequisites:
  - "Site reachable at $SITE_URL on Reign 8.1.2; logged in as an administrator"
estimated_runtime_minutes: 10
---

# One admin menu in six groups (1.7.0)

Card 10549517915 (absorbs 10521831740 and 10522347291). Before 1.7.0 an owner met two menus: the
WordPress submenu (17 items, load order) and a second side menu inside Settings (17 more), with six
screens in both, and Community Challenges opened with the Gamification menu closed and no side menu.
`src/Admin/AdminNav.php` is now the single list.

Automated cover: `tests/Unit/Admin/AdminNavTest.php` (six groups whatever the load order, every
screen once, a partner page kept, module and capability gates, hidden screens mapped).

## Steps

### 1. The WordPress menu
- **Action**: open Gamification > Dashboard at 1280.
- **Expect**: the Gamification submenu lists exactly Overview, Earning, Rewards, Members, Experience,
  Tools. The side menu shows the same six groups, Overview open with Dashboard current.

### 2. A Settings section by address
- **Action**: open `admin.php?page=wb-gamification#points`.
- **Expect**: WordPress highlights Earning; the side menu has only Earning open, "Actions and points" current.
- **On fail**: `assets/js/settings-nav.js` `markGroup()`.

### 3. Every screen, the menu stays open
- **Action**: open each old slug: wb-gam-levels, wb-gamification-badges, wb-gam-challenges,
  wb-gam-community-challenges, wb-gamification-members, wb-gamification-award, wb-gam-webhooks,
  wb-gamification-setup.
- **Expect**: each opens (no "not allowed"), the Gamification menu is open with its group bold, the
  side menu shows the group open and the item current, no horizontal scroll. Community Challenges
  marks Challenges; Setup marks Overview and has no side menu.
- **On fail**: `AdminNav::relist()` must run on admin_head (access is checked against the submenu
  before that); `CommunityChallengesPage` must register under `wb-gamification`.

### 4. Gates
- **Action**: switch the Kudos module off (Settings > Modules); log in as a staff user who only has
  `wb_gam_award_manual`.
- **Expect**: Kudos settings and Kudos moderation disappear from the menu; the staff user sees only
  Members > Award points.

### 5. Phone and RTL
- **Action**: repeat step 3 for one page at 390, and with the site in an RTL language.
- **Expect**: groups stack above the page, 44px rows, no sideways scroll; RTL mirrors the side menu
  (right side, chevrons pointing left when closed).

## Pass criteria

1. Six groups in both menus; every screen reachable from exactly one item.
2. Every old address opens its screen with the menu open.
3. Module and capability gates hold.
