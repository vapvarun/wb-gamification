import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const {
		uniqueId,
		layout,
		position,
		showLevel,
		showBadges,
		showStreak,
		showProgress,
		collapsible,
		hideForGuests,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-status-bar wb-gam-status-bar--${ layout } wb-gam-status-bar--pos-${ position } is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Layout', 'wb-gamification' ) } initialOpen>
					<SelectControl
						label={ __( 'Layout', 'wb-gamification' ) }
						value={ layout }
						options={ [
							{ label: __( 'Floating (sticky pill)', 'wb-gamification' ), value: 'floating' },
							{ label: __( 'Sticky bar (top)',       'wb-gamification' ), value: 'sticky-top' },
							{ label: __( 'Inline (in flow)',       'wb-gamification' ), value: 'inline' },
						] }
						onChange={ ( value ) => setAttributes( { layout: value } ) }
					/>
					{ layout === 'floating' && (
						<SelectControl
							label={ __( 'Floating position', 'wb-gamification' ) }
							value={ position }
							options={ [
								{ label: __( 'Top right',    'wb-gamification' ), value: 'top-right' },
								{ label: __( 'Top left',     'wb-gamification' ), value: 'top-left' },
								{ label: __( 'Bottom right', 'wb-gamification' ), value: 'bottom-right' },
								{ label: __( 'Bottom left',  'wb-gamification' ), value: 'bottom-left' },
							] }
							onChange={ ( value ) => setAttributes( { position: value } ) }
						/>
					) }
					<ToggleControl
						label={ __( 'Collapsible', 'wb-gamification' ) }
						help={ __( 'Lets members hide the bar with a single click; preference persists for the session.', 'wb-gamification' ) }
						checked={ !! collapsible }
						onChange={ ( value ) => setAttributes( { collapsible: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Hide for logged-out visitors', 'wb-gamification' ) }
						checked={ !! hideForGuests }
						onChange={ ( value ) => setAttributes( { hideForGuests: !! value } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Show', 'wb-gamification' ) } initialOpen>
					<ToggleControl
						label={ __( 'Level',       'wb-gamification' ) }
						checked={ !! showLevel }
						onChange={ ( value ) => setAttributes( { showLevel: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Badges',      'wb-gamification' ) }
						checked={ !! showBadges }
						onChange={ ( value ) => setAttributes( { showBadges: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Streak',      'wb-gamification' ) }
						checked={ !! showStreak }
						onChange={ ( value ) => setAttributes( { showStreak: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Level progress bar', 'wb-gamification' ) }
						checked={ !! showProgress }
						onChange={ ( value ) => setAttributes( { showProgress: !! value } ) }
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
					accentLabel={ __( 'Status bar accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="wb-gam-status-bar__inner">
					<div className="wb-gam-status-bar__body">
						<span className="wb-gam-status-bar__stat wb-gam-status-bar__stat--points">
							<span className="wb-gam-status-bar__value">1,205</span>
							<span className="wb-gam-status-bar__label">{ __( 'Points', 'wb-gamification' ) }</span>
						</span>
						{ showLevel && (
							<span className="wb-gam-status-bar__stat wb-gam-status-bar__stat--level">
								<span className="wb-gam-status-bar__value">{ __( 'Contributor', 'wb-gamification' ) }</span>
							</span>
						) }
						{ showBadges && (
							<span className="wb-gam-status-bar__stat wb-gam-status-bar__stat--badges">
								<span className="wb-gam-status-bar__value">12</span>
							</span>
						) }
						{ showStreak && (
							<span className="wb-gam-status-bar__stat wb-gam-status-bar__stat--streak">
								<span className="wb-gam-status-bar__value">7</span>
							</span>
						) }
						{ showProgress && (
							<span className="wb-gam-status-bar__progress" aria-hidden="true">
								<span className="wb-gam-status-bar__progress-fill" style={ { width: '42%' } } />
							</span>
						) }
					</div>
				</div>
			</div>
		</>
	);
};

export default Edit;
