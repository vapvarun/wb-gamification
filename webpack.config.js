/**
 * Webpack configuration for wb-gamification blocks.
 *
 * Per the Wbcom Block Quality Standard, every block lives at
 * `src/blocks/<slug>/` with its own `block.json` declaring entry files
 * (`index.js` for editor, `view.js` for view, `style.css` / `editor.css`
 * for stylesheets). `@wordpress/scripts` v27 discovers these
 * automatically by scanning `src/**\/block.json` and emits compiled
 * artefacts to `build/blocks/<slug>/`.
 *
 * Phase A bootstraps the pipeline before any blocks have been migrated,
 * so the build orchestrator (`bin/build-blocks.js`) short-circuits when
 * `src/blocks/` has no `block.json` yet and emits an empty `build/`
 * directory. Once Phase B/C ports the first block, this config takes
 * over via the default scripts behaviour.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = defaultConfig;
