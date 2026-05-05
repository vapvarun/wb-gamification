FULL PLUGIN AUDIT
============================================================

CODE QUALITY (14 checks)
----------------------------------------
  PHPCS            FAIL   1018 errors, 654 warnings
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
  CODE TOTAL: 1018 errors, 654 warnings

PRODUCT QUALITY (6 checks)
----------------------------------------
  UX               0 errors, 0 warnings
  TEMPLATES        0 errors, 2 warnings
  A11Y             1 errors, 0 warnings
  ADMIN-EVAL       10 errors, 1 warnings
  FRONTEND-EVAL    1 errors, 54 warnings
  MARKETING        0 errors, 3 warnings

# Plugin Audit: Redemption — LearnDash course unlock v1.0.0
Audited: 2026-05-05  |  Maturity: BASIC (63/100)  |  Code Quality: C+ (68/100)

## At a Glance
- **4** critical items to fix before release
- **1033** important items to address
- **719** recommended improvements
- **1868** code quality checks run (177 passed, 1030 failed)

## Plugin Inventory
| Feature | Count |
|---------|-------|
| REST Routes | 45 |
| Blocks | 15 |
| Admin Pages | 9 |
| Cron Jobs | 2 |
| CLI Commands | 8 |
| DB Tables | 19 |
| Templates | 1 |
| Hooks Provided | 100 |
| Hooks Consumed | 3 |
| Integrations | 2 |

## Feature Maturity
| Area | Level | Score | Details |
|------|-------|-------|---------|
| Data Model | basic | 40/100 | Data storage via 19 custom tables |
| API Layer | basic | 52/100 | API via 45 REST routes |
| Admin UI | basic | 40/100 | UI via 9 admin pages |
| Block Editor | basic | 60/100 | Editor via 15 blocks |
| Lifecycle | missing | 0/100 | No lifecycle hooks |
| Extensibility | excellent | 100/100 | Extension via 100 custom hooks, 1 templates |
| Integrations | excellent | 100/100 | Integrates via 2 integrations, 8 CLI commands |
| Automation | basic | 50/100 | Automation via 2 cron jobs |

## What's Missing (12 gaps)

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
- **Templates lack action/filter hooks for theme customization** (medium effort)
  Fix: Add do_action() and apply_filters() hooks in templates for extensibility
- **Cross-layer wiring issues detected between JS and PHP** (medium effort)
  Fix: Fix broken AJAX calls, dead handlers, nonce mismatches, and orphaned selectors

### Other Gaps
- No block patterns provided (medium effort)
- No activation hook registered (small effort)
- No deactivation hook registered (small effort)
- No "Requires at least" WP version specified (small effort)
- No "Requires PHP" version specified (small effort)

## Code Quality: C+ (68/100)
| Category | Total | Pass | Fail | Score |
|----------|-------|------|------|-------|
| phpcs | 1672 | 0 | 1018 | 0% |
| phpstan | 1 | 0 | 0 | 0% |
| eslint | 1 | 0 | 0 | 0% |
| stylelint | 1 | 0 | 0 | 0% |
| phpcompat | 0 | 0 | 0 | 0% |
| a11y-grep | 1 | 0 | 0 | 0% |
| security-scan | 1 | 0 | 0 | 0% |
| performance-scan | 1 | 0 | 0 | 0% |
| pcp-deep | 1 | 0 | 0 | 0% |
| php-lint | 136 | 136 | 0 | 100% |
| composer-audit | 1 | 1 | 0 | 100% |
| i18n | 3 | 3 | 0 | 100% |
| plugin-check | 0 | 0 | 0 | 0% |
| bundle-size | 1 | 1 | 0 | 100% |
| ux | 5 | 5 | 0 | 100% |
| templates | 5 | 5 | 0 | 100% |
| a11y | 7 | 6 | 1 | 86% |
| admin-eval | 10 | 0 | 10 | 0% |
| frontend-eval | 10 | 9 | 1 | 90% |
| marketing | 11 | 11 | 0 | 100% |

### Top Issues (1744 total)
- [high] **Class file names should be based on the class name with "class-" prepended. Expected class-wb-gamification.php, but found wb-gamification.php.**
  File: wb-gamification.php:1
