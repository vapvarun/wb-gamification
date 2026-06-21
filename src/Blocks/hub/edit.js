import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';
import { starFilled } from '@wordpress/icons';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
	BlockPreviewCard,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const { uniqueId } = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );
	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-hub is-editor-preview`,
	} );

	return (
		<>
			<InspectorControls>
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
					accentLabel={ __( 'Hub accent', 'wb-gamification' ) }
				/>
			</InspectorControls>

			<div { ...blockProps }>
				<BlockPreviewCard
					icon={ starFilled }
					title={ __( 'Gamification Hub', 'wb-gamification' ) }
					description={ __(
						'Member dashboard — points, level, badges, challenges, leaderboard, and kudos in a connected card layout.',
						'wb-gamification'
					) }
					status={ __(
						'Live data renders on the front end for the logged-in member.',
						'wb-gamification'
					) }
					statusType="configured"
				/>
			</div>
		</>
	);
};

export default Edit;
