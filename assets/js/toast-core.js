/**
 * WB Gamification: the one toast renderer.
 *
 * Both the front-end toast feed (assets/js/toast.js) and the wp-admin helpers
 * (assets/js/admin-rest-utils.js) call window.wbGam.toast(). Before this there were two copies of
 * the toast DOM, and the admin's built class names the admin stylesheet never styled, so every
 * "Saved" landed as bare text at the bottom of the page.
 *
 * Contract: the shared toast standard (TOASTS.md on the standards shelf), the same options
 * BuddyNext's bnToast takes:
 *
 *   var handle = wbGam.toast( {
 *       key:       'pts:comment',   // same key while alive = update in place, never a second toast
 *       tone:      'reward',        // reward | achievement | success | danger | info
 *       icon:      'icon-sparkles', // Lucide class, or a picture URL (badge art); a default per tone otherwise
 *       title:     '+{count} points', // one line; {count} is replaced by the number
 *       count:     { from: 10, to: 25 }, // optional: the number counts up over 400ms
 *       body:      'Leave a comment', // optional second line
 *       href:      '/gamification/',  // optional link; the toast stays longer (8s), it still closes
 *       hrefLabel: 'See my progress',
 *       onShow:    function ( el, handle ) {}
 *   } );
 *   handle.el / handle.update( opts ) / handle.dismiss()
 *
 * On a page where BuddyNext's window.bnToast exists the toast is drawn by it (one stack for every
 * plugin, no two bottom-centre stacks over each other) and this renderer is only the fallback,
 * used in wp-admin and on sites without BuddyNext. `count` and a picture icon go to bnToast only
 * when it says it supports them (bnToast.supports); before that it gets the final number and its
 * own status icon. The rules below apply on both renderers: this file decides
 * when a toast shows and when it goes, BuddyNext only draws it.
 *
 * Rules: two visible at most (one on a phone); the rest wait their turn, 600ms apart, and focus
 * inside a closing toast goes back where it came from. A level-up or other celebration shows
 * alone: toasts wait until it closes. Every toast closes by itself: 4s, or 8s when it carries a link,
 * paused while the pointer or focus is on it. Escape closes the newest one. The host is a manual
 * popover so it sits in the top layer above any native dialog opened later.
 * A toast never covers what the member is using: while a modal or dialog is open (ours or another
 * plugin's), or while the member is typing in a field on the front end (a composer, a comment box),
 * toasts wait. A new one joins the queue and one already showing is taken off screen and put back
 * at the front. They show once the dialog closes or the member leaves the field. Nothing is dropped.
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

	// Two at most on a desktop, one on a phone; the rest wait their turn (TOASTS.md).
	var MAX_VISIBLE = 2;
	// Phones show one toast at a time, in order: three stacked covered about a quarter of the
	// screen, over the form being filled in. Same rule as BuddyNext's stack.
	var ONE_AT_A_TIME = '(max-width: 640px)';
	var waiting       = []; // { opts, real, handle, held }, oldest first.
	var DISMISS_MS  = 4000;
	// Long enough to read two lines and decide on the link; hover or focus pauses it, so a keyboard
	// member who tabs into the toast keeps it. The destination is also in the member's menu.
	var LINK_MS     = 8000;
	var RESUME_MS   = 2000;
	var EXIT_MS     = 250;
	var NEXT_GAP_MS = 350; // With EXIT_MS: a waiting toast follows 600ms after one closes.
	var COUNT_MS    = 400;
	var GAP         = 16; // Matches the 1rem breathing room in the stylesheet fallback.
	var MODAL_CHECK_MS = 500;
	var watchTimer     = 0;

	var DEFAULT_ICON = {
		reward: 'icon-sparkles',
		achievement: 'icon-medal',
		success: 'icon-check',
		danger: 'icon-circle-alert',
		info: 'icon-info',
	};

	var container = null;
	var live      = {}; // key -> handle
	var bnLive    = []; // { opts, cur, pulled, dead }: toasts drawn by BuddyNext, so a dialog or Escape reaches them.

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
		// A toast held off screen behind a dialog starts its countdown when it shows again.
		if ( handle.held || handle.el.matches( ':hover' ) ) {
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
		// A toast held off screen while a dialog was open sits in the queue as itself.
		waiting = waiting.filter( function ( e ) { return e.real !== handle; } );
		// Focus inside the toast (its button or close) goes back where it came from, never to a
		// removed element.
		if ( handle.el.contains( document.activeElement ) ) {
			var back = handle.returnTo && handle.returnTo.isConnected ? handle.returnTo : null;
			if ( back && typeof back.focus === 'function' ) {
				back.focus();
			} else {
				document.activeElement.blur();
			}
		}
		handle.el.classList.add( 'wb-gam-toast--exit' );
		setTimeout( function () {
			if ( handle.el.parentNode ) {
				handle.el.remove();
			}
			setTimeout( nextToast, NEXT_GAP_MS );
		}, EXIT_MS );
	}

	/**
	 * Queue a toast behind the one on screen (phones). Nothing is dropped. The handle forwards
	 * to the real toast once it shows; until then update() edits the queued options.
	 *
	 * @param {Object} opts Same options as toast().
	 * @return {Object} { el, gone, update, dismiss }
	 */
	function waitTurn( opts ) {
		watch();
		var same = opts.key ? waiting.filter( function ( e ) { return e.opts && e.opts.key === opts.key; } )[ 0 ] : null;
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
		return entry.handle;
	}

	/** Show the next waiting toast, if any. Waits while a dialog is open or the member is typing. */
	function nextToast() {
		if ( ! waiting.length || blocked() ) {
			if ( waiting.length ) {
				watch();
			}
			return;
		}
		var entry = waiting.shift();
		if ( entry.bn ) {
			// A BuddyNext-drawn toast taken off screen: drawn again, and the caller's handle follows it.
			var bn    = entry.bn;
			bn.pulled = false;
			bn.cur    = bnDraw( bn.opts );
			if ( bn.cur ) {
				bnLive.push( bn );
			} else {
				bn.dead = true;
			}
			return;
		}
		if ( entry.held ) {
			// A toast taken off screen while a dialog was open: the same element and handle come
			// back, so the caller's handle still updates and dismisses it.
			entry.real.held = false;
			ensureContainer().appendChild( entry.real.el );
			raise();
			arm( entry.real, entry.real.ms );
			return;
		}
		entry.real      = toast( entry.opts, true );
		entry.handle.el = entry.real.el;
	}

	/**
	 * Is a modal or dialog open on the page (ours or another plugin's)? Our own toast host is a
	 * popover, not a dialog, and is skipped. A dialog counts only when it is visible on screen: a
	 * closed drawer left in the DOM (display:none, or parked off-screen with a transform, like
	 * WPMediaVerse's chat panel) must not hold the queue forever.
	 *
	 * @return {boolean}
	 */
	function modalOpen() {
		var nodes = document.querySelectorAll( 'dialog[open], [aria-modal="true"], [role="dialog"]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var node = nodes[ i ];
			if ( container && container.contains( node ) ) {
				continue;
			}
			if ( ! onScreen( node ) || 'hidden' === window.getComputedStyle( node ).visibility ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Is the member writing something on the front end? True while the focused field holds text
	 * not yet sent: a toast then waits, so it never sits over the composer's Post button or the
	 * phone keyboard's edge. Once the text is sent the field empties (focus often stays in it, as
	 * BuddyNext's comment box does) and the waiting toast shows. An empty field holds nothing, so a
	 * page that focuses a box on load does not hold every toast. Not in wp-admin, where the toast
	 * confirms the save the owner just made from that field.
	 *
	 * @return {boolean}
	 */
	function editing() {
		var el = document.activeElement;
		if ( ! el || ! document.body || document.body.classList.contains( 'wp-admin' ) || ( container && container.contains( el ) ) ) {
			return false;
		}
		if ( el.isContentEditable ) {
			return '' !== el.textContent.trim();
		}
		var field = 'TEXTAREA' === el.tagName || ( 'INPUT' === el.tagName && /^(text|search|email|url|tel|number)$/.test( el.type ) );
		return field && '' !== el.value.trim();
	}

	/**
	 * Must toasts wait? Behind an open dialog, while the member writes, and while the page is
	 * still loading. A toast from the page-load seed arrives before BuddyNext's toast module has
	 * replaced its stub (shell/extras.js), which queues the call and returns no handle, so that
	 * toast could not be closed with Escape or held behind a dialog. Waiting the few
	 * milliseconds until the page has parsed hands it to the real function instead.
	 *
	 * @return {boolean}
	 */
	function blocked() {
		return 'loading' === document.readyState || buddyNextStarting() || modalOpen() || momentOpen() || editing();
	}

	/**
	 * Is BuddyNext's early stub (shell/extras.js) still standing in for its real toast? It queues
	 * calls in window.__bnToastQueue until shell/dialog.js replaces it, which can be just after the
	 * page has parsed. Toasts wait those milliseconds so they are drawn by BuddyNext's stack, not ours.
	 *
	 * @return {boolean}
	 */
	function buddyNextStarting() {
		var f = window.bnToast;
		return 'function' === typeof f && ! isBuddyNextToast() && -1 !== String( f ).indexOf( '__bnToastQueue' );
	}

	/**
	 * Is a celebration (level up, streak, promotion) on screen? It is an announcement, not a
	 * dialog, so modalOpen() does not see it; the biggest moment shows alone and the summary
	 * toast follows once it closes.
	 *
	 * @return {boolean}
	 */
	function momentOpen() {
		var m = document.querySelector( '.wb-gam-moment:not([hidden])' );
		return !! m && onScreen( m );
	}

	/**
	 * Does the element have a box that overlaps the viewport?
	 *
	 * @param {Element} node Element.
	 * @return {boolean}
	 */
	function onScreen( node ) {
		var r = node.getBoundingClientRect();
		return r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 && r.top < window.innerHeight && r.left < window.innerWidth;
	}

	/**
	 * While a toast is showing or waiting, check every half second for an open dialog or a field
	 * being typed in. Showing toasts are taken off screen (front of the queue, newest last so order
	 * is kept); when the page is clear again the queue drains. The check stops once nothing is
	 * showing or waiting.
	 */
	function watch() {
		if ( watchTimer ) {
			return;
		}
		watchTimer = setInterval( function () {
			var showingEls = container ? Array.prototype.slice.call( container.children ) : [];
			bnLive = bnLive.filter( bnAlive );
			if ( ! showingEls.length && ! bnLive.length && ! waiting.length ) {
				clearInterval( watchTimer );
				watchTimer = 0;
				return;
			}
			if ( blocked() ) {
				var held = [];
				showingEls.forEach( function ( el ) {
					var h = el._wbGamToast;
					if ( ! h || h.gone || el.classList.contains( 'wb-gam-toast--exit' ) ) {
						return;
					}
					clearTimeout( h.timer );
					h.held = true;
					el.remove();
					held.push( { opts: null, real: h, handle: h, held: true } );
				} );
				bnLive.forEach( function ( e ) {
					e.pulled = true;
					e.cur.dismiss( true );
					held.push( { opts: null, bn: e, held: true } );
				} );
				bnLive  = [];
				waiting = held.concat( waiting );
				return;
			}
			// The page is clear: show what waited, one at a time on a phone, up to three otherwise.
			var phone = window.matchMedia( ONE_AT_A_TIME ).matches;
			var shown = function () {
				return ( container ? container.children.length : 0 ) + bnLive.length;
			};
			while ( waiting.length && ( phone ? ! shown() : shown() < MAX_VISIBLE ) ) {
				nextToast();
				if ( phone ) {
					break;
				}
			}
		}, MODAL_CHECK_MS );
	}

	/** Fill (or refill) the text, link and shape of a toast from an options object. */
	function paint( handle, opts ) {
		var el     = handle.el;
		var title  = el.querySelector( '.wb-gam-toast__message' );
		var detail = el.querySelector( '.wb-gam-toast__detail' );
		var link   = el.querySelector( '.wb-gam-toast__link' );
		var hasBody = !! ( opts.body && String( opts.body ).length );
		var hasLink = !! ( opts.href && /^(\/(?!\/)|https?:\/\/)/.test( opts.href ) );

		setTitle( handle, title, opts );
		setIcon( el.querySelector( '.wb-gam-disc' ), opts );

		detail.textContent = hasBody ? opts.body : '';
		detail.hidden = ! hasBody;

		if ( hasLink ) {
			link.hidden      = false;
			link.href        = opts.href;
			link.textContent = opts.hrefLabel || text( 'view', __( 'View', 'wb-gamification' ) );
		} else {
			link.hidden = true;
		}


		// Every toast closes by itself; one with a link stays longer so it can be read and followed.
		handle.ms = hasLink ? LINK_MS : DISMISS_MS;
	}

	/** A picture URL (badge art) rather than an icon class name. */
	function isImage( icon ) {
		return /^(\/(?!\/)|https?:\/\/)/.test( String( icon || '' ) );
	}

	function setIcon( disc, opts ) {
		var tone = opts.tone || 'reward';
		var icon = opts.icon || DEFAULT_ICON[ tone ] || DEFAULT_ICON.reward;
		var want = isImage( icon ) ? 'img' : 'i';
		var node = disc.firstElementChild;
		if ( ! node || node.tagName.toLowerCase() !== want ) {
			node = document.createElement( want );
			disc.textContent = '';
			disc.appendChild( node );
		}
		disc.classList.toggle( 'wb-gam-disc--image', 'img' === want );
		if ( 'img' === want ) {
			node.alt = ''; // Decorative: the title carries the meaning.
			if ( node.getAttribute( 'src' ) !== icon ) {
				node.src = icon;
			}
		} else {
			node.className = icon;
		}
	}

	function reducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/** The title with its {count} token as the final number, for readers and for stacks that do not count. */
	function countText( opts ) {
		var c = opts.count;
		return String( opts.title || '' ).replace( '{count}', c ? Number( c.to ).toLocaleString() : '' );
	}

	/**
	 * Fill the title. With `count: { from, to }` and a {count} token the number counts up over
	 * 400ms in an aria-hidden span, while a hidden copy holds the final text, so a screen reader
	 * hears it once. Reduced motion shows the final number at once.
	 */
	function setTitle( handle, title, opts ) {
		var c = opts.count;
		var raw = String( opts.title || '' );
		if ( handle.countFrame ) {
			window.cancelAnimationFrame( handle.countFrame );
		}
		if ( ! c || -1 === raw.indexOf( '{count}' ) ) {
			title.textContent = raw;
			return;
		}
		var parts = raw.split( '{count}' );
		var num   = document.createElement( 'span' );
		num.className = 'wb-gam-count';
		num.setAttribute( 'aria-hidden', 'true' );
		var shown = document.createElement( 'span' );
		shown.setAttribute( 'aria-hidden', 'true' );
		shown.append( parts[ 0 ], num, parts.slice( 1 ).join( '{count}' ) );
		var said = document.createElement( 'span' );
		said.className = 'screen-reader-text';
		said.textContent = countText( opts );
		title.textContent = '';
		title.append( shown, said );

		var from = Number( c.from ) || 0;
		var to   = Number( c.to ) || 0;
		if ( reducedMotion() || from === to ) {
			num.textContent = to.toLocaleString();
			return;
		}
		var start = 0;
		var step  = function ( t ) {
			start = start || t;
			var k = Math.min( 1, ( t - start ) / COUNT_MS );
			num.textContent = Math.round( from + ( to - from ) * ( 1 - Math.pow( 1 - k, 3 ) ) ).toLocaleString();
			if ( k < 1 ) {
				handle.countFrame = window.requestAnimationFrame( step );
			}
		};
		num.textContent = from.toLocaleString();
		handle.countFrame = window.requestAnimationFrame( step );
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
		el.appendChild( body );
		el.appendChild( link ); // Its own column: a small button on the right.

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
		opts = sayable( opts || {} );
		if ( ! opts ) {
			return; // Nothing to say: keep what is on screen.
		}
		paint( handle, opts );
		arm( handle, handle.ms );
	}

	// BuddyNext's status set. Its disc draws only these four icons, so a per-event icon class is
	// dropped there on purpose: one look for every plugin on a BuddyNext page.
	var BN_TYPE = { reward: 'achievement', achievement: 'achievement', success: 'success', danger: 'error', info: 'info' };

	/**
	 * Draw one toast with BuddyNext's stack (window.bnToast). The lifetime is always passed, so
	 * BuddyNext never keeps a toast of ours open until it is closed by hand (its default for a
	 * toast with a link).
	 *
	 * @param {Object} opts Same options as toast().
	 * @return {?Object} BuddyNext's handle, or null when it did not take the toast.
	 */
	function bnDraw( opts ) {
		var hasLink = !! ( opts.href && /^(\/(?!\/)|https?:\/\/)/.test( opts.href ) );
		// BuddyNext counts and draws pictures only once its rebuilt stack says so; before that the
		// final number goes in the title and the type's own icon shows.
		var can  = window.bnToast.supports || {};
		var type = BN_TYPE[ opts.tone || 'reward' ] || 'info';
		var h = window.bnToast( {
			key: opts.key || '',
			title: can.count ? String( opts.title || '' ) : countText( opts ),
			count: can.count ? opts.count : undefined,
			icon: can.icon && isImage( opts.icon ) ? opts.icon : undefined,
			body: opts.body || '',
			type: can.award && 'achievement' === type ? 'award' : type,
			href: hasLink ? opts.href : '',
			linkLabel: opts.hrefLabel || '',
			persist: false,
			timeout: hasLink ? LINK_MS : DISMISS_MS,
		} );
		// A toast BuddyNext is holding in line on a phone (h.waiting) is alive, not dead: it
		// still takes updates, so a points burst keeps merging into one toast.
		if ( ! h || ( ! h.el && ! h.waiting ) ) {
			return null;
		}
		if ( h.el && typeof opts.onShow === 'function' ) {
			window.requestAnimationFrame( function () { opts.onShow( h.el, h ); } );
		}
		return h;
	}

	/** Is a BuddyNext-drawn toast still on screen or in BuddyNext's line? */
	function bnAlive( e ) {
		return ! e.pulled && ! e.dead && ( e.cur.el ? e.cur.el.isConnected : !! e.cur.waiting );
	}

	/**
	 * Show the toast through BuddyNext's stack so two plugins never draw two bottom-centre stacks
	 * over each other. The handle is adapted to ours and survives being taken off screen behind a
	 * dialog: while it waits, update() edits what will be drawn. A call BuddyNext did not take
	 * returns a dead handle, so the caller simply makes the next toast fresh.
	 *
	 * @param {Object} opts Same options as toast().
	 * @return {Object} { el, gone, update, dismiss }
	 */
	function viaBuddyNext( opts ) {
		var h = bnDraw( opts );
		if ( ! h ) {
			return { el: null, gone: true, update: function () {}, dismiss: function () {} };
		}
		var e = { opts: opts, cur: h, pulled: false, dead: false };
		bnLive.push( e );
		watch();
		return {
			get el() { return e.pulled || e.dead ? null : e.cur.el; },
			get gone() { return e.pulled ? false : ! bnAlive( e ); },
			update: function ( next ) {
				var merged = sayable( Object.assign( {}, e.opts, next || {} ) );
				if ( ! merged ) {
					return; // Nothing to say: keep what is on screen.
				}
				e.opts = merged;
				next   = merged;
				if ( ! e.pulled && ! e.dead ) {
					var can = window.bnToast.supports || {};
					var out = Object.assign( {}, next || {} );
					if ( ! can.count && 'title' in out ) {
						out.title = countText( e.opts );
						delete out.count;
					}
					if ( ! can.icon || ! isImage( out.icon ) ) {
						delete out.icon;
					}
					e.cur.update( out );
				}
			},
			dismiss: function () {
				if ( e.pulled ) {
					waiting = waiting.filter( function ( w ) { return w.bn !== e; } );
					e.dead = true;
				} else {
					e.cur.dismiss();
				}
			},
		};
	}

	/**
	 * Is window.bnToast BuddyNext's own toast (which takes an options object)? Other plugins define
	 * a text-only window.bnToast when BuddyNext is absent (Jetonomy's header.js), and BuddyNext's
	 * early stub is text-only too; handing either an object printed "[object Object]". BuddyNext's
	 * real one is the named function `bnToast` (shell/dialog.js) and, from BuddyNext 1.3.1, says
	 * what it supports.
	 *
	 * @return {boolean}
	 */
	function isBuddyNextToast() {
		var f = window.bnToast;
		return 'function' === typeof f && ( !! f.supports || 'bnToast' === f.name );
	}

	/**
	 * @param {Object}  opts      See the file header.
	 * @param {boolean} fromQueue Internal: the queue is handing over its next toast.
	 */
	/**
	 * A toast always says something. An empty title takes the body's place; with neither there is
	 * nothing to show, so no toast is drawn (an empty pill with only an icon and a close button,
	 * on either stack, is never acceptable).
	 *
	 * @param {Object} opts Toast options.
	 * @return {?Object} Options with a title, or null.
	 */
	function sayable( opts ) {
		if ( '' !== countText( opts ).trim() ) {
			return opts;
		}
		var body = String( opts.body || '' ).trim();
		if ( '' === body ) {
			return null;
		}
		return Object.assign( {}, opts, { title: body, body: '', count: undefined } );
	}

	var SILENT = { el: null, gone: true, update: function () {}, dismiss: function () {} };

	function toast( opts, fromQueue ) {
		opts = sayable( opts || {} );
		if ( ! opts ) {
			return SILENT;
		}
		var onBn = isBuddyNextToast();

		// Same key while alive: update in place, never stack a second copy (BuddyNext does this itself).
		if ( ! onBn && opts.key && live[ opts.key ] && ! live[ opts.key ].gone ) {
			update( live[ opts.key ], opts );
			return live[ opts.key ];
		}

		// A dialog is open, the member is typing, or toasts are already waiting: join the queue.
		// Before the BuddyNext hand-off, so the rule holds whichever stack draws the toast.
		if ( ! fromQueue && ( blocked() || waiting.some( function ( e ) { return e.held; } ) ) ) {
			return waitTurn( opts );
		}

		if ( onBn ) {
			return viaBuddyNext( opts );
		}
		var host = ensureContainer();

		// Full (one on a phone, two otherwise): wait in line. A toast is never dropped.
		var showing = Array.prototype.filter.call( host.children, function ( c ) { return ! c.classList.contains( 'wb-gam-toast--exit' ); } ).length;
		if ( showing >= ( window.matchMedia( ONE_AT_A_TIME ).matches ? 1 : MAX_VISIBLE ) || ( ! fromQueue && waiting.length ) ) {
			return waitTurn( opts );
		}

		var handle = { key: opts.key || '', el: build( opts ), timer: 0, gone: false, ms: DISMISS_MS };
		handle.update  = function ( next ) { update( handle, next || {} ); };
		handle.dismiss = function () { dismiss( handle ); };
		handle.el._wbGamToast = handle;
		paint( handle, opts );

		handle.el.querySelector( '.wb-gam-close' ).addEventListener( 'click', function () { dismiss( handle ); } );
		handle.el.addEventListener( 'mouseenter', function () { clearTimeout( handle.timer ); } );
		handle.el.addEventListener( 'focusin', function ( e ) {
			clearTimeout( handle.timer );
			if ( e.relatedTarget && ! handle.el.contains( e.relatedTarget ) ) {
				handle.returnTo = e.relatedTarget;
			}
		} );
		handle.el.addEventListener( 'mouseleave', function () { arm( handle, RESUME_MS ); } );
		handle.el.addEventListener( 'focusout', function () { arm( handle, RESUME_MS ); } );

		if ( handle.key ) {
			live[ handle.key ] = handle;
		}
		host.appendChild( handle.el );
		raise();
		arm( handle, handle.ms );
		watch();

		if ( typeof opts.onShow === 'function' ) {
			window.requestAnimationFrame( function () { opts.onShow( handle.el, handle ); } );
		}

		return handle;
	}

	// Escape closes the newest toast. Not while a dialog is open: Escape belongs to the dialog then.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key || e.defaultPrevented || modalOpen() ) {
			return;
		}
		var bn = bnLive.filter( function ( b ) { return bnAlive( b ) && b.cur.el; } ).pop();
		if ( bn ) {
			bn.cur.dismiss();
			return;
		}
		var last = container && container.lastElementChild;
		if ( last && last._wbGamToast && ! last._wbGamToast.gone ) {
			dismiss( last._wbGamToast );
		}
	} );

	window.wbGam.toast = toast;
}() );
