import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './editor.css';
// Frontend styles — webpack emits style-index.css from this import,
// which block.json's `style: "file:./style-index.css"` enqueues.
// Without it the per-block frontend CSS (including the new
// completed-state treatment) never compiles.
import './style.css';

registerBlockType( metadata.name, { icon: 'megaphone', edit, save: () => null } );
