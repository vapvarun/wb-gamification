( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var ToggleControl    = wp.components.ToggleControl;
	var TextControl      = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/level-progress', {
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
						el( ToggleControl, {
							label:    __( 'Show progress bar', 'wb-gamification' ),
							checked:  a.show_progress_bar,
							onChange: function ( val ) { set( { show_progress_bar: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show next level', 'wb-gamification' ),
							help:     __( 'Display the next level name and points remaining', 'wb-gamification' ),
							checked:  a.show_next_level,
							onChange: function ( val ) { set( { show_next_level: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show level icon', 'wb-gamification' ),
							checked:  a.show_icon,
							onChange: function ( val ) { set( { show_icon: val } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/level-progress', attributes: a } )
				),
			];
		},
	} );
} )();
