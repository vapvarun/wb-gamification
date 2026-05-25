/**
 * WB Gamification — Toast Notification System
 *
 * Polls the REST API for pending toast notifications (points, badges,
 * challenges) and renders them as dismissible toasts in the bottom-right
 * corner. Works alongside the Interactivity API overlay system for
 * level-up and streak milestones.
 *
 * @since 1.0.0
 */

/* global wbGamToast */

(function() {
	'use strict';

	if ( typeof wbGamToast === 'undefined' ) {
		return;
	}

	// Create the toast container.
	var container = document.createElement( 'div' );
	container.className = 'wb-gam-toasts wb-gam-toasts--rest';
	container.setAttribute( 'role', 'region' );
	container.setAttribute( 'aria-label', 'Notifications' );
	container.setAttribute( 'aria-live', 'polite' );
	container.setAttribute( 'aria-relevant', 'additions' );
	document.body.appendChild( container );

	/**
	 * Lucide icon class map for toast types. The toast lucide-icons
	 * stylesheet must be enqueued on the page for these to render.
	 */
	var iconMap = {
		points:    'icon-sparkles',
		badge:     'icon-medal',
		challenge: 'icon-target',
		level_up:  'icon-rocket',
		streak:    'icon-flame',
		kudos:     'icon-heart-handshake'
	};

	/**
	 * Fetch and display pending toasts.
	 */
	function checkToasts() {
		fetch( wbGamToast.restUrl + 'members/me/toasts', {
			headers: { 'X-WP-Nonce': wbGamToast.nonce },
			credentials: 'same-origin'
		} )
		.then( function( response ) {
			if ( ! response.ok ) {
				return [];
			}
			return response.json();
		} )
		.then( function( toasts ) {
			if ( ! toasts || ! toasts.length ) {
				return;
			}
			toasts.forEach( function( toast ) {
				showToast( toast );
			} );
		} )
		.catch( function() {
			// Silently fail — toast polling is non-critical.
		} );
	}

	/**
	 * Create and display a single toast element.
	 *
	 * @param {Object} toast Toast data with type, message, icon, detail properties.
	 */
	function showToast( toast ) {
		var el = document.createElement( 'div' );
		el.className = 'wb-gam-toast';
		el.setAttribute( 'data-type', toast.type || 'points' );

		var icon = document.createElement( 'span' );
		var iconClass = toast.icon || iconMap[ toast.type ] || iconMap.points;
		icon.className = 'wb-gam-toast__icon ' + iconClass;
		icon.setAttribute( 'aria-hidden', 'true' );
		el.appendChild( icon );

		var body = document.createElement( 'div' );
		body.className = 'wb-gam-toast__body';

		var message = document.createElement( 'strong' );
		message.className = 'wb-gam-toast__message';
		message.textContent = toast.message || '';
		body.appendChild( message );

		if ( toast.detail ) {
			var detail = document.createElement( 'span' );
			detail.className = 'wb-gam-toast__detail';
			detail.textContent = toast.detail;
			body.appendChild( detail );
		}

		el.appendChild( body );

		var close = document.createElement( 'button' );
		close.className = 'wb-gam-toast__close';
		close.setAttribute( 'aria-label', 'Dismiss' );
		close.textContent = '\u2715';
		close.addEventListener( 'click', function() {
			el.remove();
		} );
		el.appendChild( close );

		container.appendChild( el );

		// Animate in — toast slides from the top and fades. Without this,
		// the toast popped in instantly which read as a glitch to non-tech
		// users (Basecamp 9925151443 suggestion).
		requestAnimationFrame( function () {
			el.classList.add( 'wb-gam-toast--enter' );
		} );

		// Auto-dismiss after 4 seconds.
		setTimeout( function() {
			if ( el.parentNode ) {
				el.classList.add( 'wb-gam-toast--exit' );
				setTimeout( function() {
					if ( el.parentNode ) {
						el.remove();
					}
				}, 320 );
			}
		}, 4000 );
	}

	// Check on page load after a 2-second delay (let page settle).
	setTimeout( checkToasts, 2000 );

	// Poll every 8 seconds while the tab is active (was 30s — the long
	// interval is what made achievements feel like they only appeared on
	// page reload). When the tab is hidden we slow back to 60s to stay off
	// the server. Visibilitychange refresh below picks up anything queued
	// during the hidden window.
	var FAST_INTERVAL_MS = 8000;
	var IDLE_INTERVAL_MS = 60000;
	var timer = setInterval( checkToasts, FAST_INTERVAL_MS );
	document.addEventListener( 'visibilitychange', function() {
		clearInterval( timer );
		if ( document.hidden ) {
			timer = setInterval( checkToasts, IDLE_INTERVAL_MS );
		} else {
			checkToasts();
			timer = setInterval( checkToasts, FAST_INTERVAL_MS );
		}
	} );
	// Also check on window focus — covers browsers that don't reliably
	// fire visibilitychange when the admin alt-tabs back from a tool.
	window.addEventListener( 'focus', checkToasts );
})();
