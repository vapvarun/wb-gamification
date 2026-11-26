---
journey: toasts-close-and-never-cover
plugin: wb-gamification
priority: high
roles: [subscriber]
covers: [toast-auto-dismiss, toast-escape, toast-hold-while-typing, toast-hold-under-dialog, toast-buddynext-path, toast-fast-after-own-action]
prerequisites:
  - "Site reachable at $SITE_URL on Reign, logged-in member with a points history"
  - "Run once with BuddyNext active and once on a site without BuddyNext"
  - "Points actions for post and comment switched on (Settings > Points)"
estimated_runtime_minutes: 10
---

# Toasts close by themselves and never cover what the member is using (1.6.9)

Card 10516939110 and BuddyNext QA: a toast with a link stayed until closed (65s and more), ignored
Escape, and sat over the composer's Post button at 390. Inside BuddyNext the "wait while a popup is
open" rule never ran, because the toast was handed to BuddyNext's stack before the check. A points
toast after a BuddyNext post or comment also waited up to 60s for the next poll.

Automated cover: `npm run test:js` (tests/js/toast-core.test.js, tests/js/heartbeat.test.js); 10 of
them fail on the 1.6.8 files.

## Setup

- Member with a long history, e.g. `bn_demo_alex_rivera` (`?autologin=bn_demo_alex_rivera`).
- Viewports 1280 and 390, light and dark (Reign's own toggle).

## Steps

### 1. A real action brings its toast quickly
- **Action**: on `/activity/` at 390, comment on another member's post and press Post.
- **Expect**: the comment REST response carries `Server-Timing: wbgam-toast`; the "+N Points, Write a
  comment" toast appears within about 5s (measured 3.5s).
- **On fail**: `NotificationBridge::push()` header; `assets/js/heartbeat.js` `markedForToast()`.

### 2. Toasts close by themselves
- **Action**: time the toast from step 1, then a toast with a link ("You earned points and rewards
  since your last visit, See my progress").
- **Expect**: plain toast gone after about 4s, toast with a link after about 8s. Hovering or tabbing
  into a toast pauses it.
- **On fail**: `toast-core.js` `paint()` (`handle.ms`), `bnDraw()` (`persist: false`, `timeout`).

### 3. Escape
- **Action**: with a toast showing and no dialog open, press Escape.
- **Expect**: the newest toast closes. With a dialog open, Escape closes the dialog, not the toast.
- **On fail**: the `keydown` listener at the end of `toast-core.js`.

### 4. Never over the composer
- **Action**: type in the post composer or a comment box while a toast is due.
- **Expect**: no toast while the field holds unsent text; it shows as soon as the text is sent (focus
  may stay in the emptied box) or the field is left.
- **On fail**: `toast-core.js` `editing()` / `blocked()`.

### 5. Never over a popup
- **Action**: open any popup with a bottom button (BuddyNext share dialog, a partner's role=dialog
  popup) while a toast is due.
- **Expect**: no toast while it is open; it shows after it closes. Same with BuddyNext active.
- **On fail**: `toast()` must check `blocked()` before the `viaBuddyNext()` hand-off.

### 6. Popup buttons read like BuddyNext's own (inside BuddyNext)
- **Action**: inside BuddyNext, light mode, compare the level-up card's "Awesome!" button
  (`.wb-gam-moment .wb-gam-btn--primary`) with the composer's Post button; repeat with a red and a
  yellow accent (`--bn-hue` 25 and 90).
- **Expect**: same fill and a white label, 4.5:1 or more (measured 5.18 to 5.26:1). Dark mode follows
  BuddyNext's published pair (`--bn-accent-solid` / `--bn-accent-fg`); its composer button overrides
  that pair with its own dark rule.
- **On fail**: `src/shared/design-tokens.css` `--wb-gam-pop-accent` and the `@supports` label rule
  must read the BuddyNext pair first (tests/js/design-tokens.test.js).

## Pass criteria

1. Every toast closes by itself (4s or 8s), on both stacks.
2. Escape closes the newest toast.
3. No toast appears over a field with unsent text or over an open dialog.
4. The toast for the member's own action arrives within about 5s inside BuddyNext.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Link toast never closes inside BuddyNext | `persist` passed to `bnToast` again | `assets/js/toast-core.js` `bnDraw()` |
| Toast over a popup only inside BuddyNext | hand-off moved before `blocked()` | `assets/js/toast-core.js` `toast()` |
| Points toast never shows after commenting | hold tests focus instead of field text | `assets/js/toast-core.js` `editing()` |
| Toast after a comment takes 45-60s | no Server-Timing mark, or observer missing | `src/Engine/NotificationBridge.php` `push()`, `assets/js/heartbeat.js` |
