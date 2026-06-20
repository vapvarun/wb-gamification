/**
 * Give Kudos — block registration entry.
 *
 * Previously shipped with only block.json + render.php and no editorScript,
 * so the editor offered no inspector controls for its 25 attributes and no
 * real preview. This entry wires the standard edit surface (options ready +
 * ServerSideRender preview) per the Wbcom Block Quality Standard.
 */

import { registerBlockType } from '@wordpress/blocks';
import { people } from '@wordpress/icons';
import metadata from './block.json';
import edit from './edit';

registerBlockType( metadata.name, {
	icon: people,
	edit,
	save: () => null,
} );
