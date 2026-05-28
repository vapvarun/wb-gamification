/**
 * WB Gamification — Toast Notification Renderer.
 *
 * Sole owner of the toast STACK surface. Creates ONE
 * `<div class="wb-gam-toasts">` container appended to document.body and
 * renders dismissible notifications in the top-center position.
 *
 * Consumes three independent input paths, all deduped by event `_id`:
 *   1. Page-load seed in `window.wbGamNotifications`.
 *   2. wbGamRealtime broker live deliveries (heartbeat ticks + SSE).
 *   3. REST `/members/me/toasts` poll — fallback only when the broker
 *      doesn't come online (logged-out pages, third-party Heartbeat
 *      strip).
 *
 * Event-type routing:
 *   - level_up + streak_milestone → SKIPPED here, owned by the
 *     Interactivity store overlay surface (assets/interactivity/
 *     notifications.js). Toasts and overlays are different visual
 *     treatments for different signal weights.
 *   - everything else (points, badge, challenge, kudos, …) → toast.
 *
 * @since 1.0.0
 * @refactored 1.4.0 — moved to realtime broker subscription.
 * @refactored 1.5.0 — single-owner of the toast container; overlays
 *                    routed out to the IA store.
 */

/* global wbGamToast, wbGamRealtime */

( function () {
	'use strict';

	if ( typeof wbGamToast === 'undefined' ) {
		return;
	}

	// Create the single toast container. The `--rest` modifier was used
	// in 1.4.0 to distinguish from a now-removed IA-rendered duplicate
	// container; dropped in 1.5.0 as part of the single-owner refactor.
	var container = document.createElement( 'div' );
	container.className = 'wb-gam-toasts';
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
	 * Reference to the most recent points-type toast still visible.
	 * When a new points toast arrives within AGGREGATE_WINDOW_MS, we
	 * MERGE into this element (bump total + action count + reset
	 * dismiss timer) instead of painting a sibling.
	 *
	 * Why client-side aggregation:
	 *   A power-user clicking around can fire 5 points-awarded events
	 *   in 3 seconds — server-side that's 5 distinct rows in the queue
	 *   table (each its own "+5 Points" payload). Without aggregation
	 *   the user sees 5 stacked top-center toasts, which is noisy.
	 *   Client-side is the right layer because:
	 *     1. Visibility-aware — we only merge if the previous toast is
	 *        still on screen, not just "recently fired"
	 *     2. No server-state coordination — cursors stay simple, queue
	 *        rows stay as authoritative individual events for the audit
	 *        trail / GraphQL / SSE clients
	 *     3. Other surfaces (mobile SDK consumers) can implement their
	 *        own aggregation policy; we don't lock them into ours
	 */
	var lastPointsToast = null; // { el, points, actionCount, dismissTimer, type }
	var AGGREGATE_WINDOW_MS = 2000;

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
			// Overlays (level-up + streak milestone) are the IA store's
			// surface — different visual treatment, separate dismiss
			// affordance, full-viewport modal. Skip them here so they
			// don't ALSO render as a toast pill in the corner.
			if ( toast.type === 'level_up' || toast.type === 'streak_milestone' ) {
				return;
			}
			var key = toast._id != null
				? 'id:' + toast._id
				: 'fp:' + ( toast.type || '' ) + '|' + ( toast.message || '' ) + '|' + ( toast._ts || '' );
			if ( seenIds.has( key ) ) {
				return;
			}
			seenIds.add( key );

			// Points-toast aggregation: if a previous points toast is
			// still on screen, merge this one into it.
			if ( toast.type === 'points' && lastPointsToast && lastPointsToast.el.parentNode ) {
				mergeIntoPointsToast( toast );
				return;
			}

			showToast( toast );
		} );
	}

	/**
	 * Merge a new points toast into the previous one still visible.
	 * Updates the message to read "+TOTAL pts (N actions)" and resets
	 * the dismiss timer so the user has time to register the change.
	 *
	 * @param {Object} toast Incoming points toast.
	 */
	function mergeIntoPointsToast( toast ) {
		var add = parseInt( toast.points, 10 );
		if ( isNaN( add ) ) {
			// No structured points field — fall back to extracting from
			// the message ("+5 Points" → 5). Defensive; the server-side
			// on_points_awarded always sets toast.points so this is rare.
			var m = ( toast.message || '' ).match( /\+(\d+)/ );
			add = m ? parseInt( m[1], 10 ) : 0;
		}
		if ( add <= 0 ) {
			return;
		}

		lastPointsToast.points += add;
		lastPointsToast.actionCount += 1;

		// Re-render the message in place.
		var msgEl = lastPointsToast.el.querySelector( '.wb-gam-toast__message' );
		if ( msgEl ) {
			// Preserve the currency label the server-side wrote into the
			// last toast. The label is whatever came after "+N " in the
			// message — typically "Points" / "XP" / "Coins".
			var labelMatch = ( msgEl.textContent || '' ).match( /^\+\d+\s+(.+?)(?:\s+\(.*\))?$/ );
			var label = labelMatch ? labelMatch[1] : 'Points';
			msgEl.textContent = '+' + lastPointsToast.points + ' ' + label
				+ ' (' + lastPointsToast.actionCount + ' actions)';
		}
		// Hide the per-action detail line since we now span multiple actions.
		var detailEl = lastPointsToast.el.querySelector( '.wb-gam-toast__detail' );
		if ( detailEl ) {
			detailEl.hidden = true;
		}

		// Reset the dismiss timer so the user has time to see the bump.
		if ( lastPointsToast.dismissTimer ) {
			clearTimeout( lastPointsToast.dismissTimer );
		}
		lastPointsToast.dismissTimer = setTimeout( function () {
			if ( lastPointsToast && lastPointsToast.el && lastPointsToast.el.parentNode ) {
				lastPointsToast.el.classList.add( 'wb-gam-toast--exit' );
				var el = lastPointsToast.el;
				setTimeout( function () {
					if ( el.parentNode ) { el.remove(); }
				}, 320 );
			}
		}, 4000 );
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
		var dismissTimer = setTimeout( function () {
			if ( el.parentNode ) {
				el.classList.add( 'wb-gam-toast--exit' );
				setTimeout( function () {
					if ( el.parentNode ) {
						el.remove();
					}
				}, 320 );
			}
		}, 4000 );

		// Track points toasts so subsequent ones within
		// AGGREGATE_WINDOW_MS can merge instead of stacking.
		if ( toast.type === 'points' ) {
			lastPointsToast = {
				el:           el,
				points:       parseInt( toast.points, 10 ) || 0,
				actionCount:  1,
				dismissTimer: dismissTimer,
				type:         'points',
			};
			// Clear the reference when the toast leaves the DOM (close
			// button click, auto-dismiss exit animation). After
			// AGGREGATE_WINDOW_MS the reference is stale anyway — the
			// next points toast will paint as a new one.
			setTimeout( function () {
				if ( lastPointsToast && lastPointsToast.el === el ) {
					lastPointsToast = null;
				}
			}, AGGREGATE_WINDOW_MS );
		}
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
