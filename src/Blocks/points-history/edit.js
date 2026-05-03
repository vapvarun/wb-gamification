import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, TextControl, RangeControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const { uniqueId, user_id: userId, limit, show_action_label: showLabel } = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-points-history is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'wb-gamification' ) } initialOpen>
					<TextControl
						label={ __( 'User ID', 'wb-gamification' ) }
						help={ __( '0 = currently logged-in user.', 'wb-gamification' ) }
						value={ String( userId ?? 0 ) }
						type="number"
						onChange={ ( value ) => setAttributes( { user_id: parseInt( value, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={ __( 'Limit', 'wb-gamification' ) }
						value={ limit }
						min={ 1 }
						max={ 100 }
						onChange={ ( value ) => setAttributes( { limit: value ?? 20 } ) }
					/>
					<ToggleControl
						label={ __( 'Show action label', 'wb-gamification' ) }
						checked={ !! showLabel }
						onChange={ ( value ) => setAttributes( { show_action_label: !! value } ) }
					/>
				</PanelBody>

				<StandardLayoutPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					device={ device }
					onDeviceChange={ setDevice }
					uniqueId={ uniqueId }
				/>

				<StandardStylePanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					device={ device }
					onDeviceChange={ setDevice }
					uniqueId={ uniqueId }
					accentLabel={ __( 'Points history accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/points-history" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
