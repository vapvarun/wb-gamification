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

	document.querySelectorAll( '.wb-gam-give-kudos' ).forEach( bind );
}() );
