---
journey: update-channel
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [edd-sdk-update-channel, basecamp-10410883964]
prerequisites:
  - "Site reachable at $SITE_URL with WB Gamification active"
  - "The previous release installed, the new one on the store (for step 4)"
estimated_runtime_minutes: 5
---

# Sites are offered WB Gamification updates

The EDD SL SDK builds an updater only when it finds a key in its key option, `{id}_license_key` unless `option_name` is passed; it ignores the registered `license` value. `LicenseActivator` stores the preset in `wb_gam_license_key`, while 1.6.6 and older registered without `option_name`, so the SDK read `wb-gamification_license_key`, found nothing and never built the updater: 8 of 382 activated sites checked for updates (Oct 2026). Fixed in 1.6.7 by registering `option_name => wb_gam_license_key`.

## Steps

1. As an administrator (the SDK builds updaters only for `manage_options` or cron), load any admin page.
   - Expect `get_option( 'wb_gam_license_key' )` === `WB_GAM_LICENSE_PRESET_KEY`.
2. `EasyDigitalDownloads\Updater\Registry::instance()['wb-gamification']` has `option_name` = `wb_gam_license_key`.
3. After `init`, `pre_set_site_transient_update_plugins` has an updater whose `item_id` is `1662147`.
   - FAILS on 1.6.6: no updater for 1662147 (the other Wbcom products on the site have one).
4. With the previous release installed: Dashboard > Updates > Check again offers the new version and it installs.

## Negative

- `option_name` removed from the registration: step 3 fails (no updater for 1662147). The gate must go red here.
