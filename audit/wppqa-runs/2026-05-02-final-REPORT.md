FULL PLUGIN AUDIT
============================================================

CODE QUALITY (14 checks)
----------------------------------------
  PHPCS            FAIL   734 errors, 565 warnings
  PHPSTAN          SKIP   0 errors, 0 warnings
  ESLINT           SKIP   0 errors, 0 warnings
  STYLELINT        SKIP   0 errors, 0 warnings
  PHPCOMPAT        PASS   0 errors, 0 warnings
  A11Y-GREP        SKIP   0 errors, 0 warnings
  SECURITY-SCAN    SKIP   0 errors, 0 warnings
  PERFORMANCE-SCAN SKIP   0 errors, 0 warnings
  PCP-DEEP         SKIP   0 errors, 0 warnings
  PHP-LINT         PASS   0 errors, 0 warnings
  COMPOSER-AUDIT   PASS   0 errors, 0 warnings
  I18N             PASS   0 errors, 0 warnings
  PLUGIN-CHECK     PASS   0 errors, 0 warnings
  BUNDLE-SIZE      SKIP   0 errors, 0 warnings
  ────────────────────────────────────────
  CODE TOTAL: 734 errors, 565 warnings

PRODUCT QUALITY (6 checks)
----------------------------------------
  UX               0 errors, 0 warnings
  TEMPLATES        0 errors, 1 warnings
  A11Y             2 errors, 2 warnings
  ADMIN-EVAL       4 errors, 1 warnings
  FRONTEND-EVAL    0 errors, 31 warnings
  MARKETING        0 errors, 4 warnings

# Plugin Audit: WB Gamification v1.0.0
Audited: 2026-05-02  |  Maturity: BASIC (56/100)  |  Code Quality: B (77/100)

## At a Glance
- **4** critical items to fix before release
- **742** important items to address
- **607** recommended improvements
- **1472** code quality checks run (160 passed, 740 failed)

## Plugin Inventory
| Feature | Count |
|---------|-------|
| REST Routes | 38 |
| Blocks | 12 |
| Admin Pages | 9 |
| Cron Jobs | 2 |
| CLI Commands | 6 |
| DB Tables | 20 |
| Hooks Provided | 61 |
| Integrations | 1 |

## Feature Maturity
| Area | Level | Score | Details |
|------|-------|-------|---------|
| Data Model | basic | 40/100 | Data storage via 20 custom tables |
| API Layer | basic | 52/100 | API via 38 REST routes |
| Admin UI | basic | 40/100 | UI via 9 admin pages |
| Block Editor | basic | 60/100 | Editor via 12 blocks |
| Lifecycle | missing | 0/100 | No lifecycle hooks |
| Extensibility | basic | 50/100 | Extension via 61 custom hooks |
| Integrations | excellent | 100/100 | Integrates via 1 integrations, 6 CLI commands |
| Automation | basic | 50/100 | Automation via 2 cron jobs |

## What's Missing (9 gaps)

### Critical Gaps
- **Some REST routes lack proper permission callbacks** (small effort)
  Because: Has REST API routes | Fix: Add permission_callback with current_user_can() checks to all REST routes
- **Custom tables exist but no uninstall hook to clean them up** (small effort)
  Because: Has custom database tables | Fix: Add uninstall.php or register_uninstall_hook to drop custom tables on plugin deletion
- **Custom tables exist but no activation hook to create them** (small effort)
  Because: Has custom database tables | Fix: Add register_activation_hook to create tables via dbDelta() on plugin activation
- **Cron jobs scheduled but no deactivation hook to clear them** (small effort)
  Because: Has cron jobs | Fix: Add register_deactivation_hook to wp_clear_scheduled_hook() all plugin cron events

### Important Gaps
- **Admin pages exist but no settings registered via Settings API** (medium effort)
  Fix: Use register_setting() and add_settings_field() for proper WP settings management
- **Cross-layer wiring issues detected between JS and PHP** (medium effort)
  Fix: Fix broken AJAX calls, dead handlers, nonce mismatches, and orphaned selectors

### Other Gaps
- No block patterns provided (medium effort)
- No activation hook registered (small effort)
- No deactivation hook registered (small effort)

