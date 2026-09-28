/**
 * WB Gamification: the one toast renderer.
 *
 * Both the front-end toast feed (assets/js/toast.js) and the wp-admin helpers
 * (assets/js/admin-rest-utils.js) call window.wbGam.toast(). Before this there were two copies of
 * the toast DOM, and the admin's built class names the admin stylesheet never styled, so every
 * "Saved" landed as bare text at the bottom of the page.
 *
 * Contract (mirrors the bnToast options form BuddyNext is publishing, so a later switch to
 * BuddyNext's toast is a one-line change):
 *
 *   var handle = wbGam.toast( {
 *       key:       'pts:comment',   // same key while alive = update in place, never a second toast
 *       tone:      'reward',        // reward | achievement | success | danger | info
 *       icon:      'icon-sparkles', // Lucide class; a default per tone otherwise
 *       title:     '+10 Points',
 *       body:      'Leave a comment', // optional second line
 *       href:      '/gamification/',  // optional link; a toast with a link stays until dismissed
 *       hrefLabel: 'See my progress',
 *       persist:   false,
 *       onShow:    function ( el, handle ) {}
 *   } );
 *   handle.el / handle.update( opts ) / handle.dismiss()
 *
 * Rules: three visible at most, 4s then fade, paused while the pointer or focus is on it, the
 * host is a manual popover so it sits in the top layer above any native dialog opened later.
 *
 * @package WB_Gamification
 * @since   1.6.5
 */
