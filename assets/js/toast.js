/**
 * WB Gamification — Toast Notification Renderer.
 *
 * Receives queued toast payloads from the wb-gamification-realtime broker
 * (Heartbeat-backed) and renders them as dismissible notifications in
 * the top-center stack. Replaces the pre-1.4.0 setInterval REST poller
 * — the broker tells us when toasts arrive; we just paint them.
 *
 * Fallback: if the broker hasn't published yet (e.g. very first paint
 * before Heartbeat ticks), we do one REST poll to flush whatever was
 * queued by the previous request. After that, all updates come through
 * the broker.
 *
 * @since 1.0.0
 * @refactored 1.4.0 — moved to realtime broker subscription
 */

/* global wbGamToast, wbGamRealtime */

( function () {
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
	 * Render a queued payload of toasts.
	 *
	 * @param {Array<object>|undefined} toasts From the heartbeat / REST poll.
	 */
	function renderToasts( toasts ) {
		if ( ! toasts || ! toasts.length ) {
			return;
		}
		toasts.forEach( showToast );
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
		close.textContent = '✕';
		close.addEventListener( 'click', function () {
			el.remove();
		} );
		el.appendChild( close );

		container.appendChild( el );

		// Animate in.
		requestAnimationFrame( function () {
			el.classList.add( 'wb-gam-toast--enter' );
		} );

		// Auto-dismiss after 4 seconds.
		setTimeout( function () {
			if ( el.parentNode ) {
				el.classList.add( 'wb-gam-toast--exit' );
				setTimeout( function () {
					if ( el.parentNode ) {
						el.remove();
					}
				}, 320 );
			}
		}, 4000 );
	}

	/**
	 * First-paint fallback — drain any toast queued before the broker
	 * came online. Once the broker fires its first tick we never read
	 * this endpoint again.
	 */
	function firstPaintFallback() {
		fetch( wbGamToast.restUrl + 'members/me/toasts', {
			headers: { 'X-WP-Nonce': wbGamToast.nonce },
			credentials: 'same-origin'
		} )
			.then( function ( r ) { return r.ok ? r.json() : []; } )
			.then( renderToasts )
			.catch( function () { /* silent — broker will catch up */ } );
	}

	/**
	 * Subscribe to the realtime broker. The broker replays its last
	 * payload synchronously on subscribe, so if heartbeat has already
	 * ticked we paint immediately.
	 */
	function subscribe() {
		if ( window.wbGamRealtime && typeof window.wbGamRealtime.subscribe === 'function' ) {
			window.wbGamRealtime.subscribe( 'toasts', renderToasts );
			return true;
		}
		return false;
	}

	if ( subscribe() ) {
		// Broker already there — still do one fallback drain in case the
		// page had a queued toast that pre-dated heartbeat's first tick.
		firstPaintFallback();
	} else {
		// Broker not loaded yet (jQuery / wp.heartbeat boot order). Wait
		// for the ready event the broker dispatches on init.
		document.addEventListener( 'wbGamRealtimeReady', function () {
			subscribe();
		}, { once: true } );
		// And drain anything queued from a previous request.
		firstPaintFallback();
	}
}() );