## Code Quality: B (77/100)
| Category | Total | Pass | Fail | Score |
|----------|-------|------|------|-------|
| phpcs | 1299 | 0 | 734 | 0% |
| phpstan | 1 | 0 | 0 | 0% |
| eslint | 1 | 0 | 0 | 0% |
| stylelint | 1 | 0 | 0 | 0% |
| phpcompat | 0 | 0 | 0 | 0% |
| a11y-grep | 1 | 0 | 0 | 0% |
| security-scan | 1 | 0 | 0 | 0% |
| performance-scan | 1 | 0 | 0 | 0% |
| pcp-deep | 1 | 0 | 0 | 0% |
| php-lint | 113 | 113 | 0 | 100% |
| composer-audit | 1 | 1 | 0 | 100% |
| i18n | 3 | 3 | 0 | 100% |
| plugin-check | 0 | 0 | 0 | 0% |
| bundle-size | 1 | 1 | 0 | 100% |
| ux | 5 | 5 | 0 | 100% |
| templates | 5 | 5 | 0 | 100% |
| a11y | 7 | 5 | 2 | 71% |
| admin-eval | 10 | 6 | 4 | 60% |
| frontend-eval | 10 | 10 | 0 | 100% |
| marketing | 11 | 11 | 0 | 100% |

### Top Issues (1344 total)
- [high] **Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.**
  File: blocks/points-history/edit.asset.php:1
- [high] **Missing file doc comment**
  File: blocks/points-history/edit.asset.php:1
- [high] **When a multi-item array uses associative keys, each value should start on a new line.**
  File: blocks/points-history/edit.asset.php:1
  Fix: Run: phpcbf to auto-fix
- [high] **Overriding WordPress globals is prohibited. Found assignment to $id**
  File: blocks/earning-guide/render.php:36
- [high] **Overriding WordPress globals is prohibited. Found assignment to $action**
  File: blocks/earning-guide/render.php:36
- [high] **Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.**
  File: blocks/streak/edit.asset.php:1
- [high] **Missing file doc comment**
  File: blocks/streak/edit.asset.php:1
- [high] **When a multi-item array uses associative keys, each value should start on a new line.**
  File: blocks/streak/edit.asset.php:1
  Fix: Run: phpcbf to auto-fix
- [high] **Short array syntax is not allowed**
  File: blocks/streak/render.php:21
  Fix: Run: phpcbf to auto-fix
- [high] **Short array syntax is not allowed**
  File: blocks/streak/render.php:35
  Fix: Run: phpcbf to auto-fix
- [high] **Short array syntax is not allowed**
  File: blocks/streak/render.php:45
  Fix: Run: phpcbf to auto-fix
- [high] **Short array syntax is not allowed**
  File: blocks/streak/render.php:54
  Fix: Run: phpcbf to auto-fix
- [high] **Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.**
  File: blocks/challenges/edit.asset.php:1
- [high] **Missing file doc comment**
  File: blocks/challenges/edit.asset.php:1
- [high] **When a multi-item array uses associative keys, each value should start on a new line.**
  File: blocks/challenges/edit.asset.php:1
  Fix: Run: phpcbf to auto-fix
- [high] **Short array syntax is not allowed**
  File: blocks/challenges/render.php:34
  Fix: Run: phpcbf to auto-fix
- [high] **Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.**
  File: blocks/kudos-feed/edit.asset.php:1
- [high] **Missing file doc comment**
  File: blocks/kudos-feed/edit.asset.php:1
- [high] **When a multi-item array uses associative keys, each value should start on a new line.**
  File: blocks/kudos-feed/edit.asset.php:1
  Fix: Run: phpcbf to auto-fix
- [high] **Short array syntax is not allowed**
  File: blocks/kudos-feed/render.php:23
  Fix: Run: phpcbf to auto-fix
  ... and 1324 more

## Action Items

### Fix Before Release
- [GAP] Some REST routes lack proper permission callbacks — Add permission_callback with current_user_can() checks to all REST routes
- [GAP] Custom tables exist but no uninstall hook to clean them up — Add uninstall.php or register_uninstall_hook to drop custom tables on plugin deletion
- [GAP] Custom tables exist but no activation hook to create them — Add register_activation_hook to create tables via dbDelta() on plugin activation
- [GAP] Cron jobs scheduled but no deactivation hook to clear them — Add register_deactivation_hook to wp_clear_scheduled_hook() all plugin cron events

