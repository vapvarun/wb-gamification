import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	TextControl,
	RangeControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const {
		uniqueId,
		user_id: userId,
		show_locked: showLocked,
		category,
		limit,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-badge-showcase is-editor-preview`,
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
					<ToggleControl
						label={ __( 'Show locked badges', 'wb-gamification' ) }
						checked={ !! showLocked }
						onChange={ ( value ) => setAttributes( { show_locked: !! value } ) }
					/>
					<TextControl
						label={ __( 'Category filter', 'wb-gamification' ) }
						help={ __( 'Leave blank to show every category.', 'wb-gamification' ) }
						value={ category || '' }
						onChange={ ( value ) => setAttributes( { category: value } ) }
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={ __( 'Limit (0 = all)', 'wb-gamification' ) }
						value={ limit }
						min={ 0 }
						max={ 100 }
						onChange={ ( value ) => setAttributes( { limit: value ?? 0 } ) }
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
					accentLabel={ __( 'Badge accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/badge-showcase" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