- [high] **Missing member variable doc comment**
  File: wb-gamification.php:97
- [high] **Missing doc comment for function instance()**
  File: wb-gamification.php:99
- [high] **Missing doc comment for function __construct()**
  File: wb-gamification.php:106
- [high] **Missing doc comment for function init_hooks()**
  File: wb-gamification.php:110
- [high] **Missing doc comment for function handle_unsubscribe()**
  File: wb-gamification.php:166
- [high] **Missing doc comment for function load_textdomain()**
  File: wb-gamification.php:195
- [high] **Missing doc comment for function register_routes()**
  File: wb-gamification.php:199
- [high] **Missing doc comment for function register_blocks()**
  File: wb-gamification.php:222
- [high] **Missing doc comment for function enqueue_assets()**
  File: wb-gamification.php:230
- [high] **Filenames should be all lowercase with hyphens as word separators. Expected csstest.php, but found CSSTest.php.**
  File: tests/Unit/Blocks/CSSTest.php:1
- [high] **Class file names should be based on the class name with "class-" prepended. Expected class-csstest.php, but found CSSTest.php.**
  File: tests/Unit/Blocks/CSSTest.php:1
- [high] **Missing short description in doc comment**
  File: tests/Unit/Blocks/CSSTest.php:22
- [high] **Missing doc comment for function setUp()**
  File: tests/Unit/Blocks/CSSTest.php:29
- [high] **Missing doc comment for function tearDown()**
  File: tests/Unit/Blocks/CSSTest.php:49
- [high] **Missing doc comment for function test_empty_unique_id_returns_empty_string()**
  File: tests/Unit/Blocks/CSSTest.php:55
- [high] **Missing doc comment for function test_empty_attributes_return_empty_string()**
  File: tests/Unit/Blocks/CSSTest.php:59
- [high] **Missing doc comment for function test_padding_emits_desktop_rule_with_unique_id_selector()**
  File: tests/Unit/Blocks/CSSTest.php:63
- [high] **Missing doc comment for function test_responsive_padding_emits_three_breakpoint_groups()**
  File: tests/Unit/Blocks/CSSTest.php:80
- [high] **When a multi-item array uses associative keys, each value should start on a new line.**
  File: tests/Unit/Blocks/CSSTest.php:84
  Fix: Run: phpcbf to auto-fix
  ... and 1724 more

## Action Items

### Fix Before Release
- [GAP] Some REST routes lack proper permission callbacks — Add permission_callback with current_user_can() checks to all REST routes
- [GAP] Custom tables exist but no uninstall hook to clean them up — Add uninstall.php or register_uninstall_hook to drop custom tables on plugin deletion
- [GAP] Custom tables exist but no activation hook to create them — Add register_activation_hook to create tables via dbDelta() on plugin activation
- [GAP] Cron jobs scheduled but no deactivation hook to clear them — Add register_deactivation_hook to wp_clear_scheduled_hook() all plugin cron events

