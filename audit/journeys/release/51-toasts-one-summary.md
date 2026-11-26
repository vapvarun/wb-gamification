---
journey: toasts-one-summary
plugin: wb-gamification
priority: high
roles: [member, administrator]
covers: [toast-merge, level-up-first, count-up, badge-picture, max-two-visible, never-dropped, focus-return, reduced-motion, theme-fit, buddynext, standalone, 390, rtl, dark]
prerequisites:
  - "Site on Reign 8.1.2; a member logged in on a front-end page; an admin in another browser"
estimated_runtime_minutes: 15
---

# Toasts: one summary, the level-up first, a look that fits any site (1.7.0)

Card 10549640016. The shared standard is TOASTS.md on the standards shelf (BuddyNext, Gamification
and QA agreed it). Automated cover: `tests/js/toast-core.test.js` and `tests/js/toast-summary.test.js`
(10 of the new tests fail on the 1.6.9 files).

## Steps

### 1. Several awards at once become one toast
- **Action**: as the admin, award the member twice within a second (Members > Award points, or
  `POST /wb-gamification/v1/points/award`); better, have the member post something that also earns a badge.
- **Expect**: ONE toast on the member's page: "+N Points" (or "+N Points and a new badge"), the
  detail lists what earned it ("Manual award ×2", or the action, the streak, the badge name), the
  number counts up, the badge's picture fills the round icon.

### 2. The biggest moment first
- **Action**: award enough to reach the next level.
- **Expect**: the level-up celebration shows alone; the summary toast appears only after it closes,
  never over it.

### 3. First points
- **Action**: a member with no points earns their first.
- **Expect**: "+N Points. Welcome aboard!" / "Your first points in <site>" / [See progress], closing after 8s.

### 4. Never more than two, nothing dropped
- **Action**: queue four separate toasts (for example an approval, a limit notice and two awards a few seconds apart).
- **Expect**: two at most on a desktop, one on a phone; the rest follow 600ms after one closes.

### 5. Look and access
- **Action**: check at 1280 and 390, light and dark, RTL, and on one plain block theme (Twenty
  Twenty-Five); tab to a toast's button, then let it close; turn on reduced motion.
- **Expect**: 360px wide (the screen minus 12px on a phone), one height, the site's font and the
  theme's accent (no plugin purple), clear of BuddyNext's bottom bar; RTL mirrors; focus goes back
  where it was; reduced motion shows the final number without counting.

### 6. BuddyNext
- **Action**: repeat step 1 on a BuddyNext page.
- **Expect**: one toast through BuddyNext's stack, never a literal "{count}". After BuddyNext's own
  rebuild (bnToast.supports.count) the number counts up and the badge picture shows there too.

## Pass criteria

1. One summary per burst, level-up first, nothing dropped.
2. Same shape on every site, in the site's own font and accent.
3. Accessible: one announcement, focus return, reduced motion.
