# Extending Blocks

WB Gamification ships 12 server-rendered blocks. Each one fires a uniform set of WordPress action hooks that let you inject UI before or after the block's HTML — without forking it.

## The hooks

Three hooks per block render:

```php
do_action(    'wb_gam_block_before_render', $slug, $attributes, $context );
do_action(    'wb_gam_block_after_render',  $slug, $attributes, $context );
apply_filters( 'wb_gam_block_data',         $data, $slug, $attributes );
```

| Hook | Type | Fires when |
|---|---|---|
| `wb_gam_block_before_render` | action | Immediately before the block emits any HTML |
| `wb_gam_block_after_render` | action | Immediately after the block finishes its HTML |
| `wb_gam_block_data` | filter | When a block invokes it to allow data transforms |

`$slug` is the block slug **without** the `wb-gamification/` namespace prefix.

## Block slugs

| Slug | Block | List-style? |
|---|---|---|
| `badge-showcase` | Member's earned badges | Yes |
| `challenges` | Active challenges with progress bars | Yes |
| `earning-guide` | "How to earn points" reference | Yes |
| `hub` | Layout-owning member hub page (full-width) | No |
| `kudos-feed` | Recent peer kudos | Yes |
| `leaderboard` | Ranked member list | Yes |
| `level-progress` | Current level + bar to next | No |
| `member-points` | Single points total | No |
| `points-history` | Recent point transactions | Yes |
| `streak` | Current + longest streak (+ heatmap) | No |
| `top-members` | Top-N member card | Yes |
| `year-recap` | Year-in-review | No |

## Hook signatures

### `wb_gam_block_before_render` (action, 3 args)

```php
do_action( 'wb_gam_block_before_render', string $slug, array $attributes, array $context );
```

- `$slug` — block slug (e.g. `'leaderboard'`)
- `$attributes` — resolved block attributes from the editor / shortcode (`['period' => 'all_time', 'limit' => 10, ...]`)
- `$context` — per-block runtime state. Typically includes `user_id` and any block-specific keys the block has already computed. Listeners receive this so they don't have to re-derive state.

Listeners can echo HTML (it appears before the block's wrapper `<div>`) or buffer/manipulate output via `ob_start()`.

### `wb_gam_block_after_render` (action, 3 args)

Same signature as `before_render`. Fires after the closing wrapper. Listeners echo HTML to append below the block.

### `wb_gam_block_data` (filter, 3 args)

```php
apply_filters( 'wb_gam_block_data', mixed $data, string $slug, array $attributes );
```

- `$data` — block-specific payload (rows, badges, history items, etc.)
- `$slug` — block slug
- `$attributes` — block attributes

Returns the filtered data. **Note:** not every block fires this filter today — see the block's `render.php` to confirm before relying on it. The action hooks fire for all 12 blocks unconditionally.

## When hooks DON'T fire

Empty-state render paths intentionally skip the hooks:

- User not logged in (where the block requires login)
- No data to show (e.g. no badges earned, no challenges active)
- Block is hidden by an admin gate

If you need to know about those states, listen to the underlying engine event — `wb_gam_streak_broken`, `wb_gam_points_awarded`, etc. — rather than the block hooks.

## Common patterns

### Append a CTA below a block

```php
add_action( 'wb_gam_block_after_render', function ( $slug, $attributes, $context ) {
    if ( 'leaderboard' !== $slug ) return;
    ?>
    <div class="my-cta">
        <button class="my-share-rank"><?php esc_html_e( 'Share my rank', 'my-plugin' ); ?></button>
    </div>
    <?php
}, 10, 3 );
```

### Conditional banner before a block

```php
add_action( 'wb_gam_block_before_render', function ( $slug, $attributes, $context ) {
    if ( 'streak' !== $slug ) return;
    if ( ! my_plugin_in_campaign_window() ) return;
    echo '<div class="my-banner">🔥 Double points week!</div>';
}, 10, 3 );
```

### Annotate block data via filter

```php
add_filter( 'wb_gam_block_data', function ( $data, $slug, $attributes ) {
    if ( 'leaderboard' !== $slug ) return $data;
    foreach ( (array) $data as &$row ) {
        $row['country'] = get_user_meta( $row['user_id'], 'country', true );
    }
    return $data;
}, 10, 3 );
```

### Track block impressions for analytics

```php
add_action( 'wb_gam_block_after_render', function ( $slug ) {
    $counts = get_transient( 'my_block_renders' ) ?: [];
    $counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
    set_transient( 'my_block_renders', $counts, HOUR_IN_SECONDS );
} );
```

### Replace a block's output entirely (advanced)

Capture the block's render output and replace it. This is intentionally awkward — if you find yourself needing it, consider building a competing block reading the same REST endpoint instead.

```php
add_action( 'wb_gam_block_before_render', function ( $slug ) {
    if ( 'leaderboard' === $slug ) ob_start();
}, 10, 3 );

add_action( 'wb_gam_block_after_render', function ( $slug ) {
    if ( 'leaderboard' !== $slug ) return;
    ob_get_clean(); // discard the original
    echo my_render_alternate_leaderboard();
}, 10, 3 );
```

## Performance notes

- Both action hooks fire once per block render. Keep listeners cheap; they're on the page-render hot path.
- Don't issue per-row DB queries from a `wb_gam_block_data` filter — batch your lookups.
- For analytics, prefer transient-based aggregation over per-render `update_option()` calls.

## What you CAN'T do (today)

- **Replace a block's render with a "skip default" flag.** Output capture (above) works but is awkward.
- **Inject a column into a tabular block.** The templates aren't column-pluggable. If you need this for a specific block, open an issue with the use case.
- **Modify the wrapper `<div>` attributes.** The wrapper attrs are computed by `get_block_wrapper_attributes()` and not filterable from outside the block. Use the `_after_render` action with `ob_start()` if you must rewrite.

These are tracked as future-roadmap items; see [`plans/INTEGRATION-GAPS-ROADMAP.md`](../../../../plans/INTEGRATION-GAPS-ROADMAP.md).

## Worked example

A complete worked example with 4 patterns lives at [`examples/10-inject-into-block-render/`](../../../../examples/10-inject-into-block-render/). Copy it into your plugin and run on a Local install — every pattern is verified.

## Related

- [`hooks-filters.md`](hooks-filters.md) — full hook + filter reference (43 actions + 12 filters fired by the engine)
- [`rest-api.md`](rest-api.md) — for replacing a block, build a competing block that reads the same REST endpoint
- [`manifest-files.md`](manifest-files.md) — for tracking new events that flow through the engine + into block renders
