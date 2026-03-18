( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var RangeControl     = wp.components.RangeControl;
	var ToggleControl    = wp.components.ToggleControl;
	var TextControl      = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/badge-showcase', {
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
							label:    __( 'Show locked badges', 'wb-gamification' ),
							help:     __( 'Display unearned badges greyed out', 'wb-gamification' ),
							checked:  a.show_locked,
							onChange: function ( val ) { set( { show_locked: val } ); },
						} ),
						el( TextControl, {
							label:    __( 'Category filter', 'wb-gamification' ),
							help:     __( 'Badge category slug — leave blank to show all', 'wb-gamification' ),
							value:    a.category,
							onChange: function ( val ) { set( { category: val } ); },
						} ),
						el( RangeControl, {
							label:    __( 'Max badges', 'wb-gamification' ),
							help:     __( '0 = show all', 'wb-gamification' ),
							value:    a.limit,
							min:      0,
							max:      50,
							onChange: function ( val ) { set( { limit: val } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/badge-showcase', attributes: a } )
				),
			];
		},
	} );
} )();
