# Architecture — WB Gamification

**Status:** living doc · update on every structural change
**Audience:** anyone landing on this codebase — devs, QA, future-you

This is the "where does X go?" guide. If you're about to add a feature, fix a bug, or restructure something — read here first.

---

## Authoritative skills (read these too)

This doc is the plugin-specific application of two canonical skills. **Skills win over this doc** when they conflict; if a skill ships a new pattern, update this doc to match.

| Skill | What it owns | When to invoke |
|---|---|---|
| **`wp-plugin-onboard`** | The `audit/` inventory: `manifest.json`, `FEATURE_AUDIT.md`, `CODE_FLOWS.md`, `ROLE_MATRIX.md`, `graph.html`, READ-FIRST CLAUDE.md pointer, `wppqa-baseline-{date}/SUMMARY.md`. Machine-generated and refreshable. | First clone of a plugin OR after any non-trivial structural change. Run `/wp-plugin-onboard --refresh`. |
| **`wp-plugin-development`** (this is the day-to-day skill) | Code patterns: structure, hooks, REST, DB, cache, security, admin UI, frontend, blocks, email, performance, CI discipline. The 7-layer canonical architecture. | Every PR, feature, bug fix, or refactor. |
| **`wp-plugin-release`** | Tagging, versioning, building zip, publishing. | Cutting a release. |

**Strict ownership boundaries** (per `wp-plugin-onboard` skill):
- `audit/` is owned **only** by `wp-plugin-onboard` — never hand-edit, always refresh
- `plan/` (singular — per skill convention) is human-authored — release plans, design docs, audits like `CODEBASE-AUDIT-2026-05-06.md`
- `docs/website/` is customer-facing (owned by docs team / `wbcom-docs` MCP)

## Enforcement: wp-plugin-qa MCP — the gate, not this doc

This doc describes patterns. The **`wp-plugin-qa` MCP** enforces them. Prose contracts get skimmed; the MCP fails the build when a rule is violated.

### Course-correction loop — on every PR

```
1. Build a change
2. wppqa_audit_plugin --plugin_path=/path/to/wb-gamification
3. Fix every high-severity finding
4. Re-run the audit
5. Only ship when failed=0
```

### Tools to run + when

| MCP tool | Catches | When |
|---|---|---|
| `wppqa_check_plugin_dev_rules` | Nonce without capability, $_POST iteration, browser alert/confirm, inline onclick, tap targets <40px, raw -1 inputs | Every PR touching PHP or JS |
| `wppqa_check_rest_js_contract` | JS reads `data.foo` while PHP returns `{bar}` — silent blank-state bugs | Every PR touching a REST controller OR its JS consumer |
| `wppqa_check_wiring_completeness` | Settings saved to DB that no template reads | Every PR adding a setting or admin form |
| `wppqa_check_a11y` | A11y regressions | Every PR touching CSS / templates |
| `wppqa_check_api` | Live REST behaviour — auth, nonce, malformed input | Pre-release on Local |
| `wppqa_audit_plugin` | All of the above in one pass | Pre-release gate — must return failed=0 |

Latest baseline: `audit/wppqa-baseline-2026-05-03/SUMMARY.md` — `failed=0` across all 4 checks.

---

## TL;DR — design principle

> **Events in → rules evaluate → effects out.**
>
> The Engine owns three surfaces: event normalisation, rule evaluation, output.
> Everything else (BuddyPress display, WooCommerce triggers, mobile, blocks, admin pages) is a **consumer**.

A new feature is almost always one of:
1. A new **event** (something users do that should be tracked) → add it to a manifest under `integrations/`
2. A new **rule** evaluating those events → add to `wb_gam_rules` via REST or admin; if it's a new rule type, extend `RuleEngine`
3. A new **effect** (output: badge, points multiplier, notification) → most exist already; if not, new `*Engine` in `src/Engine/`
4. A new **surface** that displays the engine state → new block in `src/Blocks/<slug>/`, new admin page in `src/Admin/`, or new REST controller in `src/API/`

