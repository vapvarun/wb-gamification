/**
 * Run a block's setup when its element appears -- whenever that is.
 *
 * Every interactive surface in this plugin bound itself exactly once, at DOMContentLoaded:
 *
 *     document.querySelectorAll( '.wb-gam-give-kudos' ).forEach( bind );
 *
 * That is correct for a page the browser loaded, and wrong for a page a ROUTER loaded. Our blocks do
 * not live on their own -- they sit inside host pages (BuddyNext, BuddyX) that navigate client-side,
 * swapping new markup into the document long after DOMContentLoaded has been and gone. Markup that
 * arrives that way carries no event listeners, and nothing was watching for it.
 *
 * The result is not a subtle one. Reproduced in a browser before this file was written: on a fresh
 * load the give-kudos form's submit handler calls preventDefault() and POSTs via fetch. After a
 * client-side swap it is simply not there -- so the browser falls back to a NATIVE form submission
 * and navigates the member away from the page, kudos message in the query string. The block did not
 * degrade; it did something worse than nothing.
 *
 * `badge-showcase` had already solved this locally, with its own MutationObserver that re-ran
 * bindAll() on every mutation anywhere in the body -- correct, but it re-scans the DOM on every
 * keystroke in an unrelated input, and it is one copy of an answer four other files also needed.
 * This is that answer, once:
 *
 *     wbGam.onMount( '.wb-gam-give-kudos', bind );
 *
 * `bind` runs for the elements already on the page, and again for any that appear later. It runs at
 * most ONCE per element (a WeakSet remembers), so a mutation storm cannot double-bind a form and
 * send two kudos.
 *
 * Why not delegate every listener to `document` instead? For pure click handlers, delegation would
 * work and need no observer at all. But half of these surfaces are not listening for clicks -- the
 * status bar subscribes to a realtime channel, the leaderboard registers itself with the heartbeat
 * broker. That is lifecycle, not events, and lifecycle needs to know when the element ARRIVES.
 * One mechanism that covers both beats two that each cover half.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */
( function ( window, document ) {
	'use strict';

	/**
	 * Every registration: { selector, callback, seen }.
	 *
	 * @type {Array<{selector: string, callback: Function, seen: WeakSet}>}
	 */
	var registry = [];

	/**
	 * One observer for all of them. Five blocks watching the DOM independently is five times the work
	 * to learn the same fact.
	 *
	 * @type {MutationObserver|null}
	 */
	var observer = null;

	/**
	 * Run a registration's callback against any matching element inside `root` (and `root` itself),
	 * skipping elements it has already run for.
	 *
	 * @param {Object}  entry The registration.
	 * @param {Element|Document} root  Where to look.
	 * @return {void}
	 */
	function mountWithin( entry, root ) {
		var matches = [];

		// The swapped-in node may BE the block, or merely contain it. A router hands you either.
		if ( root.nodeType === 1 && root.matches && root.matches( entry.selector ) ) {
			matches.push( root );
		}

		if ( root.querySelectorAll ) {
			Array.prototype.push.apply( matches, root.querySelectorAll( entry.selector ) );
		}

		matches.forEach( function ( el ) {
			if ( entry.seen.has( el ) ) {
				return;
			}

			entry.seen.add( el );

			try {
				entry.callback( el );
			} catch ( e ) {
				// One block's setup throwing must not stop the others from mounting. Report it --
				// swallowing it silently is how a dead surface goes unnoticed for a release.
				if ( window.console && window.console.error ) {
					window.console.error( 'wbGam.onMount: setup failed for ' + entry.selector, e );
				}
			}
		} );
	}

	/**
	 * Start watching for nodes added after load. Lazily, on first registration -- a page with none of
	 * our blocks on it pays nothing.
	 *
	 * @return {void}
	 */
	function observe() {
		if ( observer || ! window.MutationObserver ) {
			return;
		}

		observer = new window.MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				Array.prototype.forEach.call( mutation.addedNodes, function ( node ) {
					if ( node.nodeType !== 1 ) {
						return;
					}

					registry.forEach( function ( entry ) {
						mountWithin( entry, node );
					} );
				} );
			} );
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	/**
	 * Run `callback( element )` for every element matching `selector` -- now, and whenever one is
	 * added to the document later.
	 *
	 * Idempotent per element: an element is passed to a given callback at most once, no matter how
	 * many times it is re-parented or how noisy the DOM is.
	 *
	 * @param {string}   selector CSS selector for the block root.
	 * @param {Function} callback Receives the element. Runs once per element.
	 * @return {void}
	 */
	function onMount( selector, callback ) {
		if ( ! selector || typeof callback !== 'function' ) {
			return;
		}

		var entry = {
			selector: selector,
			callback: callback,
			seen: new WeakSet(),
		};

		registry.push( entry );

		function start() {
			mountWithin( entry, document );
			observe();
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	}

	window.wbGam         = window.wbGam || {};
	window.wbGam.onMount = onMount;
} )( window, document );
