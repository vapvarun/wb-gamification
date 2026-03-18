( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var RangeControl     = wp.components.RangeControl;
	var ToggleControl    = wp.components.ToggleControl;
	var TextControl      = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/streak', {
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
							label:    __( 'Show longest streak', 'wb-gamification' ),
							help:     __( 'Display all-time personal best streak', 'wb-gamification' ),
							checked:  a.show_longest,
							onChange: function ( val ) { set( { show_longest: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show activity heatmap', 'wb-gamification' ),
							help:     __( 'GitHub-style contribution heatmap', 'wb-gamification' ),
							checked:  a.show_heatmap,
							onChange: function ( val ) { set( { show_heatmap: val } ); },
						} ),
						a.show_heatmap && el( RangeControl, {
							label:    __( 'Heatmap days', 'wb-gamification' ),
							value:    a.heatmap_days,
							min:      30,
							max:      365,
							onChange: function ( val ) { set( { heatmap_days: val } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/streak', attributes: a } )
				),
			];
		},
	} );
} )();