Don't put business logic in admin pages, blocks, or REST controllers. They're thin adapters over the engine.

---

## Top-level layout

```
wb-gamification/
├── wb-gamification.php           # Plugin bootstrap — defines constants, loads autoloader, registers hooks
├── uninstall.php                 # Drops all 20 tables on plugin removal
├── composer.json + vendor/       # PSR-4 autoload + dependencies (Action Scheduler, etc.)
├── package.json + node_modules/  # Block build (npm run build)
├── webpack.config.js             # @wordpress/scripts default config — npm run build
├── bin/build-release.sh          # Release packager (rsync + zip; replaces legacy Gruntfile)
├── phpstan.neon.dist             # PHPStan level 5 config
├── phpunit.xml.dist              # PHPUnit config
├── .phpcs.xml                    # WPCS config (project-specific allowlist)
│
├── src/                          # All PSR-4 PHP — namespace: WBGam\ → src/
├── integrations/                 # Manifest files: integrations/<host>.php (auto-loaded)
├── templates/                    # User-overridable templates (themes can override)
│   └── emails/                   # Email templates — themes override at {theme}/wb-gamification/emails/
├── assets/                       # Source CSS / JS / images that aren't blocks
├── build/                        # Compiled blocks (output of npm run build) — committed
├── languages/                    # .pot + .po + .mo files (i18n)
├── sdk/                          # Bundled SDKs / PHP libraries we ship for downstream consumers
│
├── tests/                        # PHPUnit tests
│   └── Unit/                     # Mirror of src/ (Admin, Blocks, Engine subdirs)
│
├── audit/                        # MACHINE-GENERATED inventory. Hand-edits get overwritten.
│   ├── manifest.json             # Canonical inventory — read first, don't grep
│   ├── manifest.summary.json     # Counts only
│   ├── FEATURE_AUDIT.md
│   ├── CODE_FLOWS.md
│   ├── ROLE_MATRIX.md            # REST permission map (which __return_true is allowed where)
│   ├── journeys/                 # Acceptance journey scripts (release tiers)
│   ├── release-runs/             # Per-date release verification reports
│   └── wppqa-baseline-*/         # Quality-baseline snapshots
│
├── plan/                        # HUMAN-AUTHORED design docs + roadmap
│   ├── ARCHITECTURE.md           # ← this file
│   ├── COMPETITIVE-ANALYSIS.md
│   ├── CODEBASE-AUDIT-2026-05-06.md
│   ├── v1.0-release-plan.md
│   ├── v1.1-release-plan.md
│   ├── QA-MANUAL-TEST-PLAN.md    # Persona walkthrough for QA team
│   ├── V1-RELEASE-VERIFICATION-PLAN.md
│   └── decisions/                # ADRs — one MD per significant decision (TODO: create)
│
├── docs/website/                 # Customer-facing documentation (owned by docs team)
├── examples/                     # 10 third-party integration samples
├── bin/                          # Local CI scripts + git hooks
└── dist/                         # Built release zips (output of bin/build-release.sh)
```

## Canonical 7-layer architecture (target)

