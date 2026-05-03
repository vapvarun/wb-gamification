/**
 * Redemption Store — block registration entry.
 *
 * Phase C pilot for the Wbcom Block Quality Standard. The full standard
 * editor surface — Content / Layout / Style / Advanced inspector panels
 * with responsive controls, hover colours, per-side spacing, box-shadow,
 * border-radius, and device visibility — lives in `./edit.js`.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase C
 */

import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';

import './style.css';
import './editor.css';

registerBlockType( metadata.name, {
	icon: 'cart',
	edit,
	save: () => null, // Server-rendered.
} );
