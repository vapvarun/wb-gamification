/**
 * WB Gamification: Interactivity API store for the Moment card.
 *
 * Powers the single, data-driven Moment card rendered by `WBGam\Engine\NotificationBridge::render()`.
 * The toast stack lives elsewhere (assets/js/toast.js and toast-core.js); this store does not touch
 * toasts.
 *
 * Moment event types (each carries its own translated eyebrow, title, sub, ring and cta):
 *   - level_up, streak_milestone, cohort_promotion, community_goal
 *
 * Every other type passes through silently. toast.js owns those.
 *
 * Two inputs feed the card:
 *   1. `window.wbGamNotifications`: the page-load seed, the same payload the toast stack reads.
 *   2. `window.wbGamRealtime` broker subscription: live deliveries via heartbeat and SSE, so a
 *      celebration that arrives after page load is not skipped.
 *
 * One card at a time. A second celebration that arrives while one is showing waits and shows
 * after the member dismisses the first. Opening a card starts the confetti (assets/js/celebrate.js).
 *
 * @since 1.2.0
 * @refactored 1.6.5 - one Moment card replaces the separate level-up and streak overlays, and the
 *                    two new moments (league promotion, community goal) use it unchanged.
 */

import { store } from '@wordpress/interactivity';

const NS = 'wb-gamification';

const MOMENT_TYPES = [ 'level_up', 'streak_milestone', 'cohort_promotion', 'community_goal' ];

const EMPTY_MOMENT = {
	active: false,
	type: '',
	eyebrow: '',
	title: '',
	sub: '',
	ring: '',
	iconUrl: '',
	cta: '',
};

const { state, actions } = store( NS, {
	state: {
		moment: { ...EMPTY_MOMENT },
		// Translatable fallback string, delivered from the server via
		// wp_interactivity_state( 'wb-gamification', [ 'i18n' => [ ... ] ] ). Kept as an English
		// default so the store still works if the injection is absent.
		i18n: {
			levelUp: 'Level up!',
		},
	},

	actions: {
		dismissMoment() {
			state.moment = { ...EMPTY_MOMENT };
			showNext();
		},
	},

	callbacks: {
		init() {
			// Ids already turned into a card, so a live broker delivery of the same event (the broker
			// replays its last payload to late subscribers) does not reopen a card the member just
			// dismissed.
			const seen = new Set();

			/**
			 * Queue one event as a Moment card. Returns true when the event was a Moment type (rendered,
			 * queued, or caught by the dedupe set), false for a toast-type event.
			 *
			 * @param {Object} event Single notification payload.
			 * @return {boolean}
			 */
			function apply( event ) {
				if ( ! event || typeof event !== 'object' || ! MOMENT_TYPES.includes( event.type ) ) {
					return false;
				}

				const key = event._id != null
					? `id:${ event._id }`
					: `fp:${ event.type }|${ event.message || '' }|${ event._ts || '' }`;
				if ( seen.has( key ) ) {
					return true;
				}
				seen.add( key );

				enqueue( toMoment( event ) );
				return true;
			}

			// 1. Page-load seed.
			const seed = Array.isArray( window.wbGamNotifications ) ? window.wbGamNotifications : [];
			seed.forEach( apply );

			// 2. Live broker subscription.
			function subscribe() {
				if ( ! window.wbGamRealtime || typeof window.wbGamRealtime.subscribe !== 'function' ) {
					return false;
				}
				window.wbGamRealtime.subscribe( 'toasts', ( events ) => {
					if ( Array.isArray( events ) ) {
						events.forEach( apply );
					}
				} );
				return true;
			}
			if ( ! subscribe() ) {
				document.addEventListener( 'wbGamRealtimeReady', subscribe, { once: true } );
			}

			// 3. Escape closes the open card: a backstop for a theme stylesheet that hides the button.
			document.addEventListener( 'keydown', ( ev ) => {
				if ( ev.key === 'Escape' && state.moment.active ) {
					actions.dismissMoment();
					ev.preventDefault();
				}
			} );
		},
	},
} );

// A card waiting behind the one on screen.
const waiting = [];

/**
 * Map a queue payload to the card's fields. The server sends the copy already translated; the
 * fallbacks read the older payload fields so a card still renders from an event queued before the
 * upgrade.
 *
 * @param {Object} event Notification payload.
 * @return {Object}
 */
function toMoment( event ) {
	return {
		active: true,
		type: event.type,
		eyebrow: event.eyebrow || ( state.i18n && state.i18n.levelUp ) || '',
		title: event.title || event.levelName || event.message || '',
		sub: event.sub || '',
		ring: event.ring || ( event.days != null ? String( event.days ) : '' ),
		iconUrl: event.icon_url || '',
		cta: event.cta || '',
	};
}

function show( moment ) {
	state.moment = moment;
	// The store updates the DOM on the next frame; start the confetti once the card is visible.
	window.requestAnimationFrame( () => {
		const host = document.querySelector( '.wb-gam-moment' );
		if ( host && window.wbGam && typeof window.wbGam.celebrate === 'function' ) {
			window.wbGam.celebrate( host, 'full' );
		}
	} );
}

function enqueue( moment ) {
	if ( state.moment.active ) {
		waiting.push( moment );
		return;
	}
	show( moment );
}

function showNext() {
	const next = waiting.shift();
	if ( next ) {
		show( next );
	}
}
