/**
 * WB Gamification — deactivation feedback (Plugins screen only).
 *
 * Intercepts THIS plugin's Deactivate link, opens the survey <dialog>, and
 * records the reason before following through. Optional and resilient: Skip
 * (or any network failure) still deactivates; only our own row's link is
 * touched, so other plugins' deactivate dialogs are unaffected.
 *
 * Uses document-level event delegation so it does not depend on the footer
 * print order of the script vs the <dialog> markup (the dialog + the plugin
 * row are both resolved at click time, never at script-load time).
 *
 * @package WBGamification
 * @since   1.6.2
 */
( function () {
	'use strict';

	var cfg = window.wbGamDeactivate;
	if ( ! cfg || ! cfg.basename ) {
		return;
	}

	var proceeding = false;
	var deactivateUrl = '';

	/**
	 * Is this anchor THIS plugin's Deactivate link?
	 *
	 * @param {HTMLAnchorElement} a Candidate link.
	 * @return {boolean}
	 */
	function isOurDeactivateLink( a ) {
		var row = a.closest ? a.closest( 'tr[data-plugin]' ) : null;
		if ( row && row.getAttribute( 'data-plugin' ) === cfg.basename && a.closest( '.deactivate' ) ) {
			return true;
		}
		var href = a.getAttribute( 'href' ) || '';
		return href.indexOf( 'action=deactivate' ) !== -1 &&
			href.indexOf( encodeURIComponent( cfg.basename ) ) !== -1;
	}

	/**
	 * Follow the original deactivate URL.
	 */
	function proceed() {
		proceeding = true;
		window.location.href = deactivateUrl;
	}

	/**
	 * POST the payload (or a skip) then deactivate regardless of the result.
	 *
	 * @param {HTMLDialogElement} dialog  The survey dialog.
	 * @param {boolean}           skipped Whether the user skipped the survey.
	 */
	function sendThenProceed( dialog, skipped ) {
		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'skipped', skipped ? '1' : '0' );
		if ( ! skipped ) {
			var reason = dialog.querySelector( 'input[name="wb_gam_reason"]:checked' );
			var detail = dialog.querySelector( '.wb-gam-deactivate__detail' );
			var consent = dialog.querySelector( '#wb-gam-deactivate-consent' );
			body.set( 'reason', reason ? reason.value : '' );
			body.set( 'detail', detail ? detail.value : '' );
			body.set( 'consent', consent && consent.checked ? '1' : '0' );
		}
		var done = false;
		var finish = function () {
			if ( ! done ) {
				done = true;
				proceed();
			}
		};
		// Deactivate no matter what — never block on the collector.
		var timer = window.setTimeout( finish, 3000 );
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
			signal: ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) ? AbortSignal.timeout( 3000 ) : undefined,
		} ).finally( function () {
			window.clearTimeout( timer );
			finish();
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( proceeding ) {
			return; // Second pass — let it navigate.
		}
		var a = e.target && e.target.closest ? e.target.closest( 'a' ) : null;
		if ( ! a || ! isOurDeactivateLink( a ) ) {
			return;
		}
		var dialog = document.getElementById( 'wb-gam-deactivate-dialog' );
		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return; // No dialog / no native support → let the default deactivate proceed.
		}

		e.preventDefault();
		deactivateUrl = a.getAttribute( 'href' );

		if ( ! dialog.dataset.wbGamWired ) {
			dialog.dataset.wbGamWired = '1';
			var submit = dialog.querySelector( '.wb-gam-deactivate__submit' );
			var skip = dialog.querySelector( '.wb-gam-deactivate__skip' );
			if ( submit ) {
				submit.addEventListener( 'click', function () {
					dialog.close();
					sendThenProceed( dialog, false );
				} );
			}
			if ( skip ) {
				skip.addEventListener( 'click', function () {
					dialog.close();
					sendThenProceed( dialog, true );
				} );
			}
			// Closing via Esc / backdrop cancels deactivation entirely (no send).
		}

		dialog.showModal();
		var first = dialog.querySelector( 'input[name="wb_gam_reason"]' );
		if ( first ) {
			first.focus();
		}
	} );
}() );
