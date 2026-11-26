/**
 * Setup: one step at a time over a single form (render() in src/Admin/SetupWizard.php).
 *
 * Without JavaScript every step shows and Launch still submits the whole form. With it: the step
 * buttons and Continue / Back move between steps, a plugin switched off in step 1 hides its
 * actions in step 2, each plugin shows "N of M on", and step 4 summarises what Launch will save.
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
		sum( 'earning', fmt( s.earning, total, pace ? pace.closest( 'label' ).querySelector( 'strong' ).textContent : '' ) );
		sum( 'rewards', fmt( s.rewards, root.querySelectorAll( '.wbgam-setup__levels li' ).length, board && 'jetonomy' === board.value ? s.boardJt : s.boardOurs ) );
	}

	function show( n, moved ) {
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
		if ( go ) {
			show( Number( go.dataset.wbgamGoto ), true );
		}
	} );
	root.addEventListener( 'change', refresh );
	root.classList.add( 'is-stepped' );
	show( 1 );
}() );
