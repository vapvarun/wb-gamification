/**
 * WB Gamification: the toast feed.
 *
 * Reads queued events and hands each one to the shared toast renderer (assets/js/toast-core.js,
 * window.wbGam.toast). It owns nothing visual: the shell, timing, pause on hover, the
 * three-visible limit and the top-layer host all live in the renderer and popups.css.
 *
 * Three independent input paths, all deduped by event `_id`:
 *   1. Page-load seed in `window.wbGamNotifications`.
 *   2. wbGamRealtime broker live deliveries (heartbeat ticks + SSE).
 *   3. REST `/members/me/toasts` poll, only when the broker does not come online.
 *
 * Event routing:
 *   - level_up, streak_milestone, cohort_promotion, community_goal are Moment cards, owned by the
 *     Interactivity store (assets/interactivity/notifications.js). Skipped here.
 *   - badge, challenge: an Achievement toast with a small confetti burst.
 *   - points, kudos, welcome, submission, skip: a plain toast. Repeats of the SAME points action
 *     inside two seconds merge into one toast ("+2 Points", "Leave a comment x2").
 *
 * @since 1.0.0
 * @refactored 1.6.5 - the renderer moved to toast-core.js so the admin shares it.
 */

/* global wbGamToast, wbGamRealtime */

( function () {
	'use strict';

	if ( typeof wbGamToast === 'undefined' ) {
		return;
	}

	function toastI18n( key, fallback ) {
		return ( wbGamToast.i18n && wbGamToast.i18n[ key ] ) || fallback;
	}

	var MOMENT_TYPES = [ 'level_up', 'streak_milestone', 'cohort_promotion', 'community_goal' ];
	var AGGREGATE_WINDOW_MS = 2000;

	var ICONS = {
		points: 'icon-sparkles',
		badge: 'icon-medal',
		challenge: 'icon-target',
		kudos: 'icon-heart-handshake',
		welcome: 'icon-sparkles',
		skip: 'icon-info',
		submission: 'icon-check',
	};

	/**
	 * Set of toast `_id`s already rendered in this page session. Three delivery paths can hand us
	 * the same event; this guarantees one toast per `_id`. Fallback key for legacy payloads that
	 * carry no `_id`: type + message + _ts.
	 */
	var seenIds = new Set();

	// The most recent points toast, so a repeat of the same action merges into it.
	var lastPoints = null; // { handle, points, count, action, label, unitMany, at }

	function celebrate( el ) {
		if ( window.wbGam && typeof window.wbGam.celebrate === 'function' ) {
			window.wbGam.celebrate( el, 'small' );
		}
	}

	function pointsToast( toast ) {
		var add = parseInt( toast.points, 10 );
		if ( isNaN( add ) ) {
			var m = ( toast.message || '' ).match( /\+(\d+)/ );
			add = m ? parseInt( m[ 1 ], 10 ) : 0;
		}
		var action = toast.action || '';
		var label  = toast.detail || '';
		var now    = Date.now();

		if (
			add > 0
			&& lastPoints
			&& ! lastPoints.handle.gone
			&& lastPoints.action === action
			&& lastPoints.label === label
			&& now - lastPoints.at < AGGREGATE_WINDOW_MS
		) {
			lastPoints.points += add;
			lastPoints.count  += 1;
			lastPoints.at      = now;
			// A merged total is always more than one, so it takes the plural name the server sent.
			var unit = lastPoints.unitMany || toastI18n( 'points', 'points' );
			lastPoints.handle.update( {
				title: '+' + lastPoints.points.toLocaleString() + ' ' + unit,
				body: label ? label + ' ×' + lastPoints.count : '×' + lastPoints.count,
			} );
			return;
		}

		var handle = window.wbGam.toast( {
			type: 'points',
			tone: 'reward',
			icon: toast.icon || ICONS.points,
			title: toast.message || '',
			body: label,
		} );
		lastPoints = add > 0
			? { handle: handle, points: add, count: 1, action: action, label: label, unitMany: toast.unit_many || '', at: now }
			: null;
	}

	function showOne( toast ) {
		switch ( toast.type ) {
			case 'points':
				pointsToast( toast );
				return;

			case 'badge':
			case 'challenge':
				window.wbGam.toast( {
					type: toast.type,
					tone: 'achievement',
					icon: toast.icon || ICONS[ toast.type ],
					title: toast.message || '',
					body: toast.detail || '',
					onShow: celebrate,
				} );
				return;

			case 'skip':
				window.wbGam.toast( { type: 'skip', tone: 'info', icon: ICONS.skip, title: toast.message || '', body: toast.detail || '' } );
				return;

			case 'submission':
				window.wbGam.toast( {
					type: 'submission',
					tone: 'approved' === toast.outcome ? 'success' : 'info',
					icon: toast.icon || ( 'approved' === toast.outcome ? 'icon-check' : 'icon-info' ),
					title: toast.message || '',
					body: toast.detail || '',
				} );
				return;

			default:
				window.wbGam.toast( {
					type: toast.type || 'points',
					tone: 'reward',
					icon: toast.icon || ICONS[ toast.type ] || ICONS.points,
					title: toast.message || '',
					body: toast.detail || '',
					href: toast.url || '',
					hrefLabel: toast.url_label || '',
				} );
		}
	}

	/**
	 * Render a queued payload, deduping by `_id` so the same event never paints twice.
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
			// Moment cards are the Interactivity store's surface; they must not also paint a toast.
			if ( MOMENT_TYPES.indexOf( toast.type ) !== -1 ) {
				return;
			}
			var key = toast._id != null
				? 'id:' + toast._id
				: 'fp:' + ( toast.type || '' ) + '|' + ( toast.message || '' ) + '|' + ( toast._ts || '' );
			if ( seenIds.has( key ) ) {
				return;
			}
			seenIds.add( key );
			showOne( toast );
		} );
	}

	/**
	 * First-paint fallback: drain any toast queued before the broker came online. Once the broker
	 * fires its first tick this endpoint is never read again.
	 */
	function firstPaintFallback() {
		window.wbGam.rest( wbGamToast.restUrl + 'members/me/toasts', {
			nonce: wbGamToast.nonce,
		} )
			.then( function ( result ) { return result.ok ? result.data : []; } )
			.then( renderToasts )
			.catch( function () { /* silent: the broker will catch up */ } );
	}

	/**
	 * Subscribe to the realtime broker. It replays its last payload synchronously on subscribe, so
	 * if heartbeat has already ticked we paint immediately.
	 */
	function subscribe() {
		if ( window.wbGamRealtime && typeof window.wbGamRealtime.subscribe === 'function' ) {
			window.wbGamRealtime.subscribe( 'toasts', renderToasts );
			return true;
		}
		return false;
	}

	// Step 1: paint anything that arrived in the page-load seed (cursor=footer).
	if ( window.wbGamNotifications && Array.isArray( window.wbGamNotifications ) ) {
		renderToasts( window.wbGamNotifications );
	}

	// Step 2: subscribe to the broker. If it is up we rely on it for live delivery and do NOT also
	// call the REST fallback, which would re-deliver the same events under a different cursor.
	if ( ! subscribe() ) {
		document.addEventListener( 'wbGamRealtimeReady', subscribe, { once: true } );
		firstPaintFallback();
	}
}() );