### Should Fix
- [GAP] Admin pages exist but no settings registered via Settings API — Use register_setting() and add_settings_field() for proper WP settings management
- [GAP] Cross-layer wiring issues detected between JS and PHP — Fix broken AJAX calls, dead handlers, nonce mismatches, and orphaned selectors
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $id
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $action
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$wrapper_attributes'.
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 4 tabs, found 3 — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$wrapper_attributes'.
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$url'.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $year
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$display_year'.
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $action
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$action['event_count']'.
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$recap['badges_earned']['count']'.
- [phpcs] A function call to __() with texts containing placeholders was found, but was not accompanied by a "translators:" comment on the line above to clarify the meaning of the placeholders.
- [phpcs] Multiple placeholders in translatable strings should be ordered. Expected "%1$s, %2$d", but got "%s, %d" in '%s — %d in Community'. — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$wrapper_attributes'.
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 4 tabs, found 3 — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 4 tabs, found 3 — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 5 tabs, found 4 — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 4 tabs, found 3 — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected edit-asset.php, but found edit.asset.php.
- [phpcs] Missing file doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$wrapper_attributes'.
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] A function call to __() with texts containing placeholders was found, but was not accompanied by a "translators:" comment on the line above to clarify the meaning of the placeholders.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-wb-gamification.php, but found wb-gamification.php.
- [phpcs] Missing member variable doc comment
- [phpcs] Missing doc comment for function instance()
- [phpcs] Missing doc comment for function __construct()
- [phpcs] Missing doc comment for function init_hooks()
- [phpcs] Missing doc comment for function handle_unsubscribe()
- [phpcs] Missing doc comment for function load_textdomain()
- [phpcs] Missing doc comment for function register_routes()
- [phpcs] Missing doc comment for function register_blocks()
- [phpcs] Missing doc comment for function enqueue_assets()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected manualawardpagetest.php, but found ManualAwardPageTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-manualawardpagetest.php, but found ManualAwardPageTest.php.
- [phpcs] Missing doc comment for class ManualAwardPageTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_init_registers_hooks()
- [phpcs] Missing doc comment for function test_normalize_points_clamps_to_range()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected settingspageautomationtest.php, but found SettingsPageAutomationTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-settingspageautomationtest.php, but found SettingsPageAutomationTest.php.
- [phpcs] Missing file doc comment
- [phpcs] Missing doc comment for class SettingsPageAutomationTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_normalize_rule_add_bp_group()
- [phpcs] Missing doc comment for function test_normalize_rule_change_wp_role()
- [phpcs] Missing doc comment for function test_normalize_rule_send_bp_message()
- [phpcs] Missing doc comment for function test_normalize_rule_returns_null_on_invalid_level()
- [phpcs] Each item in a multi-line array must be on a new line. Found: 1 space — Run: phpcbf to auto-fix
- [phpcs] Each item in a multi-line array must be on a new line. Found: 1 space — Run: phpcbf to auto-fix
- [phpcs] Each item in a multi-line array must be on a new line. Found: 1 space — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_normalize_rule_returns_null_on_unknown_action()
- [phpcs] Each item in a multi-line array must be on a new line. Found: 1 space — Run: phpcbf to auto-fix
- [phpcs] Each item in a multi-line array must be on a new line. Found: 1 space — Run: phpcbf to auto-fix
- [phpcs] Each item in a multi-line array must be on a new line. Found: 1 space — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected shortcodehandlertest.php, but found ShortcodeHandlerTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-shortcodehandlertest.php, but found ShortcodeHandlerTest.php.
- [phpcs] Missing doc comment for class ShortcodeHandlerTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_init_registers_all_shortcodes()
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_leaderboard_atts_sanitized()
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected cohortenginetest.php, but found CohortEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-cohortenginetest.php, but found CohortEngineTest.php.
- [phpcs] Missing doc comment for class CohortEngineTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_cohort_size_is_30()
- [phpcs] Missing doc comment for function test_promote_pct_and_demote_pct_sum_to_less_than_1()
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Missing doc comment for function test_get_user_tier_clamps_to_valid_range()
- [phpcs] Missing doc comment for function test_get_user_tier_returns_integer_from_meta()
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Missing doc comment for function test_demote_n_floors_correctly()
- [phpcs] Missing doc comment for function test_middle_band_members_stay()
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected nudgeenginetest.php, but found NudgeEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-nudgeenginetest.php, but found NudgeEngineTest.php.
- [phpcs] Missing doc comment for class NudgeEngineTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected ratelimitertest.php, but found RateLimiterTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-ratelimitertest.php, but found RateLimiterTest.php.
- [phpcs] Missing doc comment for class RateLimiterTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_consume_allows_first_request()
- [phpcs] Missing doc comment for function test_consume_denies_when_bucket_empty()
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_consume_returns_false_for_invalid_user()
- [phpcs] Missing doc comment for function test_consume_refills_tokens_over_time()
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_remaining_returns_capacity_when_no_bucket()
- [phpcs] Missing doc comment for function test_reset_deletes_transient()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected pointsenginetest.php, but found PointsEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-pointsenginetest.php, but found PointsEngineTest.php.
- [phpcs] Missing doc comment for class PointsEngineTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Use Yoda Condition checks, you must.
- [phpcs] Use Yoda Condition checks, you must.
- [phpcs] Missing doc comment for function test_debit_returns_false_on_db_error()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing doc comment for function test_debit_always_stores_negative_amount()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_passes_rate_limits_false_when_not_repeatable_and_already_performed()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected pointshistorytest.php, but found PointsHistoryTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-pointshistorytest.php, but found PointsHistoryTest.php.
- [phpcs] Missing doc comment for class PointsHistoryTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_get_history_returns_rows_for_user()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'points'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'created_at'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_get_history_clamps_limit()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing doc comment for function test_get_history_returns_empty_array_on_null()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing doc comment for function mockWpdb()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected redemptionenginetest.php, but found RedemptionEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-redemptionenginetest.php, but found RedemptionEngineTest.php.
- [phpcs] Missing doc comment for class RedemptionEngineTest
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing doc comment for function test_redeem_returns_error_when_out_of_stock()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_redeem_returns_error_when_insufficient_points()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgesharepagetest.php, but found BadgeSharePageTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgesharepagetest.php, but found BadgeSharePageTest.php.
- [phpcs] Missing file doc comment
- [phpcs] Missing doc comment for class BadgeSharePageTest
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_linkedin_url_contains_cert_name()
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_linkedin_url_has_required_params()
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_share_url_format()
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Tabs must be used to indent lines; spaces are not allowed — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 8 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 8 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 4 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 4 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 5 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 5 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 2 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 2 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 8 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 8 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 4 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 4 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 5 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 5 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before "||"; 2 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 2 found — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 4; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 4; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$table} at "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`"
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $role
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected settingspage.php, but found SettingsPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-settingspage.php, but found SettingsPage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Detected usage of a non-sanitized input variable: $_POST['wb_gam_level']
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$table} at "SELECT min_points FROM {$table} WHERE id = %d"
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected manualawardpage.php, but found ManualAwardPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-manualawardpage.php, but found ManualAwardPage.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected redemptionstorepage.php, but found RedemptionStorePage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-redemptionstorepage.php, but found RedemptionStorePage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected setupwizard.php, but found SetupWizard.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-setupwizard.php, but found SetupWizard.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected challengemanagerpage.php, but found ChallengeManagerPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-challengemanagerpage.php, but found ChallengeManagerPage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected communitychallengespage.php, but found CommunityChallengesPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-communitychallengespage.php, but found CommunityChallengesPage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected cohortsettingspage.php, but found CohortSettingsPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-cohortsettingspage.php, but found CohortSettingsPage.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected analyticsdashboard.php, but found AnalyticsDashboard.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-analyticsdashboard.php, but found AnalyticsDashboard.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Missing doc comment for function render_sparkline()
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected apikeyspage.php, but found ApiKeysPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-apikeyspage.php, but found ApiKeysPage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgeadminpage.php, but found BadgeAdminPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgeadminpage.php, but found BadgeAdminPage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Mixing different binary boolean operators within an expression without using parentheses to clarify precedence is not allowed.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected logscommand.php, but found LogsCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-logscommand.php, but found LogsCommand.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected exportcommand.php, but found ExportCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-exportcommand.php, but found ExportCommand.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected pointscommand.php, but found PointsCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-pointscommand.php, but found PointsCommand.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected actionscommand.php, but found ActionsCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-actionscommand.php, but found ActionsCommand.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected membercommand.php, but found MemberCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-membercommand.php, but found MemberCommand.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected doctorcommand.php, but found DoctorCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-doctorcommand.php, but found DoctorCommand.php.
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected directoryintegration.php, but found DirectoryIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-directoryintegration.php, but found DirectoryIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected profileintegration.php, but found ProfileIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-profileintegration.php, but found ProfileIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected activityintegration.php, but found ActivityIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-activityintegration.php, but found ActivityIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected levelscontroller.php, but found LevelsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-levelscontroller.php, but found LevelsController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected actionscontroller.php, but found ActionsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-actionscontroller.php, but found ActionsController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected rulescontroller.php, but found RulesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-rulescontroller.php, but found RulesController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected kudoscontroller.php, but found KudosController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-kudoscontroller.php, but found KudosController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgescontroller.php, but found BadgesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgescontroller.php, but found BadgesController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected challengescontroller.php, but found ChallengesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-challengescontroller.php, but found ChallengesController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected capabilitiescontroller.php, but found CapabilitiesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-capabilitiescontroller.php, but found CapabilitiesController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected recapcontroller.php, but found RecapController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-recapcontroller.php, but found RecapController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected memberscontroller.php, but found MembersController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-memberscontroller.php, but found MembersController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected abilitiesregistration.php, but found AbilitiesRegistration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-abilitiesregistration.php, but found AbilitiesRegistration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected eventscontroller.php, but found EventsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-eventscontroller.php, but found EventsController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected redemptioncontroller.php, but found RedemptionController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-redemptioncontroller.php, but found RedemptionController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected credentialcontroller.php, but found CredentialController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-credentialcontroller.php, but found CredentialController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected webhookscontroller.php, but found WebhooksController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-webhookscontroller.php, but found WebhooksController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected pointscontroller.php, but found PointsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-pointscontroller.php, but found PointsController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected leaderboardcontroller.php, but found LeaderboardController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-leaderboardcontroller.php, but found LeaderboardController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected apikeyauth.php, but found ApiKeyAuth.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-apikeyauth.php, but found ApiKeyAuth.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected openapicontroller.php, but found OpenApiController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-openapicontroller.php, but found OpenApiController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgesharecontroller.php, but found BadgeShareController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgesharecontroller.php, but found BadgeShareController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected cosmeticengine.php, but found CosmeticEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-cosmeticengine.php, but found CosmeticEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected leaderboardnudge.php, but found LeaderboardNudge.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-leaderboardnudge.php, but found LeaderboardNudge.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected weeklyemailengine.php, but found WeeklyEmailEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-weeklyemailengine.php, but found WeeklyEmailEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected asyncevaluator.php, but found AsyncEvaluator.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-asyncevaluator.php, but found AsyncEvaluator.php.
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgesharepage.php, but found BadgeSharePage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgesharepage.php, but found BadgeSharePage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected event.php, but found Event.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-event.php, but found Event.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected ruleengine.php, but found RuleEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-ruleengine.php, but found RuleEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected engine.php, but found Engine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-engine.php, but found Engine.php.
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgeengine.php, but found BadgeEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgeengine.php, but found BadgeEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected ratelimiter.php, but found RateLimiter.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-ratelimiter.php, but found RateLimiter.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected recapengine.php, but found RecapEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-recapengine.php, but found RecapEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected shortcodehandler.php, but found ShortcodeHandler.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-shortcodehandler.php, but found ShortcodeHandler.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected streakengine.php, but found StreakEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-streakengine.php, but found StreakEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected leaderboardengine.php, but found LeaderboardEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-leaderboardengine.php, but found LeaderboardEngine.php.
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cache_table} at "TRUNCATE TABLE {$cache_table}"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cache_table} at "SELECT TIMESTAMPDIFF(MINUTE, MAX(updated_at), NOW()) FROM {$cache_table}"
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected sitefirstbadgeengine.php, but found SiteFirstBadgeEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-sitefirstbadgeengine.php, but found SiteFirstBadgeEngine.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected dbupgrader.php, but found DbUpgrader.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-dbupgrader.php, but found DbUpgrader.php.
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$defs} at "SHOW COLUMNS FROM `{$defs}`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$defs} at "ALTER TABLE `{$defs}` ADD COLUMN `validity_days` INT UNSIGNED DEFAULT NULL AFTER `is_credential`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$ubadg} at "SHOW COLUMNS FROM `{$ubadg}`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$ubadg} at "ALTER TABLE `{$ubadg}` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `earned_at`, ADD KEY `idx_expires_at` (`expires_at`)"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cc} at "SHOW COLUMNS FROM `{$cc}`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cc} at "ALTER TABLE `{$cc}` CHANGE `action_id` `target_action` VARCHAR(100) NOT NULL"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cc} at "ALTER TABLE `{$cc}` CHANGE `target` `target_count` BIGINT UNSIGNED NOT NULL"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cc} at "ALTER TABLE `{$cc}` CHANGE `current_count` `global_progress` BIGINT UNSIGNED DEFAULT 0"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cc} at "ALTER TABLE `{$cc}` RENAME INDEX `action_id` TO `target_action`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$pts} at "SHOW INDEX FROM `{$pts}` WHERE Key_name = 'idx_user_action_created'"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$pts} at "ALTER TABLE `{$pts}` ADD KEY `idx_user_action_created` (user_id, action_id, created_at)"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$prefs} at "SHOW INDEX FROM `{$prefs}` WHERE Key_name = 'idx_opt_out'"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$prefs} at "ALTER TABLE `{$prefs}` ADD KEY `idx_opt_out` (leaderboard_opt_out)"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$chal} at "SHOW INDEX FROM `{$chal}` WHERE Key_name = 'idx_status_action'"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$chal} at "ALTER TABLE `{$chal}` ADD KEY `idx_status_action` (status, action_id)"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$defs} at "SHOW COLUMNS FROM `{$defs}`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$defs} at "ALTER TABLE `{$defs}` ADD COLUMN `closes_at` DATETIME DEFAULT NULL AFTER `validity_days`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$defs} at "ALTER TABLE `{$defs}` ADD COLUMN `max_earners` INT UNSIGNED DEFAULT NULL AFTER `closes_at`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$table} at "ALTER TABLE `{$table}`\n
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$cache_table} at "CREATE TABLE IF NOT EXISTS `{$cache_table}` (\n
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$charset} at             ) {$charset};"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$events} at "SHOW COLUMNS FROM `{$events}`"
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$events} at "ALTER TABLE `{$events}` ADD COLUMN `site_id` VARCHAR(100) NOT NULL DEFAULT '' AFTER `metadata`, ADD KEY `idx_site_id` (`site_id`)"
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected registry.php, but found Registry.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-registry.php, but found Registry.php.
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found '$action['id']'.
- [phpcs] All output should be run through an escaping function (see the Security sections in the WordPress Developer Handbooks), found 'self'.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected challengeengine.php, but found ChallengeEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-challengeengine.php, but found ChallengeEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected cohortengine.php, but found CohortEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-cohortengine.php, but found CohortEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected capabilities.php, but found Capabilities.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-capabilities.php, but found Capabilities.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected redemptionengine.php, but found RedemptionEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-redemptionengine.php, but found RedemptionEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected privacy.php, but found Privacy.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-privacy.php, but found Privacy.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected featureflags.php, but found FeatureFlags.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-featureflags.php, but found FeatureFlags.php.
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected tenurebadgeengine.php, but found TenureBadgeEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-tenurebadgeengine.php, but found TenureBadgeEngine.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected personalrecordengine.php, but found PersonalRecordEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-personalrecordengine.php, but found PersonalRecordEngine.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected pointsengine.php, but found PointsEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-pointsengine.php, but found PointsEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected statusretentionengine.php, but found StatusRetentionEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-statusretentionengine.php, but found StatusRetentionEngine.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected webhookdispatcher.php, but found WebhookDispatcher.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-webhookdispatcher.php, but found WebhookDispatcher.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected communitychallengeengine.php, but found CommunityChallengeEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-communitychallengeengine.php, but found CommunityChallengeEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected kudosengine.php, but found KudosEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-kudosengine.php, but found KudosEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected nudgeengine.php, but found NudgeEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-nudgeengine.php, but found NudgeEngine.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected installer.php, but found Installer.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-installer.php, but found Installer.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected credentialexpiryengine.php, but found CredentialExpiryEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-credentialexpiryengine.php, but found CredentialExpiryEngine.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected logpruner.php, but found LogPruner.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-logpruner.php, but found LogPruner.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected rankautomation.php, but found RankAutomation.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-rankautomation.php, but found RankAutomation.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected notificationbridge.php, but found NotificationBridge.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-notificationbridge.php, but found NotificationBridge.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected manifestloader.php, but found ManifestLoader.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-manifestloader.php, but found ManifestLoader.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected levelengine.php, but found LevelEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-levelengine.php, but found LevelEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [a11y] outline:none without :focus-visible replacement — Add a matching :focus-visible rule with outline: 2px solid <brand>; outline-offset: 2px;
- [a11y] outline:none without :focus-visible replacement — Add a matching :focus-visible rule with outline: 2px solid <brand>; outline-offset: 2px;
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling

