/**
 * Give Kudos block — frontend submit handler.
 *
 * Intercepts `<form.wb-gam-give-kudos>` submits, POSTs to
 * `/wb-gamification/v1/kudos`, and shows status feedback.
 *
 * @since 1.4.0
 */

/* global wbGamGiveKudos */

( function () {
	'use strict';

	if ( typeof wbGamGiveKudos === 'undefined' ) {
		return;
	}

	const i18n = wbGamGiveKudos.i18n || {};

	function bind( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			const status   = form.querySelector( '.wb-gam-give-kudos__status' );
			const submit   = form.querySelector( '.wb-gam-give-kudos__submit' );
			const idField  = form.querySelector( 'input[name="receiver_id"]' );
			const loginEl  = form.querySelector( 'input[name="recipient_login"]' );
			const msgEl    = form.querySelector( 'textarea[name="message"]' );

			const body = { message: msgEl ? msgEl.value : '' };
			if ( idField && idField.value ) {
				body.receiver_id = parseInt( idField.value, 10 );
			} else if ( loginEl ) {
				const slug = ( loginEl.value || '' ).trim();
				if ( ! slug ) {
					status.textContent = i18n.missingRecipient || '';
					return;
				}
				body.recipient_login = slug;
			}

			submit.disabled    = true;
			status.textContent = i18n.sending || '';

			fetch( form.dataset.restUrl, {
				signal: ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) ? AbortSignal.timeout( 15000 ) : undefined,
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   form.dataset.restNonce,
				},
				body: JSON.stringify( body ),
			} )
				.then( function ( r ) {
					return r.json().then( function ( d ) {
						return { ok: r.ok, data: d };
					} );
				} )
				.then( function ( result ) {
					if ( result && result.ok ) {
						status.textContent = i18n.success || '';
						if ( msgEl ) {
							msgEl.value = '';
						}
						if ( loginEl ) {
							loginEl.value = '';
						}
						// Force an immediate broker tick so the sender sees
						// their points-delta toast in <1s instead of waiting
						// up to 5s for the next heartbeat. The recipient's
						// kudos-received toast goes through the same broker
						// and is pushed on this tick too.
						if ( window.wbGamRealtime && typeof window.wbGamRealtime.ping === 'function' ) {
							window.wbGamRealtime.ping();
						}
					} else {
						const msg = ( result && result.data && result.data.message ) || i18n.failure || '';
						status.textContent = msg;
					}
				} )
				.catch( function () {
					status.textContent = i18n.network || '';
				} )
				.finally( function () {
					submit.disabled = false;
				} );
		} );
	}

	// Not `querySelectorAll().forEach( bind )`. This block is a real <form>, so when a host theme
	// navigates client-side and swaps fresh markup in, the submit handler is not merely absent -- the
	// browser falls back to a NATIVE form submission and navigates the member away from the page,
	// their kudos message in the query string. Verified in a browser before this was changed.
	//
	// onMount runs bind() for the forms already here AND for any that arrive later, once each.
	window.wbGam.onMount( '.wb-gam-give-kudos', bind );
}() );
