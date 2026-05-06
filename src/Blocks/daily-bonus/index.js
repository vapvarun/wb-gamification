/**
 * Daily Login Bonus — block registration entry.
 */

import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';

import './style.css';

registerBlockType( metadata.name, {
	icon: 'star-filled',
	edit,
	save: () => null,
} );
