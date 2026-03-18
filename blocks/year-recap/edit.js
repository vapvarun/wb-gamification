( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var ToggleControl    = wp.components.ToggleControl;
	var TextControl      = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/year-recap', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'controls' },
					el(
						PanelBody,
						{ title: __( 'Settings', 'wb-gamification' ), initialOpen: true },
						el( TextControl, {
							label:    __( 'User ID', 'wb-gamification' ),
							help:     __( '0 = currently logged-in user', 'wb-gamification' ),
							value:    String( a.user_id ),
							type:     'number',
							onChange: function ( val ) { set( { user_id: parseInt( val, 10 ) || 0 } ); },
						} ),
						el( TextControl, {
							label:    __( 'Year', 'wb-gamification' ),
							help:     __( '0 = current year', 'wb-gamification' ),
							value:    String( a.year ),
							type:     'number',
							onChange: function ( val ) { set( { year: parseInt( val, 10 ) || 0 } ); },
						} ),
						el( TextControl, {
							label:       __( 'Accent colour', 'wb-gamification' ),
							help:        __( 'Hex value, e.g. #7c3aed — leave blank for default', 'wb-gamification' ),
							value:       a.accent_color,
							placeholder: '#7c3aed',
							onChange:    function ( val ) { set( { accent_color: val } ); },
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Content', 'wb-gamification' ), initialOpen: false },
						el( ToggleControl, {
							label:    __( 'Show share button', 'wb-gamification' ),
							checked:  a.show_share_button,
							onChange: function ( val ) { set( { show_share_button: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show badges', 'wb-gamification' ),
							checked:  a.show_badges,
							onChange: function ( val ) { set( { show_badges: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show kudos', 'wb-gamification' ),
							checked:  a.show_kudos,
							onChange: function ( val ) { set( { show_kudos: val } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/year-recap', attributes: a } )
				),
			];
		},
	} );
} )();
