/**
 * Give Kudos — editor surface.
 *
 * Static block (no Interactivity-API runtime markup), so per the Wbcom Block
 * Quality Standard decision tree the editor uses ServerSideRender for a true
 * preview, plus a Content panel and the shared Layout/Style panels so all 25
 * attributes are editable. Shared canvas styles (tokens + card) are injected
 * via block_editor_settings_all (see wb-gamification.php).
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const { uniqueId, to, label } = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-give-kudos is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'wb-gamification' ) } initialOpen>
					<TextControl
						label={ __( 'Recipient user ID', 'wb-gamification' ) }
						help={ __( 'Leave empty to let the member choose a recipient.', 'wb-gamification' ) }
						value={ to }
						onChange={ ( value ) => setAttributes( { to: value } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Button label', 'wb-gamification' ) }
						help={ __( 'Defaults to "Give Kudos".', 'wb-gamification' ) }
						value={ label }
						onChange={ ( value ) => setAttributes( { label: value } ) }
						__nextHasNoMarginBottom
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
					accentLabel={ __( 'Kudos accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/give-kudos" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
