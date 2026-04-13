/**
 * WB Gamification — Interactivity API store
 *
 * Handles:
 *  - Smart-batched toast notifications (points, badges, streaks, challenges)
 *  - Level-up celebration overlay
 *  - Streak milestone overlay
 *
 * PHP side pushes events via window.wbGamNotifications (localized in
 * NotificationBridge.php) on page load, then REST polling adds real-time
 * events.
 *
 * Batching rules (per the market research on notification fatigue):
 *  - Points toasts are collapsed: "You earned 42 pts" instead of 7 separate toasts
 *  - Badges, level-ups, and milestones always show individually
 *  - Max 3 toasts visible at once; excess queues silently
 *  - Each toast auto-dismisses after 4 s (badges/level-ups: 6 s)
 *
 * @since 0.1.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

const TOAST_DURATION_DEFAULT = 4000;
const TOAST_DURATION_SPECIAL  = 6000;
const MAX_VISIBLE_TOASTS       = 3;

/**
 * @typedef {{ id: string, type: string, message: string, detail: string|null, icon: string, duration: number }} Toast
 */

const { state, actions } = store( 'wb-gamification', {
	state: {
		/** @type {Toast[]} */
		toasts: [],
		/** @type {Toast[]} */
		queue: [],

		levelUp: {
			active:    false,
			levelName: '',
			iconUrl:   '',
		},

		streakMilestone: {
			active: false,
			days:   0,
		},
	},

	actions: {
		/**
		 * Add a notification event. Batches points; shows others immediately.
		 *
		 * @param {{ type: string, points?: number, message?: string, detail?: string, icon?: string }} event
		 */
		push( event ) {
			if ( event.type === 'points' ) {
				actions._mergePoints( event );
				return;
			}

			if ( event.type === 'level_up' ) {
				state.levelUp.active    = true;
				state.levelUp.levelName = event.levelName ?? '';
				state.levelUp.iconUrl   = event.iconUrl ?? '';
				return;
			}

			if ( event.type === 'streak_milestone' ) {
				state.streakMilestone.active = true;
				state.streakMilestone.days   = event.days ?? 0;
				return;
			}

			actions._enqueue( {
				id:       crypto.randomUUID(),
				type:     event.type,
				message:  event.message ?? '',
				detail:   event.detail  ?? null,
				icon:     event.icon    ?? '🎉',
				duration: TOAST_DURATION_SPECIAL,
			} );
		},

		/** Merge a points event into an existing pending points toast or create one. */
		_mergePoints( event ) {
			const pts = event.points ?? 0;
			// Find an existing points toast that hasn't been shown yet (in queue).
			const existing = state.queue.find( t => t.type === 'points' );
			if ( existing ) {
				const prevPts = parseInt( existing._rawPoints ?? 0, 10 );
				const newPts  = prevPts + pts;
				existing._rawPoints = newPts;
				existing.message    = `+${ newPts } points`;
				return;
			}

			// Also check visible toasts.
			const visible = state.toasts.find( t => t.type === 'points' );
			if ( visible ) {
				const prevPts = parseInt( visible._rawPoints ?? 0, 10 );
				visible._rawPoints = prevPts + pts;
				visible.message    = `+${ visible._rawPoints } points`;
				return;
			}

			actions._enqueue( {
				id:         crypto.randomUUID(),
				type:       'points',
				message:    `+${ pts } points`,
				detail:     event.detail ?? null,
				icon:       '⭐',
				duration:   TOAST_DURATION_DEFAULT,
				_rawPoints: pts,
			} );
		},

		/** Add toast to queue, flush if slots available. */
		_enqueue( toast ) {
			if ( state.toasts.length < MAX_VISIBLE_TOASTS ) {
				actions._show( toast );
			} else {
				state.queue.push( toast );
			}
		},

		/** Move toast from queue to visible. */
		_show( toast ) {
			state.toasts.push( toast );
			setTimeout( () => actions._dismiss( toast.id ), toast.duration );
		},

		/** Remove a toast by id, then flush queue if space. */
		_dismiss( id ) {
			state.toasts = state.toasts.filter( t => t.id !== id );
			if ( state.queue.length > 0 && state.toasts.length < MAX_VISIBLE_TOASTS ) {
				actions._show( state.queue.shift() );
			}
		},

		dismissToast() {
			const ctx = getContext();
			actions._dismiss( ctx.toastId );
		},

		dismissLevelUp() {
			state.levelUp.active = false;
		},

		dismissStreakMilestone() {
			state.streakMilestone.active = false;
		},
	},

	callbacks: {
		/** Auto-start: consume any events queued by PHP before JS loaded. */
		init() {
			const events = window.wbGamNotifications ?? [];
			// Drain the queue in small batches so points can be merged.
			events.forEach( e => actions.push( e ) );
			// Clear so the next page load starts clean.
			window.wbGamNotifications = [];

			// ESC key handler: dismiss any active overlay.
			document.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' ) {
					if ( state.levelUp.active ) {
						actions.dismissLevelUp();
					}
					if ( state.streakMilestone.active ) {
						actions.dismissStreakMilestone();
					}
				}
			} );
		},
	},
} );
