/**
 * Submit Achievement — editor surface.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	useUniqueId( attributes, setAttributes, clientId );
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Submit Achievement', 'wb-gamification' ) } initialOpen={ true }>
					<p style={ { color: '#6b7280', fontSize: '12px', margin: 0 } }>
						{ __(
							'Members pick an action, write evidence, and submit. Admins review at Gamification → Submissions.',
							'wb-gamification'
						) }
					</p>
				</PanelBody>
				<StandardLayoutPanel attributes={ attributes } setAttributes={ setAttributes } />
				<StandardStylePanel attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="wb-gamification/submit-achievement"
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default Edit;
