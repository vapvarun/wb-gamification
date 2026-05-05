/**
 * Reward type toggle on the Redemption Store admin form.
 *
 * Shows / hides config rows + hint blocks based on the currently-selected
 * reward type. Pure DOM, no dependencies.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	var typeSelect = document.getElementById( 'wb-gam-reward-type' );
	if ( ! typeSelect ) {
		return;
	}

	var rows  = document.querySelectorAll( '.wb-gam-config-row' );
	var hints = document.querySelectorAll( '.wb-gam-cfg-hint' );

	function sync() {
		var type = typeSelect.value;
		rows.forEach( function ( row ) {
			var allowed = ( row.getAttribute( 'data-show-for' ) || '' ).split( /\s+/ );
			row.classList.toggle( 'is-visible', allowed.indexOf( type ) !== -1 );
		} );
		hints.forEach( function ( hint ) {
			hint.classList.toggle( 'is-visible', hint.getAttribute( 'data-hint-for' ) === type );
		} );
	}

	typeSelect.addEventListener( 'change', sync );
	sync();
}() );
