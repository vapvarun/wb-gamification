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
	 * Set of toast `_id`s already rendered in this page session.
	 *
	 * Three independent delivery paths can hand us the same event:
	 *   1. The page-load seed via `window.wbGamNotifications` (cursor=footer).
	 *   2. The Heartbeat broker (cursor=heartbeat) — fires on every tick and
	 *      also replays the last payload to late subscribers.
	 *   3. The REST fallback fetch (cursor=rest).
	 *
	 * Each cursor advances independently on the PHP side, so the SAME event
	 * `_id` can arrive twice on a single page load (broker replay + REST
	 * fallback). This Set guarantees one visible toast per `_id` no matter
	 * how many surfaces deliver it. Closes Basecamp #9932791974.
	 *
	 * Fallback key for legacy payloads missing `_id`: type+message+_ts.
	 */
	var seenIds = new Set();

	/**
	 * Render a queued payload of toasts, deduping by `_id` so the same
	 * event never paints twice.
	 *
	 * @param {Array<object>|undefined} toasts From the heartbeat / REST poll / seed.
	 */
	function renderToasts( toasts ) {
		if ( ! toasts || ! toasts.length ) {
			return;
		}
		toasts.forEach( function ( toast ) {
			if ( ! toast || typeof toast !== 'object' ) {
				return;
			}
			var key = toast._id != null
				? 'id:' + toast._id
				: 'fp:' + ( toast.type || '' ) + '|' + ( toast.message || '' ) + '|' + ( toast._ts || '' );
			if ( seenIds.has( key ) ) {
				return;
			}
			seenIds.add( key );
			showToast( toast );
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

	// Step 1 — paint anything that arrived in the page-load seed
	// (cursor=footer, same payload the IA store reads for overlays).
	// This was the missing path that left users staring at an empty
	// container for ~15 seconds until the first heartbeat tick.
	if ( window.wbGamNotifications && Array.isArray( window.wbGamNotifications ) ) {
		renderToasts( window.wbGamNotifications );
	}

	// Step 2 — subscribe to the realtime broker. If the broker is up, we
	// rely on it for live delivery. We do NOT also call firstPaintFallback
	// because the broker's replay covers anything queued before subscribe
	// (heartbeat.js:120) and the REST fallback would just re-deliver the
	// same events under a different cursor (the original duplicate path).
	if ( ! subscribe() ) {
		document.addEventListener( 'wbGamRealtimeReady', subscribe, { once: true } );
		// Broker not online — REST fallback is the only real-time path.
		// Dedupe still protects us if heartbeat boots before this resolves.
		firstPaintFallback();
	}
}() );
