---
journey: third-party-manifest-active-gating
plugin: wb-gamification
priority: critical
roles: [admin]
covers: [manifest-loader, third-party-manifests, plugin-activation-state, registry]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wb-gamification active"
  - "wpmediaverse-pro plugin installed (any version that ships its own wb-gamification.php)"
estimated_runtime_minutes: 2
---

# Third-party manifests gate on plugin activation

`ManifestLoader::load_from_plugins()` scans `WP_PLUGIN_DIR/*/wb-gamification.php`
to auto-discover third-party manifests. Earlier, this glob loaded a file
whenever it existed on disk — irrespective of whether the plugin owning that
file was active. The Registry then exposed action IDs from deactivated
plugins, awarding points for hooks that would never fire in production
(or worse, that would fire if some unrelated plugin happened to emit a
matching action name).

The fix gates the scan on `active_plugins` + `active_sitewide_plugins`. This
journey re-locks that contract: deactivate a manifest-shipping plugin, the
manifest's actions disappear from the registry; reactivate it, they return.

Caught during the 2026-05-28 per-integration live testing pass. The user
deactivated wpmediaverse-pro but `mvs_battle_win` / `mvs_tournament_win` /
`mvs_streak_milestone` were still in the registry and still awarded points.

## Setup

- Site: `$SITE_URL`
- Trigger plugin: `wpmediaverse-pro` (any version with a bundled
  `wb-gamification.php` declaring `mvs_battle_win` etc.)
- No DB cleanup required — this is a registry-state journey, not a
  ledger journey.

## Steps

### 1. Deactivate wpmediaverse-pro, ensure free-only is active

- **Action**: `wp plugin deactivate wpmediaverse-pro && wp plugin activate wpmediaverse`
- **Expect**: only `wpmediaverse` (free) is active. `MVS_PRO_VERSION` is
  not defined.
- **Verify**: `wp eval 'echo defined("MVS_PRO_VERSION") ? "BUG" : "ok";'`
  prints `ok`.

### 2. Confirm Pro-only action IDs are ABSENT from the registry

- **Action**:
  ```bash
  wp eval 'foreach (["mvs_battle_win","mvs_tournament_win","mvs_streak_milestone","mvs_challenge_participate"] as $a) {
      $r = WBGam\Engine\Registry::get_action($a);
      echo "$a: " . ($r ? "FAIL — in registry" : "absent") . "\n";
  }'
  ```
- **Expect**: every line ends in `absent`.
- **On fail**: `src/Engine/ManifestLoader::load_from_plugins()` is loading
  manifest files regardless of plugin activation state. Re-check the
  `$active_dirs` gate added in commit (this journey's commit).

### 3. Confirm Free action IDs remain present

- **Action**:
  ```bash
  wp eval 'foreach (["mvs_upload_photo","mvs_receive_like","mvs_bookmark_photo"] as $a) {
      $r = WBGam\Engine\Registry::get_action($a);
      echo "$a: " . ($r ? "ok" : "FAIL — absent") . "\n";
  }'
  ```
- **Expect**: every line ends in `ok`. (Free actions come from the in-tree
  manifest at `integrations/wpmediaverse.php`, not the third-party one.)
- **On fail**: the gating change broke the first-party scan path. Check
  `load_first_party()` was not modified.

### 4. Reactivate Pro, confirm Pro IDs return

- **Action**: `wp plugin activate wpmediaverse-pro`
- **Verify**:
  ```bash
  wp eval 'foreach (["mvs_battle_win","mvs_tournament_win","mvs_streak_milestone"] as $a) {
      $r = WBGam\Engine\Registry::get_action($a);
      echo "$a: " . ($r ? "ok" : "FAIL — absent") . "\n";
  }'
  ```
- **Expect**: every line ends in `ok`.
- **On fail**: the gate is permanently excluding Pro instead of toggling
  with activation state. Check it reads the active-plugin list at scan
  time (every `plugins_loaded`), not at boot time.

## Pass criteria

ALL of the following hold:

1. With wpmediaverse-pro deactivated, all 7 of its bundled-manifest
   action IDs are absent from the registry.
2. Free-tier wpmediaverse actions remain present (the gating must not
   affect the first-party scan path).
3. Reactivating wpmediaverse-pro brings the 7 IDs back into the
   registry on the next request.
4. No fatal in `plugins_loaded`, no notices in the debug log.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Pro IDs in registry while plugin is inactive | `$active_dirs` lookup is failing or skipped | `src/Engine/ManifestLoader.php::load_from_plugins()` |
| Free IDs missing | First-party scan accidentally went through the gate | same file — verify `load_first_party()` is untouched |
| Pro IDs never return after reactivation | `active_plugins` option not refreshed; runtime caching of the option | check no static cache shadows `get_option('active_plugins')` |
| Multisite network-active plugin's manifest not loading | `active_sitewide_plugins` merge missing | check `array_merge` includes both sources |
