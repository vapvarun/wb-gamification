( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var RangeControl     = wp.components.RangeControl;
	var SelectControl    = wp.components.SelectControl;
	var ToggleControl    = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/top-members', {
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
							label:    __( 'Layout', 'wb-gamification' ),
							value:    a.layout,
							options:  [
								{ label: __( 'Podium', 'wb-gamification' ), value: 'podium' },
								{ label: __( 'List', 'wb-gamification' ),   value: 'list' },
							],
							onChange: function ( val ) { set( { layout: val } ); },
						} ),
						el( SelectControl, {
							label:    __( 'Period', 'wb-gamification' ),
							value:    a.period,
							options:  [
								{ label: __( 'All Time', 'wb-gamification' ),   value: 'all_time' },
								{ label: __( 'This Week', 'wb-gamification' ),  value: 'this_week' },
								{ label: __( 'This Month', 'wb-gamification' ), value: 'this_month' },
							],
							onChange: function ( val ) { set( { period: val } ); },
						} ),
						el( RangeControl, {
							label:    __( 'Number of members', 'wb-gamification' ),
							value:    a.limit,
							min:      1,
							max:      10,
							onChange: function ( val ) { set( { limit: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show badges', 'wb-gamification' ),
							checked:  a.show_badges,
							onChange: function ( val ) { set( { show_badges: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show level', 'wb-gamification' ),
							checked:  a.show_level,
							onChange: function ( val ) { set( { show_level: val } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/top-members', attributes: a } )
				),
			];
		},
	} );
} )();
