/**
 * Settings → Points tab: per-action cooldown / daily-cap autosave.
 *
 * Listens for `change` on inputs tagged with `data-wb-gam-action-override`,
 * debounces 250ms, then POSTs to `/wb-gamification/v1/actions/{id}/overrides`.
 *
 * @since 1.4.0
 */

/* global wbGamActionOverrides */

( function () {
	'use strict';

	if ( typeof wbGamActionOverrides === 'undefined' ) {
		return;
	}

	const inputs = document.querySelectorAll( '[data-wb-gam-action-override]' );
	if ( ! inputs.length ) {
		return;
	}

	const base    = wbGamActionOverrides.restBase;
	const nonce   = wbGamActionOverrides.nonce;
	const timers  = {};

	function save( actionId, field, value ) {
		const body  = {};
		body[ field ] = parseInt( value, 10 ) || 0;

		fetch( base + encodeURIComponent( actionId ) + '/overrides', {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			body: JSON.stringify( body ),
		} )
			.then( function ( r ) {
				return r.ok ? r.json() : Promise.reject( r );
			} )
			.then( function () {
				const sel = '[data-wb-gam-action-id="' + actionId + '"][data-wb-gam-action-override="' + field + '"]';
				const el  = document.querySelector( sel );
				if ( el ) {
					el.classList.add( 'wbgam-input--saved' );
					setTimeout( function () {
						el.classList.remove( 'wbgam-input--saved' );
					}, 1000 );
				}
			} )
			.catch( function () {
				const sel = '[data-wb-gam-action-id="' + actionId + '"][data-wb-gam-action-override="' + field + '"]';
				const el  = document.querySelector( sel );
				if ( el ) {
					el.classList.add( 'wbgam-input--error' );
				}
			} );
	}

	inputs.forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			const actionId = input.dataset.wbGamActionId;
			const field    = input.dataset.wbGamActionOverride;
			const key      = actionId + '|' + field;
			clearTimeout( timers[ key ] );
			timers[ key ] = setTimeout( function () {
				save( actionId, field, input.value );
			}, 250 );
		} );
	} );
}() );
