# Example 02 — Programmatic Registration (runtime register)

Use this when you need to register triggers conditionally — only if a setting is on, only if a premium add-on is active, only for certain post types, etc. Also the right pattern for **themes** and **mu-plugins** (which the manifest scanner does NOT walk).

## When to pick this over Example 01

| Scenario | Use |
|---|---|
| Always-on triggers from a plugin | [Example 01](../01-track-event-via-manifest/) (drop-a-file) |
| Conditional registration based on a site option | **This example** |
| Theme registering its own triggers | **This example** |
| mu-plugin registering shared triggers | **This example** |
| Premium-only triggers behind a license check | **This example** |
| Per-post-type dynamic registration | **This example** |

## How it works

We hook `wb_gam_engines_booted` (fired by WB Gamification once all engines are wired). When this action runs, three guarantees hold:

1. WB Gamification is installed and active.
2. The `wb_gam_register_action()` helper exists.
3. The Registry is ready to receive triggers.

If WB Gamification is NOT installed, the action never fires and our code never runs. No fatal, no `function_exists()` guards needed inside the callback.

## Files in this example

- [`your-plugin.php`](your-plugin.php) — the registration code, ready to drop into your plugin's main file or `inc/gamification.php`.

## Three patterns demonstrated

1. **Option-gated registration** — only register if an admin setting is on.
2. **Constant-gated registration** — premium-only triggers behind a license constant.
3. **Filter-driven dynamic registration** — themes / other plugins can extend the list of CPTs your plugin tracks.

## Three public registration helpers

```php
wb_gam_register_action( $args );           // Track a custom event
wb_gam_register_badge_trigger( $args );    // Custom badge condition
wb_gam_register_challenge_type( $args );   // Custom challenge mechanic
```

See [Example 05](../05-custom-badge-condition/) and [Example 07](../07-custom-challenge-type/) for badge trigger / challenge type patterns.

## Verifying registration

```bash
# CLI
wp wb-gamification actions list

# REST
curl http://your-site/wp-json/wb-gamification/v1/actions
```

## Common pitfall: don't register before `wb_gam_engines_booted`

```php
// ❌ Wrong — fires too early, helpers may not exist yet
add_action( 'plugins_loaded', 'yourplugin_register_gam_triggers', 1 );

// ❌ Wrong — also too early on some priority orderings
add_action( 'init', 'yourplugin_register_gam_triggers' );

// ✅ Right — guaranteed safe, fires only when WB Gamification is fully loaded
add_action( 'wb_gam_engines_booted', 'yourplugin_register_gam_triggers' );
```

If you genuinely need to register at `init` (because you're listening on an `init`-fired hook), then you DO need the explicit guard:

```php
add_action( 'init', function () {
	if ( function_exists( 'wb_gam_register_action' ) ) {
		wb_gam_register_action( [ /* ... */ ] );
	}
} );
```

## Related

- For triggers that should always be on regardless of any condition, prefer [Example 01](../01-track-event-via-manifest/) — it's lower-coupling.
- For badges with custom conditions (not just point thresholds), see [Example 05](../05-custom-badge-condition/).
