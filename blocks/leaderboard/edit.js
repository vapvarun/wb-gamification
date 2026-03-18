( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var RangeControl     = wp.components.RangeControl;
	var SelectControl    = wp.components.SelectControl;
	var ToggleControl    = wp.components.ToggleControl;
	var TextControl      = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/leaderboard', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'controls' },
					el(
						PanelBody,
						{ title: __( 'Display', 'wb-gamification' ), initialOpen: true },
						el( SelectControl, {
							label:    __( 'Period', 'wb-gamification' ),
							value:    a.period,
							options:  [
								{ label: __( 'All Time', 'wb-gamification' ),   value: 'all' },
								{ label: __( 'This Month', 'wb-gamification' ), value: 'month' },
								{ label: __( 'This Week', 'wb-gamification' ),  value: 'week' },
								{ label: __( 'Today', 'wb-gamification' ),      value: 'day' },
							],
							onChange: function ( val ) { set( { period: val } ); },
						} ),
						el( RangeControl, {
							label:    __( 'Number of members', 'wb-gamification' ),
							value:    a.limit,
							min:      1,
							max:      100,
							onChange: function ( val ) { set( { limit: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show avatars', 'wb-gamification' ),
							checked:  a.show_avatars,
							onChange: function ( val ) { set( { show_avatars: val } ); },
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Scope (advanced)', 'wb-gamification' ), initialOpen: false },
						el( TextControl, {
							label:    __( 'Scope type', 'wb-gamification' ),
							help:     __( 'e.g. bp_group', 'wb-gamification' ),
							value:    a.scope_type,
							onChange: function ( val ) { set( { scope_type: val } ); },
						} ),
						el( TextControl, {
							label:    __( 'Scope ID', 'wb-gamification' ),
							help:     __( 'Group or object ID', 'wb-gamification' ),
							value:    String( a.scope_id ),
							type:     'number',
							onChange: function ( val ) { set( { scope_id: parseInt( val, 10 ) || 0 } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/leaderboard', attributes: a } )
				),
			];
		},
	} );
} )();
