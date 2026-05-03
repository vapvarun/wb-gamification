/**
 * Streak — block registration entry.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase D.1
 */

import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';

import './editor.css';
import './style.css';

registerBlockType( metadata.name, {
	icon: 'chart-line',
	edit,
	save: () => null,
} );
