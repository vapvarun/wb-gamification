import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './editor.css';

registerBlockType( metadata.name, { icon: 'groups', edit, save: () => null } );
