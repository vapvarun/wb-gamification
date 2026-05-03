/**
 * Admin REST utilities — shared by every Tier 0.C-migrated admin page.
 *
 * Exports a single global namespace `window.wbGamAdminRest` with:
 *   - apiFetch(method, path, body, settings) → { ok, status, data }
 *   - toast(message, tone)
 *   - clearChildren(node)
 *   - confirmAction(message) → boolean   (temporary native confirm — Tier 7
 *     replaces with a promise-based modal; documented exception #1)
 *
 * Per-page modules (admin-levels.js, admin-cohort.js, admin-api-keys.js,
 * admin-webhooks.js, admin-badges.js, admin-challenges.js,
 * admin-community-challenges.js, admin-redemption.js, admin-manual-award.js)
 * declare a `wbGamXSettings` localized object containing { restUrl, nonce, i18n }
 * and call wbGamAdminRest.apiFetch( method, path, body, settings ).
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	if ( window.wbGamAdminRest ) {
		return;
	}

	function ensureToastContainer() {
		let c = document.querySelector( '.wb-gam-toasts' );
		if ( ! c ) {
			c = document.createElement( 'div' );
			c.className = 'wb-gam-toasts';
			document.body.appendChild( c );
		}
		return c;
	}

	/**
	 * Surface a transient toast message.
	 *
	 * @param {string} message Body text.
	 * @param {'success'|'error'|'info'} [tone='info'] Visual tone.
	 */
	function toast( message, tone ) {
		tone = tone || 'info';
		const container = ensureToastContainer();
		const node = document.createElement( 'div' );
		node.className = 'wb-gam-toast wb-gam-toast--' + tone;
		node.setAttribute( 'role', 'status' );
		node.textContent = message;
		container.appendChild( node );
		// Force reflow then animate in.
		// eslint-disable-next-line no-unused-expressions
		node.offsetHeight;
		node.classList.add( 'is-visible' );
		setTimeout( function () {
			node.classList.remove( 'is-visible' );
			setTimeout( function () { node.remove(); }, 250 );
		}, 4500 );
	}

	/**
	 * REST fetch wrapper with X-WP-Nonce + JSON body + safe response parsing.
	 *
	 * @param {string} method   HTTP verb.
	 * @param {string} path     Path relative to settings.restUrl (e.g. '/levels').
	 * @param {object|null} body  JSON body (omit for GET/DELETE).
	 * @param {{restUrl: string, nonce: string}} settings Page-level localized settings.
	 * @returns {Promise<{ok: boolean, status: number, data: object}>}
	 */
	async function apiFetch( method, path, body, settings ) {
		if ( ! settings || ! settings.restUrl || ! settings.nonce ) {
			return { ok: false, status: 0, data: { message: 'wbGam admin: missing settings' } };
		}
		const url = settings.restUrl.replace( /\/$/, '' ) + path;
		const init = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': settings.nonce,
				'Content-Type': 'application/json',
			},
		};
		if ( body ) {
			init.body = JSON.stringify( body );
		}
		const response = await fetch( url, init );
		let data = null;
		try {
			data = await response.json();
		} catch ( e ) {
			data = {};
		}
		return { ok: response.ok, status: response.status, data: data };
	}

	/**
	 * Remove every child node of an element via removeChild loop.
	 *
	 * Avoids assignment-based DOM reset to keep static-analysis tools happy.
	 *
	 * @param {Element} node Container to empty.
	 */
	function clearChildren( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Confirm a destructive action via a shared promise-based modal.
	 *
	 * Replaces window.confirm() per admin-ux-rulebook Rule 10 (no native
	 * dialogs in admin). Returns a Promise<boolean> that resolves true on
	 * confirm, false on cancel/Esc/backdrop. Some legacy callers expect a
	 * synchronous boolean — they can `await` the result inside an async
	 * handler, which every Tier 0.C admin handler already is.
	 *
	 * @param {string|{title?: string, message: string, confirmText?: string, cancelText?: string, tone?: 'danger'|'primary'}} prompt
	 * @returns {Promise<boolean>}
	 */
	function confirmAction( prompt ) {
		const opts = typeof prompt === 'string' ? { message: prompt } : ( prompt || {} );
		const title       = opts.title || '';
		const message     = opts.message || 'Are you sure?';
		const confirmText = opts.confirmText || 'Confirm';
		const cancelText  = opts.cancelText || 'Cancel';
		const tone        = opts.tone === 'primary' ? 'primary' : 'danger';

		return new Promise( function ( resolve ) {
			const overlay = document.createElement( 'div' );
			overlay.className = 'wb-gam-confirm-overlay';
			overlay.setAttribute( 'role', 'presentation' );

			const dialog = document.createElement( 'div' );
			dialog.className = 'wb-gam-confirm-dialog';
			dialog.setAttribute( 'role', 'dialog' );
			dialog.setAttribute( 'aria-modal', 'true' );

			if ( title ) {
				const h = document.createElement( 'h2' );
				h.className = 'wb-gam-confirm-dialog__title';
				h.textContent = title;
				dialog.appendChild( h );
				dialog.setAttribute( 'aria-labelledby', 'wb-gam-confirm-title' );
				h.id = 'wb-gam-confirm-title';
			}
			const body = document.createElement( 'p' );
			body.className = 'wb-gam-confirm-dialog__body';
			body.textContent = message;
			dialog.appendChild( body );

			const actions = document.createElement( 'div' );
			actions.className = 'wb-gam-confirm-dialog__actions';

			const cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'button';
			cancelBtn.textContent = cancelText;

			const confirmBtn = document.createElement( 'button' );
			confirmBtn.type = 'button';
			confirmBtn.className = 'button button-primary' + ( 'danger' === tone ? ' button-link-delete' : '' );
			confirmBtn.textContent = confirmText;

			actions.appendChild( cancelBtn );
			actions.appendChild( confirmBtn );
			dialog.appendChild( actions );

			overlay.appendChild( dialog );
			document.body.appendChild( overlay );

			const previousFocus = document.activeElement;
			confirmBtn.focus();

			function close( result ) {
				document.removeEventListener( 'keydown', onKey );
				overlay.removeEventListener( 'click', onBackdrop );
				overlay.remove();
				if ( previousFocus && typeof previousFocus.focus === 'function' ) {
					previousFocus.focus();
				}
				resolve( result );
			}
			function onKey( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					close( false );
				} else if ( e.key === 'Tab' ) {
					// Trap focus inside the dialog.
					if ( ! dialog.contains( document.activeElement ) ) {
						confirmBtn.focus();
						e.preventDefault();
					}
				}
			}
			function onBackdrop( e ) {
				if ( e.target === overlay ) {
					close( false );
				}
			}

			cancelBtn.addEventListener( 'click', function () { close( false ); } );
			confirmBtn.addEventListener( 'click', function () { close( true ); } );
			document.addEventListener( 'keydown', onKey );
			overlay.addEventListener( 'click', onBackdrop );
		} );
	}

	/**
	 * Convenience: surface a server error from a {ok,status,data} result.
	 *
	 * @param {{ok:boolean, status:number, data:object}} result
	 * @param {string} fallback Default text when no `data.message` is present.
	 */
	function toastError( result, fallback ) {
		const msg = result && result.data && result.data.message
			? result.data.message
			: ( fallback || 'Request failed.' );
		toast( msg, 'error' );
	}

	window.wbGamAdminRest = {
		apiFetch: apiFetch,
		toast: toast,
		toastError: toastError,
		clearChildren: clearChildren,
		confirmAction: confirmAction,
	};
} )();
