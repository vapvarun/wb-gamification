import { __ } from '@wordpress/i18n';
import { ToggleControl, BaseControl } from '@wordpress/components';

export default function DeviceVisibility( {
	hideOnDesktop = false,
	hideOnTablet = false,
	hideOnMobile = false,
	onChange,
} ) {
	return (
		<BaseControl
			label={ __( 'Device Visibility', 'wb-gamification' ) }
			className="wb-gam-device-visibility"
		>
			<ToggleControl
				label={ __( 'Hide on Desktop', 'wb-gamification' ) }
				checked={ hideOnDesktop }
				onChange={ ( val ) => onChange( { hideOnDesktop: val } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Hide on Tablet', 'wb-gamification' ) }
				checked={ hideOnTablet }
				onChange={ ( val ) => onChange( { hideOnTablet: val } ) }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __( 'Hide on Mobile', 'wb-gamification' ) }
				checked={ hideOnMobile }
				onChange={ ( val ) => onChange( { hideOnMobile: val } ) }
				__nextHasNoMarginBottom
			/>
		</BaseControl>
	);
}
