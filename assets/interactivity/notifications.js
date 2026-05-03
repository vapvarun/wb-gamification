/**
 * WB Gamification — Interactivity API store for the notification surface.
 *
 * Powers the markup emitted by `WBGam\Engine\NotificationBridge::render()`:
 * a level-up overlay, a streak-milestone overlay, and a stack of toast
 * notifications. Without this store the overlay markup renders but never
 * binds — `data-wp-bind--hidden="!state.streakMilestone.active"` evaluates
 * to `!undefined` (truthy) which leaves the overlay permanently visible
 * AND the dismiss button inert because `actions.dismissStreakMilestone`
 * doesn't exist. Result: customer is locked out of the page.
 *
 * Seed data flow:
 *   PHP transient → `window.wbGamNotifications` JSON → callbacks.init →
 *   state.levelUp / state.streakMilestone / state.toasts.
 *
 * @since 1.2.0
 */

import { store, getContext } from '@wordpress/interactivity';

const NS = 'wb-gamification';

const ICON_FOR_TYPE = {
	points:     '✨',
	badge:      '🏅',
	level_up:   '🚀',
	streak:     '🔥',
	challenge:  '🎯',
	kudos:      '👏',
};

const initialState = {
	toasts: [],
	levelUp: {
		active:    false,
		iconUrl:   '',
		levelName: '',
	},
	streakMilestone: {
		active: false,
		days:   '',
	},
};

let toastSequence = 0;
const nextToastId = () => `wb-gam-toast-${ ++toastSequence }`;

const { state, actions } = store( NS, {
	state: initialState,

	actions: {
		dismissToast() {
			const ctx = getContext();
			state.toasts = state.toasts.filter( ( t ) => t.id !== ctx.toast.id );
		},

		dismissLevelUp() {
			state.levelUp = { ...state.levelUp, active: false };
		},

		dismissStreakMilestone() {
			state.streakMilestone = { ...state.streakMilestone, active: false };
		},
	},

	callbacks: {
		init() {
			const events = Array.isArray( window.wbGamNotifications )
				? window.wbGamNotifications
				: [];

			let levelUpShown = false;
			let streakShown  = false;

			for ( const event of events ) {
				if ( ! event || typeof event !== 'object' ) {
					continue;
				}

				switch ( event.type ) {
					case 'level_up':
						if ( ! levelUpShown ) {
							state.levelUp = {
								active:    true,
								iconUrl:   event.icon_url || '',
								levelName: event.message || event.detail || 'Level up!',
							};
							levelUpShown = true;
						}
						break;

					case 'streak_milestone':
						if ( ! streakShown ) {
							const days =
								event.days
								|| event.streak_length
								|| ( typeof event.detail === 'string'
									? ( event.detail.match( /\d+/ ) || [ '' ] )[ 0 ]
									: '' );
							state.streakMilestone = {
								active: true,
								days:   String( days ),
							};
							streakShown = true;
						}
						break;

					default:
						state.toasts = [
							...state.toasts,
							{
								id:      nextToastId(),
								type:    event.type || 'points',
								icon:    event.icon || ICON_FOR_TYPE[ event.type ] || '✨',
								message: event.message || '',
								detail:  event.detail || '',
							},
						];
				}
			}

			// Auto-dismiss toasts after 6 seconds — overlays stay until clicked.
			if ( state.toasts.length ) {
				const ids = state.toasts.map( ( t ) => t.id );
				window.setTimeout( () => {
					state.toasts = state.toasts.filter( ( t ) => ! ids.includes( t.id ) );
				}, 6000 );
			}

			// Escape closes any open overlay so a stuck customer always
			// has an out — backstop for the case the dismiss button
			// itself is blocked by a theme stylesheet.
			document.addEventListener( 'keydown', ( ev ) => {
				if ( ev.key !== 'Escape' ) {
					return;
				}
				if ( state.levelUp.active ) {
					actions.dismissLevelUp();
					ev.preventDefault();
				} else if ( state.streakMilestone.active ) {
					actions.dismissStreakMilestone();
					ev.preventDefault();
				}
			} );
		},
	},
} );
