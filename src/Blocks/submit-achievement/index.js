/**
 * Submit Achievement — block registration entry.
 */

import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';

import './style.css';

registerBlockType( metadata.name, {
	icon: 'plus-alt',
	edit,
	save: () => null,
} );
