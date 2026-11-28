/**
 * Setup: one step at a time over a single form (render() in src/Admin/SetupWizard.php).
 *
 * Without JavaScript every step shows and Launch still submits the whole form. With it: the step
 * buttons and Continue / Back move between steps, a plugin switched off in step 1 hides its
 * actions in step 2, each plugin shows "N of M on", and step 4 summarises what Launch will save.
 * An amount that is not a whole number from 0 to its max (blank included) stops Next and Launch:
 * the page jumps to its step, opens its plugin, focuses it and says why next to it. The server
 * applies the same rule (SetupWizard::invalid_points()) for when this script does not run.
 *
 * @since 1.7.0
 */
( function () {
	'use strict';

	const root = document.querySelector( '[data-wbgam-setup]' );
	if ( ! root ) {
		return;
	}
	const s      = ( window.wbGamSetup && window.wbGamSetup.i18n ) || {};
	const fmt    = ( str, ...a ) => a.reduce( ( out, v, i ) => out.replace( '%' + ( i + 1 ) + '$d', v ).replace( '%' + ( i + 1 ) + '$s', v ).replace( '%d', v ).replace( '%s', v ), str || '' );
	const panels = Array.from( root.querySelectorAll( '[data-wbgam-step]' ) );
	const steps  = Array.from( root.querySelectorAll( '.wbgam-setup__step' ) );

	function kept() {
		return Array.from( root.querySelectorAll( '[data-wbgam-plugin-toggle]' ) ).filter( ( c ) => c.checked ).map( ( c ) => c.value );
	}

	function refresh() {
		const on = kept();
		let total = 0;
		root.querySelectorAll( '[data-wbgam-plugin]' ).forEach( ( group ) => {
			const name   = group.dataset.wbgamPlugin;
			const active = 'WordPress' === name || on.includes( name );
			group.hidden = ! active;
			const boxes  = Array.from( group.querySelectorAll( 'input[type=checkbox]' ) );
			const count  = boxes.filter( ( b ) => b.checked ).length;
			total += active ? count : 0;
			group.querySelector( '[data-wbgam-group-count]' ).textContent = fmt( s.groupCount, count, boxes.length );
		} );
		const pace  = root.querySelector( 'input[name=pace]:checked' );
		const board = root.querySelector( 'input[name=leaderboard]:checked' );
		const sum   = ( key, text ) => {
			const el = root.querySelector( '[data-wbgam-sum="' + key + '"]' );
			if ( el ) {
				el.textContent = text;
			}
		};
		sum( 'plugins', on.length ? fmt( s.plugins, on.join( ', ' ) ) : s.onlyWp );
		sum( 'earning', fmt( s.earning, total, pace ? pace.closest( 'label' ).querySelector( 'strong' ).textContent.split( '·' )[ 0 ].trim() : '' ) );
		sum( 'rewards', fmt( s.rewards, root.querySelectorAll( '.wbgam-setup__levels li' ).length, board && 'jetonomy' === board.value ? s.boardJt : s.boardOurs ) );
	}

	const form    = root.querySelector( 'form' );
	let current   = 1;

	// A whole number from 0 to the input's max. Blank is not a value (the Earning screen keeps the
	// saved amount for a blank Points field), so it is rejected rather than saved as nothing.
	function pointsOk( input ) {
		const v = input.value.trim();
		return /^\d+$/.test( v ) && Number( v ) <= Number( input.max || 9999 );
	}

	function setError( input, on ) {
		let msg = input.parentNode.querySelector( '.wbgam-setup__error' );
		if ( on && ! msg ) {
			msg = document.createElement( 'span' );
			msg.className = 'wbgam-setup__error';
			msg.id = 'wbgam-pts-err-' + input.name.replace( /^points\[|\]$/g, '' );
			msg.setAttribute( 'role', 'alert' );
			msg.setAttribute( 'aria-live', 'assertive' );
			input.after( msg );
		}
		if ( on ) {
			msg.textContent = s.pointsErr;
			input.setAttribute( 'aria-invalid', 'true' );
			input.setAttribute( 'aria-describedby', msg.id );
		} else if ( msg ) {
			msg.remove();
			input.removeAttribute( 'aria-invalid' );
			input.removeAttribute( 'aria-describedby' );
		}
	}

	// The first amount, in a plugin that is being rewarded, that cannot be saved.
	function firstInvalid() {
		return Array.from( root.querySelectorAll( 'input[name^="points["]' ) ).find( ( i ) => ! i.closest( '[data-wbgam-plugin]' ).hidden && ! pointsOk( i ) );
	}

	function reveal( input ) {
		const group = input.closest( '[data-wbgam-plugin]' );
		show( Number( input.closest( '[data-wbgam-step]' ).dataset.wbgamStep ), false );
		group.open = true;
		setError( input, true );
		input.focus();
		input.scrollIntoView( { block: 'center' } );
	}

	function show( n, moved ) {
		current = n;
		panels.forEach( ( p ) => {
			p.hidden = String( n ) !== p.dataset.wbgamStep;
		} );
		steps.forEach( ( b ) => {
			const here = String( n ) === b.dataset.wbgamGoto;
			b.classList.toggle( 'is-current', here );
			b.classList.toggle( 'is-done', Number( b.dataset.wbgamGoto ) < n );
			if ( here ) {
				b.setAttribute( 'aria-current', 'step' );
			} else {
				b.removeAttribute( 'aria-current' );
			}
		} );
		refresh();
		if ( ! moved ) {
			return; // First paint: leave focus and scroll where the browser put them.
		}
		const heading = root.querySelector( '[data-wbgam-step="' + n + '"] h2' );
		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus();
		}
		root.scrollIntoView( { block: 'start' } );
	}

	root.addEventListener( 'click', ( e ) => {
		const go = e.target.closest( '[data-wbgam-goto]' );
		// Launch is the only submit that saves amounts; Skip saves nothing and is never blocked.
		const launch = e.target.closest( 'button[name="wbgam_setup"][value="launch"]' );
		const stuck  = launch ? firstInvalid() : null;
		if ( stuck ) {
			e.preventDefault();
			reveal( stuck );
			return;
		}
		if ( ! go ) {
			return;
		}
		const to  = Number( go.dataset.wbgamGoto );
		const bad = to > current ? firstInvalid() : null;
		if ( bad ) {
			reveal( bad );
			return;
		}
		show( to, true );
	} );
	form.noValidate = true; // The browser cannot focus an amount in a hidden step; this script reports it instead.
	root.addEventListener( 'input', ( e ) => {
		if ( e.target.matches( 'input[name^="points["]' ) && pointsOk( e.target ) ) {
			setError( e.target, false );
		}
	} );
	root.addEventListener( 'change', ( e ) => {
		// Choosing a plugin in step 1 means rewarding it: when none of its actions is on yet,
		// they all start on (each still switchable in step 2).
		const toggle = e.target.closest( '[data-wbgam-plugin-toggle]' );
		if ( toggle && toggle.checked ) {
			const group = root.querySelector( '[data-wbgam-plugin="' + toggle.value + '"]' );
			const boxes = group ? Array.from( group.querySelectorAll( 'input[type=checkbox]' ) ) : [];
			if ( boxes.length && ! boxes.some( ( b ) => b.checked ) ) {
				boxes.forEach( ( b ) => {
					b.checked = true;
				} );
			}
		}
		refresh();
	} );
	root.classList.add( 'is-stepped' );
	// A Launch the server rejected comes back with its bad amounts marked: open the first one.
	const marked = root.querySelector( 'input[aria-invalid="true"]' );
	show( 1 );
	if ( marked ) {
		reveal( marked );
	}
}() );
