/**
 * Action-row toggle on the Settings → Automation tab.
 *
 * Shows the field row that matches the currently-selected rule action;
 * hides all others. Pure DOM, no dependencies.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	var sel = document.getElementById( 'wb_gam_new_rule_action' );
	if ( ! sel ) {
		return;
	}

	function toggle() {
		var val = sel.value;
		document.querySelectorAll( '.wb-gam-auto-field-row' ).forEach( function ( row ) {
			// Visibility via class — keeps presentation in CSS, not inline.
			row.classList.toggle( 'is-visible', row.dataset.for === val );
		} );
	}

	sel.addEventListener( 'change', toggle );
	toggle();
}() );
