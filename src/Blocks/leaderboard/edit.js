/**
 * Leaderboard — editor surface.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const { uniqueId, period, limit, show_avatars: showAvatars } = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-leaderboard is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'wb-gamification' ) } initialOpen>
					<SelectControl
						label={ __( 'Period', 'wb-gamification' ) }
						value={ period || 'all' }
						options={ [
							{ label: __( 'All time', 'wb-gamification' ), value: 'all' },
							{ label: __( 'This month', 'wb-gamification' ), value: 'month' },
							{ label: __( 'This week', 'wb-gamification' ), value: 'week' },
							{ label: __( 'Today', 'wb-gamification' ), value: 'day' },
						] }
						onChange={ ( value ) => setAttributes( { period: value } ) }
					/>
					<RangeControl
						label={ __( 'Limit', 'wb-gamification' ) }
						value={ limit }
						min={ 1 }
						max={ 100 }
						onChange={ ( value ) => setAttributes( { limit: value ?? 10 } ) }
					/>
					<ToggleControl
						label={ __( 'Show avatars', 'wb-gamification' ) }
						checked={ !! showAvatars }
						onChange={ ( value ) => setAttributes( { show_avatars: !! value } ) }
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
					accentLabel={ __( 'Rank accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/leaderboard" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
