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

	// Recipient suggestions: an ARIA combobox (WAI-ARIA 1.2 list autocomplete) filled
	// from GET /members?context=view. Picking a member writes "Name (@slug)" into the
	// input; submit sends the slug. Typing a username without picking still works.
	const SUGGEST_DELAY_MS = 200;

	function suggestMembers( form ) {
		const input = form.querySelector( 'input[name="recipient_login"][role="combobox"]' );
		const list  = input && document.getElementById( input.getAttribute( 'aria-controls' ) );
		if ( ! list || ! form.dataset.membersUrl ) {
			return;
		}
		let timer  = null;
		let seq    = 0;
		let active = -1;

		function options() {
			return list.querySelectorAll( '[role="option"]' );
		}

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function highlight( i ) {
			const opts = options();
			if ( ! opts.length ) {
				return;
			}
			active = ( i + opts.length ) % opts.length;
			opts.forEach( function ( o, n ) {
				o.setAttribute( 'aria-selected', n === active ? 'true' : 'false' );
			} );
			input.setAttribute( 'aria-activedescendant', opts[ active ].id );
			opts[ active ].scrollIntoView( { block: 'nearest' } );
		}

		function pick( opt ) {
			input.value = opt.dataset.value;
			close();
		}

		function render( items ) {
			list.replaceChildren( ...items.map( function ( m, n ) {
				const li  = document.createElement( 'li' );
				li.id     = list.id + '-' + n;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				li.className     = 'wb-gam-give-kudos__option';
				li.dataset.value = m.name + ' (@' + m.slug + ')';

				const img = document.createElement( 'img' );
				img.src = m.avatar;
				img.alt = '';
				img.className = 'wb-gam-give-kudos__option-avatar';
				img.width = img.height = 28;

				const name = document.createElement( 'span' );
				name.className   = 'wb-gam-give-kudos__option-name';
				name.textContent = m.name;

				const handle = document.createElement( 'span' );
				handle.className   = 'wb-gam-give-kudos__option-handle';
				handle.textContent = '@' + m.slug;

				const text = document.createElement( 'span' );
				text.className = 'wb-gam-give-kudos__option-text';
				text.append( name, handle );

				li.append( img, text );
				return li;
			} ) );
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );
			list.hidden = ! items.length;
			input.setAttribute( 'aria-expanded', items.length ? 'true' : 'false' );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			const term = input.value.trim().replace( /^@/, '' );
			if ( term.length < 2 || /\(@[^)]+\)$/.test( term ) ) {
				++seq;
				close();
				return;
			}
			timer = setTimeout( function () {
				const mine = ++seq;
				const url  = form.dataset.membersUrl
					+ ( form.dataset.membersUrl.indexOf( '?' ) === -1 ? '?' : '&' )
					+ 'context=view&per_page=8&search=' + encodeURIComponent( term );
				window.wbGam.rest( url, { nonce: form.dataset.restNonce } )
					.then( function ( result ) {
						if ( mine === seq && result && result.ok ) {
							render( result.data.items || [] ); // Older responses lose to a newer keystroke.
						}
					} )
					.catch( function () { /* Suggestions are a convenience; typing a username still works. */ } );
			}, SUGGEST_DELAY_MS );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( list.hidden ) {
				return;
			}
			if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
				e.preventDefault();
				highlight( active + ( 'ArrowDown' === e.key ? 1 : -1 ) );
			} else if ( 'Enter' === e.key && active >= 0 ) {
				e.preventDefault(); // Pick, do not submit.
				pick( options()[ active ] );
			} else if ( 'Escape' === e.key ) {
				e.preventDefault();
				close();
			}
		} );

		// mousedown, not click: it fires before the input's blur closes the list.
		list.addEventListener( 'mousedown', function ( e ) {
			const opt = e.target.closest( '[role="option"]' );
			if ( opt ) {
				e.preventDefault();
				pick( opt );
			}
		} );
		input.addEventListener( 'blur', close );
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
