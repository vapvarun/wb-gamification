import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, TextControl } from '@wordpress/components';
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
		year,
		show_share_button: showShare,
		show_badges: showBadges,
		show_kudos: showKudos,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-year-recap is-editor-preview`,
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
					<TextControl
						label={ __( 'Year', 'wb-gamification' ) }
						help={ __( '0 = previous year.', 'wb-gamification' ) }
						value={ String( year ?? 0 ) }
						type="number"
						onChange={ ( value ) => setAttributes( { year: parseInt( value, 10 ) || 0 } ) }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Show share button', 'wb-gamification' ) }
						checked={ !! showShare }
						onChange={ ( value ) => setAttributes( { show_share_button: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show badges', 'wb-gamification' ) }
						checked={ !! showBadges }
						onChange={ ( value ) => setAttributes( { show_badges: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show kudos', 'wb-gamification' ) }
						checked={ !! showKudos }
						onChange={ ( value ) => setAttributes( { show_kudos: !! value } ) }
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
					accentLabel={ __( 'Recap accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/year-recap" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
