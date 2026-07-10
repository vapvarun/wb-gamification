# Integrate a plugin whose API loads on a hook (FluentCRM)

**Use this when:** the plugin you want to detect defines its API *inside its
own `plugins_loaded` callback* rather than at file-parse time — so a
`function_exists()` check is only reliable *after* that plugin has booted.
FluentCRM is the textbook case: `fluentCrmApi()` is registered at
`plugins_loaded` **priority 10**.

## Why the drop-a-file manifest (Example 01) does not work here

WB Gamification's `ManifestLoader` `include()`s every `wb-gamification.php`
manifest at **`plugins_loaded` priority 5** — *before* priority 10. So a
manifest that gates its return value on the other plugin's function:

```php
// wb-gamification.php  — DON'T do this for a hooked-API plugin
if ( ! function_exists( 'fluentCrmApi' ) ) {
    return [];          // taken on EVERY request at priority 5
}
return [ 'triggers' => [ /* ... */ ] ];
```

...returns an empty array every time — **silently**. No PHP error, no admin
warning, no log line, no points. The manifest is syntactically perfect; it
just runs one boot phase too early to ever see the function it tests for.

> This is specifically a **top-level / parse-time** guard problem. A
> `function_exists()` check *inside* a `user_callback` is fine — callbacks run
> when the real event fires, long after every plugin has loaded.

### What IS safe to gate a manifest on at parse time

| Guard | Safe at `plugins_loaded@5`? | Why |
|---|---|---|
| `defined( 'FLUENTCRM' )` | ✅ | Constants exist at file-parse time. |
| `class_exists( 'FluentCrm\\App\\App' )` | ✅ | Classes are declared at file-parse time. |
| `function_exists( 'fluentCrmApi' )` | ❌ | Defined inside FluentCRM's own `plugins_loaded@10` callback. |

The bundled `integrations/contrib/the-events-calendar.php` manifest gates on
`class_exists( 'Tribe__Events__Main' )` — safe, because that class parses
early. Copying that file's shape but swapping in `function_exists()` is the
exact trap this example exists to prevent.

## The fix: register late instead of via a manifest

[`your-plugin.php`](your-plugin.php) registers the triggers on the **`init`**
hook, which fires after *all* `plugins_loaded` callbacks (priorities 5, 10,
20, …). By then both `wb_gam_register_action()` and `fluentCrmApi()` are
defined, so the `function_exists()` checks are finally reliable.

```php
add_action( 'init', function () {
    if ( ! function_exists( 'wb_gam_register_action' ) ) { return; } // WB Gam active?
    if ( ! function_exists( 'fluentCrmApi' ) )           { return; } // FluentCRM booted?

    wb_gam_register_action( [
        'id'            => 'fluentcrm_tag_added',
        'hook'          => 'fluentcrm_contact_added_to_tags',
        'user_callback' => 'yourplugin_fluentcrm_user_id',
        'default_points'=> 10,
        // ...
    ] );
} );
```

Registering at `init` never misses an event: FluentCRM's tag hooks
(`fluentcrm_contact_added_to_tags` / `fluentcrm_contact_removed_from_tags`)
only fire on a real contact-tag change, which is always well after `init`.

Put `your-plugin.php`'s contents in **your own plugin** or an mu-plugin — it is
ordinary PHP in your load path, **not** a `wb-gamification.php` manifest.

## Where this file goes

- ❌ Not as `wb-gamification.php` in a plugin root (that path is scanned at
  priority 5 — too early).
- ✅ Inside your plugin's normal bootstrap, or as an mu-plugin.

## Related examples

- [`01-track-event-via-manifest/`](../01-track-event-via-manifest/) — the
  drop-a-file manifest, correct for plugins whose presence is detectable at
  parse time (constant/class).
- [`02-programmatic-register/`](../02-programmatic-register/) — the general
  runtime-registration pattern (`wb_gam_engines_booted`, conditional
  registration, per-CPT triggers).
