/**
 * Wbcom Block Quality Standard — shared API surface.
 *
 * Re-exports the editor-side primitives every wb-gamification block
 * imports: responsive controls, spacing, typography, box-shadow, border
 * radius, hover colours, device visibility, plus the per-instance CSS
 * generator and unique-ID hook. Block edit.js files should import from
 * `../../shared` rather than reaching into sub-paths.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md
 * @see ~/.claude/skills/wp-block-development/references/block-quality-standard.md
 */

export * from './components';
export * from './hooks';
export * from './utils';
