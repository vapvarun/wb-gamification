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

### G1 — Block render layer has no extension slots [HIGH]

**Problem.** Each `blocks/{slug}/render.php` is a closed render — third parties can't add UI inside an existing block (e.g. add a "Send Kudos" button to the leaderboard, or a custom column to the points-history table).

**Workaround today.** Fork the block via `register_block_type` collision, or build a competing block alongside. Both are bad — the fork eats updates, the parallel block fragments the UX.

**What "fixing" looks like.**
- Add `do_action( 'wb_gam_block_{slug}_before_render', $attributes, $context )` and `_after_render` to every block render.
- Add a `wb_gam_block_{slug}_columns` filter to list-style blocks (leaderboard, points-history) so columns are pluggable.
- Document the per-block extension contract in `docs/website/developer-guide/extending-blocks.md`.

**Scoping.** ~1-2 days for the hooks; ~1-2 days for the docs + example. Touches all 12 block render files. Defer until at least one consumer asks (today the BuddyPress + Reign theme are the only frontends and they don't extend blocks).

---

### G2 — Email templates inline-rendered [MEDIUM]

**Problem.** `WeeklyEmailEngine::run()` and `LeaderboardNudge::run()` build the email HTML inline. Third parties (or the team itself) can't theme, swap, or fully restructure these emails without a fork.

**Workaround today.** Filter the body content via a `wb_gamification_weekly_email_html` action listener (does not exist yet — this is a hypothetical workaround). Today the only realistic workaround is to disable the engine and re-implement in your own plugin.

**What "fixing" looks like.**
- Move the inline HTML into `templates/emails/weekly-recap.php` and `templates/emails/leaderboard-nudge.php`.
- Use `locate_template()`-style override resolution: `wb-gamification/emails/weekly-recap.php` in the theme wins.
- Add `wb_gamification_email_template_path` filter for full programmatic override.

**Scoping.** ~half a day. Touches 2 engine files. Worth doing because email is the highest-friction surface to customize and the customer-visible touch point with the most variation in expectations.

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

If we have one week of integration-quality work to spend, in order:

1. **G2 — email templates** (½ day, broad customer benefit). Highest ROI fix.
2. **G1 — block extension slots** (~2-3 days, unlocks the next 3-4 partner integrations). Highest strategic value.
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
