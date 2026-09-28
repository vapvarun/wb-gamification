---
journey: popup-shell-family
plugin: wb-gamification
priority: high
roles: [member, administrator]
covers: [popup-shells, moment-card, toast-core, confetti, hub-drawer, convert-dialog, deactivation-survey, kudos-menu, bell-types, motion-tokens]
prerequisites:
  - "Site reachable at $SITE_URL with BuddyNext active and a hub page"
  - "A member with points; one active currency conversion rule (create a temporary point type and rule with wp eval, delete both after)"
  - "An administrator"
estimated_runtime_minutes: 15
---

# Pop-ups: one shell family, one motion scale

Every overlay is one of six shells in `assets/css/popups.css` and reads `--wb-gam-pop-*` tokens that
read BuddyNext first. If this regresses, surfaces drift back to their own radius, shadow, scrim and
timing, the family stops matching BuddyNext, and a member sees two different products.

## Setup

- Member via `?autologin=<member login>`, admin via `?autologin=1`.
- Queue events for the member with `do_action()` through `wp eval` (`wb_gam_level_changed`,
  `wb_gam_streak_milestone`, `wb_gam_cohort_outcome` promoted, `wb_gam_community_goal_reached`,
  `wb_gam_submission_approved`, `wb_gam_submission_rejected`). Delete the queue rows, the bell rows
  and the temporary point type and rule afterwards.

## Steps

### 1. Shells share one look (computed style, 1440px)
- **Action**: open the hub drawer, the convert dialog, the redemption confirm, and (admin) a confirm
  dialog; show a toast and a Moment card.
- **Expect**: every dialog and the drawer share the surface colour, `--wb-gam-pop-shadow-lg`, a scrim of
  `--wb-gam-pop-scrim` (blurred 2px on dialogs and the Moment card, NOT on the drawer), header title 18px/600 (drawer 16px), a 40px `.wb-gam-close`, and footer
  buttons 40px tall (`.wb-gam-btn`). The toast is the inverted pill at the bottom centre.
  Banners are measured on the node that paints the words: `.wb-gam-banner__text` in a shown success and danger banner must be at least 4.5:1 against the banner background in light and dark, and must stay so after injecting `span{color:#fff} .is-success span{color:#fff} button{color:#fff}` (a host rule must not win).
- **On fail**: `assets/css/popups.css`; a surface carrying its own shell rules (`assets/css/hub.css`,
  `src/Blocks/redemption-store/style.css`).

### 2. Moment cards: one at a time, with confetti
- **Action**: queue a level-up and a league promotion together; load a page; press Awesome on each.
- **Expect**: one `.wb-gam-moment` at a time, the second appears after the first is dismissed; confetti
  pieces (`.wb-gam-cf--1..5`) run on open; Escape and the button both dismiss. With
  `prefers-reduced-motion: reduce` there are no confetti pieces (a ring instead) and the numeral does not animate.
- **On fail**: `assets/interactivity/notifications.js`, `assets/js/celebrate.js`, `assets/css/popups.css` reduced-motion block.

### 3. Toasts merge, cap, and sit above dialogs
- **Action**: queue five points events for one action within two seconds, plus a badge; then open a hub
  panel and trigger another toast.
- **Expect**: the repeated action is ONE toast ("+N Points", body "x count"); at most three toasts are
  visible; the toast host is a manual popover and paints above the open `dialog`; a badge toast fires a
  small confetti burst.
  A one-line toast is vertically centred: the `.wb-gam-toast__message` box centre equals the icon disc centre (a hidden link or detail must take no space).
  On a BuddyNext page a toast with a link (`wbGam.toast({href:'/members/'})`) is still on screen after 6s, a plain toast is gone, and a `//host/x` link is dropped.
  On a BuddyNext page the toasts render inside BuddyNext's single `.bn-toast-container` (no `.wb-gam-toasts` host is created), a BuddyNext toast fired at the same moment never overlaps them at 1440 or 390, and same-key updates repaint in place. In wp-admin (no `window.bnToast`) the plugin's own renderer is used.
- **On fail**: `assets/js/toast.js` `pointsToast()`, `assets/js/toast-core.js` `viaBuddyNext()`, `assets/js/toast-core.js` `raise()` / `MAX_VISIBLE`.

### 4. Convert dialog, hub drawer, survey and kudos menu use the shared shells (390px)
- **Action**: at 390px open the convert dialog (`[data-wb-gam-convert-open]`), the Kudos panel, type two
  letters in Recipient, then (admin, Plugins screen) click Deactivate on WB Gamification and close with the x.
- **Expect**: the convert dialog is 358px wide inside a 16px gutter with no horizontal page scroll; the
  drawer is full width; the recipient list is `.wb-gam-menu` (z-index 99000) with 40px option rows; the
  survey is `dialog.wb-gam-dialog` with the same header, footer and buttons, and the x cancels WITHOUT deactivating.
- **On fail**: `src/Blocks/hub/render.php`, `src/Engine/ShortcodeHandler.php` (menu classes),
  `src/Admin/DeactivationFeedback.php`, `assets/js/dialog.js` (`[data-wb-gam-dialog-close]`).

### 5. New moments reach the bell
- **Action**: fire `wb_gam_cohort_outcome` (promoted), `wb_gam_community_goal_reached`,
  `wb_gam_submission_approved` and `wb_gam_submission_rejected` for the member; open `/notifications/`.
- **Expect**: one row per event under the Achievements group (types `cohort_promotion`, `community_goal`,
  `submission_result`); a demotion creates no row; the reviewer's private note is NOT in the row text.
  `object_type` is 20 characters or fewer after BuddyNext's prefix (its column is `varchar(32)`).
- **On fail**: `src/Engine/CommunityNotifications.php` (handlers, `filter_types`).

### 6. No new raw motion values
- **Action**: `bash bin/check-motion-tokens.sh`.
- **Expect**: PASS. Adding `transition: opacity 0.3s ease` to any component stylesheet FAILS it by file name.
- **On fail**: move the value to a `--wb-gam-dur*` / `--wb-gam-ease*` token.

## Pass criteria

ALL of the following hold:
1. Every dialog, drawer, toast and Moment matches the shared shell values above.
2. Moment cards queue one at a time, and confetti obeys reduced motion.
3. Repeated points merge into one toast, at most three are visible, and toasts paint above dialogs.
4. The four migrated surfaces fit 390px and use the shared shells; the survey x does not deactivate.
5. The new events create bell rows, a demotion creates none, no private note leaks.
6. The motion-token gate passes and fails when a raw value is added.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A dialog has a different radius or shadow | surface reintroduced its own shell rules | that surface's stylesheet; `assets/css/popups.css` |
| Bell row missing for one new event | `object_type` too long for BuddyNext's `varchar(32)` (the receiver drops the insert silently) | `src/Engine/CommunityNotifications.php` |
| Toast hidden behind an open dialog | host not re-shown as a popover | `assets/js/toast-core.js` `raise()` |
| Confetti runs under reduced motion | reduced-motion guard lost | `assets/js/celebrate.js` |
| Survey x deactivates the plugin | x wired to skip instead of `[data-wb-gam-dialog-close]` | `src/Admin/DeactivationFeedback.php` |
