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
 *   - level_up, cohort_promotion, community_goal are Moment cards, owned by the
 *     Interactivity store (assets/interactivity/notifications.js). Skipped here.
 *   - points, badge, challenge, kudos, welcome: collected for 1.5s and shown as ONE summary toast
 *     ("+17 Points and a new badge" / "Publish a blog post, Conversation Starter"), which keeps
 *     adding up while it is on screen; the number counts up. The badge's own picture is the icon.
 *     No confetti here: only a level-up celebrates (TOASTS.md). A level-up Moment shows alone
 *     first; toast-core holds the summary until it closes.
 *   - submission, skip, the "since your last visit" summary: a toast of their own.
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

	// A level-up (and the rarer promotion / community goal) shows alone; a streak day joins the summary.
	var MOMENT_TYPES = [ 'level_up', 'cohort_promotion', 'community_goal' ];
	// Awards that land together (a post that pays points, finishes a streak day and earns a badge)
	// become ONE summary toast instead of a pile (TOASTS.md).
	var REWARD_TYPES = [ 'points', 'badge', 'challenge', 'kudos', 'welcome', 'streak_milestone' ];
	// Bonus points whose reason is already named by its own item (the challenge, the streak).
	var BONUS_ACTIONS = [ 'challenge_completed', 'streak_milestone' ];
	var COLLECT_MS   = 1500;
	var SUMMARY_KEY  = 'wbgam-awards';

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

	var pending = [];   // Awards collected in the current 1.5s window.
	var collectTimer = 0;
	var sum = null;     // The summary on screen (or waiting), still taking awards.

	/**
	 * A phrase with a count. The server sends it for the recipient's language, one string per count
	 * (an array indexed 0..N, built with _n() so every plural form of the language is right); a plain
	 * string is the fallback for pages that did not get the table. Counts past the table use its last form.
	 */
	function fmt( key, fallback, n ) {
		var v = wbGamToast.i18n && wbGamToast.i18n[ key ];
		if ( Array.isArray( v ) ) {
			v = v[ Math.min( n, v.length - 1 ) ];
		}
		return String( v || fallback ).replace( '%d', n ).replace( '%s', n );
	}

	function collect( toast ) {
		pending.push( toast );
		if ( ! collectTimer ) {
			collectTimer = setTimeout( flush, COLLECT_MS );
		}
	}

	function flush() {
		collectTimer = 0;
		var items = pending;
		pending = [];
		if ( ! items.length ) {
			return;
		}
		if ( ! sum || ! sum.handle || sum.handle.gone ) {
			sum = { handle: null, total: 0, shown: 0, unitMany: '', unitOne: '', locale: '', firstMessage: '', pointItems: 0, labels: [], counts: {}, badges: [], challenges: [], kudos: [], streaks: [], singles: [], welcome: null, image: '', url: '', urlLabel: '' };
		}
		items.forEach( add );
		var opts = summarise();
		if ( sum.handle ) {
			sum.handle.update( opts );
		} else {
			sum.handle = window.wbGam.toast( opts );
		}
		sum.shown = sum.total;
		// The rows are DISPLAYED once the summary is on screen, not when it is handed over: it can wait
		// behind a level-up card or a dialog, and a reload then must still find them on the server.
		whenShown( sum.handle, items.map( function ( t ) { return t._id; } ) );
	}

	function label( text ) {
		if ( ! text ) {
			return;
		}
		if ( ! sum.counts[ text ] ) {
			sum.labels.push( text );
		}
		sum.counts[ text ] = ( sum.counts[ text ] || 0 ) + 1;
	}

	function add( t ) {
		sum.singles.push( t );
		if ( t.url && ! sum.url ) {
			sum.url      = t.url;
			sum.urlLabel = t.url_label || '';
		}
		switch ( t.type ) {
			case 'points':
				var n = parseInt( t.points, 10 );
				if ( isNaN( n ) ) {
					var m = ( t.message || '' ).match( /\+(\d+)/ );
					n = m ? parseInt( m[ 1 ], 10 ) : 0;
				}
				sum.total += n;
				sum.pointItems++;
				sum.unitMany     = sum.unitMany || t.unit_many || toastI18n( 'points', 'points' );
				sum.unitOne      = sum.unitOne || t.unit_one || '';
				sum.locale       = sum.locale || t.locale || '';
				sum.firstMessage = sum.firstMessage || t.message || '';
				if ( BONUS_ACTIONS.indexOf( t.action ) === -1 ) {
					label( t.detail );
				}
				return;
			case 'streak_milestone':
				sum.streaks.push( t.message );
				return;
			case 'badge':
				sum.badges.push( t.name || t.message );
				sum.image = sum.image || t.image || '';
				return;
			case 'challenge':
				sum.challenges.push( t.name || t.message );
				return;
			case 'kudos':
				sum.kudos.push( t.name ? fmt( 'kudosFrom', 'Kudos from %s', t.name ) : t.message );
				return;
			case 'welcome':
				sum.welcome = t;
				return;
		}
	}

	/** One title under 40 characters, one detail line, one optional button. */
	function summarise() {
		var extras = [
			[ sum.badges, 'andBadges', 'and a new badge', 'and %d new badges' ],
			[ sum.challenges, 'andChallenges', 'and a challenge done', 'and %d challenges done' ],
			[ sum.kudos, 'andKudos', 'and kudos', 'and kudos' ],
		].filter( function ( e ) { return e[ 0 ].length; } );
		var others = sum.badges.length + sum.challenges.length + sum.kudos.length;
		var title;
		var body;
		var count;

		if ( sum.total > 0 ) {
			if ( 1 === sum.pointItems && ! sum.shown && sum.firstMessage ) {
				title = sum.firstMessage; // "+1 Point": the server already chose singular or plural.
			} else {
				title = '+{count} ' + sum.unitMany;
				count = { from: sum.shown, to: sum.total };
				if ( sum.locale ) {
					count.locale = sum.locale;
				}
				if ( sum.unitOne && sum.unitMany && sum.unitOne !== sum.unitMany ) {
					// The frame that reads 1 says "Point", not "Points".
					count.unitOne  = sum.unitOne;
					count.unitMany = sum.unitMany;
				}
			}
			if ( sum.welcome ) {
				// The welcome is the detail line: at 360px next to its button, "+10 Points. Welcome
				// aboard!" did not fit one line.
			} else if ( 1 === extras.length ) {
				var e = extras[ 0 ];
				title += ' ' + fmt( e[ 1 ], 1 === e[ 0 ].length ? e[ 2 ] : e[ 3 ], e[ 0 ].length );
			} else if ( extras.length > 1 ) {
				title += ' ' + fmt( 'andRewards', 'and %d rewards', others );
			}
		} else if ( 1 === sum.singles.length ) {
			title = sum.singles[ 0 ].message || '';
		} else {
			title = fmt( 'rewards', '%d new rewards', others || sum.singles.length );
		}

		if ( sum.welcome ) {
			body = toastI18n( 'welcome', 'Welcome aboard!' ) + ( sum.welcome.detail ? ' ' + sum.welcome.detail : '' );
		} else if ( 1 === sum.singles.length || ( ! sum.total && 1 === others ) ) {
			var only = sum.singles.filter( function ( t ) { return 'points' !== t.type; } )[ 0 ] || sum.singles[ 0 ];
			body = only.detail || '';
		} else {
			body = sum.labels.map( function ( l ) {
				return sum.counts[ l ] > 1 ? l + ' ×' + sum.counts[ l ] : l;
			} ).concat( sum.streaks, sum.badges, sum.challenges, sum.kudos ).join( ', ' );
		}

		var kind = sum.badges.length ? 'badge' : ( sum.challenges.length ? 'challenge' : ( sum.kudos.length && ! sum.total ? 'kudos' : 'points' ) );
		return {
			key: SUMMARY_KEY,
			type: kind,
			tone: sum.badges.length || sum.challenges.length ? 'achievement' : 'reward',
			icon: sum.image || ICONS[ kind ],
			title: title,
			count: count,
			body: body,
			href: sum.url,
			hrefLabel: sum.urlLabel,
		};
	}

	function showOne( toast ) {
		if ( REWARD_TYPES.indexOf( toast.type ) !== -1 ) {
			collect( toast );
			return;
		}
		switch ( toast.type ) {
			case 'skip':
				whenShown( window.wbGam.toast( { type: 'skip', tone: 'info', icon: ICONS.skip, title: toast.message || '', body: toast.detail || '' } ), [ toast._id ] );
				return;

			case 'submission':
				whenShown( window.wbGam.toast( {
					type: 'submission',
					tone: 'approved' === toast.outcome ? 'success' : 'info',
					icon: toast.icon || ( 'approved' === toast.outcome ? 'icon-check' : 'icon-info' ),
					title: toast.message || '',
					body: toast.detail || '',
				} ), [ toast._id ] );
				return;

			default:
				whenShown( window.wbGam.toast( {
					type: toast.type || 'points',
					tone: 'reward',
					icon: toast.icon || ICONS[ toast.type ] || ICONS.points,
					title: toast.message || '',
					body: toast.detail || '',
					href: toast.url || '',
					hrefLabel: toast.url_label || '',
				} ), [ toast._id ] );
		}
	}

	/**
	 * Delivery: what the member has actually SEEN, and the one server position that follows it.
	 *
	 * The server keeps one monotonic position per member and re-delivers every row above it, so a page
	 * that vanishes before showing something (a reload right after a post) loses nothing. The page moves
	 * the position by acknowledging, and it may acknowledge only rows it DISPLAYED. "Displayed" means the
	 * toast element is attached to the page (its frame is painted next), or the Moment card was opened
	 * (assets/interactivity/notifications.js); a reward still waiting in the 1.5s collect window, behind
	 * a level-up card, behind a dialog or in BuddyNext's line is not displayed yet.
	 *
	 * Display order is not row order (a level-up row is newer than the points rows but shows first), and
	 * the position is one number, so the page acknowledges the highest row of the contiguous displayed
	 * PREFIX of the rows it received: every received row at or below it has been displayed. A row held
	 * behind the level-up pins the position below itself until it is shown.
	 *
	 * Rows displayed above the pinned position are remembered in this tab (sessionStorage, per member,
	 * pruned to rows above the acknowledged position). If the page is reloaded meanwhile, the next page
	 * receives them again, does not paint them a second time, and counts them as displayed. The record
	 * lives only in the browser: nothing on the server stores what a tab has seen, so there is nothing
	 * to clean up on uninstall.
	 */
	var SHOWN_KEY  = 'wbgam-shown:' + ( parseInt( wbGamToast.userId, 10 ) || 0 );
	var SHOWN_KEEP = 100;
	var received = new Map(); // Row id => displayed on this page.
	var head     = 0;         // Highest `_ack` the server sent: the end of the window it read.
	var ackSent  = 0;
	var record   = new Set();

	try {
		JSON.parse( window.sessionStorage.getItem( SHOWN_KEY ) || '[]' ).forEach( function ( id ) {
			if ( 'number' === typeof id ) {
				record.add( id );
			}
		} );
	} catch ( e ) { /* No storage: a reload may show the Moment card again, never lose a toast. */ }

	function persist() {
		try {
			var ids = Array.from( record ).sort( function ( a, b ) { return a - b; } ).slice( -SHOWN_KEEP );
			window.sessionStorage.setItem( SHOWN_KEY, JSON.stringify( ids ) );
		} catch ( e ) { /* Storage full or blocked. */ }
	}

	/** Rows displayed: remember them, then move the acknowledgement as far as the prefix allows. */
	function shown( ids ) {
		var any = false;
		( ids || [] ).forEach( function ( id ) {
			id = parseInt( id, 10 );
			if ( id > 0 ) {
				record.add( id );
				if ( received.has( id ) ) {
					received.set( id, true );
				}
				any = true;
			}
		} );
		if ( any ) {
			persist();
			advance();
		}
	}

	function advance() {
		var ids = Array.from( received.keys() ).sort( function ( a, b ) { return a - b; } );
		var upTo = 0;
		var i = 0;
		while ( i < ids.length && received.get( ids[ i ] ) ) {
			upTo = ids[ i++ ];
		}
		if ( i === ids.length ) {
			upTo = Math.max( upTo, head ); // Everything received is displayed: take the whole window (undecodable rows included).
		}
		acknowledge( upTo );
	}

	function acknowledge( ack ) {
		if ( ack <= ackSent ) {
			return;
		}
		var prev = ackSent;
		ackSent  = ack;
		window.wbGam.rest( wbGamToast.restUrl + 'members/me/toasts/ack', {
			method: 'POST',
			body: { id: ack },
			nonce: wbGamToast.nonce,
			keepalive: true,
		} ).then( function ( result ) {
			if ( result && result.status >= 500 ) {
				ackSent = Math.min( ackSent, prev );
				return;
			}
			record.forEach( function ( id ) { // The server is past these: nothing will re-deliver them.
				if ( id <= ack ) {
					record.delete( id );
				}
			} );
			persist();
		} ).catch( function () {
			ackSent = Math.min( ackSent, prev ); // Network failure: the next displayed row asks again, and the rows stay for re-delivery.
		} );
	}

	/**
	 * Mark rows displayed once the toast handle is on screen. A toast waiting behind a level-up card,
	 * a dialog or BuddyNext's line is polled until it shows; a handle that is already gone (nothing to
	 * say, or dismissed from the line) has nothing left to lose.
	 *
	 * @param {Object}        handle The renderer's handle.
	 * @param {Array<number>} ids    Queue rows this toast shows.
	 */
	function whenShown( handle, ids ) {
		ids = ids.filter( function ( id ) { return id != null; } );
		if ( ! ids.length || ! handle ) {
			return;
		}
		var tries = 0;
		( function check() {
			if ( ( handle.el && handle.el.isConnected ) || handle.gone ) {
				shown( ids );
			} else if ( ++tries < 2000 ) {
				setTimeout( check, 150 );
			}
		}() );
	}

	// For the Moment-card store (a separate module): skip rows an earlier page already showed, report cards shown.
	window.wbGamDelivery = {
		consumed: function ( id ) { return record.has( parseInt( id, 10 ) ); },
		shown: shown,
	};

	/**
	 * Render a queued payload, deduping by `_id` so the same event never paints twice, and never
	 * painting a row an earlier page of this tab already displayed.
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
			var id = parseInt( toast._id, 10 ) || 0;
			head = Math.max( head, parseInt( toast._ack, 10 ) || 0 );
			if ( id && ! received.has( id ) ) {
				received.set( id, record.has( id ) );
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
			if ( id && record.has( id ) ) {
				return; // Shown by the page before this one: counted as displayed, not shown twice.
			}
			showOne( toast );
		} );
		advance();
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
