/**
 * Submit Achievement — view-side handler.
 *
 * Listens for form submit, POSTs to /submissions, shows inline success
 * or error. No external nonce middleware — the rest_url + nonce + every
 * user-facing string are passed via data-attrs on the wrapper. PHP
 * renders those through `__('…', 'wb-gamification')` so the view.js
 * stays locale-agnostic.
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
		const i18nSuccess = wrapper.getAttribute( 'data-i18n-success' ) || 'Submitted!';
		const i18nFailed = wrapper.getAttribute( 'data-i18n-failed' ) || 'Submission failed.';
		const i18nNetwork = wrapper.getAttribute( 'data-i18n-network' ) || 'Network error.';

		const setStatus = ( text, tone ) => {
			status.textContent = text;
			status.classList.remove(
				'wb-gam-submit-achievement__status--error',
				'wb-gam-submit-achievement__status--success'
			);
			if ( tone === 'error' ) {
				status.classList.add( 'wb-gam-submit-achievement__status--error' );
			} else if ( tone === 'success' ) {
				status.classList.add( 'wb-gam-submit-achievement__status--success' );
			}
		};

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			const submit = form.querySelector( 'button[type=submit]' );
			submit.disabled = true;
			setStatus( '', null );

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
						setStatus( ( data && data.message ) || i18nFailed, 'error' );
						submit.disabled = false;
						return;
					}
					setStatus( i18nSuccess, 'success' );
					form.reset();
				} )
				.catch( () => {
					setStatus( i18nNetwork, 'error' );
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
