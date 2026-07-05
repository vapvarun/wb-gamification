/**
 * WB Gamification - Streaks moderation actions.
 *
 * The roster table is server-rendered (src/Admin/StreaksPage.php); this script
 * wires the per-row Adjust / Reset buttons to the write REST endpoints
 * (POST / DELETE /members/{id}/streak) through the shared admin REST wrapper.
 *
 * Uses an inline editor (a small in-row form) rather than native
 * prompt()/confirm() so the flow is accessible, keyboard-operable, and matches
 * the plugin's no-native-dialog rule. Each action is audited server-side.
 *
 * @package WBGamification
 * @since   1.6.2
 */
( function () {
	'use strict';

	var cfg  = window.wbGamStreaks;
	var rest = window.wbGamAdminRest;
	if ( ! cfg || ! rest ) {
		return;
	}
	var i18n = cfg.i18n || {};

	/**
	 * The member id for a row.
	 *
	 * @param {HTMLElement} el Element inside the row.
	 * @return {number} User ID, or 0.
	 */
	function rowUserId( el ) {
		var tr = el.closest( 'tr[data-user-id]' );
		return tr ? parseInt( tr.getAttribute( 'data-user-id' ), 10 ) || 0 : 0;
	}

	/**
	 * Reflect a new current-streak value in the row.
	 *
	 * @param {HTMLElement} tr      Row element.
	 * @param {number}      current New current streak.
	 */
	function updateRow( tr, current ) {
		var cell = tr.querySelector( '[data-col="current"]' );
		if ( cell ) {
			cell.textContent = String( current );
		}
		var adjustBtn = tr.querySelector( '.wb-gam-streak-adjust' );
		if ( adjustBtn ) {
			adjustBtn.setAttribute( 'data-current', String( current ) );
		}
	}

	/**
	 * Remove any open inline editor and restore the action buttons.
	 *
	 * @param {HTMLElement} tr Row element.
	 */
	function closeEditor( tr ) {
		var editor = tr.querySelector( '.wb-gam-streak-editor' );
		if ( editor ) {
			editor.remove();
		}
		var actions = tr.querySelector( '.wb-gam-streaks-table__actions' );
		if ( actions ) {
			actions.hidden = false;
		}
	}

	/**
	 * Build a labelled input.
	 *
	 * @param {string} type        Input type.
	 * @param {string} label       Accessible label.
	 * @param {string} value       Initial value.
	 * @param {object} [attrs]     Extra attributes (min, placeholder…).
	 * @return {HTMLInputElement}
	 */
	function makeInput( type, label, value, attrs ) {
		var input = document.createElement( 'input' );
		input.type = type;
		input.className = 'wbgam-input wb-gam-streak-editor__field';
		input.value = value;
		input.setAttribute( 'aria-label', label );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				input.setAttribute( k, attrs[ k ] );
			} );
		}
		return input;
	}

	/**
	 * Open an inline editor in the actions cell.
	 *
	 * @param {HTMLElement} tr      Row.
	 * @param {string}      mode    'adjust' | 'reset'.
	 * @param {number}      current Current streak (for the adjust prefill).
	 */
	function openEditor( tr, mode, current ) {
		closeEditor( tr );
		var actions = tr.querySelector( '.wb-gam-streaks-table__actions' );
		if ( ! actions ) {
			return;
		}
		actions.hidden = true;

		var box = document.createElement( 'div' );
		box.className = 'wb-gam-streak-editor';
		box.setAttribute( 'role', 'group' );

		var valueInput = null;
		if ( 'adjust' === mode ) {
			valueInput = makeInput( 'number', i18n.adjustPrompt || 'New current streak (days)', String( current ), { min: '0', step: '1' } );
			box.appendChild( valueInput );
		} else {
			var note = document.createElement( 'span' );
			note.className = 'wb-gam-streak-editor__note';
			note.textContent = i18n.resetConfirm || 'Reset this streak to 0?';
			box.appendChild( note );
		}

		var reasonInput = makeInput( 'text', i18n.reasonPrompt || 'Reason', '', { placeholder: i18n.reasonPrompt || 'Reason' } );
		box.appendChild( reasonInput );

		var save = document.createElement( 'button' );
		save.type = 'button';
		save.className = 'button button-small button-primary wb-gam-streak-editor__save';
		save.textContent = ( 'adjust' === mode ) ? ( i18n.save || 'Save' ) : ( i18n.confirmReset || 'Confirm reset' );

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button button-small wb-gam-streak-editor__cancel';
		cancel.textContent = i18n.cancel || 'Cancel';

		box.appendChild( save );
		box.appendChild( cancel );
		actions.parentNode.appendChild( box );

		( valueInput || reasonInput ).focus();

		cancel.addEventListener( 'click', function () {
			closeEditor( tr );
		} );
		box.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeEditor( tr );
			}
		} );
		save.addEventListener( 'click', function () {
			submit( tr, mode, valueInput, reasonInput, save );
		} );
	}

	/**
	 * Send the write request and update the row.
	 *
	 * @param {HTMLElement}       tr          Row.
	 * @param {string}            mode        'adjust' | 'reset'.
	 * @param {HTMLInputElement?} valueInput  Number field (adjust only).
	 * @param {HTMLInputElement}  reasonInput Reason field.
	 * @param {HTMLElement}       save        Save button (busy state).
	 */
	async function submit( tr, mode, valueInput, reasonInput, save ) {
		var id = rowUserId( tr );
		if ( ! id ) {
			return;
		}
		var reason = reasonInput.value || '';
		var res;

		save.disabled = true;
		save.setAttribute( 'aria-busy', 'true' );

		if ( 'adjust' === mode ) {
			var next = parseInt( valueInput.value, 10 );
			if ( isNaN( next ) || next < 0 ) {
				save.disabled = false;
				save.removeAttribute( 'aria-busy' );
				valueInput.focus();
				return;
			}
			res = await rest.apiFetch( 'POST', '/members/' + id + '/streak', { current_streak: next, reason: reason }, cfg );
		} else {
			res = await rest.apiFetch( 'DELETE', '/members/' + id + '/streak', { reason: reason }, cfg );
		}

		save.disabled = false;
		save.removeAttribute( 'aria-busy' );

		if ( res.ok && res.data ) {
			updateRow( tr, res.data.current_streak );
			closeEditor( tr );
			rest.toast && rest.toast( ( 'adjust' === mode ) ? ( i18n.adjusted || 'Streak updated.' ) : ( i18n.reset || 'Streak reset.' ), 'success' );
		} else {
			rest.toast && rest.toast( ( res.data && res.data.message ) || i18n.failed || 'Action failed.', 'error' );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}
		var adjust = e.target.closest( '.wb-gam-streak-adjust' );
		if ( adjust ) {
			e.preventDefault();
			openEditor( adjust.closest( 'tr[data-user-id]' ), 'adjust', parseInt( adjust.getAttribute( 'data-current' ), 10 ) || 0 );
			return;
		}
		var reset = e.target.closest( '.wb-gam-streak-reset' );
		if ( reset ) {
			e.preventDefault();
			openEditor( reset.closest( 'tr[data-user-id]' ), 'reset', 0 );
		}
	} );
}() );
