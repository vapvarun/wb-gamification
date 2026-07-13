/**
 * The one fetch() in this plugin's frontend.
 *
 * Five surfaces (give-kudos, profile-visibility, toast, submit-achievement, redemption-store) each
 * hand-rolled their own `fetch` + `X-WP-Nonce` + JSON-parse, and all five carried the same bug: the
 * nonce is baked into the page's server-rendered markup at load time and never looked at again. WP
 * REST nonces expire (WP verifies across two ~12h ticks, so a nonce is only good for up to ~24h). A
 * member who leaves a tab open overnight and then gives kudos gets back a 403
 * `rest_cookie_invalid_nonce`, every one of those five files reports it as a generic "failed, try
 * again", and retrying never helps because the nonce sitting in the DOM is still the dead one -- there
 * was no refresh path anywhere.
 *
 * This is that path, once. `wbGam.rest()` behaves exactly like the fetch each file already did (same
 * signal/timeout, same credentials, same JSON-in-JSON-out shape) except that a 403
 * rest_cookie_invalid_nonce triggers ONE attempt to mint a fresh nonce and retry, before reporting
 * failure the way the original fetch always did.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */
( function ( window ) {
	'use strict';

	/**
	 * AbortSignal.timeout() isn't available in every browser this plugin still supports, so every
	 * fetch in this codebase guarded the same way, five times over. One copy, here.
	 *
	 * @return {AbortSignal|undefined}
	 */
	function timeoutSignal() {
		return ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout )
			? AbortSignal.timeout( 15000 )
			: undefined;
	}

	/**
	 * Parse a fetch Response as JSON without throwing.
	 *
	 * An empty body (204), or an upstream error page in front of PHP (a WAF block, a fatal before any
	 * JSON is printed), makes `.json()` reject -- treated as "no structured body" rather than an
	 * uncaught rejection the caller never asked for.
	 *
	 * @param {Response} response
	 * @return {Promise<*>} Parsed body, or null.
	 */
	function safeJson( response ) {
		return response.json().catch( function () {
			return null;
		} );
	}

	/**
	 * One fetch attempt against `url`, normalized to the shape every caller already expected from its
	 * own hand-rolled `.then( r => r.json().then( data => ( { ok: r.ok, data } ) ) )`.
	 *
	 * @param {string}           url    REST endpoint.
	 * @param {string}           method HTTP method.
	 * @param {Object|undefined} body   Request payload, JSON-encoded here. Omit for a bodyless request.
	 * @param {string}           nonce  X-WP-Nonce value.
	 * @return {Promise<{ok: boolean, status: number, data: *}>}
	 */
	function attempt( url, method, body, nonce ) {
		var headers = { 'X-WP-Nonce': nonce || '' };
		var init = {
			method: method,
			credentials: 'same-origin',
			signal: timeoutSignal(),
			headers: headers,
		};

		// Only requests that actually carry a body declared Content-Type before -- toast.js's fallback
		// GET never did, and adding it unconditionally would be a behavior change, not a consolidation.
		if ( undefined !== body ) {
			headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( body );
		}

		return fetch( url, init ).then( function ( response ) {
			return safeJson( response ).then( function ( data ) {
				return { ok: response.ok, status: response.status, data: data };
			} );
		} );
	}

	/**
	 * Mint a fresh `wp_rest` nonce for whoever the current session belongs to.
	 *
	 * This goes to `admin-ajax.php?action=rest-nonce` -- WordPress core's own endpoint, the one
	 * `wp.apiFetch` uses for exactly this -- and NOT to a route of ours under `/wp-json/`. That is the
	 * whole trick, and it is worth spelling out, because the obvious implementation cannot work:
	 *
	 * Core's `rest_cookie_check_errors()` runs before every REST route's permission_callback, and it
	 * decides who you are FROM THE NONCE. Send no nonce and a cookie-authenticated request is treated
	 * as anonymous; send a dead one and it is rejected outright. So a nonce-refresh route living under
	 * /wp-json/ has to prove it holds a valid nonce in order to be given a valid nonce. It can only
	 * help you when you did not need help.
	 *
	 * (We had exactly that, briefly. It passed a CLI test -- because rest_do_request() dispatches
	 * straight to the route and skips core's auth gate entirely -- and then failed the moment a real
	 * browser sent a real dead nonce at it.)
	 *
	 * admin-ajax has no such gate: it authenticates from the session COOKIE, which is the credential
	 * that is actually still good. The cookie outlives the nonce, and that difference is the entire
	 * reason this recovery is possible at all.
	 *
	 * @param {string} nonceUrl Core's rest-nonce endpoint, localized from PHP (admin_url()).
	 * @return {Promise<string|null>} A fresh nonce, or null if one couldn't be minted.
	 */
	function refreshNonce( nonceUrl ) {
		if ( ! nonceUrl ) {
			return Promise.resolve( null );
		}

		return fetch( nonceUrl, {
			method: 'GET',
			credentials: 'same-origin',
			signal: timeoutSignal(),
		} )
			.then( function ( response ) {
				// The endpoint returns the bare nonce as text, not JSON.
				return response.ok ? response.text() : null;
			} )
			.then( function ( text ) {
				var fresh = ( 'string' === typeof text ) ? text.trim() : '';

				// A logged-out session gets admin-ajax's '0' / '-1' rather than a nonce. Treat anything
				// that is not nonce-shaped as "no nonce", so a logged-out member fails once and reports
				// the real error instead of retrying with junk.
				return /^[a-f0-9]{8,}$/i.test( fresh ) ? fresh : null;
			} )
			.catch( function () {
				return null;
			} );
	}

	/**
	 * Perform a same-origin wb-gamification/v1 REST request, refreshing an expired nonce ONCE and
	 * retrying before reporting failure.
	 *
	 * @param {string} url               REST endpoint, anywhere under wb-gamification/v1.
	 * @param {Object} [options]
	 * @param {string} [options.method]  HTTP method. Defaults to 'GET'.
	 * @param {Object} [options.body]    Request payload. JSON-encoded automatically when present.
	 * @param {string} [options.nonce]   Current X-WP-Nonce value.
	 * @return {Promise<{ok: boolean, status: number, data: *}>} Resolves even on a failed response --
	 *         rejects only on a network-level failure (offline, timeout, abort), same as a bare fetch.
	 */
	function rest( url, options ) {
		var opts = options || {};
		var method = opts.method || 'GET';
		var nonce = opts.nonce;

		return attempt( url, method, opts.body, nonce ).then( function ( result ) {
			var staleNonce = 403 === result.status
				&& result.data
				&& 'rest_cookie_invalid_nonce' === result.data.code;

			if ( ! staleNonce ) {
				return result;
			}

			var cfg = window.wbGamRest || {};

			return refreshNonce( cfg.nonceUrl ).then( function ( freshNonce ) {
				if ( ! freshNonce ) {
					return result; // Couldn't refresh -- report the original failure, unchanged.
				}

				// Put the fresh nonce back where the page keeps it, so the NEXT action on this page
				// does not have to rediscover the same expiry. Without this, every single submission
				// on a long-open tab pays for its own round trip to find out what we already know.
				document.querySelectorAll( '[data-rest-nonce="' + nonce + '"]' ).forEach( function ( el ) {
					el.setAttribute( 'data-rest-nonce', freshNonce );
				} );

				return attempt( url, method, opts.body, freshNonce );
			} );
		} );
	}

	window.wbGam      = window.wbGam || {};
	window.wbGam.rest = rest;
} )( window );
