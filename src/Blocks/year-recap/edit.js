import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { calendar } from '@wordpress/icons';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
	BlockPreviewCard,
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
				<BlockPreviewCard
					icon={ calendar }
					title={ __( 'Year in Community Recap', 'wb-gamification' ) }
					description={ __(
						'A shareable Spotify-Wrapped-style recap card of a member’s highlights for the year.',
						'wb-gamification'
					) }
					status={
						year > 0
							? sprintf(
									/* translators: %d: recap year. */
									__( 'Recap for %d', 'wb-gamification' ),
									year
							  )
							: __( 'Recap for the previous year', 'wb-gamification' )
					}
					statusType="configured"
				/>
			</div>
		</>
	);
};

export default Edit;
