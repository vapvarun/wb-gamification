/**
 * Member Points — editor surface.
 *
 * Phase D.1 of the Wbcom Block Quality Standard migration. Adopts the
 * canonical 3-panel inspector (Content / Layout / Style) via the shared
 * `StandardLayoutPanel` and `StandardStylePanel` helpers; block-specific
 * settings (user_id, level visibility, progress bar) live in the
 * Content panel.
 */

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
	const { uniqueId, user_id: userId, show_level: showLevel, show_progress_bar: showProgress } =
		attributes;

	useUniqueId( clientId, uniqueId, setAttributes );

	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-member-points is-editor-preview`,
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
						onChange={ ( value ) =>
							setAttributes( { user_id: parseInt( value, 10 ) || 0 } )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Show level', 'wb-gamification' ) }
						checked={ !! showLevel }
						onChange={ ( value ) => setAttributes( { show_level: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show progress bar', 'wb-gamification' ) }
						checked={ !! showProgress }
						onChange={ ( value ) =>
							setAttributes( { show_progress_bar: !! value } )
						}
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
					accentLabel={ __( 'Points number colour', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="wb-gamification/member-points"
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default Edit;
