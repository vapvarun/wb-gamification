---
journey: popup-keyboard-and-icons
plugin: wb-gamification
priority: high
roles: [member, administrator]
covers: [toast-icons-blank, hub-tiles-keyboard, hub-panel-focus, admin-confirm-dialog, toast-link-timeout, badge-share-focus]
prerequisites:
  - "Site reachable at $SITE_URL with BuddyNext active and a hub page"
  - "A member with points, and an administrator"
estimated_runtime_minutes: 8
---

# Pop-ups: icons, keyboard reach, focus

Every pop-up surface must be reachable and leavable by keyboard, and a toast must look finished. If
this regresses, keyboard and screen-reader members cannot open the hub panels, an admin's stray Enter
can delete data, and every toast loses its icon on pages without a gamification block.

## Setup

- Site: `$SITE_URL`; member via `?autologin=<member login>`, admin via `?autologin=1`.
- Queue a toast for the member before step 1 (`NotificationBridge::push()` through a `wp eval-file`), and
  delete the rows afterwards.

## Steps

### 1. Toast icons render on a page with no gamification block (390px)
- **Action**: load `/activity/` as the member with a queued points, badge and kudos toast.
- **Expect**: each `.wb-gam-toast__icon` is at least 16px wide (not 0x0), `lucide-icons` is in
  `document.styleSheets`, and the icon font-size is 1.25rem (not the inherited body size).
- **On fail**: `src/Engine/NotificationBridge.php` render() (the `lucide-icons` enqueue), `assets/css/frontend.css` `.wb-gam-toast .wb-gam-toast__icon`.

### 2. A link toast stays; a plain toast fades and pauses on hover
- **Action**: render a toast with `url` (the welcome toast) beside a plain one; wait 6s; hover a second plain toast for 6s, then move away.
- **Expect**: the plain toast is gone at about 4s, the link toast is still there, the hovered toast survives the hover and fades about 2s after the pointer leaves.
- **On fail**: `assets/js/toast.js` `armDismiss()` and the `if ( ! link )` block in `showToast()`.

### 3. Hub tiles open by keyboard
- **Action**: on the hub page focus a `.gam-card`, press Enter; close; press Space on another.
- **Expect**: every tile is `role="button"` with `tabindex="0"`; Enter and Space both open the panel; a focus ring is visible.
- **On fail**: `src/Blocks/hub/render.php` (tile markup), `assets/interactivity/hub.js` `onTileKey()`.

### 4. The hub panel is a real modal dialog
- **Action**: open a panel by keyboard, press Tab and Shift+Tab, then Escape; repeat closing by backdrop click and the Back button; load `?panel=badges`.
- **Expect**: `dialog.gam-panel` is open and focus is inside it (on the Back button); focus never reaches the page behind; Escape, backdrop and Back all close it, clear the panel body and the body scroll lock, and return focus to the tile that opened it. `?panel=badges` opens it on load.
- **On fail**: `assets/interactivity/hub.js` `showPanel()` / `resetPanel()`, `assets/js/dialog.js`.

### 5. The admin confirm dialog starts on Cancel for a danger action
- **Action**: on a wp-admin gamification page call `wbGamAdminRest.confirmAction({ tone: 'danger', confirmText: 'Delete' })`; press Tab twice, then Escape.
- **Expect**: `dialog.wb-gam-confirm-dialog` is open with focus on Cancel; Tab cycles Cancel, Delete, then the browser, never the page; Escape resolves `false`, removes the dialog and returns focus to the element that opened it. A `tone: 'primary'` prompt starts on its confirm button.
- **On fail**: `assets/js/admin-rest-utils.js` `confirmAction()`; the `wb-gam-dialog` dependency on the enqueue.

### 6. Badge Share keeps focus
- **Action**: focus a badge's Share button in the hub badges panel, press Enter, wait, press Enter again.
- **Expect**: after each press the same button still has focus, `aria-pressed` toggles, and `aria-disabled` is gone; the badge ends where it started.
- **On fail**: `src/Blocks/badge-showcase/view.js` share handler (must not use the `disabled` property).

## Pass criteria

ALL of the following hold:
1. Toast icons are visible on `/activity/` at 390px.
2. Link toasts persist; plain toasts fade at about 4s and pause on hover.
3. Every hub tile opens with Enter and Space and shows a focus ring.
4. The hub panel traps focus, closes three ways, and returns focus to its tile.
5. A danger confirm starts on Cancel and does not leak focus to the page.
6. Badge Share never drops focus.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Toast has a stripe but no icon | `lucide-icons` not enqueued on that page | `src/Engine/NotificationBridge.php` render() |
| Tab from a hub tile skips it | tile lost `role`/`tabindex` | `src/Blocks/hub/render.php` (rebuild `npm run build`) |
| Focus lands on `<body>` after closing the panel | `onClose` not passed to `wbGam.dialog.open` | `assets/interactivity/hub.js` showPanel() |
| Enter on a danger confirm deletes | initial focus on the confirm button | `assets/js/admin-rest-utils.js` |
