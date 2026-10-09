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
 * On a page where BuddyNext's window.bnToast exists the call is handed to it (one stack for every
 * plugin, no two bottom-centre stacks over each other) and this renderer is only the fallback,
 * used in wp-admin and on sites without BuddyNext. bnToast draws its own four status icons, so
 * a per-event icon is ignored there.
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
	// Phones show one toast at a time, in order: three stacked covered about a quarter of the
	// screen, over the form being filled in. Same rule as BuddyNext's stack.
	var ONE_AT_A_TIME = '(max-width: 640px)';
	var waiting       = []; // { opts, real, handle }, oldest first.
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
			nextToast();
		}, EXIT_MS );
	}

	/**
	 * Queue a toast behind the one on screen (phones). Nothing is dropped. The handle forwards
	 * to the real toast once it shows; until then update() edits the queued options.
	 *
	 * @param {Object}      opts Same options as toast().
	 * @param {HTMLElement} host The stack.
	 * @return {Object} { el, gone, update, dismiss }
	 */
	function waitTurn( opts, host ) {
		var same = opts.key ? waiting.filter( function ( e ) { return e.opts.key === opts.key; } )[ 0 ] : null;
		if ( same ) {
			same.opts = opts;
			return same.handle;
		}
		var entry = { opts: opts, real: null };
		entry.handle = {
			el: null,
			get gone() { return entry.real ? entry.real.gone : -1 === waiting.indexOf( entry ); },
			update: function ( next ) {
				if ( entry.real ) {
					entry.real.update( next );
				} else {
					entry.opts = Object.assign( {}, entry.opts, next || {} );
				}
			},
			dismiss: function () {
				if ( entry.real ) {
					entry.real.dismiss();
				} else if ( -1 !== waiting.indexOf( entry ) ) {
					waiting.splice( waiting.indexOf( entry ), 1 );
				}
			},
		};
		waiting.push( entry );

		// The toast on screen must not hold the line: one that would stay open (it carries a
		// link) now times out like the rest.
		var showing = host.lastElementChild && host.lastElementChild._wbGamToast;
		if ( showing && showing.persist ) {
			showing.persist = false;
			arm( showing, DISMISS_MS );
		}
		return entry.handle;
	}

	/** Show the next waiting toast, if any. */
	function nextToast() {
		var entry = waiting.shift();
		if ( entry ) {
			entry.real      = toast( entry.opts );
			entry.handle.el = entry.real.el;
		}
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

	// BuddyNext's status set. Its disc draws only these four icons, so a per-event icon class is
	// dropped there on purpose: one look for every plugin on a BuddyNext page.
	var BN_TYPE = { reward: 'achievement', achievement: 'achievement', success: 'success', danger: 'error', info: 'info' };

	/**
	 * Show the toast through BuddyNext's stack (window.bnToast, same option shape) so two plugins
	 * never draw two bottom-centre stacks over each other. The handle is adapted to ours: `gone`
	 * follows the element, and a call BuddyNext only queued (its module has not loaded yet)
	 * returns a dead handle, so the caller simply makes the next toast fresh.
	 *
	 * @param {Object} opts Same options as toast().
	 * @return {Object} { el, gone, update, dismiss }
	 */
	function viaBuddyNext( opts ) {
		// Same rule as paint(): a toast with a usable link stays until dismissed. Passing
		// persist:false would override BuddyNext's own default and time the link out.
		var hasLink = !! ( opts.href && /^(\/(?!\/)|https?:\/\/)/.test( opts.href ) );
		var h = window.bnToast( {
			key: opts.key || '',
			title: opts.title || '',
			body: opts.body || '',
			type: BN_TYPE[ opts.tone || 'reward' ] || 'info',
			href: hasLink ? opts.href : '',
			linkLabel: opts.hrefLabel || '',
			persist: !! ( opts.persist || hasLink ),
		} );

		// A toast BuddyNext is holding in line on a phone (h.waiting) is alive, not dead: it
		// still takes updates, so a points burst keeps merging into one toast.
		if ( ! h || ( ! h.el && ! h.waiting ) ) {
			return { el: null, gone: true, update: function () {}, dismiss: function () {} };
		}
		if ( h.el && typeof opts.onShow === 'function' ) {
			window.requestAnimationFrame( function () { opts.onShow( h.el, h ); } );
		}
		return {
			get el() { return h.el; },
			get gone() { return h.el ? ! h.el.isConnected : ! h.waiting; },
			update: function ( next ) { h.update( next || {} ); },
			dismiss: function () { h.dismiss(); },
		};
	}

	function toast( opts ) {
		opts = opts || {};
		if ( typeof window.bnToast === 'function' ) {
			return viaBuddyNext( opts );
		}
		var host = ensureContainer();

		// Same key while alive: update in place, never stack a second copy.
		if ( opts.key && live[ opts.key ] && ! live[ opts.key ].gone ) {
			update( live[ opts.key ], opts );
			return live[ opts.key ];
		}

		if ( host.children.length && window.matchMedia( ONE_AT_A_TIME ).matches ) {
			return waitTurn( opts, host );
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