### Recommended
- [GAP] No block patterns provided — Add block patterns to give users pre-built layouts using your blocks
- [GAP] No activation hook registered — Add register_activation_hook() for initial setup (version check, default options, flush rewrite rules)
- [GAP] No deactivation hook registered — Add register_deactivation_hook() for cleanup (clear cron, flush rewrite rules)
- [phpcs] Equals sign not aligned with surrounding assignments; expected 1 space but found 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 1 space but found 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 1 space but found 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 1 space but found 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 1 space but found 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 1 space but found 3 spaces — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 2 spaces but found 4 spaces — Run: phpcbf to auto-fix
- [phpcs] Calling current_time() with a $type of "timestamp" or "U" is strongly discouraged as it will not return a Unix (UTC) timestamp. Please consider using a non-timestamp format or otherwise refactoring this code.
- [phpcs] Equals sign not aligned with surrounding assignments; expected 9 spaces but found 8 spaces — Run: phpcbf to auto-fix
- [phpcs] Found precision alignment of 2 spaces. — Run: phpcbf to auto-fix
- [phpcs] Found precision alignment of 2 spaces. — Run: phpcbf to auto-fix
- [phpcs] Equals sign not aligned with surrounding assignments; expected 7 spaces but found 5 spaces — Run: phpcbf to auto-fix
  ... and 592 more

