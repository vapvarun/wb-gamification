---
journey: admin-card-spacing
plugin: wb-gamification
priority: normal
roles: [administrator]
covers: [admin-layout, card-gap, basecamp-10483141467]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one reward and one redemption in the store (or none, for step 2)"
estimated_runtime_minutes: 5
---

# Admin: every card is separated from the next one

Two admin cards that touch read as one block, so an owner cannot tell where "All Rewards" ends and
"Recent Redemptions" starts. The gap comes from one rule (`.wbgam-card + .wbgam-card` in
`assets/css/admin/components.css`), so it holds on every admin page whether or not a card remembers
the `wbgam-stack-block` class.

## Setup

- Site: `$SITE_URL`, admin `?autologin=1`. Viewports 1440 and 390.

## Steps

### 1. Cards that follow cards keep a gap
- **Action**: open `wp-admin/admin.php?page=wb-gam-redemption`. In the console run
  `[...document.querySelectorAll('.wbgam-wrap .wbgam-card + .wbgam-card')].map(c => getComputedStyle(c).marginBlockStart)`.
- **Expect**: every value is `20px`. Visually the "All Rewards" card and the "Recent Redemptions" card
  have a clear gap at 1440 and 390.
- **On fail**: `assets/css/admin/components.css` card-after-card rule, or an override with higher specificity.

### 2. Empty store still has a gap
- **Action**: same page with no rewards (or a fresh site).
- **Expect**: the "No rewards yet" box and the "Recent Redemptions" card do not touch.
- **On fail**: `src/Admin/RedemptionStorePage.php` empty state lost `wbgam-stack-block`.

### 3. Dashboard grid stays aligned
- **Action**: open `wp-admin/admin.php?page=wb-gamification` (Dashboard).
- **Expect**: "Top members" and "Top actions" cards start at the same height (side by side at 1440);
  their `marginBlockStart` is `0px`.
- **On fail**: `.wbgam-dashboard-row > .wbgam-card` reset in `assets/css/admin/pages/settings.css`.

## Pass criteria

1. No two adjacent admin cards touch on any wb-gamification admin page, at 1440 and 390.
2. The dashboard grid cards stay top-aligned.
