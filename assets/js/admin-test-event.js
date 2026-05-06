/**
 * WB Gamification — first-run "Send a test event" button.
 *
 * Wires the button rendered on the Dashboard's welcome card to
 * POST /wb-gamification/v1/points/award for the current admin so they
 * see the engine fire end-to-end (welcome toast on the front-end, KPI
 * counter incrementing on this page) without needing a real member action.
 *
 * The button is rendered with data attrs:
 *   data-wb-gam-test-event           — the trigger
 *   data-points                      — point amount (number)
 *   data-wb-gam-test-event-status    — sibling span where we surface result text
 *
 * All result rendering uses textContent + DOM nodes — no innerHTML — so
 * untrusted-string injection is impossible.
 *
 * @since 1.0.0
 */

( function () {
	const button = document.querySelector( '[data-wb-gam-test-event]' );
	const status = document.querySelector( '[data-wb-gam-test-event-status]' );

	if ( ! button || ! status || typeof window.wbGamTestEvent === 'undefined' ) {
		return;
	}

	const cfg = window.wbGamTestEvent;
	const i18n = cfg.i18n || {};

	function setBusy( busy ) {
		button.disabled = busy;
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}

	function clearStatus() {
		while ( status.firstChild ) {
			status.removeChild( status.firstChild );
		}
	}

	button.addEventListener( 'click', function () {
		const points = parseInt( button.dataset.points || '10', 10 );

		setBusy( true );
		status.textContent = i18n.sending || 'Awarding…';
		status.className = 'wbgam-test-event-status wbgam-test-event-status--pending';

		fetch( cfg.restUrl + 'points/award', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				user_id: cfg.userId,
				points: points,
				reason: 'wizard_test_event',
				note: 'Sent from the Setup Wizard test-event button.',
			} ),
		} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
				return res.json();
			} )
			.then( function () {
				status.className = 'wbgam-test-event-status wbgam-test-event-status--success';
				clearStatus();
				const icon = document.createElement( 'span' );
				icon.className = 'icon-check-circle';
				icon.setAttribute( 'aria-hidden', 'true' );
				status.appendChild( icon );
				status.appendChild( document.createTextNode( ' ' + ( i18n.success || 'Test event sent. Visit your Hub to see the welcome toast.' ) ) );
				if ( cfg.hubUrl ) {
					const link = document.createElement( 'a' );
					link.href = cfg.hubUrl;
					link.target = '_blank';
					link.rel = 'noopener';
					link.textContent = ' ' + ( i18n.viewHub || 'Open Hub' ) + ' →';
					status.appendChild( link );
				}
				setBusy( false );
			} )
			.catch( function () {
				status.className = 'wbgam-test-event-status wbgam-test-event-status--error';
				status.textContent = i18n.error || 'Could not send test event. Check the error log.';
				setBusy( false );
			} );
	} );
} )();
