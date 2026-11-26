---
journey: earning-one-save
plugin: wb-gamification
priority: high
roles: [administrator]
covers: [earning-actions, autosave, undo, failed-save, restore-defaults, filters, 390, rtl]
prerequisites:
  - "Site reachable at $SITE_URL on Reign 8.1.2; logged in as an administrator"
estimated_runtime_minutes: 10
---

# Earning: every action by plugin, one save behaviour (1.7.0)

Card 10549518372. Before 1.7.0 the switch and amount saved with the form's Save button while
limits saved on their own, on the same row; "0" meant unlimited with no hint; at 390 the limit
columns sat in a sideways-scrolling table. Now every row change saves through
`POST /actions/{id}/overrides` and the form carries only retention and expiry.

Automated cover: `tests/Unit/Admin/ActionPointTypeMappingTest.php` (ids with `/`, `.`, capitals
save through REST; unknown point type refused with nothing half-saved; restore resets the row;
the form save no longer touches any action).

## Steps

### 1. Change an amount, a switch and a limit
- **Action**: Gamification > Earning. Change "Publish a blog post" to 40; switch it off and on;
  open its limit, set Most per day to 3, then empty it.
- **Expect**: each change shows "Saved." with Undo; the limit reads "3 a day", then "No limit";
  a reload keeps every value (options `wb_gam_points_wp_publish_post`, `wb_gam_enabled_*`,
  `wb_gam_action_overrides`).

### 2. Undo and a failed save
- **Action**: change an amount, press Undo within 10s. Then make the REST route fail (stop the
  site's PHP or block the request) and change it again.
- **Expect**: Undo puts the old value back on screen and in the option. The failed save shows
  "Not saved. The previous value is back" and the field shows the old value.

### 3. Group actions
- **Action**: "Switch all off" on a plugin group, then Undo; edit an amount, then "Restore <plugin> defaults".
- **Expect**: all rows off, then back on; restore puts the plugin's amounts, switches, limits and
  point types back.

### 4. Find and filter
- **Action**: type "comment"; choose Has a limit, Takes points, Off; pick one plugin.
- **Expect**: the count reads "N of M actions", empty groups hide, nothing matching shows the hint.

### 5. The award uses the new value
- **Action**: set an amount, then perform that action as a member.
- **Expect**: the ledger row carries the new amount.

### 6. Phone and RTL
- **Action**: repeat step 1 at 390 and with the site in RTL.
- **Expect**: every row is a card with all values visible, no sideways scroll; RTL mirrors.

## Pass criteria

1. One save behaviour on every field, with Saved, Undo and a visible failure.
2. Values persist and pay.
3. 390 and RTL hold.