( function () {
	'use strict';

	window.wbGam = window.wbGam || {};
	if ( window.wbGam.toast ) {
		return;
	}

	var cfg       = window.wbGamToast || {};
	var i18n      = cfg.i18n || {};
	// wp.i18n where it is loaded (wp-admin and the front end both declare it as a dependency).
	var __        = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) ? window.wp.i18n.__ : function ( s ) { return s; };
	var POSITIONS = [ 'bottom-center', 'bottom-right', 'bottom-left', 'top-center', 'top-right' ];
	var position  = POSITIONS.indexOf( cfg.position ) === -1 ? 'bottom-center' : cfg.position;

	var MAX_VISIBLE = 3;
	var DISMISS_MS  = 4000;
	var RESUME_MS   = 2000;
	var EXIT_MS     = 250;
	var GAP         = 16; // Matches the 1rem breathing room in the stylesheet fallback.

	var DEFAULT_ICON = {
		reward: 'icon-sparkles',
		achievement: 'icon-medal',
		success: 'icon-check',
		danger: 'icon-circle-alert',
		info: 'icon-info',
	};

	var container = null;
	var live      = {}; // key -> handle

	// A string the page localized itself wins; otherwise the translated default.
	function text( key, fallback ) {
		return ( i18n && i18n[ key ] ) || fallback;
	}

	/**
	 * Park the stack clear of whatever is pinned to the viewport edge it is anchored to.
	 *
	 * A plugin cannot know in CSS what a theme pins to the top or bottom (a sticky header, a
	 * mobile tab bar, a chat widget), so the real obstruction is measured (assets/js/top-offset.js)
	 * and set as a custom property. Only set when something is found, so an owner's own override
	 * of the property still wins on a clear page.
	 */
	function applyOffsets() {
		if ( ! container ) {
			return;
		}
		var wbGam = window.wbGam;

		if ( 0 === position.indexOf( 'top' ) ) {
			var top = typeof wbGam.topObstructionBottom === 'function' ? wbGam.topObstructionBottom( container ) : 0;
			if ( top > 0 ) {
				container.style.setProperty( '--wb-gam-toast-offset-top', top + GAP + 'px' );
			} else {
				container.style.removeProperty( '--wb-gam-toast-offset-top' );
			}
			return;
		}

		var bottom = typeof wbGam.bottomObstructionHeight === 'function' ? wbGam.bottomObstructionHeight( container ) : 0;
		if ( bottom > 0 ) {
			container.style.setProperty( '--wb-gam-toast-offset-bottom', bottom + GAP + 'px' );
		} else {
			container.style.removeProperty( '--wb-gam-toast-offset-bottom' );
		}
	}

	function ensureContainer() {
		if ( container && container.isConnected ) {
			return container;
		}

		container = document.createElement( 'div' );
		container.className = 'wb-gam-toasts wb-gam-toasts--' + position;
		container.setAttribute( 'role', 'region' );
		container.setAttribute( 'aria-label', text( 'region', __( 'Notifications', 'wb-gamification' ) ) );
		container.setAttribute( 'aria-live', 'polite' );
		container.setAttribute( 'aria-relevant', 'additions' );
		container.setAttribute( 'popover', 'manual' );
		document.body.appendChild( container );

		applyOffsets();

		// rAF-throttled: a header scrolls away and a bar can change height at a breakpoint.
		var queued = false;
		function queue() {
			if ( queued ) {
				return;
			}
			queued = true;
			window.requestAnimationFrame( function () {
				queued = false;
				applyOffsets();
			} );
		}
		window.addEventListener( 'resize', queue );
		window.addEventListener( 'scroll', queue, { passive: true } );

		return container;
	}

	/**
	 * Move the host to the top of the top layer, above any native dialog opened after it.
	 * A no-op where the Popover API is missing; the z-index token still applies there.
	 */
	function raise() {
		if ( typeof container.showPopover !== 'function' ) {
			return;
		}
		try { container.hidePopover(); } catch ( e ) { /* not showing */ }
		try { container.showPopover(); } catch ( e ) { /* unsupported state */ }
	}

	function arm( handle, ms ) {
		clearTimeout( handle.timer );
		// A merge or a late arm must not start the countdown under the pointer; mouseleave re-arms.
		if ( handle.persist || handle.el.matches( ':hover' ) ) {
			return;
		}
		handle.timer = setTimeout( function () {
			dismiss( handle );
		}, ms );
	}

	function dismiss( handle ) {
		if ( handle.gone ) {
			return;
		}
		handle.gone = true;
		clearTimeout( handle.timer );
		if ( handle.key && live[ handle.key ] === handle ) {
			delete live[ handle.key ];
		}
		handle.el.classList.add( 'wb-gam-toast--exit' );
		setTimeout( function () {
			if ( handle.el.parentNode ) {
				handle.el.remove();
			}
		}, EXIT_MS );
	}

	/** Fill (or refill) the text, link and shape of a toast from an options object. */
	function paint( handle, opts ) {
		var el     = handle.el;
		var title  = el.querySelector( '.wb-gam-toast__message' );
		var detail = el.querySelector( '.wb-gam-toast__detail' );
		var link   = el.querySelector( '.wb-gam-toast__link' );
		var hasBody = !! ( opts.body && String( opts.body ).length );
		var hasLink = !! ( opts.href && /^(\/(?!\/)|https?:\/\/)/.test( opts.href ) );

		title.textContent = opts.title || '';

		detail.textContent = hasBody ? opts.body : '';
		detail.hidden = ! hasBody;

		if ( hasLink ) {
			link.hidden      = false;
			link.href        = opts.href;
			link.textContent = opts.hrefLabel || text( 'view', __( 'View', 'wb-gamification' ) );
		} else {
			link.hidden = true;
		}

		// One line is a pill, two lines take the large radius.
		el.classList.toggle( 'wb-gam-toast--single', ! hasBody && ! hasLink );

		// A toast with a link stays until it is dismissed: it sits at the end of the page, so a
		// keyboard or screen-reader member could not reach the link inside four seconds.
		handle.persist = !! ( opts.persist || hasLink );
	}

	function build( opts ) {
		var tone = opts.tone || 'reward';
		var el   = document.createElement( 'div' );
		el.className = 'wb-gam-toast';
		if ( opts.type ) {
			el.setAttribute( 'data-type', opts.type );
		}

		var disc = document.createElement( 'span' );
		disc.className = 'wb-gam-disc' + ( 'success' === tone || 'danger' === tone || 'info' === tone ? ' wb-gam-disc--' + tone : '' );
		disc.setAttribute( 'aria-hidden', 'true' );
		var icon = document.createElement( 'i' );
		icon.className = opts.icon || DEFAULT_ICON[ tone ] || DEFAULT_ICON.reward;
		disc.appendChild( icon );
		el.appendChild( disc );

		var body = document.createElement( 'div' );
		var message = document.createElement( 'strong' );
		message.className = 'wb-gam-toast__message';
		var detail = document.createElement( 'span' );
		detail.className = 'wb-gam-toast__detail';
		var link = document.createElement( 'a' );
		link.className = 'wb-gam-toast__link';
		body.appendChild( message );
		body.appendChild( detail );
		body.appendChild( link );
		el.appendChild( body );

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'wb-gam-close';
		close.setAttribute( 'aria-label', text( 'dismiss', __( 'Dismiss', 'wb-gamification' ) ) );
		var x = document.createElement( 'i' );
		x.className = 'icon-x';
		x.setAttribute( 'aria-hidden', 'true' );
		close.appendChild( x );
		el.appendChild( close );

		return el;
	}

	function update( handle, opts ) {
		paint( handle, opts );
		arm( handle, DISMISS_MS );
	}

	function toast( opts ) {
		opts = opts || {};
		var host = ensureContainer();

		// Same key while alive: update in place, never stack a second copy.
		if ( opts.key && live[ opts.key ] && ! live[ opts.key ].gone ) {
			update( live[ opts.key ], opts );
			return live[ opts.key ];
		}

		var handle = { key: opts.key || '', el: build( opts ), timer: 0, gone: false, persist: false };
		handle.update  = function ( next ) { update( handle, next || {} ); };
		handle.dismiss = function () { dismiss( handle ); };
		handle.el._wbGamToast = handle;
		paint( handle, opts );

		handle.el.querySelector( '.wb-gam-close' ).addEventListener( 'click', function () { dismiss( handle ); } );
		handle.el.addEventListener( 'mouseenter', function () { clearTimeout( handle.timer ); } );
		handle.el.addEventListener( 'focusin', function () { clearTimeout( handle.timer ); } );
		handle.el.addEventListener( 'mouseleave', function () { arm( handle, RESUME_MS ); } );
		handle.el.addEventListener( 'focusout', function () { arm( handle, RESUME_MS ); } );

		// Three at most: a burst of distinct awards must never cover the page.
		while ( host.children.length >= MAX_VISIBLE ) {
			var oldest = host.firstElementChild;
			if ( oldest._wbGamToast ) {
				dismiss( oldest._wbGamToast );
				oldest.remove();
			} else {
				oldest.remove();
			}
		}

		if ( handle.key ) {
			live[ handle.key ] = handle;
		}
		host.appendChild( handle.el );
		raise();
		arm( handle, DISMISS_MS );

		if ( typeof opts.onShow === 'function' ) {
			window.requestAnimationFrame( function () { opts.onShow( handle.el, handle ); } );
		}

		return handle;
	}

	window.wbGam.toast = toast;
}() );
