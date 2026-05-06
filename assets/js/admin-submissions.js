/**
 * Admin: Submission queue interactions.
 *
 * Wires Approve / Reject buttons on each row to the REST endpoints:
 *   POST /submissions/{id}/approve
 *   POST /submissions/{id}/reject
 *
 * Reload-on-success — keeps the queue snapshot fresh and shows the
 * banner-style confirmation provided by SubmissionsPage::render_page.
 */
( function () {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const cfg = window.wbGamSubmissions || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	if ( ! apiFetch || ! cfg.restUrl ) {
		return;
	}
	apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );

	const i18n = cfg.i18n || {};

	function callDecision( id, decision, notes ) {
		return apiFetch( {
			path: 'wb-gamification/v1/submissions/' + id + '/' + decision,
			method: 'POST',
			data: { notes: notes || '' },
		} );
	}

	function showRowError( row, message ) {
		let err = row.querySelector( '.wb-gam-submission-error' );
		if ( ! err ) {
			err = document.createElement( 'div' );
			err.className = 'wb-gam-submission-error';
			err.style.cssText = 'color:#b91c1c;font-size:12px;margin-top:4px;';
			row.querySelector( 'td:last-child' ).appendChild( err );
		}
		err.textContent = message;
	}

	function bindRow( row ) {
		const id = row.getAttribute( 'data-submission-id' );
		const approveBtn = row.querySelector( '[data-wb-gam-submission-approve]' );
		const rejectBtn = row.querySelector( '[data-wb-gam-submission-reject]' );

		if ( approveBtn ) {
			approveBtn.addEventListener( 'click', function () {
				approveBtn.disabled = true;
				callDecision( id, 'approve', '' )
					.then( function () {
						window.location.reload();
					} )
					.catch( function () {
						showRowError( row, i18n.failed || 'Failed' );
						approveBtn.disabled = false;
					} );
			} );
		}

		if ( rejectBtn ) {
			rejectBtn.addEventListener( 'click', function () {
				// First click: render an inline reason input + Confirm button.
				if ( ! row.querySelector( '.wb-gam-submission-reject-input' ) ) {
					const wrap = document.createElement( 'div' );
					wrap.className = 'wb-gam-submission-reject-input';
					wrap.style.cssText = 'margin-top:6px;display:flex;gap:6px;';

					const input = document.createElement( 'input' );
					input.type = 'text';
					input.placeholder = i18n.reason || 'Reason';
					input.style.cssText = 'flex:1;padding:4px 8px;font-size:12px;';

					const confirm = document.createElement( 'button' );
					confirm.type = 'button';
					confirm.className = 'wbgam-btn wbgam-btn--sm wbgam-btn--secondary';
					confirm.textContent = i18n.rejected || 'Confirm';
					confirm.addEventListener( 'click', function () {
						confirm.disabled = true;
						callDecision( id, 'reject', input.value )
							.then( function () {
								window.location.reload();
							} )
							.catch( function () {
								showRowError( row, i18n.failed || 'Failed' );
								confirm.disabled = false;
							} );
					} );

					wrap.appendChild( input );
					wrap.appendChild( confirm );
					row.querySelector( 'td:last-child' ).appendChild( wrap );
					input.focus();
					return;
				}
			} );
		}
	}

	function boot() {
		document
			.querySelectorAll( 'tr[data-submission-id]' )
			.forEach( bindRow );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
