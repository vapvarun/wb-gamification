# Integration Gaps & Roadmap

**Generated**: 2026-05-02
**Source**: third-party integration audit (post v1.0.0)

This document tracks every place where the third-party integration story is weaker than it should be. Each gap has a severity, the workaround available today, and a scoping note for when we'd close it.

> **Companion**: [`/examples/`](../examples/) shows what works today. This document tracks what does NOT.

## Severity scale

| | Description |
|---|---|
| **High** | Common third-party need, no clean workaround, blocking real integrations |
| **Medium** | Common need, ugly workaround exists |
| **Low** | Niche need or workaround is clean enough |

## Gap inventory

### G1 — Block render layer has no extension slots [HIGH] — **CLOSED 2026-05-02**

**Was.** Each `blocks/{slug}/render.php` was a closed render. Third parties couldn't add UI inside an existing block without forking the block.

**Resolved by.** A new `WBGam\Engine\BlockHooks` helper that all 12 blocks call, plus 3 hooks per block:

- **`wb_gam_block_before_render`** (action) — fires immediately before each block emits HTML; receives `($slug, $attributes, $context)`.
- **`wb_gam_block_after_render`** (action) — fires after each block finishes its HTML.
- **`wb_gam_block_data`** (filter) — transformable per-block payload (where invoked).

Empty-state paths intentionally skip the hooks — extensions don't fire when the block didn't render.

**Files:**

- `src/Engine/BlockHooks.php` (new) — uniform hook helper.
- `blocks/{slug}/render.php` ×12 — all patched to call `BlockHooks::before` + `BlockHooks::after`.
- `examples/10-inject-into-block-render/` — 4 worked patterns (CTA append, banner prepend, data filter, impression tracking).
- `docs/website/developer-guide/extending-blocks.md` (new) — full reference.

**What it cost.** ~80 lines added to BlockHooks helper; 1 `use` statement + ~3 lines of hook calls per block × 12 blocks = ~50 lines total in the block files. Net new extension surface: 36 hook firings (3 per block × 12 blocks) + 1 example + 1 doc.

**What's NOT in this fix (still gaps for future work):**

- No "skip default render" flag — replacing a block requires `ob_start()` capture in `before_render` + `ob_get_clean()` in `after_render`. Awkward but possible.
- No column-injection for tabular blocks — `points-history`, `leaderboard`, `top-members` columns are still hardcoded in their templates. Adding pluggable columns requires per-block template surgery.
- Wrapper `<div>` attributes are not filterable from outside.

These are tracked as future-roadmap items; close them when a real consumer needs them.

---

### G2 — Email templates inline-rendered [MEDIUM] — **CLOSED 2026-05-02**

**Was.** `WeeklyEmailEngine::run()` and `LeaderboardNudge::run()` built the email HTML inline. Third parties (or the team itself) could not theme, swap, or fully restructure these emails without a fork.

**Resolved by.** A new `WBGam\Engine\Email` helper that does `locate_template()`-style override resolution + 3 new filters:

- **`templates/emails/weekly-recap.php`** — the inline HTML moved into a real template file.
- **`Email::render( $template, $vars )`** — locates and renders with theme-override support.
- **Theme override path:** `YOUR-THEME/wb-gamification/emails/weekly-recap.php` (child theme wins over parent via `locate_template()`).
- **Filter `wb_gamification_email_template_path`** — full programmatic override.
- **Filter `wb_gamification_weekly_email_body`** — replace the rendered HTML body entirely.
- **Filter `wb_gamification_email_from_header`** — replace the From header.
- **Filter `wb_gamification_nudge_message`** — replace the leaderboard-nudge message body (LeaderboardNudge uses plain text, not HTML — single-filter override is enough).

**Example:** [`/examples/09-override-email-template/`](../examples/09-override-email-template/) ships a complete branded variant of the weekly recap that drops into a theme verbatim.

**What it cost.** ~120 lines added (Email helper + filter wires), ~150 lines removed (inline HTML → template file). One template file. Net code reduction of ~30 lines plus a real extension surface.

---

### G3 — No service container / DI [LOW]

**Problem.** Engines are static singletons (`BadgeEngine::evaluate()`, `LeaderboardEngine::write_snapshot()` etc.). You can't swap `BadgeEngine` for a custom implementation without forking the plugin.

