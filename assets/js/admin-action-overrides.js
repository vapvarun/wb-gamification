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
			signal: ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) ? AbortSignal.timeout( 15000 ) : undefined,
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

	function scheduleSave( input ) {
		const actionId = input.dataset.wbGamActionId;
		const field    = input.dataset.wbGamActionOverride;
		const key      = actionId + '|' + field;
		clearTimeout( timers[ key ] );
		// Debounce window — long enough that holding a number-spinner key
		// doesn't fire a request per tick, short enough that the value
		// reaches the server before the admin walks away from the field.
		timers[ key ] = setTimeout( function () {
			save( actionId, field, input.value );
		}, 600 );
	}

	inputs.forEach( function ( input ) {
		// `input` fires while the admin is actually typing (or clicking the
		// number-spinner). `change` only fires on blur/Enter for number
		// inputs — Simran's repro showed values silently dropped when the
		// admin typed a value, clicked Save before the field blurred, and
		// the POST body never carried `cooldown` / `daily_cap`. Listening
		// to both keeps real-time autosave honest and the field saves on
		// blur even if the debounce timer hasn't fired yet (Basecamp
		// 9925174468).
		input.addEventListener( 'input', function () {
			scheduleSave( input );
		} );
		input.addEventListener( 'change', function () {
			scheduleSave( input );
		} );
		input.addEventListener( 'blur', function () {
			const key = input.dataset.wbGamActionId + '|' + input.dataset.wbGamActionOverride;
			if ( timers[ key ] ) {
				clearTimeout( timers[ key ] );
				save( input.dataset.wbGamActionId, input.dataset.wbGamActionOverride, input.value );
			}
		} );
	} );
}() );
