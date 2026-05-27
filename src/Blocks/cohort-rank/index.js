import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './editor.css';
// Frontend styles — webpack emits style-index.css from this import,
// which block.json's `style: "file:./style-index.css"` enqueues.
// Without it the per-block CSS (incl. the new tier-accent treatment)
// never reaches the page.
import './style.css';

registerBlockType( metadata.name, { icon: 'businessperson', edit, save: () => null } );
