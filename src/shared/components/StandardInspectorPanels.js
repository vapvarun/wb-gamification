/**
 * Shared Layout + Style InspectorControls panels for Wbcom standard
 * blocks. Each block's edit.js renders <StandardLayoutPanel /> and
 * <StandardStylePanel /> alongside its own <ContentPanel>, so the
 * 150-odd lines of responsive control wiring live in exactly one
 * place and every standardised block surfaces the same controls in
 * the same order.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase D
 */

import { __ } from '@wordpress/i18n';
import { PanelBody, BaseControl, TextControl } from '@wordpress/components';

import ResponsiveControl from './ResponsiveControl';
import SpacingControl from './SpacingControl';
import TypographyControl from './TypographyControl';
import BoxShadowControl from './BoxShadowControl';
import BorderRadiusControl from './BorderRadiusControl';
import ColorHoverControl from './ColorHoverControl';
import DeviceVisibility from './DeviceVisibility';

const pickPerDevice = ( device, desktop, tablet, mobile ) => {
	if ( device === 'Mobile' ) {
		return mobile ?? desktop;
	}
	if ( device === 'Tablet' ) {
		return tablet ?? desktop;
	}
	return desktop;
};

const setForDevice = ( setAttributes, device, baseKey, value ) => {
	const key =
		device === 'Mobile'
			? `${ baseKey }Mobile`
			: device === 'Tablet'
				? `${ baseKey }Tablet`
				: baseKey;
	setAttributes( { [ key ]: value } );
};

export function StandardLayoutPanel( {
	attributes,
	setAttributes,
	device,
	onDeviceChange,
	uniqueId,
	includeColumns = false,
	columnsExtra = null,
} ) {
	const {
		padding,
		paddingTablet,
		paddingMobile,
		paddingUnit,
		margin,
		marginTablet,
		marginMobile,
		marginUnit,
		hideOnDesktop,
		hideOnTablet,
		hideOnMobile,
	} = attributes;

	return (
		<PanelBody title={ __( 'Layout', 'wb-gamification' ) }>
			{ includeColumns && columnsExtra }

			<ResponsiveControl
				label={ __( 'Padding', 'wb-gamification' ) }
				device={ device }
				onDeviceChange={ onDeviceChange }
			>
				<SpacingControl
					label={ __( 'Padding', 'wb-gamification' ) }
					values={
						pickPerDevice( device, padding, paddingTablet, paddingMobile ) ||
						padding
					}
					unit={ paddingUnit }
					onChange={ ( value ) =>
						setForDevice( setAttributes, device, 'padding', value )
					}
					onUnitChange={ ( value ) => setAttributes( { paddingUnit: value } ) }
				/>
			</ResponsiveControl>

			<ResponsiveControl
				label={ __( 'Margin', 'wb-gamification' ) }
				device={ device }
				onDeviceChange={ onDeviceChange }
			>
				<SpacingControl
					label={ __( 'Margin', 'wb-gamification' ) }
					values={
						pickPerDevice( device, margin, marginTablet, marginMobile ) || margin
					}
					unit={ marginUnit }
					onChange={ ( value ) =>
						setForDevice( setAttributes, device, 'margin', value )
					}
					onUnitChange={ ( value ) => setAttributes( { marginUnit: value } ) }
				/>
			</ResponsiveControl>

			<DeviceVisibility
				hideOnDesktop={ hideOnDesktop }
				hideOnTablet={ hideOnTablet }
				hideOnMobile={ hideOnMobile }
				onChange={ ( values ) => setAttributes( values ) }
			/>
		</PanelBody>
	);
}