Per `wp-plugin-development` skill (`references/layered-architecture.md`), every Wbcom plugin should resolve to these 7 layers:

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Bootstrap        wb-gamification.php → Engine\Engine     │
├─────────────────────────────────────────────────────────────┤
│ 2. Container        Service registration / lazy factories   │
├─────────────────────────────────────────────────────────────┤
│ 3. Repository       Custom-table SQL ONLY. No HTTP, no HTML │
├─────────────────────────────────────────────────────────────┤
│ 4. Services         Business logic. Returns structured data │
│   + Integrations/   Third-party adapters per platform       │
├─────────────────────────────────────────────────────────────┤
│ 5. Surface adapters REST controllers / CLI / blocks         │
│   (HTTP/CLI/blocks) Thin glue. Validates → service → format │
├─────────────────────────────────────────────────────────────┤
│ 6. Templates        Presentation only. Theme-overridable    │
├─────────────────────────────────────────────────────────────┤
│ 7. Admin UI         Menu pages + settings. Submits to REST  │
└─────────────────────────────────────────────────────────────┘
```

**Layer rule**: a higher layer may depend on layers below, never above. Repository (3) doesn't know REST (5). Services (4) don't echo HTML. Templates (6) don't run SQL.

### Where WB Gamification stands today

| Layer | Canonical Wbcom | WB Gamification | Match? |
|---|---|---|---|
| 1. Bootstrap | `plugin.php` + `Core/Plugin.php` | `wb-gamification.php` + `WBGam\Engine\Engine` | ✅ |
| 2. Container | `Core/ServiceContainer.php` + `register_services()` | Implicit (no explicit DI container; engines are singletons via `Registry`) | ⚠ partial |
| 3. Repository | `Repository/<Domain>Repository.php` (SQL only) | DB queries inlined in `Engine/*Engine.php` classes | ❌ — Repository layer not extracted |
| 4. Services | `Services/<Capability>Service.php` | `WBGam\Engine\*Engine.php` (mixes service + repository concerns) | ⚠ engines do both jobs |
| 5. Surface adapters | `REST/Controller/`, `CLI/`, `Blocks/`, `Shortcodes/` | `WBGam\API\*Controller.php`, `WBGam\CLI\`, `WBGam\Blocks\<slug>/`, `WBGam\Engine\ShortcodeHandler` | ✅ aligned |
| 6. Templates | `templates/*.php` + `templates/partials/` | `templates/emails/`; block-PHP renderers live in `src/Blocks/<slug>/render.php` | ⚠ partial |
| 7. Admin UI | `Admin/*Page.php` + `Admin/Settings/*.php` | `WBGam\Admin\*Page.php` | ✅ |

**Gap**: Repository extraction (layer 3 split out of layer 4). For v1.0 we accept the deviation — the engines are stable, tested, and refactoring 40 files now is risky pre-launch. **Tracked as a v1.x roadmap item** — see `plan/CODEBASE-AUDIT-2026-05-06.md` § "Tier 2: Subnamespace src/Engine/" + the longer-term Tier 3 (full domain layout). Recommended pre-v2.0.

When adding new code, **build to the canonical layers from day one** if you can — even if the surrounding code is mixed:
- New domain → add a `WBGam\Repository\<Thing>Repository.php` for SQL + a `WBGam\Services\<Thing>Service.php` for logic
- Existing engines stay as-is until v1.x refactor sprint

---

## `src/` namespace map — what we actually have today

PSR-4: `WBGam\` → `src/`. Every directory below is a subnamespace.

| Subnamespace | Path | Purpose | Naming convention |
|---|---|---|---|
| **Engine** | `src/Engine/` | Business logic. The brain. | Domain engines end with `Engine.php` (e.g. `PointsEngine`, `BadgeEngine`). Cross-cutting utilities don't (e.g. `Log`, `Privacy`, `Installer`, `RateLimiter`). |
| **API** | `src/API/` | REST controllers + auth. Thin adapter over the engine. | Controllers end with `Controller.php`. One controller per resource. |
| **Admin** | `src/Admin/` | WP-admin page classes + setup wizard. UI shell only — calls REST internally. | Pages end with `Page.php` or `Dashboard.php`. |
| **Blocks** | `src/Blocks/<slug>/` | One folder per Gutenberg block. Full apiVersion 3 compliance. | Each folder has `block.json`, `index.js` (editor), `view.js` (front), `render.php`, `edit.js`, `style.css`, `editor.css`. |
| **CLI** | `src/CLI/` | WP-CLI commands. | Commands end with `Command.php`. Registered in `Engine.php`. |
| **Integrations** | `src/Integrations/<Host>/` | PHP logic for third-party plugin adapters. | One subfolder per host (e.g. `WooCommerce`, `WordPress`). Pairs with `integrations/<host>.php` manifest at root. |
| **BuddyPress** | `src/BuddyPress/` | BP-specific PHP logic. **TODO**: move under `src/Integrations/BuddyPress/` for parity with WC. | Same conventions as Integrations. |
| **Extensions** | `src/Extensions/` | Hooks for third-party plugin extension. | Extension contracts (interfaces, base classes) live here. |
| **shared/** | `src/shared/` | Cross-feature reusable components. **Currently empty** — populate or remove. | If you find yourself copying code across two engines, move the source here. |

### Canonical naming rules (enforced or to-be-enforced)

- Domain engine class → `<Thing>Engine.php` (e.g. `PointsEngine`, `BadgeEngine`)
- REST controller class → `<Thing>Controller.php` (e.g. `PointsController`)
- Admin page class → `<Thing>Page.php` (e.g. `ManualAwardPage`)
- WP-CLI command → `<Thing>Command.php` (e.g. `PointsCommand`)
- Integration manifest file → `integrations/<host>.php` (lowercase host name)
- DB table → `{prefix}wb_gam_<plural_noun>` (e.g. `wb_gam_points`, `wb_gam_badge_defs`)
- Hook prefix → `wb_gam_` for actions, `wb_gamification_` for filters (legacy mix exists; align on `wb_gam_` going forward)

---

## Where does X go? — decision tree

### "I want to track a new event."

Add a manifest entry under `integrations/<host>.php`:
```php
[
    'id'              => 'host_event_name',           // unique slug
    'label'           => 'Human-readable label',
    'description'     => 'What earns points',
    'hook'            => 'wp_action_name',            // WP/host action to listen on
    'user_callback'   => fn(...) => $user_id,         // resolves event → user ID
    'metadata_callback' => fn(...) => [...],          // optional — captured per event
    'default_points'  => 10,
    'category'        => 'wordpress',                 // dropdown grouping in admin
    'icon'            => 'dashicons-star-filled',
    'repeatable'      => true,
    'requires_buddypress' => false,                   // optional — gate by host
],
```

That's it. `ManifestLoader` discovers + registers. No core file changes needed.

### "I want a new earning rule (e.g. condition + multiplier)."

Two paths:

1. **It fits an existing rule type** (`points_multiplier`, `badge_condition`, etc.) → write it to `wb_gam_rules` via the admin or REST. No code changes.
2. **It's a new rule type** → extend `WBGam\Engine\RuleEngine::evaluate()` with one new `case`. Add a corresponding `WBGam\API\RulesController` schema entry. Document the rule shape in `audit/FEATURE_AUDIT.md`.

### "I want a new visible surface (block / shortcode / widget)."

- **Block** → `src/Blocks/<slug>/` with full block-standard files. Run `npm run build` to compile to `build/Blocks/<slug>/`.
- **Shortcode** → register in `WBGam\Engine\ShortcodeHandler` (back-compat surface; prefer blocks for new work).
- **Both** → typical pattern: write the renderer in the block's `render.php`, then `ShortcodeHandler` wraps it for shortcode use. Don't duplicate logic.

### "I want a new admin page."

`src/Admin/<Thing>Page.php`. Register in `Engine.php` boot. UI calls REST internally — **never** read/write directly via `$_POST + update_option` for new pages. Use the `data-wb-gam-rest-*` attribute pattern from `assets/js/admin-rest-form.js`.

### "I want a new REST endpoint."

`src/API/<Thing>Controller.php`. Register in `WBGam\API\RestServer` (or whichever class boots controllers).

**Permission rule**: `permission_callback` must be a real check (capability or authenticated). If it's truly public, add the controller filename to the allowlist in `bin/coding-rules-check.sh` and document the reason in `audit/ROLE_MATRIX.md`. **The CI gate fails otherwise.**

### "I want to add a new DB table."

1. Add `dbDelta()` block in `WBGam\Engine\Installer::install_tables()`
2. Add a new `upgrade_to_X_Y_Z()` method in `WBGam\Engine\DbUpgrader` (only if existing installs need migration — for fresh installs, Installer covers it)
3. Add the drop statement to `uninstall.php`
4. Update `audit/manifest.json` table count via `/wp-plugin-onboard --refresh`

Naming: `{prefix}wb_gam_<plural_noun>` (lower_snake_case).

### "I want a new email."

Pipeline already in place. Add:
1. Template at `templates/emails/<event-name>.php`
2. Wire `WBGam\Engine\Email::send($template, $args)` to the event hook
3. Add a per-event toggle in `WBGam\Admin\SettingsPage` (`notifications` tab)
4. Theme override: themes drop a copy at `{theme}/wb-gamification/emails/<event-name>.php` — picked up automatically

See `WBGam\Engine\WeeklyEmailEngine` as the reference pattern.

### "I want a new webhook event."

1. Fire `do_action('wb_gam_<event_name>', $payload)` at the right moment
2. Add the event slug to `WBGam\Admin\WebhooksAdminPage::available_events()` AND to the REST schema in `WBGam\API\WebhooksController` — **these MUST stay in sync** (regression: `badge_awarded` vs `badge_earned` mismatch caught + fixed in `audit/release-runs/2026-05-03/`)
3. The `WebhookDispatcher` handles delivery + HMAC + retry — no extra code

### "I want a new WP-CLI command."

`src/CLI/<Thing>Command.php`. Register in `Engine.php` boot inside the `defined('WP_CLI')` block.

### "I want to support a new third-party plugin (e.g. PMPro)."

1. `integrations/pmpro.php` — manifest with action triggers (auto-loaded if PMPro is active)
2. `src/Integrations/PMPro/` — any PHP logic that needs more than a fn-callback
3. Defensive gating — manifest's actions should be no-ops when host isn't active
4. Add a row to the integration matrix in `plan/v1.0-release-plan.md`

---

## Extension contract (for third parties)

What's **public** (stable API for third-party plugins to extend us):

- **Manifest discovery** — drop a PHP file at `wp-content/plugins/<your-plugin>/wb-gam-integration.php` that returns a manifest array (same shape as `integrations/*.php`). `ManifestLoader::scan_extensions()` discovers it. See `examples/` for 10 reference integrations.
- **Filters** — `wb_gamification_*` filters. Document each new filter we ship in `docs/website/developer-guide/filters.md`.
- **Actions** — `wb_gam_*` actions for downstream listeners (e.g. `wb_gam_points_awarded`, `wb_gam_badge_earned`).
- **REST** — public endpoints listed in `audit/ROLE_MATRIX.md` are stable contracts (versioned via `/v1/` namespace).
- **OpenBadges credentials** — `/badges/{id}/credential` is a public, stable, OpenBadges-v3-compliant endpoint.

What's **private** (don't reach in; subject to change):

- Direct `WBGam\Engine\*` class methods (use REST or filters instead)
- DB table schemas (use REST queries; `wb_gam_*` tables can change between minor versions)
- JS module internals — only `assets/js/admin-rest-utils.js` (`wbGamAdminRest`) is a stable JS surface

---

## Testing layout

```
tests/Unit/
├── Admin/      # Admin page tests
├── Blocks/     # Block render tests
└── Engine/     # Domain engine tests (largest tier)
```

**To add tests for new code**: mirror src/ path. New `src/Engine/Foo.php` → tests live at `tests/Unit/Engine/FooTest.php`.

**Gap**: no `tests/Integration/REST/` tier. As REST surface grows past 51 endpoints, add HTTP-tier tests using `WP_REST_Request` round-trips. See `plan/CODEBASE-AUDIT-2026-05-06.md` § Recommendations.

**CI gate**: `composer ci` runs PHP lint + WPCS + PHPStan + coding-rules + journeys (~30s + browser). Pre-push hook is opt-in via `composer install-hooks`.

---

## DB schema conventions

- Table prefix: `{wpdb_prefix}wb_gam_<noun>` (lowercase plural)
- Every table has `id BIGINT UNSIGNED AUTO_INCREMENT` PK + `created_at DATETIME` (event tables also have `event_id VARCHAR(36)` UUID)
- Foreign keys are application-enforced, not DB-level (WP convention)
- All schema in `WBGam\Engine\Installer::install_tables()`; migrations in `WBGam\Engine\DbUpgrader`
- `uninstall.php` drops every table; verify after adding a new one

20 tables today: events, points, user_badges, badge_defs, rules, levels, challenges, challenge_log, community_challenges, community_challenge_contributions, kudos, member_prefs, leaderboard_cache, webhooks, streaks, redemptions, redemption_items, cohort_members (+ 2 cosmetics tables — legacy, scheduled for removal).

---

## Coding rules (enforced)

### Plugin-specific (via `bin/coding-rules-check.sh` in local CI)

1. **`current_user_can('wb-gamification/...')` is BANNED.** Those slugs are WP Abilities API discovery, not capabilities. Use a real cap (`manage_options` or `wb_gam_*`).
2. **REST `__return_true` permission_callback** is allowed only for the documented public controllers (catalog reads, OG share, OpenBadges credential, leaderboard, OpenAPI spec). New `__return_true` outside the allowlist fails the gate.
3. **Translators comments** must sit immediately above the gettext call (inside multi-line `printf(...)` when applicable). Plugin Check fails otherwise — see `audit/release-runs/2026-05-05/SUMMARY.md`.
4. **No `admin_post_*` or `wp_ajax_*` handlers.** All admin form submissions go through REST. Tier 0 migration removed all 17.
5. **No mocking the database in tests.** Integration-tier tests (when added) hit a real WP test DB.

### Wbcom-wide rules (enforced via `wp-plugin-qa` MCP, see top of doc)

Reference these `wp-plugin-development` skill files when in doubt:

| Topic | Skill reference |
|---|---|
| Layered architecture | `references/layered-architecture.md` |
| Plugin structure & bootstrap | `references/structure.md` |
| Wbcom-specific layout | `references/wbcom-architecture.md` |
| Admin UI rules (Rule 10: confirm-modal etc.) | `references/admin-ux-rulebook.md` |
| Security & escaping | `references/security.md` |
| REST contract | `references/rest-contract.md` |
| Settings API patterns | `references/settings-api.md` |
| Email system | `references/email-system.md` |
| Data + cron | `references/data-and-cron.md` |
| Frontend tokens / responsive / a11y / components | `references/frontend-{tokens,responsive,accessibility,components}.md` |
| Mobile ergonomics | `references/mobile-ergonomics.md` |
| Performance budgets | `references/performance-budgets.md` |
| Scale + cache | `references/scale-and-cache.md` |
| Lifecycle (activation / deactivation / uninstall) | `references/lifecycle.md` |
| Pre-release checklist | `references/pr-checklist.md` |
| Release engineering | `references/release-engineering.md` |
| Debugging | `references/debugging.md` |
| Wbcom wrapper migration (legacy admin → modern) | `references/wbcom-wrapper-migration.md` |

---

## Build pipeline

| Command | What it does |
|---|---|
| `npm run build` | Compiles all 15 blocks (`src/Blocks/<slug>/` → `build/Blocks/<slug>/`) |
| `npm run start` | Watch-mode for block dev |
| `bash bin/build-release.sh` | Production zip in `dist/wb-gamification-<version>.zip` (excludes src/, tests/, audit/, plan/, node_modules/, composer.*, package.*, phpunit.*, phpstan.*, .phpcs.*, CLAUDE.md, .git*) |
| `composer ci` | Full local CI gate: lint + WPCS + PHPStan + coding-rules + manifest + journeys |
| `composer ci:no-journeys` | Same minus browser tier |
| `composer ci:quick` | Lint + coding-rules only (~10s) |
| `composer install-hooks` | Activates pre-push hook (one-time per clone) |

---

## When this doc is wrong

If anything here doesn't match what you find in the code, the **code wins**. Open a PR that updates this file in the same change, so it stays accurate.

When you make a structural decision worth remembering for the future (e.g. "we chose X over Y because…"), drop an ADR in `plan/decisions/` so the rationale outlives the conversation.

---

Updated by Varun — 2026-05-06.
