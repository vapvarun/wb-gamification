import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './editor.css';
import './style.css';

registerBlockType( metadata.name, { icon: 'list-view', edit, save: () => null } );