---
Total: 1472 code checks | 9 gaps found | 1353 action items

## Customer Expectation Analysis
Detected Category: **Community Platform** (59% confidence)
Also matches: Learning Management System (38%), E-Commerce (25%)

Feature Completeness: **89%** (13 found / 0 partial / 2 missing out of 15)

| Feature | Importance | Status | Industry Context |
|---------|-----------|--------|-----------------|
| User Profiles | must-have | FOUND | Circle, Mighty Networks, Discord all have rich profiles with customizable fields |
| Activity Feed | must-have | FOUND | Every community platform (Circle, Discourse, Facebook Groups) has an activity stream |
| Groups / Spaces | must-have | FOUND | Circle has Spaces, Mighty Networks has Groups, Discord has Channels/Categories |
| Private Messaging | must-have | FOUND | Discord DMs, Circle DMs, Mighty Networks messaging — expected by 95%+ of users |
| Notifications | must-have | FOUND | Every platform has multi-channel notifications |
| Friend / Follow System | should-have | FOUND | Twitter/X follow model or Facebook friend model |
| Content Moderation | must-have | FOUND | Discourse has trust levels + auto-moderation, Circle has admin tools, Discord has bots |
| Search & Discovery | should-have | MISSING | Circle has search across all content types |
| Reactions / Likes | should-have | FOUND | Discourse has likes, Circle has reactions, Discord has emoji reactions |
| Mentions / Tagging | should-have | MISSING | Universal across all community platforms — Slack, Discord, Circle all support @mentions |
| Media Sharing | should-have | FOUND | Instagram-like media in Circle/Mighty Networks |
| Email Digests | should-have | FOUND | Discourse sends excellent digest emails |
| Mobile Responsive / App | must-have | FOUND | Circle, Mighty Networks, Discord all have native mobile apps |
| SSO / Social Login | should-have | FOUND | Every modern platform supports social login |
| Onboarding / Welcome Flow | should-have | FOUND | Circle has welcome spaces, Mighty Networks has guided setup, Discord has onboarding screens |

### Should-Have Gaps (competitors have these)
- **Search & Discovery**: Search members, content, groups. Discovery recommendations
  Industry: Circle has search across all content types. Discourse has full-text search with filters
- **Mentions / Tagging**: @mention users in posts and comments
  Industry: Universal across all community platforms — Slack, Discord, Circle all support @mentions
