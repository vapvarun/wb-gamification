/**
 * How far down the page does the top strip actually go?
 *
 * ANY element this plugin pins to the top of the viewport has to answer this question, and it must
 * answer it by MEASURING — not by assuming.
 *
 * We have now got this wrong twice, the same way:
 *
 *   - Toasts pinned to a top position rendered BEHIND the theme header. The first fix looked for a
 *     header with `position: fixed` or `sticky`. BuddyX's header is `position: static`, so the fix
 *     did nothing on the theme our customers actually run.
 *   - The user status bar hardcoded `top: 48px` (the WP admin-bar height) and shipped a CSS variable
 *     with a comment inviting THEMES to correct it. No theme sets it, including our own, so the bar
 *     landed on top of the header's nav on every real site.
 *
 * Both are the same mistake: guessing what is at the top of somebody else's page. A page can have an
 * admin bar, a static header, a sticky nav, a cookie bar, a promo strip — in any combination, at any
 * height, and it can change on scroll.
 *
 * So this measures whatever is actually up there, whatever its position value, and returns the bottom
 * edge of the lowest thing in the way. It lives in one file because two copies of this logic
 * guarantees the next fix lands in only one of them.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */
( function ( window, document ) {
	'use strict';

	/**
	 * How far into the page an element can start and still count as "pinned to the top".
	 *
	 * Something beginning 200px down is page content, not a bar in the way.
	 */
	var TOP_STRIP = 200;

	/**
	 * Taller than this and it is not a bar, it is an overlay.
	 *
	 * An admin bar is 32px. A theme header is 50-120px. A cookie strip is 60-150px. Stack all three
	 * and you are still under 300. Anything taller that starts at the top of the viewport is a
	 * full-screen panel — an off-canvas menu, a modal backdrop — and pushing content below it means
	 * pushing content off the screen.
	 */
	var MAX_BAR_HEIGHT = 300;

	/**
	 * The bottom edge of the lowest thing occupying the top strip.
	 *
	 * @param {Element} [ignore] Element to exclude — the thing being positioned, and its ancestors.
	 * @return {number} Bottom edge in CSS pixels, or 0 when the top of the page is clear.
	 */
	function topObstructionBottom( ignore ) {
		var candidates = [];
		var adminBar   = document.getElementById( 'wpadminbar' );

		if ( adminBar ) {
			candidates.push( adminBar );
		}

		// The theme header, HOWEVER it is positioned. This is the line the first toast fix missed.
		Array.prototype.push.apply(
			candidates,
			document.querySelectorAll( 'header, .site-header, #masthead' )
		);

		// Anything else pinned across the top: cookie bars, promo strips, sticky navs.
		Array.prototype.forEach.call( document.body.children, function ( el ) {
			var pos = window.getComputedStyle( el ).position;
			if ( pos === 'fixed' || pos === 'sticky' ) {
				candidates.push( el );
			}
		} );

		var lowest = 0;

		candidates.forEach( function ( el ) {
			if ( ignore && ( el === ignore || ignore.contains( el ) || el.contains( ignore ) ) ) {
				return;
			}

			var style = window.getComputedStyle( el );
			if ( style.display === 'none' || style.visibility === 'hidden' ) {
				return;
			}

			var rect = el.getBoundingClientRect();

			// Scrolled away, zero-height, not in the top strip, or too narrow to be a bar.
			if ( rect.height === 0 || rect.bottom <= 0 ) {
				return;
			}
			if ( rect.top > TOP_STRIP ) {
				return;
			}
			if ( rect.width < window.innerWidth * 0.5 ) {
				return;
			}

			// TOO TALL TO BE A BAR.
			//
			// A thing in the way at the top of the page is a BAR: an admin bar, a header, a cookie
			// strip. It is short and wide. A full-height element that happens to start at y=0 is an
			// OVERLAY — an off-canvas mobile menu, a quick-view backdrop — and it is not "in the way"
			// of anything, it is covering everything.
			//
			// Without this, BuddyX's `.mobile-menu-close` and Listora's `.listora-qv-overlay` (both
			// `position: fixed`, both 844px tall, both idle in the DOM at 390px) measured as
			// obstructions 844px deep, and the status bar was pushed clean off the bottom of the
			// screen. Found in the browser at 390px; it would never have shown up on a desktop check.
			if ( rect.height > Math.min( MAX_BAR_HEIGHT, window.innerHeight * 0.3 ) ) {
				return;
			}

			if ( rect.bottom > lowest ) {
				lowest = rect.bottom;
			}
		} );

		return lowest;
	}

	/**
	 * How many pixels of the BOTTOM of the viewport are currently covered by a pinned bar.
	 *
	 * The mirror of topObstructionBottom(), and it exists for the same reason: the CSS can only
	 * offset constants it knows about, and a bottom bar belongs to whatever else is on the page.
	 * A bottom-anchored toast stack sat at `bottom: 1rem` regardless, so on BuddyNext's mobile
	 * layout the "+3 Points" toast landed squarely on the fixed bottom nav — obscuring the primary
	 * navigation for a few seconds after almost every rewarded action.
	 *
	 * WHY THIS DOES NOT REUSE THE TOP SCAN. The top scan walks `document.body.children`, which is
	 * enough for an admin bar or a theme header — they are body-level. Bottom bars are not:
	 * BuddyNext's `.bn-mobile-nav` is `position: fixed` but sits SIX levels deep
	 * (nav > .bn-app > .wb-grid > .container > main.site-content > …), so a body-children scan
	 * misses it completely. Measured in the browser at 390px.
	 *
	 * So this asks the honest question the same way the top scan does — "what is in the way RIGHT
	 * NOW" — but by hit-testing the bottom edge of the viewport instead of guessing at the DOM
	 * shape. elementsFromPoint() is O(1), sees through nesting, and finds whatever actually paints
	 * there: our nav, a theme's live-chat widget, a cookie bar. Three probes (centre and both
	 * corners) so a bar that hugs one side is still found.
	 *
	 * @param {Element} [ignore] Element to exclude — the thing being positioned, and its ancestors.
	 * @return {number} Pixels occupied at the bottom, or 0 when the bottom of the page is clear.
	 */
	function bottomObstructionHeight( ignore ) {
		if ( typeof document.elementsFromPoint !== 'function' ) {
			return 0;
		}

		var vh     = window.innerHeight;
		var vw     = window.innerWidth;
		var probeY = vh - 2;
		var seen   = [];

		[ Math.round( vw / 2 ), 24, vw - 24 ].forEach( function ( x ) {
			Array.prototype.forEach.call( document.elementsFromPoint( x, probeY ), function ( el ) {
				if ( seen.indexOf( el ) === -1 ) {
					seen.push( el );
				}
			} );
		} );

		var highestTop = 0;

		seen.forEach( function ( el ) {
			if ( el === document.body || el === document.documentElement ) {
				return;
			}
			if ( ignore && ( el === ignore || ignore.contains( el ) || el.contains( ignore ) ) ) {
				return;
			}

			var style = window.getComputedStyle( el );
			if ( style.position !== 'fixed' && style.position !== 'sticky' ) {
				return; // In-flow content at the bottom scrolls away; it is not in the way.
			}
			if ( style.display === 'none' || style.visibility === 'hidden' ) {
				return;
			}

			var rect = el.getBoundingClientRect();
			if ( rect.height === 0 || rect.top >= vh ) {
				return;
			}
			if ( rect.width < vw * 0.5 ) {
				return; // A corner bubble is not a bar; the stack can sit beside it.
			}

			// Too tall to be a bar — same reasoning as the top scan: a full-height fixed element
			// is an overlay covering everything, not a strip in the way of anything.
			if ( rect.height > Math.min( MAX_BAR_HEIGHT, vh * 0.3 ) ) {
				return;
			}

			if ( highestTop === 0 || rect.top < highestTop ) {
				highestTop = rect.top;
			}
		} );

		return highestTop > 0 ? Math.max( 0, vh - highestTop ) : 0;
	}

	window.wbGam = window.wbGam || {};
	window.wbGam.topObstructionBottom  = topObstructionBottom;
	window.wbGam.bottomObstructionHeight = bottomObstructionHeight;
} )( window, document );
