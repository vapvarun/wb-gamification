/**
 * User Status Bar — frontend view module.
 *
 * Subscribes to the wb-gamification realtime broker (Heartbeat-backed)
 * and updates the bar's data bindings on every tick. Pure DOM mutation
 * — no rerender, no virtual DOM, so the bar plays nicely with FSE
 * theme.json overrides and any custom CSS the site has bolted on.
 *
 * @since 1.4.0
 */

( function () {
	'use strict';

	/**
	 * Park a top-anchored bar BELOW whatever is actually at the top of the page.
	 *
	 * The CSS used to hardcode `top: 48px` — the WP admin-bar height — and expose a
	 * `--wb-gam-status-bar-top-offset` variable with a comment inviting THEMES to correct it. No theme
	 * sets it, including our own: on BuddyX the bar rendered at y=48..146 while the header occupied
	 * y=37..106, so it sat squarely on top of the site's nav.
	 *
	 * Expecting every theme on earth to fix our positioning is not a default; it is a bug with a
	 * comment. So we measure what is up there and set the variable ourselves. The variable STAYS —
	 * a theme or an owner that wants to override the measurement still can.
	 *
	 * @param {Element} bar The status bar.
	 */
	function parkBelowTopStrip( bar ) {
		if ( ! window.wbGam || typeof window.wbGam.topObstructionBottom !== 'function' ) {
			return;
		}

		// Only the top-anchored variants can collide with anything.
		if ( ! bar.classList.contains( 'wb-gam-status-bar--pos-top-right' )
			&& ! bar.classList.contains( 'wb-gam-status-bar--pos-top-left' ) ) {
			return;
		}

		var measure = function () {
			var bottom = window.wbGam.topObstructionBottom( bar );

			// The CSS already adds its own 1rem gap and the admin-bar allowance on top of this
			// variable, so what we contribute is only the part it could not know about: everything
			// below the admin bar. Subtract it back out so the two do not double-count.
			var adminBar = document.getElementById( 'wpadminbar' );
			var adminH   = adminBar ? adminBar.getBoundingClientRect().height : 0;
			var offset   = Math.max( 0, bottom - adminH );

			bar.style.setProperty( '--wb-gam-status-bar-top-offset', Math.round( offset ) + 'px' );
		};

		measure();

		// A header can grow, shrink, unstick or disappear as the page scrolls, so this is not a
		// one-shot measurement.
		window.addEventListener( 'scroll', measure, { passive: true } );
		window.addEventListener( 'resize', measure, { passive: true } );
	}

	function init() {
		// Deliberately NOT a captured NodeList. This used to be
		// `var bars = document.querySelectorAll(...)`, resolved once, and every later read -- including
		// the realtime payload handler below -- closed over that snapshot. A status bar that arrived
		// after load (a host theme navigating client-side) was in nobody's list: not wired, and not
		// updated when the member's points changed. Ask the document each time; there is at most a
		// handful of these on a page.
		function bars() {
			return document.querySelectorAll( '[data-wb-gam-status-bar]' );
		}

		// Collapsible toggle — pure CSS class flip; pref persists for the
		// session so an admin who collapsed it doesn't have to do it again
		// on every page. Runs per bar, when that bar appears.
		window.wbGam.onMount( '[data-wb-gam-status-bar]', function ( bar ) {
			parkBelowTopStrip( bar );

			var toggle = bar.querySelector( '[data-wb-gam-status-bar-toggle]' );
			if ( ! toggle ) {
				return;
			}
			var key = 'wbGamStatusBarCollapsed';
			if ( sessionStorage.getItem( key ) === '1' ) {
				bar.classList.add( 'is-collapsed' );
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
			// keyboard-accessible: target is a native <button> (render.php:105).
			toggle.addEventListener( 'click', function () {
				var collapsed = bar.classList.toggle( 'is-collapsed' );
				toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
				sessionStorage.setItem( key, collapsed ? '1' : '0' );
			} );
		} );

		function applyPayload( payload ) {
			if ( ! payload || typeof payload !== 'object' ) {
				return;
			}
			bars().forEach( function ( bar ) {
				// Hide bar entirely for guests (when hideForGuests=true).
				if ( ! payload.user_id ) {
					// Keep server-rendered state — don't touch the DOM.
					return;
				}
				bind( bar, 'primary_total', formatInt( payload.primary_total ) );
				bind( bar, 'primary_label', payload.primary_label || '' );
				bind( bar, 'level.name',    ( payload.level && payload.level.name ) || '' );
				bind( bar, 'badges_count',  formatInt( payload.badges_count ) );
				bind( bar, 'current_streak', formatInt( payload.current_streak ) );

				var fill = bar.querySelector( '[data-wb-gam-bind-style="progress_percent"]' );
				if ( fill ) {
					var pct = Math.max( 0, Math.min( 100, parseInt( payload.progress_percent, 10 ) || 0 ) );
					fill.style.width = pct + '%';
				}
			} );
		}

		function bind( root, key, value ) {
			var el = root.querySelector( '[data-wb-gam-bind="' + key + '"]' );
			if ( ! el ) {
				return;
			}
			// Only animate if the value actually changed.
			if ( el.textContent === value ) {
				return;
			}
			el.textContent = value;
			el.classList.remove( 'wb-gam-status-bar__value--bump' );
			// Trigger reflow for the animation restart.
			// eslint-disable-next-line no-unused-expressions
			el.offsetWidth;
			el.classList.add( 'wb-gam-status-bar__value--bump' );
		}

		function formatInt( n ) {
			if ( typeof n !== 'number' && typeof n !== 'string' ) {
				return '0';
			}
			var int = parseInt( n, 10 ) || 0;
			return int.toLocaleString();
		}

		function subscribe() {
			if ( window.wbGamRealtime && typeof window.wbGamRealtime.subscribe === 'function' ) {
				window.wbGamRealtime.subscribe( 'user', applyPayload );
				return true;
			}
			return false;
		}

		if ( ! subscribe() ) {
			document.addEventListener( 'wbGamRealtimeReady', subscribe, { once: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