### Should Fix
- [GAP] Admin pages exist but no settings registered via Settings API — Use register_setting() and add_settings_field() for proper WP settings management
- [GAP] Templates lack action/filter hooks for theme customization — Add do_action() and apply_filters() hooks in templates for extensibility
- [GAP] Cross-layer wiring issues detected between JS and PHP — Fix broken AJAX calls, dead handlers, nonce mismatches, and orphaned selectors
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected csstest.php, but found CSSTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-csstest.php, but found CSSTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_empty_unique_id_returns_empty_string()
- [phpcs] Missing doc comment for function test_empty_attributes_return_empty_string()
- [phpcs] Missing doc comment for function test_padding_emits_desktop_rule_with_unique_id_selector()
- [phpcs] Missing doc comment for function test_responsive_padding_emits_three_breakpoint_groups()
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_border_radius_uses_per_corner_object()
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_box_shadow_uses_default_color_when_missing()
- [phpcs] Missing doc comment for function test_responsive_font_size_emits_per_breakpoint()
- [phpcs] Missing doc comment for function test_visibility_classes_emit_when_flags_set()
- [phpcs] Missing doc comment for function test_visibility_returns_empty_string_when_no_flags()
- [phpcs] Missing doc comment for function test_filter_can_override_generated_css()
- [phpcs] Use Yoda Condition checks, you must.
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected registrartest.php, but found RegistrarTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-registrartest.php, but found RegistrarTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_no_build_dir_is_a_noop()
- [phpcs] Missing doc comment for function test_empty_build_dir_is_a_noop()
- [phpcs] Missing doc comment for function test_registers_each_block_once()
- [phpcs] Missing doc comment for function test_skips_blocks_already_in_wp_registry()
- [phpcs] Missing doc comment for function test_skips_re_registration_within_same_request()
- [phpcs] Missing doc comment for function seed_block()
- [phpcs] Missing doc comment for function rrmdir()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected wpblocktyperegistrystub.php, but found WPBlockTypeRegistryStub.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-wp-block-type-registry.php, but found WPBlockTypeRegistryStub.php.
- [phpcs] Missing doc comment for class WP_Block_Type_Registry
- [phpcs] Missing short description in doc comment
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function get_instance()
- [phpcs] Missing doc comment for function is_registered()
- [phpcs] Missing doc comment for function _add_for_test()
- [phpcs] Missing doc comment for function _reset()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected redemptionstorerendertest.php, but found RedemptionStoreRenderTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-redemptionstorerendertest.php, but found RedemptionStoreRenderTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Parenthesis required when creating a new anonymous class. — Run: phpcbf to auto-fix
- [phpcs] Missing member variable doc comment
- [phpcs] Missing doc comment for function get_results()
- [phpcs] Missing doc comment for function get_var()
- [phpcs] Missing doc comment for function prepare()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function test_render_emits_interactivity_namespace_and_drops_inline_script_tags()
- [phpcs] Missing doc comment for function test_render_exposes_endpoint_nonce_and_i18n_via_data_attributes()
- [phpcs] Missing doc comment for function test_render_emits_styled_confirm_panel_replacing_window_confirm()
- [phpcs] Missing doc comment for function test_render_registers_per_instance_css_via_block_css_helper()
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function test_logged_out_visitor_sees_login_cta_not_redeem_button()
- [phpcs] Missing doc comment for function test_render_passes_block_data_through_block_hooks_actions()
- [phpcs] Missing doc comment for function render()
- [phpcs] Missing doc comment for function fill_defaults()
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function reflect_styles()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected standardblockcontracttest.php, but found StandardBlockContractTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-standardblockcontracttest.php, but found StandardBlockContractTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Use Yoda Condition checks, you must.
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Use Yoda Condition checks, you must.
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Parenthesis required when creating a new anonymous class. — Run: phpcbf to auto-fix
- [phpcs] Missing member variable doc comment
- [phpcs] Missing doc comment for function get_results()
- [phpcs] Missing doc comment for function get_var()
- [phpcs] Missing doc comment for function get_row()
- [phpcs] Missing doc comment for function prepare()
- [phpcs] Missing doc comment for function query()
- [phpcs] Missing doc comment for function insert()
- [phpcs] Missing doc comment for function update()
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing doc comment for function blockProvider()
- [phpcs] Missing short description in doc comment
- [phpcs] Doc comment for parameter "$slug" missing
- [phpcs] Missing short description in doc comment
- [phpcs] Doc comment for parameter "$slug" missing
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function render()
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function reflect_styles()
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected blockhooksstub.php, but found BlockHooksStub.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-blockhooks.php, but found BlockHooksStub.php.
- [phpcs] Missing doc comment for class BlockHooks
- [phpcs] Missing doc comment for function before()
- [phpcs] Missing doc comment for function after()
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
- [phpcs] Missing short description in doc comment
- [phpcs] Doc comment for parameter "$handler_method" missing
- [phpcs] Doc comment for parameter "$expected_block_slug" missing
- [phpcs] Missing doc comment for function shortcodeDispatchProvider()
- [phpcs] Missing doc comment for function test_qa_pages_map_covers_every_block_in_src_blocks()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected levelenginetest.php, but found LevelEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-levelenginetest.php, but found LevelEngineTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing short description in doc comment
- [phpcs] Missing short description in doc comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'icon_url'". Found: 4 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 4 spaces — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'icon_url'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Missing short description in doc comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'icon_url'". Found: 4 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 4 spaces — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'icon_url'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 3 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 3 found — Run: phpcbf to auto-fix
- [phpcs] Missing short description in doc comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 3 spaces — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'icon_url'". Found: 3 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'min_points'". Found: 3 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected ruleenginetest.php, but found RuleEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-ruleenginetest.php, but found RuleEngineTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing short description in doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 4 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 4 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 2 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space after comma in argument list; 2 found — Run: phpcbf to auto-fix
- [phpcs] Missing short description in doc comment
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected nudgeenginetest.php, but found NudgeEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-nudgeenginetest.php, but found NudgeEngineTest.php.
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgeenginetest.php, but found BadgeEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgeenginetest.php, but found BadgeEngineTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing short description in doc comment
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 3; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected streakenginetest.php, but found StreakEngineTest.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-streakenginetest.php, but found StreakEngineTest.php.
- [phpcs] Missing short description in doc comment
- [phpcs] Missing doc comment for function setUp()
- [phpcs] Missing doc comment for function tearDown()
- [phpcs] Missing short description in doc comment
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $wpdb
- [phpcs] Missing short description in doc comment
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
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 4; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space before comment text but found 4; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$table} at "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`"
- [phpcs] Overriding WordPress globals is prohibited. Found assignment to $role
- [phpcs] Missing @package tag in file comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] You must use "/**" style comments for a function comment
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Doc comment for parameter "$user_id" missing
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Missing @package tag in file comment
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 3 tabs, found 2 — Run: phpcbf to auto-fix
- [phpcs] Closing PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Line indented incorrectly; expected 4 tabs, found 3 — Run: phpcbf to auto-fix
- [phpcs] Closing PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Short array syntax is not allowed — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Inline comments must end in full-stops, exclamation marks, or question marks
- [phpcs] Missing @package tag in file comment
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected registrar.php, but found Registrar.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-registrar.php, but found Registrar.php.
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Newline required after opening brace — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected css.php, but found CSS.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-css.php, but found CSS.php.
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Closing PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected settingspage.php, but found SettingsPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-settingspage.php, but found SettingsPage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected webhooksadminpage.php, but found WebhooksAdminPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-webhooksadminpage.php, but found WebhooksAdminPage.php.
- [phpcs] Opening PHP tag must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Line indented incorrectly; expected 7 tabs, found 6 — Run: phpcbf to auto-fix
- [phpcs] The CASE body must start on the line following the statement — Run: phpcbf to auto-fix
- [phpcs] The CASE body must start on the line following the statement — Run: phpcbf to auto-fix
- [phpcs] The CASE body must start on the line following the statement — Run: phpcbf to auto-fix
- [phpcs] The CASE body must start on the line following the statement — Run: phpcbf to auto-fix
- [phpcs] The DEFAULT body must start on the line following the statement — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected manualawardpage.php, but found ManualAwardPage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-manualawardpage.php, but found ManualAwardPage.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected redemptionstorepage.php, but found RedemptionStorePage.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-redemptionstorepage.php, but found RedemptionStorePage.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] A function call to esc_html_e() with texts containing placeholders was found, but was not accompanied by a "translators:" comment on the line above to clarify the meaning of the placeholders.
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected qapages.php, but found QAPages.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-qapages.php, but found QAPages.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected membercommand.php, but found MemberCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-membercommand.php, but found MemberCommand.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected qaseedcommand.php, but found QASeedCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-qaseedcommand.php, but found QASeedCommand.php.
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Expected 1 space between the comma and "'compare'". Found: 2 spaces — Run: phpcbf to auto-fix
- [phpcs] When a multi-item array uses associative keys, each value should start on a new line. — Run: phpcbf to auto-fix
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected replaycommand.php, but found ReplayCommand.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-replaycommand.php, but found ReplayCommand.php.
- [phpcs] Expected 1 space before comment text but found 4; use block comment if you need indentation — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected challengestream.php, but found ChallengeStream.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-challengestream.php, but found ChallengeStream.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected backfiller.php, but found Backfiller.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-backfiller.php, but found Backfiller.php.
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$bp_activity} at "SELECT id, user_id, action, content, date_recorded FROM {$bp_activity}\n
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$bp_activity} at "SELECT id, item_id, content FROM {$bp_activity}\n
- [phpcs] Use placeholders and $wpdb->prepare(); found interpolated variable {$bp_activity} at "SELECT id, item_id, content FROM {$bp_activity}\n
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgestream.php, but found BadgeStream.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgestream.php, but found BadgeStream.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected levelstream.php, but found LevelStream.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-levelstream.php, but found LevelStream.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected kudosstream.php, but found KudosStream.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-kudosstream.php, but found KudosStream.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected activitycard.php, but found ActivityCard.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-activitycard.php, but found ActivityCard.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Missing doc comment for function default_badge_image()
- [phpcs] Missing doc comment for function default_level_image()
- [phpcs] Missing doc comment for function default_kudos_image()
- [phpcs] Missing doc comment for function default_challenge_image()
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected activityintegration.php, but found ActivityIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-activityintegration.php, but found ActivityIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected refundhandler.php, but found RefundHandler.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-refundhandler.php, but found RefundHandler.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-hooksintegration.php, but found HooksIntegration.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected levelscontroller.php, but found LevelsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-levelscontroller.php, but found LevelsController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected actionscontroller.php, but found ActionsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-actionscontroller.php, but found ActionsController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected apikeyscontroller.php, but found ApiKeysController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-apikeyscontroller.php, but found ApiKeysController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected rulescontroller.php, but found RulesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-rulescontroller.php, but found RulesController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected kudoscontroller.php, but found KudosController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-kudoscontroller.php, but found KudosController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgescontroller.php, but found BadgesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgescontroller.php, but found BadgesController.php.
- [phpcs] Expected 67 spaces after parameter type; 68 found — Run: phpcbf to auto-fix
- [phpcs] Expected 1 spaces after parameter type; 2 found — Run: phpcbf to auto-fix
- [phpcs] Missing doc comment for function update_item()
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected communitychallengescontroller.php, but found CommunityChallengesController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-communitychallengescontroller.php, but found CommunityChallengesController.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected openapicontroller.php, but found OpenApiController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-openapicontroller.php, but found OpenApiController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected cohortsettingscontroller.php, but found CohortSettingsController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-cohortsettingscontroller.php, but found CohortSettingsController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected badgesharecontroller.php, but found BadgeShareController.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-badgesharecontroller.php, but found BadgeShareController.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected email.php, but found Email.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-email.php, but found Email.php.
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
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected ratelimiter.php, but found RateLimiter.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-ratelimiter.php, but found RateLimiter.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected recapengine.php, but found RecapEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-recapengine.php, but found RecapEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected shortcodehandler.php, but found ShortcodeHandler.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-shortcodehandler.php, but found ShortcodeHandler.php.
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
- [phpcs] Opening parenthesis of a multi-line function call must be the last content on the line — Run: phpcbf to auto-fix
- [phpcs] Only one argument is allowed per line in a multi-line function call — Run: phpcbf to auto-fix
- [phpcs] Closing parenthesis of a multi-line function call must be on a line by itself — Run: phpcbf to auto-fix
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected blockhooks.php, but found BlockHooks.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-blockhooks.php, but found BlockHooks.php.
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
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected log.php, but found Log.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-log.php, but found Log.php.
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
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected manifestloader.php, but found ManifestLoader.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-manifestloader.php, but found ManifestLoader.php.
- [phpcs] Filenames should be all lowercase with hyphens as word separators. Expected levelengine.php, but found LevelEngine.php.
- [phpcs] Class file names should be based on the class name with "class-" prepended. Expected class-levelengine.php, but found LevelEngine.php.
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [phpcs] Using short ternaries is not allowed as they are rarely used correctly
- [a11y] 1 images without alt attribute — Add alt attribute to all <img> tags. Use alt="" for decorative images.
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] Direct $_POST to update_option (bypasses Settings API) — Use register_setting() with sanitize_callback and settings_fields() for proper form handling
- [admin-eval] POST form without nonce verification — Add wp_nonce_field() inside the form and verify with wp_verify_nonce() or check_admin_referer() on submission
- [admin-eval] POST form without nonce verification — Add wp_nonce_field() inside the form and verify with wp_verify_nonce() or check_admin_referer() on submission
- [admin-eval] POST form without nonce verification — Add wp_nonce_field() inside the form and verify with wp_verify_nonce() or check_admin_referer() on submission
- [admin-eval] POST form without nonce verification — Add wp_nonce_field() inside the form and verify with wp_verify_nonce() or check_admin_referer() on submission
- [admin-eval] POST form without nonce verification — Add wp_nonce_field() inside the form and verify with wp_verify_nonce() or check_admin_referer() on submission
- [admin-eval] POST form without nonce verification — Add wp_nonce_field() inside the form and verify with wp_verify_nonce() or check_admin_referer() on submission
- [frontend-eval] Modal/popup missing close button — Add a visible close button (.close, .dismiss, or × character) to the modal