**Workaround today.** Hook the engine's input/output filters (`wb_gamification_should_award_badge`, `wb_gamification_leaderboard_results`) to mutate behaviour rather than replace the engine. Works for ~80% of cases; doesn't work when you want a completely different award algorithm.

**What "fixing" looks like.** Introduce a tiny container that holds engine instances behind interfaces:

```php
WBGam\Container::set( 'badge_engine', new \YourPlugin\CustomBadgeEngine() );
```

Each engine implements an interface (`WBGam\Contracts\BadgeEngineInterface`). Default impls stay; container resolves to whichever is set.

**Scoping.** ~1-2 days for the container + interface extraction; ~half a day per engine to extract behind interface. Total ~1 week if all engines get refactored. Big change for marginal benefit unless we have a concrete swap-out use case. **Defer indefinitely** unless someone actually needs it.

---

### G4 — No public event-replay API [LOW]

**Problem.** `wb_gam_events` is the immutable source of truth, but there's no public REST or CLI endpoint to replay events against new rules. A site owner who changes badge thresholds can't retroactively grant badges to users who would now qualify.

**Workaround today.** Direct DB read + manual replay via WP-CLI scripting. Works for power users; not exposed to admins.

**What "fixing" looks like.**
- New CLI command `wp wb-gamification replay --user=42 --since=2026-01-01 --rule=badges` — re-runs rule evaluation on stored events.
- Optional REST endpoint `POST /wb-gamification/v1/admin/replay` for headless ops.

**Scoping.** ~1 day. Self-contained. Useful for support workflows. Defer until support asks for it (which they will eventually).

---

### G5 — Admin UI not pluggable [LOW]

**Problem.** Third parties can't add tabs / pages **inside** the WB Gamification admin menu without monkey-patching `add_submenu_page` calls.

**Workaround today.** Use WP's standard `add_submenu_page` for a sibling top-level menu. Clean separation, slightly worse discoverability.

**What "fixing" looks like.** Filter the admin sub-menu list before rendering:

```php
add_filter( 'wb_gam_admin_submenu_pages', function( $pages ) {
    $pages[] = [
        'title'    => 'Your Plugin Settings',
        'cap'      => 'wb_gam_manage_yourplugin',
        'slug'     => 'your-plugin-settings',
        'callback' => 'your_render_function',
    ];
    return $pages;
} );
```

**Scoping.** Half a day. Defer until requested.

---

### G6 — No GraphQL [LOW]

**Problem.** REST only. Sites running WPGraphQL can't compose gamification queries with their other content.

**Workaround today.** Wrap REST calls in WPGraphQL resolvers manually.

**What "fixing" looks like.** Companion plugin `wb-gamification-graphql` that registers WPGraphQL types and resolvers. Don't bake into the main plugin (most sites don't run WPGraphQL).

**Scoping.** ~1 week for full schema. Phase 5+ deferred per existing roadmap.

---

### G8 — Blocks below the Wbcom Block Quality Standard [MEDIUM] — **CLOSED 2026-05-03**

> Migration shipped across PRs #21 → #30 (plan + 5 phases A → E + Phase F dead-code drop). All 15 blocks now consume `--wb-gam-*` design tokens, declare the standard attribute schema, register via `WBGam\Blocks\Registrar` from `build/Blocks/<slug>/`, and gate on `bin/check-block-standard.sh` (CI stage 2.3). Phase F also removed the orphaned `assets/interactivity/index.js` registration.

> **Phase G (deferred follow-up):** the legacy block-specific selectors in `assets/css/frontend.css` (1,425 lines) still ship alongside the migrated blocks. Phase G will extract per-block CSS sections into `src/Blocks/<slug>/style.css` files, bundled via `@wordpress/scripts`, and shrink `frontend.css` to shared utilities only (toasts, overlays, empty states, design-token aliases). Estimated 4–6 h with browser regression testing of all 15 blocks at 4 viewports — best as its own PR after #30 lands.



