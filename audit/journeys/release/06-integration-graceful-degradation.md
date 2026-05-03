---
journey: tier-6-integration-graceful-degradation
plugin: wb-gamification
priority: high
roles: [administrator]
covers: [defensive-gating, BuddyPress, WooCommerce, LearnDash, bbPress, Elementor]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli available"
estimated_runtime_minutes: 6
---

# Tier 6 — Integration Matrix (graceful degradation + live where present)

The plugin integrates with BuddyPress / WooCommerce / LearnDash / bbPress / Elementor. The integration code MUST gate on `class_exists`/`function_exists` so the plugin works even on a stripped-down install. When a host plugin IS present, its specific integration must award points correctly.

## Setup

- Site: `$SITE_URL = http://wb-gamification.local`
- Test users: `admin` (autologin)

## Steps — defensive gating (always run)

### 1. Surface inventory survives missing hosts
- **Action**: `wp eval` — count registered blocks, shortcodes, REST routes
- **Expect**: 15 blocks, 15 shortcodes, ≥40 REST routes (currently 47)

### 2. Re-fire init/bp_loaded with hosts absent
- **Action**: `wp eval`:
  ```php
  $start = error_get_last();
  do_action( "init", 30 );
  do_action( "bp_loaded" );
  echo "no_fatal=" . ( error_get_last() === $start ? "yes" : "no" );
  ```
- **Expect**: `no_fatal=yes`

## Steps — live integration probes (run when host is present)

### 3. BuddyPress (if `function_exists('buddypress')`)
- **Action**: create a new activity post via BP REST → assert `wb_gam_events` row appears with `action_id` matching the BP activity hook
- **Expect**: points awarded per the BP→action mapping in `src/BuddyPress/HooksIntegration.php`

### 4. WooCommerce (if `class_exists('WooCommerce')`)
- **Action**: place + complete a WC test order
- **Expect**: points awarded per `points_per_dollar` setting; ledger row tagged `action_id=woocommerce_order_complete`

### 5. LearnDash (if `class_exists('SFWD_LMS')`)
- **Action**: mark a course completed for the test user
- **Expect**: points awarded; ledger row tagged `learndash_course_completed`

### 6. bbPress (if `class_exists('bbPress')`)
- **Action**: create a topic
- **Expect**: points awarded; ledger row tagged `bbp_topic_created`

### 7. Elementor (if `did_action('elementor/loaded')`)
- **Action**: open Elementor editor → search blocks panel for "wb-gam"
- **Expect**: blocks appear in the Elementor inserter

## Pass criteria

For dev-box runs (hosts absent):
1. Defensive gating produces 0 fatals
2. 15 blocks, 15 shortcodes, ≥40 REST routes still register

For staging runs (hosts present): every applicable integration above awards points + logs an event.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Fatal on init when BP absent | `bp_*` function called outside `function_exists` guard | `src/BuddyPress/HooksIntegration.php` |
| Fatal on init when Woo absent | WC class referenced unguarded | `src/Integrations/WooCommerce/*.php` |
| BP activity doesn't award | Hook not registered or action mapping missing | `src/BuddyPress/HooksIntegration.php` `init()` + `manifests/buddypress.json` |
| Woo order doesn't award | Hook `woocommerce_order_status_completed` not bound | `src/Integrations/WooCommerce/HooksIntegration.php` |
