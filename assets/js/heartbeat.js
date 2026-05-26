/**
 * WB Gamification — Realtime broker (WP Heartbeat client).
 *
 * Single client-side pub/sub bus that every WBGam frontend block hooks
 * into instead of running its own setInterval poll. Subscribes once to
 * the wp-heartbeat custom event, fans out the payload to whatever JS
 * features are listening on the page.
 *
 * Why a broker:
 *   - 1 network request per tick (15-60s) instead of N intervals
 *   - one place to react to slow connections (Heartbeat already does
 *     backoff)
 *   - blocks can mount + unmount without juggling timers
 *   - boards / point types the user is actually looking at are pushed
 *     through to the server so the snapshot stays cheap
 *
 * Public API:
 *   window.wbGamRealtime.subscribe('user', fn)         -> unsubscribe()
 *   window.wbGamRealtime.subscribe('toasts', fn)
 *   window.wbGamRealtime.subscribe('leaderboards', fn)
 *   window.wbGamRealtime.registerBoard({ sig, period, scope_type, scope_id, limit, point_type })
 *   window.wbGamRealtime.unregisterBoard(sig)
 *   window.wbGamRealtime.ping()                        // force the next tick to happen ~now
 *
 * Subscriber callbacks receive the matching slice of the heartbeat
 * payload only; never the full envelope.
 *
 * @since 1.4.0
 */

/* global jQuery, wp */

( function ( wp ) {
	'use strict';

	if ( typeof window === 'undefined' || ! window.jQuery || ! wp || ! wp.heartbeat ) {
		// Heartbeat is enqueued lazily on some setups (e.g. logged-out
		// pages where the admin-bar isn't shown). Defer until it's
		// available; if it never arrives, the toast.js + block view
		// modules fall back to their REST poll path.
		document.addEventListener( 'DOMContentLoaded', function () {
			if ( window.wp && window.wp.heartbeat ) {
				init();
			}
		} );
		return;
	}
	init();

	function init() {
		var $ = jQuery;

		// Tunable interval (seconds). Heartbeat accepts 'fast' (5s) for
		// short bursts, 'standard' (15s) for the default. WP slows the
		// tick when the tab is hidden — we don't override that.
		var DEFAULT_INTERVAL = 'standard';
		wp.heartbeat.interval( DEFAULT_INTERVAL );

		var subscribers = {
			user:         new Set(),
			toasts:       new Set(),
			leaderboards: new Set(),
		};

		var boards = new Map();   // sig -> board descriptor
		var lastPayload = null;

		// Outbound: tell the server which leaderboards we're rendering.
		$( document ).on( 'heartbeat-send', function ( e, data ) {
			data.wb_gam = {
				boards: Array.from( boards.values() ),
			};
		} );

		// Inbound: fan out to subscribers, only emit when we have data.
		$( document ).on( 'heartbeat-tick', function ( e, data ) {
			if ( ! data || ! data.wb_gam ) {
				return;
			}
			lastPayload = data.wb_gam;
			fanout( 'user',         lastPayload.user );
			fanout( 'toasts',       lastPayload.toasts );
			fanout( 'leaderboards', lastPayload.leaderboards );
		} );

		// Network errors don't kill the broker — Heartbeat self-heals.

		function fanout( channel, slice ) {
			if ( ! subscribers[ channel ] ) {
				return;
			}
			subscribers[ channel ].forEach( function ( fn ) {
				try {
					fn( slice );
				} catch ( err ) {
					// Subscriber errors must not break sibling subscribers
					// or the broker. Surface to console; never to user.
					if ( window.console && window.console.error ) {
						window.console.error( 'wbGamRealtime subscriber error:', err );
					}
				}
			} );
		}

		var api = {
			/**
			 * Subscribe to a channel. Returns an unsubscribe fn.
			 *
			 * @param {string}   channel One of 'user', 'toasts', 'leaderboards'.
			 * @param {Function} fn      Receives the channel slice on every tick.
			 * @return {Function} Unsubscribe.
			 */
			subscribe: function ( channel, fn ) {
				if ( ! subscribers[ channel ] || typeof fn !== 'function' ) {
					return function () {};
				}
				subscribers[ channel ].add( fn );
				// Replay the last payload to the new subscriber so a late-
				// mounted block doesn't wait a full tick to render.
				if ( lastPayload && lastPayload[ channel ] ) {
					try {
						fn( lastPayload[ channel ] );
					} catch ( err ) { /* same isolation as fanout */ }
				}
				return function unsubscribe() {
					subscribers[ channel ].delete( fn );
				};
			},
			/**
			 * Register a leaderboard the page is currently rendering so the
			 * server includes it in the next tick.
			 *
			 * @param {object} board { sig, period, scope_type, scope_id, limit, point_type }
			 */
			registerBoard: function ( board ) {
				if ( ! board || ! board.sig ) {
					return;
				}
				boards.set( board.sig, board );
			},
			unregisterBoard: function ( sig ) {
				boards.delete( sig );
			},
			/**
			 * Force a tick. Heartbeat throttles aggressive callers — this is
			 * appropriate after a user action that the server may have
			 * already processed (form submit, kudos sent).
			 */
			ping: function () {
				if ( wp.heartbeat && typeof wp.heartbeat.connectNow === 'function' ) {
					wp.heartbeat.connectNow();
				}
			},
			/**
			 * Read the most recent payload synchronously (e.g. for SSR-replay
			 * use cases). May be null if no tick has happened yet.
			 */
			lastPayload: function () { return lastPayload; },
		};

		window.wbGamRealtime = api;

		// Custom DOM event so non-jQuery consumers can also listen.
		document.dispatchEvent( new CustomEvent( 'wbGamRealtimeReady', { detail: { api: api } } ) );
	}
}( window.wp ) );
