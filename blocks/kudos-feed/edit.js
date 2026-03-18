( function () {
	var el               = wp.element.createElement;
	var __               = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody        = wp.components.PanelBody;
	var RangeControl     = wp.components.RangeControl;
	var ToggleControl    = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'wb-gamification/kudos-feed', {
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
						el( RangeControl, {
							label:    __( 'Number of entries', 'wb-gamification' ),
							value:    a.limit,
							min:      1,
							max:      50,
							onChange: function ( val ) { set( { limit: val } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show kudos messages', 'wb-gamification' ),
							checked:  a.show_messages,
							onChange: function ( val ) { set( { show_messages: val } ); },
						} )
					)
				),
				el( 'div', { key: 'preview' },
					el( ServerSideRender, { block: 'wb-gamification/kudos-feed', attributes: a } )
				),
			];
		},
	} );
} )();