**Problem.** All 15 Gutenberg blocks violate the canonical Wbcom Block Quality Standard documented in `~/.claude/skills/wp-block-development/references/block-quality-standard.md` (derived from auditing Kadence Blocks, Stackable, Spectra, Otter Blocks). Plugin has no `src/shared/` infrastructure, no design tokens, no responsive editor controls (Desktop/Tablet/Mobile), no per-side spacing, no hover states, no per-instance scoped CSS, and no editor InspectorControls panels on most blocks.

The most recent block (`redemption-store`, PR #20) additionally violates this plugin's own existing conventions — uses inline `<style>` and `<script>` instead of `assets/css/frontend.css` + the IA pattern that `hub` follows, and uses raw `fetch()` + `window.confirm()` where `wp.apiFetch` and a styled confirm should be used.

**Workaround today.** Blocks render correctly and are functional — this is architectural debt, not user-visible bugs. Theme overrides, responsive layouts, and editor customisation just aren't possible per-block.

**What "fixing" looks like.** Full plan in [`WBCOM-BLOCK-STANDARD-MIGRATION.md`](WBCOM-BLOCK-STANDARD-MIGRATION.md). Five phases:
- **A** — Build infra (`@wordpress/scripts`, webpack)
- **B** — `src/shared/` ported from wbcom-essential v4.5.0
- **C** — `redemption-store` rebuilt as pilot/canonical reference
- **D** — Bulk migration of remaining 14 blocks in 5 groups
- **E** — Cleanup of legacy assets + CI gate

**Scoping.** ~65 hours / ~8 working days for the full migration. The pilot phases (A+B+C) are ~22 hours; the remaining 14 blocks are ~43 hours.

**Why it's tracked here, not done.** The user has explicitly banned patch-on-patch fixes. Bringing redemption-store into line with this plugin's existing convention while leaving the broader Wbcom standard gap untouched would be exactly that — a patch that gets re-patched when the migration runs.

---

### G7 — No JS SDK [MEDIUM]

**Problem.** Frontend integrations talk REST manually. No `@wbcom/wb-gamification-js-sdk` published. Every consumer hand-rolls auth + endpoint paths + response shape handling.

**Workaround today.** Use plain `wp.apiFetch` — typed responses are derivable from the OpenAPI spec at `/openapi.json` but no helpers ship.

**What "fixing" looks like.**
- Auto-generate TS types from the OpenAPI spec (one-shot script in `bin/generate-sdk.js`).
- Publish `@wbcom/wb-gamification-js-sdk` with typed wrappers around every endpoint.
- Optional React Native variant for mobile apps.

**Scoping.** ~3-5 days for v1 SDK. Phase 5+ deferred per existing roadmap. The OpenAPI spec already exists, so the heavy lift is the publish/distribution + maintenance commitment, not the codegen.

---

## Priority recommendation

Recommended next moves, in order:

1. ~~**G2 — email templates**~~ — **CLOSED 2026-05-02** ✓
2. ~~**G1 — block extension slots**~~ — **CLOSED 2026-05-02** ✓
3. **G4 — event replay CLI** (~1 day, support team will love it). Operational quality-of-life.

Skip the rest until a real consumer asks. G3/G5/G6/G7 are correct-shape gaps but not blocking known consumers; speculatively closing them risks adding maintenance burden for unused surfaces.

## What's already good (don't fix what isn't broken)

For completeness — the existing third-party integration story has these strengths and they should be preserved across any refactor:

- **Drop-a-file manifest pattern** — zero coupling, no `class_exists` checks, works without the plugin installed. See [`/examples/01-track-event-via-manifest/`](../examples/01-track-event-via-manifest/).
- **3-mechanism action discovery** — file drop, programmatic, filtered scan paths.
- **OpenAPI 3.0 self-discovery** at `/wb-gamification/v1/openapi.json` (39 paths, all schemas).
- **Outbound webhooks** with async retry — no PHP needed for external integrations.
- **3 auth modes** for REST (cookie+nonce / Application Passwords / API keys).
- **Layered cap system** — `Capabilities::user_can()` accepts manage_options OR granular cap, granular caps register on activation, removed on uninstall.
- **6 WP-CLI commands** for shell-side automation.
- **12 well-placed filters** at the right semantic boundaries.

The core architecture is genuinely good. The gaps above are surface gaps, not architectural debt.
