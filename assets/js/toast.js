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

	function fmt( key, fallback, n ) {
		return toastI18n( key, fallback ).replace( '%d', n ).replace( '%s', n );
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
			sum = { handle: null, total: 0, shown: 0, unitMany: '', locale: '', firstMessage: '', pointItems: 0, labels: [], counts: {}, badges: [], challenges: [], kudos: [], streaks: [], singles: [], welcome: null, image: '', url: '', urlLabel: '' };
		}
		items.forEach( add );
		var opts = summarise();
		if ( sum.handle ) {
			sum.handle.update( opts );
		} else {
			sum.handle = window.wbGam.toast( opts );
		}
		sum.shown = sum.total;
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
			[ sum.badges, 'andBadge', 'and a new badge', 'andBadges', 'and %d new badges' ],
			[ sum.challenges, 'andChallenge', 'and a challenge done', 'andChallenges', 'and %d challenges done' ],
			[ sum.kudos, 'andKudos', 'and kudos', 'andKudos', 'and kudos' ],
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
			}
			if ( sum.welcome ) {
				// The welcome is the detail line: at 360px next to its button, "+10 Points. Welcome
				// aboard!" did not fit one line.
			} else if ( 1 === extras.length ) {
				var e = extras[ 0 ];
				title += ' ' + ( 1 === e[ 0 ].length ? toastI18n( e[ 1 ], e[ 2 ] ) : fmt( e[ 3 ], e[ 4 ], e[ 0 ].length ) );
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
				window.wbGam.toast( { type: 'skip', tone: 'info', icon: ICONS.skip, title: toast.message || '', body: toast.detail || '' } );
				return;

			case 'submission':
				window.wbGam.toast( {
					type: 'submission',
					tone: 'approved' === toast.outcome ? 'success' : 'info',
					icon: toast.icon || ( 'approved' === toast.outcome ? 'icon-check' : 'icon-info' ),
					title: toast.message || '',
					body: toast.detail || '',
				} );
				return;

			default:
				window.wbGam.toast( {
					type: toast.type || 'points',
					tone: 'reward',
					icon: toast.icon || ICONS[ toast.type ] || ICONS.points,
					title: toast.message || '',
					body: toast.detail || '',
					href: toast.url || '',
					hrefLabel: toast.url_label || '',
				} );
		}
	}

	/**
	 * Render a queued payload, deduping by `_id` so the same event never paints twice.
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
			showOne( toast );
		} );
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
