# Example 10 — Inject UI into block renders

Hook into the WB Gamification block extension API to add custom UI **before** or **after** any of the 12 server-rendered blocks — without forking the block.

This closes [G1 in the integration roadmap](../../plans/INTEGRATION-GAPS-ROADMAP.md).

## The 3 hooks

```php
// Fires once before each block emits any HTML
do_action( 'wb_gam_block_before_render', $slug, $attributes, $context );

// Fires once after each block finishes its HTML
do_action( 'wb_gam_block_after_render',  $slug, $attributes, $context );

// Filters block-specific payload data (where the block invokes it)
apply_filters( 'wb_gam_block_data', $data, $slug, $attributes );
```

`$slug` is the block slug without the `wb-gamification/` namespace prefix — e.g. `'leaderboard'`, `'streak'`, `'hub'`. Listeners switch on `$slug` to act only on the blocks they care about.

## Block slugs

The 12 blocks that fire these hooks:

| Slug | Description |
|---|---|
| `badge-showcase` | Member's earned badges |
| `challenges` | Active challenges + progress |
| `earning-guide` | "How to earn points" reference |
| `hub` | Layout-owning member hub page |
| `kudos-feed` | Recent peer kudos |
| `leaderboard` | Ranked member list |
| `level-progress` | Current level + progress to next |
| `member-points` | Member's total points |
| `points-history` | Recent point transactions |
| `streak` | Current + longest streak |
| `top-members` | Top-N member card |
| `year-recap` | Year-in-review |

## Files in this example

- [`your-plugin.php`](your-plugin.php) — four pattern demonstrations (CTA, banner, data filter, analytics).

## Patterns demonstrated

### 1. Append a "Share my rank" CTA below the leaderboard

```php
add_action( 'wb_gam_block_after_render', function ( $slug, $attributes, $context ) {
    if ( 'leaderboard' !== $slug ) return;
    echo '<button>Share my rank</button>';
}, 10, 3 );
```

### 2. Campaign banner above the streak block (time-windowed)

Use `before_render` to prepend UI. In the example, a "double points week" banner is shown only between June 1-8.

### 3. Filter block payload via `wb_gam_block_data`

Annotate the leaderboard rows with a "trending" flag — rows pass through your filter before the block renders them. Note: not every block fires this filter today; see each block's `render.php` to verify before relying on it.

### 4. Block render analytics

Generic `after_render` listener that counts render impressions per block. Useful for telemetry / "which blocks are actually used on this site?" reporting.

## When the hooks fire (and when they don't)

| Block path | `before_render` | `after_render` |
|---|---|---|
| Empty state (e.g. user not logged in, no data) | NO — extension shouldn't fire for non-renders | NO |
| Main render path | YES (right before the wrapper `<div>`) | YES (right after the closing `</div>`) |

Empty-state paths intentionally skip the hooks. If your extension needs to know about empty states, listen to the underlying engine event (e.g. `wb_gam_streak_broken`) instead.

## What you can do

| Goal | Pattern |
|---|---|
| Append UI below a block | `wb_gam_block_after_render` + echo HTML |
| Prepend UI above a block | `wb_gam_block_before_render` + echo HTML |
| Modify what's rendered | `wb_gam_block_data` filter (where supported) |
| Count impressions | Either hook + transient/option storage |
| Different markup entirely | Capture output of the original via `ob_start()` in `before` and replace in `after` |

## What you can't do (yet)

- **Replace a block's render entirely.** No "skip default render" flag exists. To replace, build a competing block that reads the same REST endpoint.
- **Add a column to a tabular block.** The render templates aren't column-pluggable; this would need template surgery in each block. If you need this for a specific block, file an issue with the use case.

## Verifying hooks fire

```php
// Drop this in a debug mu-plugin to verify the hooks reach your code:
add_action( 'wb_gam_block_before_render', function ( $slug ) {
    error_log( "[wb_gam] block render: $slug" );
} );
```

Then load any page with a WB Gamification block and check `wp-content/debug.log`.

## Performance

Both hooks fire once per block render — fast for `before` (no work yet), fast for `after` (block already rendered). Keep your listener cheap; it's on the page-render hot path.

## Related examples

- For modifying point math → [Example 06](../06-modify-points-per-action/)
- For customizing the leaderboard JSON response (not the rendered block) → [Example 08](../08-leaderboard-customization/)
- For overriding email templates → [Example 09](../09-override-email-template/)

## Documentation

The full block extension API is documented in [`docs/website/developer-guide/extending-blocks.md`](../../docs/website/developer-guide/extending-blocks.md).
