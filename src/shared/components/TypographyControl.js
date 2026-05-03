import { __ } from '@wordpress/i18n';
import {
	SelectControl,
	__experimentalNumberControl as NumberControl,
	BaseControl,
	Flex,
	FlexItem,
} from '@wordpress/components';

const FONT_WEIGHTS = [
	{ label: __( 'Default', 'wb-gamification' ), value: '' },
	{ label: '100', value: '100' },
	{ label: '200', value: '200' },
	{ label: '300', value: '300' },
	{ label: '400', value: '400' },
	{ label: '500', value: '500' },
	{ label: '600', value: '600' },
	{ label: '700', value: '700' },
	{ label: '800', value: '800' },
	{ label: '900', value: '900' },
];

const TEXT_TRANSFORMS = [
	{ label: __( 'Default', 'wb-gamification' ), value: '' },
	{ label: __( 'Uppercase', 'wb-gamification' ), value: 'uppercase' },
	{ label: __( 'Lowercase', 'wb-gamification' ), value: 'lowercase' },
	{ label: __( 'Capitalize', 'wb-gamification' ), value: 'capitalize' },
	{ label: __( 'None', 'wb-gamification' ), value: 'none' },
];

const SIZE_UNITS = [
	{ label: 'px', value: 'px' },
	{ label: 'em', value: 'em' },
	{ label: 'rem', value: 'rem' },
];

export default function TypographyControl( {
	fontFamily,
	fontSize,
	fontSizeUnit = 'px',
	fontWeight,
	lineHeight,
	lineHeightUnit = '',
	letterSpacing,
	textTransform,
	onChangeFontFamily,
	onChangeFontSize,
	onChangeFontSizeUnit,
	onChangeFontWeight,
	onChangeLineHeight,
	onChangeLetterSpacing,
	onChangeTextTransform,
} ) {
	return (
		<BaseControl className="wb-gam-typography-control">
			{ onChangeFontFamily && (
				<SelectControl
					label={ __( 'Font Family', 'wb-gamification' ) }
					value={ fontFamily || '' }
					options={ [
						{ label: __( 'Default', 'wb-gamification' ), value: '' },
						{ label: 'System UI', value: 'system-ui, -apple-system, sans-serif' },
						{ label: 'Inter', value: "'Inter', sans-serif" },
						{ label: 'Roboto', value: "'Roboto', sans-serif" },
						{ label: 'Open Sans', value: "'Open Sans', sans-serif" },
						{ label: 'Lato', value: "'Lato', sans-serif" },
						{ label: 'Montserrat', value: "'Montserrat', sans-serif" },
						{ label: 'Poppins', value: "'Poppins', sans-serif" },
						{ label: 'Raleway', value: "'Raleway', sans-serif" },
						{ label: 'Playfair Display', value: "'Playfair Display', serif" },
						{ label: 'Merriweather', value: "'Merriweather', serif" },
					] }
					onChange={ onChangeFontFamily }
					__nextHasNoMarginBottom
				/>
			) }
			<Flex align="flex-end" gap={ 2 }>
				<FlexItem isBlock>
					<NumberControl
						label={ __( 'Size', 'wb-gamification' ) }
						value={ fontSize ?? '' }
						onChange={ ( val ) =>
							onChangeFontSize( val !== '' ? Number( val ) : undefined )
						}
						min={ 0 }
						max={ 200 }
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						value={ fontSizeUnit }
						options={ SIZE_UNITS }
						onChange={ onChangeFontSizeUnit }
						hideLabelFromVision
						label={ __( 'Unit', 'wb-gamification' ) }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
			</Flex>
			<SelectControl
				label={ __( 'Weight', 'wb-gamification' ) }
				value={ fontWeight || '' }
				options={ FONT_WEIGHTS }
				onChange={ onChangeFontWeight }
				__nextHasNoMarginBottom
			/>
			<Flex align="flex-end" gap={ 2 }>
				<FlexItem isBlock>
					<NumberControl
						label={ __( 'Line Height', 'wb-gamification' ) }
						value={ lineHeight ?? '' }
						onChange={ ( val ) =>
							onChangeLineHeight( val !== '' ? Number( val ) : undefined )
						}
						min={ 0 }
						max={ 10 }
						step={ 0.1 }
					/>
				</FlexItem>
				<FlexItem isBlock>
					<NumberControl
						label={ __( 'Letter Spacing', 'wb-gamification' ) }
						value={ letterSpacing ?? '' }
						onChange={ ( val ) =>
							onChangeLetterSpacing( val !== '' ? Number( val ) : undefined )
						}
						min={ -5 }
						max={ 20 }
						step={ 0.1 }
					/>
				</FlexItem>
			</Flex>
			<SelectControl
				label={ __( 'Transform', 'wb-gamification' ) }
				value={ textTransform || '' }
				options={ TEXT_TRANSFORMS }
				onChange={ onChangeTextTransform }
				__nextHasNoMarginBottom
			/>
		</BaseControl>
	);
}
