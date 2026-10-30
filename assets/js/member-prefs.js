/**
 * Member preferences — the member's own choices on their profile.
 *
 * Each checkbox saves on change to `wb-gamification/v1/members/{id}/preferences`.
 * Two checkboxes are worded positively, so they send the inverse of the stored
 * flag: "Show me on leaderboards" stores leaderboard_opt_out, and
 * "Email me about my rewards" stores notification_mode smart/none.
 *
 * @since 1.6.8
 */
( function () {
	'use strict';

	function payload( input ) {
		const body = {};
		if ( 'leaderboard_opt_out' === input.name ) {
			body.leaderboard_opt_out = ! input.checked;
		} else if ( 'notification_mode' === input.name ) {
			body.notification_mode = input.checked ? 'smart' : 'none';
		} else {
			body[ input.name ] = input.checked;
		}
		return body;
	}

	function bind( root ) {
		const status = root.querySelector( '.wb-gam-profile-privacy__status' );

		root.addEventListener( 'change', function ( e ) {
			const input = e.target;
			if ( ! input || 'checkbox' !== input.type ) {
				return;
			}
			input.disabled = true;
			window.wbGam
				.rest( root.getAttribute( 'data-rest-url' ), {
					method: 'POST',
					body: payload( input ),
					nonce: root.getAttribute( 'data-rest-nonce' ),
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						input.checked = ! input.checked;
					}
					status.textContent = root.getAttribute(
						result.ok ? 'data-saved' : 'data-error'
					);
				} )
				.catch( function () {
					input.checked = ! input.checked;
					status.textContent = root.getAttribute( 'data-error' );
				} )
				.finally( function () {
					input.disabled = false;
				} );
		} );
	}

	window.wbGam.onMount( '.wb-gam-member-prefs', bind );
} )();
