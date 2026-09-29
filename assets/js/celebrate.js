/**
 * WB Gamification: the celebration utility.
 *
 * One small confetti burst for a Moment card (tier 'full', 30 pieces) or an achievement toast
 * (tier 'small', 12 pieces): window.wbGam.celebrate( hostElement, tier ).
 *
 * Built to fit any community:
 *   - Colour is not computed here. Each piece takes a class (--1 to --5) that popups.css points at
 *     five stops of the community's own accent (--wb-gam-cf-*), so the burst matches the brand and
 *     flips with the theme by itself. Never a rainbow.
 *   - No library. Web Animations on transform and opacity only, 30 elements at most, removed when
 *     done, hidden from assistive technology.
 *   - Live events only, one burst at a time, at most one every ten seconds.
 *   - Off under reduced motion (the Moment card gets a soft ring instead) and through one filter,
 *     wb_gam_celebration_style, for a community where confetti does not fit.
 *   - Silent. No sound.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */
( function () {
	'use strict';

	window.wbGam = window.wbGam || {};
	if ( window.wbGam.celebrate ) {
		return;
	}

	var cfg         = window.wbGamCelebrate || {};
	var COOLDOWN_MS = 10000;
	var lastAt      = 0;

	function reducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/** A soft ring on the card instead of confetti, for reduced motion. */
	function ring( card ) {
		if ( ! card ) {
			return;
		}
		card.classList.add( 'is-glow' );
		setTimeout( function () { card.classList.remove( 'is-glow' ); }, 900 );
	}

	/**
	 * Where the confetti layer lives. A Moment card already carries its own layer inside the
	 * full-screen container. A toast burst uses a fixed manual popover, so it paints above the
	 * toast host (also in the top layer).
	 */
	function layerFor( host, full ) {
		if ( full ) {
			var own = host.querySelector( '.wb-gam-confetti' );
			if ( own ) {
				return { el: own, temp: false };
			}
		}
		var layer = document.createElement( 'div' );
		layer.className = 'wb-gam-confetti wb-gam-confetti--fixed';
		layer.setAttribute( 'aria-hidden', 'true' );
		layer.setAttribute( 'popover', 'manual' );
		document.body.appendChild( layer );
		if ( typeof layer.showPopover === 'function' ) {
			try { layer.showPopover(); } catch ( e ) { /* unsupported state */ }
		}
		return { el: layer, temp: true };
	}

	function burst( layer, ox, oy, count, full ) {
		var pending = count;
		var h       = window.innerHeight;

		for ( var i = 0; i < count; i++ ) {
			var piece = document.createElement( 'i' );
			var dot   = Math.random() < 0.4;
			var size  = 6 + Math.random() * 5;
			piece.className = 'wb-gam-cf wb-gam-cf--' + ( 1 + ( i % 5 ) ) + ( dot ? ' wb-gam-cf--dot' : '' );
			piece.setAttribute( 'aria-hidden', 'true' );
			piece.style.cssText = 'left:' + ox + 'px;top:' + oy + 'px;width:' + size + 'px;height:' + ( dot ? size : size * 1.7 ) + 'px';
			layer.el.appendChild( piece );

			var angle = -Math.PI / 2 + ( Math.random() - 0.5 ) * Math.PI * ( full ? 1.25 : 0.85 );
			var speed = ( full ? 150 : 90 ) + Math.random() * ( full ? 210 : 100 );
			var dx    = Math.cos( angle ) * speed;
			var dy    = Math.sin( angle ) * speed;
			var fall  = h * 0.4 + Math.random() * 60;
			var spin  = ( Math.random() - 0.5 ) * 600;

			var run = piece.animate(
				[
					{ transform: 'translate(0,0) rotate(0deg)', opacity: 1 },
					{ transform: 'translate(' + dx * 0.7 + 'px,' + dy + 'px) rotate(' + spin * 0.5 + 'deg)', opacity: 1, offset: 0.4 },
					{ transform: 'translate(' + dx + 'px,' + ( dy + fall ) + 'px) rotate(' + spin + 'deg)', opacity: 0 },
				],
				{
					duration: ( full ? 1100 : 850 ) + Math.random() * 350,
					easing: 'cubic-bezier(0.22, 0.7, 0.4, 1)',
					fill: 'forwards',
				}
			);
			run.onfinish = function ( el ) {
				return function () {
					el.remove();
					pending--;
					if ( 0 === pending && layer.temp ) {
						layer.el.remove();
					}
				};
			}( piece );
		}
	}

	/**
	 * @param {Element} host  The Moment container (tier 'full') or the toast element (tier 'small').
	 * @param {string}  tier  'full' or 'small'.
	 */
	window.wbGam.celebrate = function ( host, tier ) {
		if ( 'none' === cfg.style || ! host || document.hidden ) {
			return;
		}

		var full = 'full' === tier;

		if ( reducedMotion() ) {
			if ( full ) {
				ring( host.querySelector( '.wb-gam-moment__card' ) );
			}
			return;
		}

		var now = Date.now();
		if ( now - lastAt < COOLDOWN_MS ) {
			return;
		}
		lastAt = now;

		var anchor = full ? host.querySelector( '.wb-gam-moment__card' ) : host;
		if ( ! anchor ) {
			return;
		}
		var rect  = anchor.getBoundingClientRect();
		var layer = layerFor( host, full );

		burst( layer, rect.left + rect.width / 2, rect.top + ( full ? rect.height * 0.25 : rect.height / 2 ), full ? 30 : 12, full );
	};
}() );
