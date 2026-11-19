# wppqa baseline — 2026-11-17

Branch `1.6.8` at `4ac3e44`. Static checks, run on the repo with the wp-plugin-qa MCP.

| Check | Passed | Failed | Skipped | Duration |
|---|---:|---:|---:|---:|
| `plugin_dev_rules` | 5 | 4 in bundled Action Scheduler, **0 in plugin code** | 0 | 252ms |
| `rest_js_contract` | 16 | **0** | 0 | 46ms |
| `wiring_completeness` | 0 | **0** | 1 | 0ms |
| `template_contract` | 0 | **0** | 1 | 95ms |

## RELEASE-READY: yes

### plugin_dev_rules
The four high findings are all in `libs/woocommerce/action-scheduler/` (WooCommerce's Action Scheduler, bundled unmodified): `ActionScheduler_Abstract_ListTable.php:184, 189, 632` and `lib/WP_Async_Request.php:172`. A vendored library is never patched in place; upstream owns it. The May baseline predates the bundled copy, which is why it showed 9/9.

Low warnings (not failures): admin button height 34px in `assets/css/admin/components.css:542` (plus its RTL and min copies). Admin-only, desktop surface; tracked, not a 1.6.8 change.

### rest_js_contract
16 route/JS pairs, no envelope drift.

### wiring_completeness, template_contract
Skipped by design: settings render through blocks (`render.php`) and `src/Admin/` pages, not `templates/` loaders.

## Companion gate run the same day
WordPress.org Plugin Check on the same code in a real WordPress (Docker lab): 0 ERRORs in shipped code (`src/`, `integrations/`, `wb-gamification.php`), warnings 376 = `audit/plugin-check-warning-baseline.txt`.
