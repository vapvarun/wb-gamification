/**
 * WB Gamification — Badge Share Page: copy-link action.
 *
 * Copies the share URL to the clipboard and shows transient in-card feedback.
 * Never uses window.prompt/alert (no classic browser popups): falls back to a
 * hidden-textarea + execCommand copy that works on plain HTTP where the async
 * Clipboard API is unavailable. Delegated listener, no dependencies.
 */
( function () {
	'use strict';

	function legacyCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}

	function feedback( btn, ok ) {
		var label = btn.querySelector( '.wb-gam-share-card__action-label' );
		var status = document.querySelector( '.wb-gam-share-card__copy-status' );
		var copiedText = btn.getAttribute( 'data-copied-label' ) || 'Copied!';
		var failText = btn.getAttribute( 'data-copy-fail-label' ) || 'Press Ctrl/Cmd+C to copy';
		var prev = label ? label.textContent : '';

		btn.classList.toggle( 'is-copied', ok );
		if ( label && ok ) {
			label.textContent = copiedText;
		}
		if ( status ) {
			status.textContent = ok ? copiedText : failText;
		}
		window.setTimeout( function () {
			btn.classList.remove( 'is-copied' );
			if ( label && ok ) {
				label.textContent = prev;
			}
			if ( status ) {
				status.textContent = '';
			}
		}, 2200 );
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '[data-wb-gam-copy]' );
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var url = btn.getAttribute( 'data-wb-gam-copy' );

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then(
				function () {
					feedback( btn, true );
				},
				function () {
					feedback( btn, legacyCopy( url ) );
				}
			);
		} else {
			feedback( btn, legacyCopy( url ) );
		}
	} );
} )();