export function StandardStylePanel( {
	attributes,
	setAttributes,
	device,
	onDeviceChange,
	uniqueId,
	includeTypography = true,
	includeAccentHover = true,
	accentLabel,
} ) {
	const {
		fontFamily,
		fontSize,
		fontSizeTablet,
		fontSizeMobile,
		fontSizeUnit,
		fontWeight,
		lineHeight,
		lineHeightUnit,
		letterSpacing,
		textTransform,
		accentColor,
		accentHoverColor,
		buttonTextColor,
		cardBackground,
		cardBorderColor,
		boxShadow,
		shadowHorizontal,
		shadowVertical,
		shadowBlur,
		shadowSpread,
		shadowColor,
		borderRadius,
		borderRadiusUnit,
	} = attributes;

	return (
		<PanelBody title={ __( 'Style', 'wb-gamification' ) } initialOpen={ false }>
			{ includeTypography && (
				<TypographyControl
					fontFamily={ fontFamily }
					fontSize={ pickPerDevice(
						device,
						fontSize,
						fontSizeTablet,
						fontSizeMobile
					) }
					fontSizeUnit={ fontSizeUnit }
					fontWeight={ fontWeight }
					lineHeight={ lineHeight }
					lineHeightUnit={ lineHeightUnit }
					letterSpacing={ letterSpacing }
					textTransform={ textTransform }
					onChangeFontFamily={ ( value ) =>
						setAttributes( { fontFamily: value } )
					}
					onChangeFontSize={ ( value ) =>
						setForDevice( setAttributes, device, 'fontSize', value )
					}
					onChangeFontSizeUnit={ ( value ) =>
						setAttributes( { fontSizeUnit: value } )
					}
					onChangeFontWeight={ ( value ) =>
						setAttributes( { fontWeight: value } )
					}
					onChangeLineHeight={ ( value ) =>
						setAttributes( { lineHeight: value } )
					}
					onChangeLetterSpacing={ ( value ) =>
						setAttributes( { letterSpacing: value } )
					}
					onChangeTextTransform={ ( value ) =>
						setAttributes( { textTransform: value } )
					}
				/>
			) }

			{ includeAccentHover && (
				<ColorHoverControl
					label={ accentLabel || __( 'Accent colour', 'wb-gamification' ) }
					color={ accentColor }
					hoverColor={ accentHoverColor }
					onChangeColor={ ( value ) => setAttributes( { accentColor: value } ) }
					onChangeHoverColor={ ( value ) =>
						setAttributes( { accentHoverColor: value } )
					}
				/>
			) }

			{ buttonTextColor !== undefined && (
				<BaseControl
					id={ `wb-gam-btn-text-${ uniqueId }` }
					label={ __( 'Button text colour', 'wb-gamification' ) }
				>
					<TextControl
						value={ buttonTextColor || '' }
						onChange={ ( value ) =>
							setAttributes( { buttonTextColor: value } )
						}
						placeholder="#ffffff"
						__nextHasNoMarginBottom
					/>
				</BaseControl>
			) }

			{ cardBackground !== undefined && (
				<BaseControl
					id={ `wb-gam-card-bg-${ uniqueId }` }
					label={ __( 'Card background', 'wb-gamification' ) }
				>
					<TextControl
						value={ cardBackground || '' }
						onChange={ ( value ) =>
							setAttributes( { cardBackground: value } )
						}
						placeholder="#ffffff"
						__nextHasNoMarginBottom
					/>
				</BaseControl>
			) }

			{ cardBorderColor !== undefined && (
				<BaseControl
					id={ `wb-gam-card-border-${ uniqueId }` }
					label={ __( 'Card border colour', 'wb-gamification' ) }
				>
					<TextControl
						value={ cardBorderColor || '' }
						onChange={ ( value ) =>
							setAttributes( { cardBorderColor: value } )
						}
						placeholder="#e2e8f0"
						__nextHasNoMarginBottom
					/>
				</BaseControl>
			) }

			<BoxShadowControl
				enabled={ !! boxShadow }
				horizontal={ shadowHorizontal }
				vertical={ shadowVertical }
				blur={ shadowBlur }
				spread={ shadowSpread }
				color={ shadowColor }
				onChange={ ( values ) => setAttributes( values ) }
			/>

			<BorderRadiusControl
				values={ borderRadius }
				unit={ borderRadiusUnit }
				onChange={ ( value ) => setAttributes( { borderRadius: value } ) }
				onUnitChange={ ( value ) => setAttributes( { borderRadiusUnit: value } ) }
			/>
		</PanelBody>
	);
}
