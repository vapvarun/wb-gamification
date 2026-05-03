/**
 * Redemption Store — editor surface.
 *
 * Implements the canonical Wbcom Block Quality Standard inspector
 * layout: Content / Layout / Style panels in that order, with the
 * shared responsive controls from `src/shared/components`. Server-side
 * preview comes from ServerSideRender so the editor mirrors the
 * frontend exactly — no parallel React render path.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase C.3
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	ToggleControl,
	TextControl,
	BaseControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import {
	ResponsiveControl,
	SpacingControl,
	TypographyControl,
	BoxShadowControl,
	BorderRadiusControl,
	ColorHoverControl,
	DeviceVisibility,
	useUniqueId,
} from '../../shared';

const Edit = ( { attributes, setAttributes, clientId } ) => {
	const {
		uniqueId,
		limit,
		columns,
		showBalance,
		showStock,
		buttonLabel,
		emptyMessage,
		padding,
		paddingTablet,
		paddingMobile,
		paddingUnit,
		margin,
		marginTablet,
		marginMobile,
		marginUnit,
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
		hideOnDesktop,
		hideOnTablet,
		hideOnMobile,
	} = attributes;

	useUniqueId( clientId, uniqueId, setAttributes );

	const [ device, setDevice ] = useState( 'Desktop' );

	const blockProps = useBlockProps( {
		className: `wb-gam-block-${ uniqueId } wb-gam-redemption is-editor-preview`,
	} );

	const paddingForDevice =
		device === 'Mobile' ? paddingMobile : device === 'Tablet' ? paddingTablet : padding;

	const marginForDevice =
		device === 'Mobile' ? marginMobile : device === 'Tablet' ? marginTablet : margin;

	const onPaddingChange = ( value ) =>
		setAttributes( {
			[ device === 'Mobile'
				? 'paddingMobile'
				: device === 'Tablet'
					? 'paddingTablet'
					: 'padding' ]: value,
		} );

	const onMarginChange = ( value ) =>
		setAttributes( {
			[ device === 'Mobile'
				? 'marginMobile'
				: device === 'Tablet'
					? 'marginTablet'
					: 'margin' ]: value,
		} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Content', 'wb-gamification' ) } initialOpen>
					<RangeControl
						label={ __( 'Items to display (0 = all active)', 'wb-gamification' ) }
						value={ limit }
						min={ 0 }
						max={ 50 }
						onChange={ ( value ) => setAttributes( { limit: value ?? 0 } ) }
					/>
					<ToggleControl
						label={ __( 'Show member point balance', 'wb-gamification' ) }
						checked={ !! showBalance }
						onChange={ ( value ) => setAttributes( { showBalance: !! value } ) }
					/>
					<ToggleControl
						label={ __( 'Show per-item stock indicator', 'wb-gamification' ) }
						checked={ !! showStock }
						onChange={ ( value ) => setAttributes( { showStock: !! value } ) }
					/>
					<TextControl
						label={ __( 'Redeem button label', 'wb-gamification' ) }
						help={ __( 'Defaults to "Redeem".', 'wb-gamification' ) }
						value={ buttonLabel }
						onChange={ ( value ) => setAttributes( { buttonLabel: value } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Empty-state message', 'wb-gamification' ) }
						help={ __( 'Shown when no rewards are active.', 'wb-gamification' ) }
						value={ emptyMessage }
						onChange={ ( value ) => setAttributes( { emptyMessage: value } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Layout', 'wb-gamification' ) }>
					<BaseControl
						id={ `wb-gam-columns-${ uniqueId }` }
						label={ __( 'Columns', 'wb-gamification' ) }
					>
						<ToggleGroupControl
							value={ String( columns ) }
							isBlock
							onChange={ ( value ) =>
								setAttributes( { columns: parseInt( value, 10 ) || 3 } )
							}
							__nextHasNoMarginBottom
						>
							{ [ 1, 2, 3, 4 ].map( ( n ) => (
								<ToggleGroupControlOption
									key={ n }
									value={ String( n ) }
									label={ String( n ) }
								/>
							) ) }
						</ToggleGroupControl>
					</BaseControl>

					<ResponsiveControl
						label={ __( 'Padding', 'wb-gamification' ) }
						device={ device }
						onDeviceChange={ setDevice }
					>
						<SpacingControl
							label={ __( 'Padding', 'wb-gamification' ) }
							values={ paddingForDevice || padding }
							unit={ paddingUnit }
							onChange={ onPaddingChange }
							onUnitChange={ ( value ) => setAttributes( { paddingUnit: value } ) }
						/>
					</ResponsiveControl>

					<ResponsiveControl
						label={ __( 'Margin', 'wb-gamification' ) }
						device={ device }
						onDeviceChange={ setDevice }
					>
						<SpacingControl
							label={ __( 'Margin', 'wb-gamification' ) }
							values={ marginForDevice || margin }
							unit={ marginUnit }
							onChange={ onMarginChange }
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

				<PanelBody title={ __( 'Style', 'wb-gamification' ) } initialOpen={ false }>
					<TypographyControl
						fontFamily={ fontFamily }
						fontSize={
							device === 'Mobile'
								? fontSizeMobile
								: device === 'Tablet'
									? fontSizeTablet
									: fontSize
						}
						fontSizeUnit={ fontSizeUnit }
						fontWeight={ fontWeight }
						lineHeight={ lineHeight }
						lineHeightUnit={ lineHeightUnit }
						letterSpacing={ letterSpacing }
						textTransform={ textTransform }
						onChangeFontFamily={ ( value ) => setAttributes( { fontFamily: value } ) }
						onChangeFontSize={ ( value ) =>
							setAttributes( {
								[ device === 'Mobile'
									? 'fontSizeMobile'
									: device === 'Tablet'
										? 'fontSizeTablet'
										: 'fontSize' ]: value,
							} )
						}
						onChangeFontSizeUnit={ ( value ) => setAttributes( { fontSizeUnit: value } ) }
						onChangeFontWeight={ ( value ) => setAttributes( { fontWeight: value } ) }
						onChangeLineHeight={ ( value ) => setAttributes( { lineHeight: value } ) }
						onChangeLetterSpacing={ ( value ) => setAttributes( { letterSpacing: value } ) }
						onChangeTextTransform={ ( value ) => setAttributes( { textTransform: value } ) }
					/>

					<ColorHoverControl
						label={ __( 'Redeem button background', 'wb-gamification' ) }
						color={ accentColor }
						hoverColor={ accentHoverColor }
						onChangeColor={ ( value ) => setAttributes( { accentColor: value } ) }
						onChangeHoverColor={ ( value ) => setAttributes( { accentHoverColor: value } ) }
					/>

					<BaseControl
						id={ `wb-gam-button-text-${ uniqueId }` }
						label={ __( 'Redeem button text', 'wb-gamification' ) }
					>
						<TextControl
							value={ buttonTextColor }
							onChange={ ( value ) => setAttributes( { buttonTextColor: value } ) }
							placeholder="#ffffff"
							__nextHasNoMarginBottom
						/>
					</BaseControl>

					<BaseControl
						id={ `wb-gam-card-bg-${ uniqueId }` }
						label={ __( 'Card background', 'wb-gamification' ) }
					>
						<TextControl
							value={ cardBackground }
							onChange={ ( value ) => setAttributes( { cardBackground: value } ) }
							placeholder="#ffffff"
							__nextHasNoMarginBottom
						/>
					</BaseControl>

					<BaseControl
						id={ `wb-gam-card-border-${ uniqueId }` }
						label={ __( 'Card border colour', 'wb-gamification' ) }
					>
						<TextControl
							value={ cardBorderColor }
							onChange={ ( value ) => setAttributes( { cardBorderColor: value } ) }
							placeholder="#e3e6ec"
							__nextHasNoMarginBottom
						/>
					</BaseControl>

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
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block={ 'wb-gamification/redemption-store' }
					attributes={ attributes }
					EmptyResponsePlaceholder={ () => (
						<p className="wb-gam-redemption__empty">
							{ emptyMessage ||
								__(
									'No rewards available yet. Check back soon!',
									'wb-gamification'
								) }
						</p>
					) }
				/>
			</div>
		</>
	);
};

export default Edit;
