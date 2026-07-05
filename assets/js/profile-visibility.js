/**
 * Profile visibility toggle — owner control on /u/{login}.
 *
 * POSTs the member's own visibility choice to
 * `wb-gamification/v1/members/me/profile-visibility` and updates the button +
 * status copy in place. Self-contained via data attributes on the section
 * (REST url + nonce), matching the give-kudos pattern — no localized globals.
 *
 * @since 1.5.5
 */
( function () {
	'use strict';

	var root = document.querySelector( '.wb-gam-profile-privacy' );
	if ( ! root ) {
		return;
	}

	var btn    = root.querySelector( '.wb-gam-profile-privacy__toggle' );
	var state  = root.querySelector( '.wb-gam-profile-privacy__state' );
	var status = root.querySelector( '.wb-gam-profile-privacy__status' );
	if ( ! btn || ! state ) {
		return;
	}

	// Strings the server already rendered, reused so the toggle stays
	// translation-correct without a second localization pass. Read the
	// initial state from the server-rendered markup.
	var strings = {
		publicState:  state.getAttribute( 'data-public-copy' ) || 'This profile is visible to anyone with the link.',
		privateState: state.getAttribute( 'data-private-copy' ) || 'Only you and site admins can see this profile.',
		makePublic:   btn.getAttribute( 'data-make-public' ) || 'Make profile public',
		makePrivate:  btn.getAttribute( 'data-make-private' ) || 'Make profile private',
		saving:       btn.getAttribute( 'data-saving' ) || 'Saving…',
		error:        root.getAttribute( 'data-error' ) || 'Could not save. Please try again.'
	};

	function render( isPublic ) {
		state.setAttribute( 'data-public', isPublic ? '1' : '0' );
		state.textContent = isPublic ? strings.publicState : strings.privateState;
		btn.textContent   = isPublic ? strings.makePrivate : strings.makePublic;
		btn.setAttribute( 'aria-pressed', isPublic ? 'false' : 'true' );
	}

	btn.addEventListener( 'click', function () {
		// Current displayed state: data-public '1' = public. Toggling means we
		// request the opposite.
		var currentlyPublic = '1' === state.getAttribute( 'data-public' );
		var nextPublic      = ! currentlyPublic;

		btn.disabled = true;
		if ( status ) {
			status.textContent = strings.saving;
		}

		fetch( root.getAttribute( 'data-rest-url' ), {
			signal: ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) ? AbortSignal.timeout( 15000 ) : undefined,
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   root.getAttribute( 'data-rest-nonce' )
			},
			body: JSON.stringify( { public: nextPublic } )
		} )
			.then( function ( r ) {
				return r.json().then( function ( d ) {
					return { ok: r.ok, data: d };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.data && typeof result.data.public === 'boolean' ) {
					render( result.data.public );
					if ( status ) {
						status.textContent = '';
					}
				} else {
					if ( status ) {
						status.textContent = strings.error;
					}
				}
			} )
			.catch( function () {
				if ( status ) {
					status.textContent = strings.error;
				}
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	} );
} )();