### Recommended
- [GAP] No block patterns provided — Add block patterns to give users pre-built layouts using your blocks
- [GAP] No activation hook registered — Add register_activation_hook() for initial setup (version check, default options, flush rewrite rules)
- [GAP] No deactivation hook registered — Add register_deactivation_hook() for cleanup (clear cron, flush rewrite rules)
- [GAP] No "Requires at least" WP version specified — Add "Requires at least: 6.0" (or your minimum) to the plugin header
- [GAP] No "Requires PHP" version specified — Add "Requires PHP: 7.4" (or your minimum) to the plugin header
- [phpcs] Use of a direct database call is discouraged.
- [phpcs] Direct database call without caching detected. Consider using wp_cache_get() / wp_cache_set() or wp_cache_delete().
- [phpcs] The method parameter $rest is never used
- [phpcs] File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
- [phpcs] File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: mkdir().
- [phpcs] File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: file_put_contents().
- [phpcs] json_encode() is discouraged. Use wp_json_encode() instead.
- [phpcs] unlink() is discouraged. Use wp_delete_file() to delete a file.
- [phpcs] File operations should use WP_Filesystem methods instead of direct PHP filesystem calls. Found: rmdir().
- [phpcs] Method name "_add_for_test" should not be prefixed with an underscore to indicate visibility
  ... and 704 more

