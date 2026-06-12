---
journey: abilities-api-registration
plugin: wb-gamification
priority: critical
roles: [administrator, anonymous]
covers: [9989818533, abilities-api, doing-it-wrong-clean-boot, headers-already-sent]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli on PATH (Local Site Shell satisfies this)"
  - "WordPress 6.9+ (Abilities API in core)"
estimated_runtime_minutes: 3
---

# Abilities API — category + all abilities register with zero notices

The v1.5.5 release zip was rejected (Basecamp #9989818533) because every
admin page emitted 15 `_doing_it_wrong` notices — the `gamification`
ability category was never registered via `wp_register_ability_category()`
on `wp_abilities_api_categories_init`, so WP 6.9+ refused every
`wp_register_ability()` call. The notices printed before headers were sent,
cascading into "Cannot modify header information" warnings that corrupted
admin responses, AJAX, and modal JS.

The deeper failure this journey locks: the abilities were defined as pure
discovery metadata with no `execute_callback` / `permission_callback`, so
they had NEVER successfully registered on any WP version — fixing only the
category swaps 15 category notices for 12 execute_callback notices. The
fix maps each definition onto core's required args: a REST-proxy
`execute_callback` (via `rest_do_request`, so controller permissions still
apply), an auth-level `permission_callback`, and an `input_schema` derived
from the parameter metadata.

## Setup

- Site: `$SITE_URL`
- Runs entirely via wp-cli + curl; no fixtures, no DB writes.

## Steps

### 1. Boot is `_doing_it_wrong`-clean
- **Action**:
  ```bash
  wp eval '
  $diw = array();
  // Too late to catch boot-time notices here, so assert the OUTCOME they
  // would have blocked: the registry actually contains our entries.
  $cats = WP_Ability_Categories_Registry::get_instance()->get_all_registered();
  echo isset( $cats["gamification"] ) ? "category-ok\n" : "category-MISSING\n";
  $mine = array_filter( array_keys( wp_get_abilities() ), fn( $k ) => str_starts_with( $k, "wb-gamification/" ) );
  echo "abilities=" . count( $mine ) . "\n";'
  ```
- **Expect**: `category-ok` and `abilities=12`. A missing category or a
  count below 12 means a definition fails `WP_Ability::prepare_properties()`
  and is emitting `_doing_it_wrong` on every page load.
- **On fail**: `src/API/AbilitiesRegistration.php` — `register_category()`
  must run on `wp_abilities_api_categories_init`; every entry from
  `get_abilities()` must map to valid `execute_callback` +
  `permission_callback` + `input_schema` in `register_abilities()`.

### 2. Admin pages render without warning output
- **Action**: `curl -sk -L "$SITE_URL/wp-admin/plugins.php?autologin=1"`
- **Expect**: HTTP 200; page HTML contains **zero** matches for
  `doing it wrong`, `headers already sent`, `Function WP_Abilities`.
- **On fail**: something is printing before headers — check debug.log for
  the first `_doing_it_wrong` source.

### 3. Abilities actually execute (the contract the metadata promises)
- **Action**:
  ```bash
  wp eval '
  $r = wp_get_ability( "wb-gamification/read-leaderboard" )->execute( array( "period" => "week", "limit" => 5 ) );
  echo is_wp_error( $r ) ? "leaderboard-ERR: " . $r->get_error_message() : "leaderboard-ok\n";
  $m = wp_get_ability( "wb-gamification/read-member" )->execute( array( "id" => 1 ) );
  echo is_wp_error( $m ) ? "member-ERR: " . $m->get_error_message() : "member-ok\n";'
  ```
- **Expect**: `leaderboard-ok` and `member-ok` — parameterized input passes
  the derived `input_schema` and `{id}` path substitution resolves.
- **On fail**: `make_input_schema()` / `make_execute_callback()` in
  `src/API/AbilitiesRegistration.php`.

### 4. Permission gates mirror the documented auth levels
- **Action**:
  ```bash
  wp eval '
  $k = wp_get_ability( "wb-gamification/manage-api-keys" );
  echo $k->check_permissions() === false ? "anon-denied\n" : "anon-ALLOWED-bug\n";
  wp_set_current_user( 1 );
  echo $k->check_permissions() === true ? "admin-allowed\n" : "admin-DENIED-bug\n";'
  ```
- **Expect**: `anon-denied` then `admin-allowed`. Admin-level abilities
  (`manage-badges`, `manage-api-keys`, `award-points`) must require
  `manage_options`; `auth: required` abilities must require a logged-in
  user.
- **On fail**: `make_permission_callback()` auth mapping drifted from the
  `auth` values in `get_abilities()`.

### 5. Fallback REST discovery contract unchanged
- **Action**: `curl -sk "$SITE_URL/wp-json/wb-gamification/v1/abilities"`
- **Expect**: HTTP 200 JSON with `plugin`, `version`, `abilities` keys and
  12 ability entries each carrying `endpoint`, `methods`, `auth` — the
  pre-existing public discovery shape consumed by older WP versions.
- **On fail**: `get_abilities()` shape changed — that array is a public
  REST contract; map new Abilities-API args in `register_abilities()`
  instead of editing the definitions.
