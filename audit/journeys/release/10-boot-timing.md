---
journey: boot-timing
plugin: wb-gamification
priority: critical
roles: [administrator]
covers: [9914460166, 9927572402, 9927279782, hook-timing, admin-page-registration]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "wp-cli on PATH (Local Site Shell satisfies this)"
  - "User ID 1 is an administrator"
estimated_runtime_minutes: 3
---

# Boot timing — every admin page registers on the request that needs it

Hook-timing drift was the most expensive recurring bug class in v1.4.0 — the
wizard activation flow alone was reopened by QA three times (Basecamp
#9914460166) before a structural refactor lifted `SetupWizard::init()` out
of a nested `plugins_loaded` callback. The community-challenges blank-screen
bug (#9927572402) had the same root shape: a hook callback registered at
the wrong WordPress lifecycle event so the asset bundle never enqueued.

This journey asserts the boot invariants no static gate enforces today:

1. Every admin module's `::init()` is invoked **directly** from the plugin's
   primary `register_hooks()` callback — never via a secondary
   `add_action('plugins_loaded', ...)` registered from inside another
   plugins_loaded callback.
2. Every admin page is on the menu after `admin_init` fires.
3. Every REST controller registers its routes after `rest_api_init`.
4. The setup wizard's `maybe_redirect` is bound on `admin_init@1` and
   consumes the `wb_gam_pending_setup_redirect` option exactly once.
5. The community-challenges admin page enqueues `admin-rest-form.js` when
   visited via either hook name (`gamification_page_*` or `admin_page_*`).

## Setup

- Site: `$SITE_URL` = `http://wb-gamification.local`
- Tools: `wp eval`, `curl`, MySQL via the Local-WP MCP
- Fixture: clear the wizard-complete sentinel so the activation flow can
  fire a fresh redirect.

```bash
wp option delete wb_gam_wizard_complete
wp option delete wb_gam_pending_setup_redirect
wp user meta delete 1 wb_gam_setup_seen
```

## Steps

### 1. No nested plugins_loaded callbacks

- **Action**: `grep -nP "add_action\(\s*'plugins_loaded'" src/ wb-gamification.php --include='*.php' -r`
- **Expect**: zero matches **inside any function body that itself runs at `plugins_loaded`**. The plugin's primary `register_hooks()` is at `plugins_loaded@0`; nothing under it may add another `plugins_loaded` callback.
- **On fail**: the file printing a match is the new boot fragility. Move its `::init()` call to be direct.

### 2. Every admin module's init is wired

- **Action**:
  ```bash
  wp eval '
    $r = new \ReflectionClass( \WB_Gamification::class );
    $m = $r->getMethod( "register_hooks" );
    echo file_get_contents( $r->getFileName() );
  ' | grep -oE 'WBGam\\Admin\\[A-Za-z]+::init\(\)' | sort -u
  ```
- **Expect**: at minimum the following 14 modules listed:
  ```
  WBGam\Admin\AnalyticsDashboard::init()
  WBGam\Admin\ApiKeysPage::init()
  WBGam\Admin\BadgeAdminPage::init()
  WBGam\Admin\ChallengeManagerPage::init()
  WBGam\Admin\CohortSettingsPage::init()
  WBGam\Admin\CommunityChallengesPage::init()
  WBGam\Admin\ManualAwardPage::init()
  WBGam\Admin\PointTypesPage::init()
  WBGam\Admin\PointTypeConversionsPage::init()
  WBGam\Admin\RedemptionStorePage::init()
  WBGam\Admin\SettingsPage::init()
  WBGam\Admin\SetupWizard::init()
  WBGam\Admin\SubmissionsPage::init()
  WBGam\Admin\WebhooksAdminPage::init()
  ```
- **On fail**: the missing module isn't booted at `plugins_loaded@0`. Add the direct `Module::init()` call to `WB_Gamification::register_hooks()`.

### 3. Every admin page renders without 5xx

- **Action**: for each menu slug under `wb-gamification*`, hit `/wp-admin/admin.php?page={slug}&autologin=1` and capture the HTTP status.
  ```bash
  for slug in wb-gamification wb-gamification-setup wb-gam-rules wb-gam-badges \
              wb-gam-challenges wb-gam-community-challenges wb-gam-redemption-store \
              wb-gam-api-keys wb-gam-webhooks wb-gam-point-types \
              wb-gam-point-type-conversions wb-gam-cohort-settings \
              wb-gam-submissions wb-gam-manual-award wb-gam-analytics; do
    code=$(curl -s -o /dev/null -w "%{http_code}" "$SITE_URL/wp-admin/admin.php?page=${slug}&autologin=1")
    echo "$slug $code"
  done
  ```
- **Expect**: every line ends `200` (admin page renders) or `302` (auth redirect on a logged-out probe). Never `500`, `404`, or `400`.
- **On fail**: the slug returning a non-2xx/3xx code is missing from the admin menu (registration didn't fire) or its render method is fataling. Inspect the page class's `register_page()` and check the `admin_menu` hook timing.

### 4. Setup wizard auto-redirect fires once on first admin visit

- **Action**:
  ```bash
  curl -sI -L "$SITE_URL/wp-admin/admin.php?page=wb-gamification&autologin=1" \
    | head -10
  ```
- **Expect**: first response is `302 Found` with `Location: ?page=wb-gamification-setup`. Subsequent requests to the same URL respond `200` (the per-user `wb_gam_setup_seen` sentinel prevents the loop).
- **On fail**: `SetupWizard::maybe_redirect` is not bound on `admin_init@1`. Check `SetupWizard::init()` registers `add_action('admin_init', [self::class, 'maybe_redirect'], 1)` and that the activation hook (or the first-visit fallback) sets the option.

### 5. Community challenges page enqueues admin-rest-form.js

- **Action**: hit `/wp-admin/admin.php?page=wb-gam-community-challenges&autologin=1`, then grep the response for `admin-rest-form.js`.
  ```bash
  curl -s "$SITE_URL/wp-admin/admin.php?page=wb-gam-community-challenges&autologin=1" \
    | grep -c 'admin-rest-form'
  ```
- **Expect**: count is `>= 1`.
- **On fail**: the page's enqueue hook is matching the wrong WordPress hook name. Inspect `CommunityChallengesPage::register_admin_page()` and confirm the enqueue check covers `admin_page_wb-gam-community-challenges` (hidden parent) + `gamification_page_wb-gam-community-challenges` (defensive future case) + `gamification_page_wb-gam-challenges` (unified tab).

### 6. REST controllers register routes after rest_api_init

- **Action**:
  ```bash
  curl -s "$SITE_URL/wp-json/wb-gamification/v1" | jq -r '.routes | keys[]' | sort
  ```
- **Expect**: at minimum all 17 documented controllers register at least one route — `members`, `points`, `badges`, `leaderboard`, `actions`, `kudos`, `badge-share`, `challenges`, `events`, `webhooks`, `rules`, `recap`, `credential`, `redemption`, `capabilities`, `levels`, `api-key-auth`. Spot-check that each `routes` key starts with `/wb-gamification/v1/`.
- **On fail**: the controller class's `register_routes()` either wasn't invoked or registered before `rest_api_init`. Check `WB_Gamification::register_routes()` is hooked to `rest_api_init` and the controller's class is autoload-resolvable.

### 7. No silent fatal during admin bootstrap

- **Action**:
  ```bash
  : > /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/debug.log
  curl -s "$SITE_URL/wp-admin/admin.php?page=wb-gamification&autologin=1" > /dev/null
  grep -E "PHP Fatal|PHP Parse|Uncaught" \
       /Users/varundubey/Local\ Sites/wb-gamification/app/public/wp-content/debug.log
  ```
- **Expect**: zero matches.
- **On fail**: the trace points at the boot-time fatal. Address before any other step is meaningful.

## Pass criteria

ALL of the following hold:

1. Step 1 — no nested `plugins_loaded` callbacks anywhere in the plugin's PHP.
2. Step 2 — every admin module class is invoked directly from `register_hooks()`.
3. Step 3 — every admin page slug returns 2xx or 3xx (no 4xx/5xx).
4. Step 4 — first-admin-visit triggers the wizard redirect; second visit does not (loop guard intact).
5. Step 5 — community challenges page enqueues `admin-rest-form.js`.
6. Step 6 — all 17 REST controllers' routes resolve under `/wb-gamification/v1`.
7. Step 7 — debug.log is clean of fatals during the admin bootstrap.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Step 1 fails — nested plugins_loaded match | A new admin module reintroduced the fragile pattern fixed in `f877744` | `wb-gamification.php` + the module's class |
| Step 2 fails — module missing from grep | The module's `::init()` isn't called from `register_hooks()` directly | `wb-gamification.php` `register_hooks()` body |
| Step 3 returns 500 on a slug | Page class fataling during render — likely a missing template or undefined helper | the page's `render()` method + `class-WBGam_Admin_*.php` |
| Step 4 returns 200 on first visit (no redirect) | Activation flag not set + first-visit fallback dead | `SetupWizard::maybe_redirect` + activation hook in main plugin file |
| Step 5 returns 0 | Hook-name mismatch in the enqueue allowlist | `CommunityChallengesPage::register_admin_page()` line ~45 |
| Step 6 missing controller | `register_routes()` not wired or class autoload broken | `WB_Gamification::register_routes()` + the controller file under `src/API/` |
| Step 7 finds fatal | Read the trace; address that file first | `wp-content/debug.log` |

## Why this journey exists

3 of the 26 v1.4.0 Ready-for-Testing bugs (#9914460166, #9927572402,
#9927279782) shared the same root cause: a hook bound at the wrong
WordPress lifecycle event, so the callback ran on the dev sandbox's quick
admin-init but not on QA's freshly-activated install. Each was reported
"not resolved" by QA at least once before a structural fix landed.

A single boot-timing journey would have caught all three on the first
implementation. Steps 1–7 above re-lock the boot invariants so the next
nested-plugins_loaded callback fails CI, not QA.
