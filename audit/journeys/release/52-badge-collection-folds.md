---
journey: badge-collection-folds
plugin: wb-gamification
priority: medium
roles: [member, visitor]
covers: [badge-showcase, show-all, per-tab-fold, header-count, limit-attribute, no-js, 390]
prerequisites:
  - "Site on Reign 8.1.2; a member with 13 or more badges in a tab (earned plus locked with show_locked)"
estimated_runtime_minutes: 5
---

# A big badge collection shows 12 per tab and "Show all N badges" (1.7.0)

Card 10531044195. Automated cover: `tests/js/badge-showcase.test.js` (3 of 4 fail on the 1.6.9 file).
On BuddyNext sites the profile grid is BuddyNext's own panel; its half is on BuddyNext's board.

## Steps

### 1. Fold and unfold
- **Action**: open a page with the Badge Showcase block (`show_locked`) for a member with 26 badges.
- **Expect**: header "1 / 26 badges earned"; 12 cards; a full-width "Show all 26 badges" button. Click it:
  all 26 show, the button goes, focus is on card 13.

### 2. Tabs
- **Action**: choose Earned, then Locked, then All.
- **Expect**: each tab folds on its own count (no button when it has 12 or fewer); a new tab starts folded.

### 3. The limit attribute
- **Action**: set the block's limit to 12 for a member with 28 badges.
- **Expect**: the header still says "N / 28" (before 1.7.0 it said "N / 12").

### 4. Owner setting and no JavaScript
- **Action**: `add_filter( 'wb_gam_badge_showcase_page_size', fn() => 0 );` then reload; separately, load with JavaScript off.
- **Expect**: with 0 every card shows and no button; without JavaScript every card shows.

### 5. Phone
- **Action**: step 1 at 390.
- **Expect**: 44px button, no sideways scroll.
