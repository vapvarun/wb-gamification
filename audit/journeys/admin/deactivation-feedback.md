# Journey — Deactivation feedback survey

**Surface:** Plugins screen (`plugins.php`)
**Card:** BC 10061742875
**Priority:** medium

## Contract

On the Plugins screen, clicking WB Gamification's **Deactivate** link opens an
optional survey `<dialog>` (native, so Esc + focus-trap are free) before the
plugin deactivates. It is fully optional and never blocks deactivation.

## Steps

1. Go to `wp-admin/plugins.php` as an admin (no `wb_gam_deactivation_prompted`
   transient set).
2. Click **Deactivate** on the WB Gamification row.
   - EXPECT: navigation is prevented; a modal dialog opens titled "Quick
     question before you go" with 6 reasons, a detail textarea, a contact-
     consent checkbox, and **Submit & deactivate** / **Skip & deactivate**.
   - EXPECT: keyboard focus lands on the first reason radio.
3. Press **Esc**.
   - EXPECT: dialog closes and the plugin stays ACTIVE (cancel = no send, no
     deactivation).
4. Click Deactivate again → pick a reason → **Submit & deactivate**.
   - EXPECT: a POST to `admin-ajax.php?action=wb_gam_deactivation_feedback`
     returns `{success:true,data:{recorded:true}}`; the entry lands in the
     `wb_gam_deactivation_reasons` option (anonymous: reason, versions, locale,
     one-way site hash; email only if consent ticked); then the plugin
     deactivates.
5. Reactivate, click Deactivate again.
   - EXPECT: NO dialog (30-day `wb_gam_deactivation_prompted` re-prompt guard);
     deactivation proceeds immediately.

## Resilience

- **Skip & deactivate** records a skip (no reason) and deactivates.
- Collector/network failure or a 3s timeout still deactivates (never blocks).
- Only THIS plugin's row link is intercepted (event delegation matches
  `data-plugin` / the encoded basename in `action=deactivate` hrefs).

## Regression notes

- The click handler MUST use document-level delegation, not a script-load-time
  `getElementById` guard: the `<dialog>` prints in the footer AFTER the script,
  so a load-time lookup returns null, the guard bails, and the raw Deactivate
  link navigates (silently deactivating with no survey). Verified 2026-07-04.
- Feedback is anonymous by default; `contact` email is included ONLY with the
  consent checkbox. A collector URL can be set via
  `wb_gam_deactivation_collector_url` (non-blocking `wp_remote_post`).
