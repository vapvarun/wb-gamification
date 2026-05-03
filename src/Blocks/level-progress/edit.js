/**
 * Level Progress — editor surface.
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
	const {
		uniqueId,
		user_id: userId,
		show_progress_bar: showBar,
		show_next_level: showNext,
		show_icon: showIcon,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );

	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-level-progress is-editor-preview`,
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
						label={ __( 'Show level icon', 'wb-gamification' ) }
						checked={ !! showIcon }
						onChange={ ( value ) => setAttributes( { show_icon: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show progress bar', 'wb-gamification' ) }
						checked={ !! showBar }
						onChange={ ( value ) =>
							setAttributes( { show_progress_bar: !! value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show next level', 'wb-gamification' ) }
						checked={ !! showNext }
						onChange={ ( value ) =>
							setAttributes( { show_next_level: !! value } )
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
					accentLabel={ __( 'Progress accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="wb-gamification/level-progress"
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default Edit;
