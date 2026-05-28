/**
 * WB Gamification — Server-Sent Events client adapter.
 *
 * Stage 1 scaffold (2026-05-28). Feature-flagged OFF on the server until
 * stages 2-3 ship the storage layer + streaming loop. This file probes
 * the transport flag at boot and no-ops cleanly if SSE isn't enabled,
 * so it can be enqueued globally alongside heartbeat.js without changing
 * runtime behaviour.
 *
 * Architectural intent: when active, this adapter receives SSE messages
 * and dispatches them into the SAME window.wbGamRealtime channels that
 * heartbeat.js publishes to. So existing subscribers (toast.js, future
 * leaderboard live-update consumers) never know which transport is
 * active. The broker is the contract; transports are interchangeable.
 *
 * Fallback chain:
 *   1. transport === 'sse'       → SSE only; refuse heartbeat
 *   2. transport === 'auto'      → SSE preferred, heartbeat on failure
 *   3. transport === 'heartbeat' → SSE never connects
 *   4. EventSource unsupported   → permanent fallback to heartbeat
 *   5. SSE connection error      → permanent fallback within this page
 *                                  load (next page reload retries SSE)
 *
 * See plan/REAL-TIME-TRANSPORT.md for the full design.
 *
 * @since 1.5.1
 */

/* global wbGamSSEConfig, wbGamRealtime */

( function () {
	'use strict';

	// Localised at enqueue time (see enqueue_realtime_assets in the
	// engines bootstrap). Provides: streamUrl, transport, lastEventId.
	var cfg = ( typeof wbGamSSEConfig !== 'undefined' ) ? wbGamSSEConfig : null;
	if ( ! cfg ) {
		return;
	}

	// Transport gate. If the option says 'heartbeat', stop here — there's
	// no SSE at all on this install.
	if ( cfg.transport === 'heartbeat' ) {
		return;
	}

	// EventSource feature detection. IE11 lacks it; some embedded webviews
	// also lack it. Heartbeat is the universal fallback.
	if ( typeof window.EventSource === 'undefined' ) {
		return;
	}

	var lastEventId = parseInt( cfg.lastEventId || 0, 10 );
	var source = null;
	var failed = false;

	function connect() {
		if ( failed ) {
			return;
		}
		var url = cfg.streamUrl;
		if ( lastEventId > 0 ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'last_event_id=' + lastEventId;
		}

		try {
			source = new EventSource( url, { withCredentials: true } );
		} catch ( e ) {
			failed = true;
			return;
		}

		source.addEventListener( 'message', handleMessage );
		source.addEventListener( 'error', handleError );
		source.addEventListener( 'close', handleServerClose );

		// SSE controller emits events with named types (points, badge,
		// level_up, kudos, etc.) using `event: <type>` lines. EventSource
		// only fires the `message` handler for unnamed events, so we
		// also listen on the explicit types our backend writes.
		[ 'points', 'badge', 'level_up', 'streak_milestone', 'challenge_completed', 'kudos', 'skip', 'unknown' ].forEach( function ( type ) {
			source.addEventListener( type, handleNamedEvent( type ) );
		} );
	}

	function handleMessage( ev ) {
		if ( ! ev || ! ev.data ) {
			return;
		}
		var payload;
		try {
			payload = JSON.parse( ev.data );
		} catch ( _e ) {
			return;
		}
		if ( ev.lastEventId ) {
			lastEventId = parseInt( ev.lastEventId, 10 ) || lastEventId;
		}
		// Dispatch into the broker. Subscribers don't know SSE exists.
		// SSE messages come from wb_gam_notifications_queue rows whose
		// payload is the full event object (with `type`, `_id`, etc.).
		// Heartbeat consumers receive arrays of these — match the shape
		// so subscribers don't need to special-case SSE.
		if ( window.wbGamRealtime && typeof window.wbGamRealtime._dispatch === 'function' ) {
			window.wbGamRealtime._dispatch( 'toasts', [ payload ] );
		}
	}

	function handleNamedEvent( eventName ) {
		return function ( ev ) {
			// `close` is the server-side soft-deadline marker; EventSource
			// auto-reconnects. No subscriber action needed.
			if ( 'close' === eventName ) {
				return;
			}
			handleMessage( ev );
		};
	}

	function handleError() {
		// EventSource auto-reconnects on transient errors. We treat
		// repeated errors as a signal to give up on SSE for this page
		// load — in 'auto' mode the heartbeat broker is already running
		// in parallel and will pick up the slack.
		if ( ! source ) {
			return;
		}
		if ( source.readyState === EventSource.CLOSED ) {
			failed = true;
			source = null;
		}
	}

	function handleServerClose() {
		// Server-side soft deadline fired (stages 2-3 emit `event: close`
		// at ~28 seconds). EventSource will auto-reconnect; let it.
	}

	// Connect on idle so we don't compete with first-paint resources.
	if ( typeof window.requestIdleCallback === 'function' ) {
		window.requestIdleCallback( connect, { timeout: 2000 } );
	} else {
		setTimeout( connect, 1500 );
	}
}() );
