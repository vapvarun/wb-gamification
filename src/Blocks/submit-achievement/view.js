/**
 * Submit Achievement — view-side handler.
 *
 * Listens for form submit, POSTs to /submissions, shows inline success
 * or error. No external nonce middleware — the rest_url + nonce are
 * passed via data-attrs on the wrapper.
 */
( function () {
	function init( wrapper ) {
		const form = wrapper.querySelector( '[data-wb-gam-submit-form]' );
		const status = wrapper.querySelector( '[data-wb-gam-submit-status]' );
		if ( ! form || ! status ) {
			return;
		}
		const restUrl = wrapper.getAttribute( 'data-rest-url' );
		const nonce = wrapper.getAttribute( 'data-rest-nonce' );

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			const submit = form.querySelector( 'button[type=submit]' );
			submit.disabled = true;
			status.textContent = '';

			const fd = new FormData( form );
			const body = {
				action_id: fd.get( 'action_id' ),
				evidence: fd.get( 'evidence' ) || '',
				evidence_url: fd.get( 'evidence_url' ) || '',
			};

			fetch( restUrl + '/submissions', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} )
				.then( ( r ) => r.json().then( ( data ) => ( { ok: r.ok, data } ) ) )
				.then( ( { ok, data } ) => {
					if ( ! ok || ( data && data.code ) ) {
						status.textContent = ( data && data.message ) || 'Submission failed.';
						status.style.color = '#b91c1c';
						submit.disabled = false;
						return;
					}
					status.textContent = 'Submitted! A reviewer will look at it soon.';
					status.style.color = '#16a34a';
					form.reset();
				} )
				.catch( () => {
					status.textContent = 'Network error.';
					status.style.color = '#b91c1c';
					submit.disabled = false;
				} );
		} );
	}

	function boot() {
		document
			.querySelectorAll( '.wb-gam-submit-achievement' )
			.forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
