/**
 * Daily Login Bonus — editor surface.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import {
	useUniqueId,
	StandardLayoutPanel,
	StandardStylePanel,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	useUniqueId( attributes, setAttributes, clientId );
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Daily Login Bonus', 'wb-gamification' ) } initialOpen={ true }>
					<p style={ { color: '#6b7280', fontSize: '12px', margin: 0 } }>
						{ __(
							'Renders the current member\'s login streak + today\'s bonus + tier ladder. Configure the tier values in Settings → Emails (or the wb_gam_login_bonus_tiers JSON option).',
							'wb-gamification'
						) }
					</p>
				</PanelBody>
				<StandardLayoutPanel attributes={ attributes } setAttributes={ setAttributes } />
				<StandardStylePanel attributes={ attributes } setAttributes={ setAttributes } />
			</InspectorControls>
			<div { ...blockProps }>
				<ServerSideRender
					block="wb-gamification/daily-bonus"
					attributes={ attributes }
				/>
			</div>
		</>
	);
};

export default Edit;
