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

	// Recipient suggestions: GET /members?context=view fills the input's <datalist>.
	// Each option reads "Display Name (@slug)"; submit sends the slug.
	const SUGGEST_DELAY_MS = 200;

	function suggestMembers( form ) {
		const input = form.querySelector( 'input[name="recipient_login"]' );
		const list  = input && input.list;
		if ( ! list || ! form.dataset.membersUrl ) {
			return;
		}
		let timer = null;
		let seq   = 0;

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			const term = input.value.trim().replace( /^@/, '' );
			if ( term.length < 2 || /\(@[^)]+\)$/.test( term ) ) {
				return;
			}
			timer = setTimeout( function () {
				const mine = ++seq;
				const url  = form.dataset.membersUrl
					+ ( form.dataset.membersUrl.indexOf( '?' ) === -1 ? '?' : '&' )
					+ 'context=view&per_page=8&search=' + encodeURIComponent( term );
				window.wbGam.rest( url, { nonce: form.dataset.restNonce } )
					.then( function ( result ) {
						if ( mine !== seq || ! result || ! result.ok ) {
							return; // A newer keystroke owns the list.
						}
						list.replaceChildren( ...( result.data.items || [] ).map( function ( m ) {
							const opt = document.createElement( 'option' );
							opt.value = m.name + ' (@' + m.slug + ')';
							return opt;
						} ) );
					} )
					.catch( function () { /* Suggestions are a convenience; typing a username still works. */ } );
			}, SUGGEST_DELAY_MS );
		} );
	}

	function bind( form ) {
		suggestMembers( form );
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
				const typed = ( loginEl.value || '' ).trim();
				const pick  = typed.match( /\(@([^)]+)\)$/ );
				const slug  = pick ? pick[ 1 ] : typed.replace( /^@/, '' );
				if ( ! slug ) {
					status.textContent = i18n.missingRecipient || '';
					return;
				}
				body.recipient_login = slug;
			}

			submit.disabled    = true;
			status.textContent = i18n.sending || '';

			window.wbGam.rest( form.dataset.restUrl, {
				method: 'POST',
				body:   body,
				nonce:  form.dataset.restNonce,
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
