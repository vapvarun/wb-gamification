import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	__experimentalNumberControl as NumberControl,
	Button,
	SelectControl,
	Flex,
	FlexItem,
	BaseControl,
} from '@wordpress/components';
import { link, linkOff } from '@wordpress/icons';

const CORNERS = [
	{ key: 'top', label: __( 'TL', 'wb-gamification' ) },
	{ key: 'right', label: __( 'TR', 'wb-gamification' ) },
	{ key: 'bottom', label: __( 'BR', 'wb-gamification' ) },
	{ key: 'left', label: __( 'BL', 'wb-gamification' ) },
];

const UNITS = [
	{ label: 'px', value: 'px' },
	{ label: '%', value: '%' },
	{ label: 'em', value: 'em' },
];

export default function BorderRadiusControl( {
	values = { top: 0, right: 0, bottom: 0, left: 0 },
	unit = 'px',
	onChange,
	onUnitChange,
} ) {
	const [ linked, setLinked ] = useState( true );

	const handleChange = ( corner, val ) => {
		const num = val !== '' ? Number( val ) : 0;
		if ( linked ) {
			onChange( { top: num, right: num, bottom: num, left: num } );
		} else {
			onChange( { ...values, [ corner ]: num } );
		}
	};

	return (
		<BaseControl
			label={ __( 'Border Radius', 'wb-gamification' ) }
			className="wb-gam-border-radius-control"
		>
			<Flex align="flex-end" gap={ 2 }>
				{ linked ? (
					<FlexItem>
						<NumberControl
							label={ __( 'All corners', 'wb-gamification' ) }
							value={ values.top }
							onChange={ ( val ) => handleChange( 'top', val ) }
							min={ 0 }
							max={ 500 }
							hideLabelFromVision
						/>
					</FlexItem>
				) : (
					CORNERS.map( ( { key, label: cornerLabel } ) => (
						<FlexItem key={ key }>
							<NumberControl
								label={ cornerLabel }
								value={ values[ key ] }
								onChange={ ( val ) => handleChange( key, val ) }
								min={ 0 }
								max={ 500 }
								hideLabelFromVision
								placeholder={ cornerLabel }
							/>
						</FlexItem>
					) )
				) }
				<FlexItem>
					<Button
						icon={ linked ? link : linkOff }
						label={ linked
							? __( 'Unlink corners', 'wb-gamification' )
							: __( 'Link corners', 'wb-gamification' )
						}
						onClick={ () => setLinked( ! linked ) }
						isPressed={ linked }
						size="small"
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						value={ unit }
						options={ UNITS }
						onChange={ onUnitChange }
						hideLabelFromVision
						label={ __( 'Unit', 'wb-gamification' ) }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
			</Flex>
		</BaseControl>
	);
}
