/**
 * Streak — editor surface.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase D.1
 */

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
		show_longest: showLongest,
		show_heatmap: showHeatmap,
		heatmap_days: heatmapDays,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );

	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-streak is-editor-preview`,
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
						label={ __( 'Show longest streak', 'wb-gamification' ) }
						checked={ !! showLongest }
						onChange={ ( value ) =>
							setAttributes( { show_longest: !! value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show contribution heatmap', 'wb-gamification' ) }
						checked={ !! showHeatmap }
						onChange={ ( value ) =>
							setAttributes( { show_heatmap: !! value } )
						}
					/>
					{ showHeatmap && (
						<RangeControl
							label={ __( 'Heatmap days', 'wb-gamification' ) }
							value={ heatmapDays }
							min={ 7 }
							max={ 365 }
							onChange={ ( value ) =>
								setAttributes( { heatmap_days: value ?? 90 } )
							}
						/>
					) }
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
					accentLabel={ __( 'Streak accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/streak" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
