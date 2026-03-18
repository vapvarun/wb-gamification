/**
 * WB Gamification — Badge Admin Page
 *
 * Toggles condition-specific form rows when the condition type selector changes.
 *
 * @since 0.5.0
 */
/* global wbGamToggleConditionFields */

function wbGamToggleConditionFields( type ) {
	document.getElementById( 'wb-gam-field-points' ).style.display = 'point_milestone' === type ? '' : 'none';
	document.getElementById( 'wb-gam-field-action' ).style.display = 'action_count' === type ? '' : 'none';
	document.getElementById( 'wb-gam-field-count' ).style.display  = 'action_count' === type ? '' : 'none';
}
