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

		// Auto-dismiss after 4 seconds.
		setTimeout( function() {
			if ( el.parentNode ) {
				el.style.opacity = '0';
				el.style.transform = 'translateX(1rem)';
				el.style.transition = 'opacity 0.3s, transform 0.3s';
				setTimeout( function() {
					if ( el.parentNode ) {
						el.remove();
					}
				}, 300 );
			}
		}, 4000 );
	}

	// Check on page load after a 2-second delay (let page settle).
	setTimeout( checkToasts, 2000 );

	// Poll every 30 seconds.
	setInterval( checkToasts, 30000 );

	// Also check when tab regains focus.
	document.addEventListener( 'visibilitychange', function() {
		if ( ! document.hidden ) {
			checkToasts();
		}
	} );
})();
