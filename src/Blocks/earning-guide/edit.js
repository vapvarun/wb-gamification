/**
 * Earning Guide — editor surface.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	BaseControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const { uniqueId, columns, show_category_headers: showHeaders } = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );

	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-earning-guide is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'wb-gamification' ) } initialOpen>
					<BaseControl
						id={ `wb-gam-eg-cols-${ uniqueId }` }
						label={ __( 'Columns', 'wb-gamification' ) }
					>
						<ToggleGroupControl
							value={ String( columns ) }
							isBlock
							onChange={ ( value ) =>
								setAttributes( { columns: parseInt( value, 10 ) || 3 } )
							}
							__nextHasNoMarginBottom
						>
							{ [ 1, 2, 3, 4 ].map( ( n ) => (
								<ToggleGroupControlOption
									key={ n }
									value={ String( n ) }
									label={ String( n ) }
								/>
							) ) }
						</ToggleGroupControl>
					</BaseControl>
					<ToggleControl
						label={ __( 'Show category headers', 'wb-gamification' ) }
						checked={ !! showHeaders }
						onChange={ ( value ) =>
							setAttributes( { show_category_headers: !! value } )
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
					accentLabel={ __( 'Card accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="wb-gamification/earning-guide"
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default Edit;