---
Total: 1868 code checks | 12 gaps found | 1756 action items

## Customer Expectation Analysis
Detected Category: **Learning Management System** (59% confidence)
Also matches: Community Platform (50%), E-Commerce (44%)

Feature Completeness: **73%** (7 found / 2 partial / 2 missing out of 11)

| Feature | Importance | Status | Industry Context |
|---------|-----------|--------|-----------------|
| Course Builder | must-have | FOUND | Teachable has drag-drop course builder |
| Quizzes & Assessments | must-have | FOUND | Teachable has 3 quiz types |
| Progress Tracking | must-have | FOUND | Netflix-like progress bars |
| Certificates | should-have | FOUND | Coursera/Udemy certificates are LinkedIn-shareable |
| Student Dashboard | must-have | MISSING | Every LMS platform has a student dashboard |
| Drip Content | should-have | MISSING | Teachable, Kajabi, Thinkific all have drip scheduling |
| Payment & Subscriptions | must-have | FOUND | Teachable takes 0-5% transaction fee |
| Discussion / Q&A | should-have | FOUND | Udemy has Q&A per lecture |
| Email Automation | should-have | PARTIAL | Kajabi has built-in email marketing |
| Instructor Tools | should-have | FOUND | Udemy is multi-instructor |
| Video Hosting / Player | must-have | PARTIAL | Teachable/Thinkific have built-in video hosting |

### Must-Have Gaps (customers will leave without these)
- **Student Dashboard**: My courses, progress overview, upcoming lessons, achievements
  Industry: Every LMS platform has a student dashboard. Teachable, Thinkific, Kajabi all have one
- **Video Hosting / Player**: Video lessons with player controls, speed adjustment, resume playback
  Industry: Teachable/Thinkific have built-in video hosting. Wistia-like players expected

### Should-Have Gaps (competitors have these)
- **Drip Content**: Release lessons on a schedule — daily, weekly, or days after enrollment
  Industry: Teachable, Kajabi, Thinkific all have drip scheduling. Prevents binge-and-refund
- **Email Automation**: Welcome emails, lesson reminders, completion congrats, abandoned cart
  Industry: Kajabi has built-in email marketing. Teachable sends auto-emails. Critical for completion rates
