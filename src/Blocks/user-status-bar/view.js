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

	function init() {
		var bars = document.querySelectorAll( '[data-wb-gam-status-bar]' );
		if ( ! bars.length ) {
			return;
		}

		// Collapsible toggle — pure CSS class flip; pref persists for the
		// session so an admin who collapsed it doesn't have to do it again
		// on every page.
		bars.forEach( function ( bar ) {
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
			bars.forEach( function ( bar ) {
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
