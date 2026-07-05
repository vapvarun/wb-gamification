/**
 * WB Gamification - Kudos moderation actions.
 *
 * The roster is server-rendered (src/Admin/KudosModerationPage.php); this
 * script wires the per-row Revoke button to DELETE /kudos/{id} through the
 * shared admin REST wrapper, using an inline reason editor (no native
 * prompt/confirm). On success the row is marked revoked in place.
 *
 * @package WBGamification
 * @since   1.6.2
 */
( function () {
	'use strict';

	var cfg  = window.wbGamKudos;
	var rest = window.wbGamAdminRest;
	if ( ! cfg || ! rest ) {
		return;
	}
	var i18n = cfg.i18n || {};

	function rowKudosId( el ) {
		var tr = el.closest( 'tr[data-kudos-id]' );
		return tr ? parseInt( tr.getAttribute( 'data-kudos-id' ), 10 ) || 0 : 0;
	}

	function closeEditor( tr ) {
		var editor = tr.querySelector( '.wb-gam-kudos-editor' );
		if ( editor ) {
			editor.remove();
		}
		var actions = tr.querySelector( '.wb-gam-kudos-table__actions' );
		if ( actions ) {
			actions.hidden = false;
		}
	}

	/**
	 * Mark a row revoked in place (status badge + remove the revoke button).
	 *
	 * @param {HTMLElement} tr Row.
	 */
	function markRevoked( tr ) {
		var openEditorBox = tr.querySelector( '.wb-gam-kudos-editor' );
		if ( openEditorBox ) {
			openEditorBox.remove();
		}
		var statusCell = tr.querySelector( '.wb-gam-kudos-table__status' );
		if ( statusCell ) {
			statusCell.textContent = '';
			var badge = document.createElement( 'span' );
			badge.className = 'wbgam-badge wbgam-badge--danger';
			badge.textContent = i18n.revoked ? i18n.revoked.replace( /\.$/, '' ) : 'Revoked';
			statusCell.appendChild( badge );
		}
		var actions = tr.querySelector( '.wb-gam-kudos-table__actions' );
		if ( actions ) {
			while ( actions.firstChild ) {
				actions.removeChild( actions.firstChild );
			}
			var dash = document.createElement( 'span' );
			dash.className = 'wb-gam-kudos-table__muted';
			dash.textContent = '—';
			actions.appendChild( dash );
			actions.hidden = false;
		}
	}

	/**
	 * Open the inline reason editor for a revoke.
	 *
	 * @param {HTMLElement} tr Row.
	 */
	function openEditor( tr ) {
		closeEditor( tr );
		var actions = tr.querySelector( '.wb-gam-kudos-table__actions' );
		if ( ! actions ) {
			return;
		}
		actions.hidden = true;

		var box = document.createElement( 'div' );
		box.className = 'wb-gam-kudos-editor';
		box.setAttribute( 'role', 'group' );

		var note = document.createElement( 'span' );
		note.className = 'wb-gam-kudos-editor__note';
		note.textContent = i18n.revokeNote || 'Revoke this kudos?';
		box.appendChild( note );

		var reason = document.createElement( 'input' );
		reason.type = 'text';
		reason.className = 'wbgam-input wb-gam-kudos-editor__reason';
		reason.setAttribute( 'aria-label', i18n.reasonPrompt || 'Reason' );
		reason.setAttribute( 'placeholder', i18n.reasonPrompt || 'Reason' );
		box.appendChild( reason );

		var confirm = document.createElement( 'button' );
		confirm.type = 'button';
		confirm.className = 'button button-small button-primary wb-gam-kudos-editor__confirm';
		confirm.textContent = i18n.confirm || 'Confirm revoke';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button button-small wb-gam-kudos-editor__cancel';
		cancel.textContent = i18n.cancel || 'Cancel';

		box.appendChild( confirm );
		box.appendChild( cancel );
		actions.parentNode.appendChild( box );
		reason.focus();

		cancel.addEventListener( 'click', function () {
			closeEditor( tr );
		} );
		box.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeEditor( tr );
			}
		} );
		confirm.addEventListener( 'click', function () {
			submit( tr, reason, confirm );
		} );
	}

	async function submit( tr, reasonInput, confirmBtn ) {
		var id = rowKudosId( tr );
		if ( ! id ) {
			return;
		}
		confirmBtn.disabled = true;
		confirmBtn.setAttribute( 'aria-busy', 'true' );

		var res = await rest.apiFetch( 'DELETE', '/kudos/' + id, { reason: reasonInput.value || '' }, cfg );

		confirmBtn.disabled = false;
		confirmBtn.removeAttribute( 'aria-busy' );

		if ( res.ok ) {
			markRevoked( tr );
			rest.toast && rest.toast( i18n.revoked || 'Kudos revoked.', 'success' );
		} else {
			rest.toast && rest.toast( ( res.data && res.data.message ) || i18n.failed || 'Action failed.', 'error' );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest ) {
			return;
		}
		var revoke = e.target.closest( '.wb-gam-kudos-revoke' );
		if ( revoke ) {
			e.preventDefault();
			openEditor( revoke.closest( 'tr[data-kudos-id]' ) );
		}
	} );
}() );
