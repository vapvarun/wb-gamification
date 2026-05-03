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
	const {
		uniqueId,
		limit,
		period,
		show_badges: showBadges,
		show_level: showLevel,
		layout,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-top-members is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'wb-gamification' ) } initialOpen>
					<SelectControl
						label={ __( 'Layout', 'wb-gamification' ) }
						value={ layout || 'podium' }
						options={ [
							{ label: __( 'Podium', 'wb-gamification' ), value: 'podium' },
							{ label: __( 'List', 'wb-gamification' ), value: 'list' },
						] }
						onChange={ ( value ) => setAttributes( { layout: value } ) }
					/>
					<SelectControl
						label={ __( 'Period', 'wb-gamification' ) }
						value={ period || 'all_time' }
						options={ [
							{ label: __( 'All time', 'wb-gamification' ), value: 'all_time' },
							{ label: __( 'This month', 'wb-gamification' ), value: 'this_month' },
							{ label: __( 'This week', 'wb-gamification' ), value: 'this_week' },
						] }
						onChange={ ( value ) => setAttributes( { period: value } ) }
					/>
					<RangeControl
						label={ __( 'Limit', 'wb-gamification' ) }
						value={ limit }
						min={ 1 }
						max={ 20 }
						onChange={ ( value ) => setAttributes( { limit: value ?? 3 } ) }
					/>
					<ToggleControl
						label={ __( 'Show badges', 'wb-gamification' ) }
						checked={ !! showBadges }
						onChange={ ( value ) => setAttributes( { show_badges: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show level', 'wb-gamification' ) }
						checked={ !! showLevel }
						onChange={ ( value ) => setAttributes( { show_level: !! value } ) }
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
					accentLabel={ __( 'Top members accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender block="wb-gamification/top-members" attributes={ attributes } />
			</div>
		</>
	);
};

export default Edit;
