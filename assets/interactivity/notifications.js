/**
 * WB Gamification — Interactivity API store for the celebration overlays.
 *
 * Powers the level-up overlay + streak-milestone overlay rendered by
 * `WBGam\Engine\NotificationBridge::render()`. The toast stack lives
 * elsewhere (assets/js/toast.js, single container appended to body) —
 * this store does NOT touch toasts.
 *
 * Two inputs feed the overlays:
 *   1. `window.wbGamNotifications` — page-load seed from the transient,
 *      same payload the toast stack also consumes for the seed paint.
 *   2. `window.wbGamRealtime` broker subscription — live deliveries via
 *      heartbeat ticks and SSE pushes. Without this, a level_up event
 *      that arrives 8 seconds after page load would render as a toast
 *      (via toast.js) but skip the overlay treatment entirely. Closes
 *      the asymmetry between seed-time and live-time overlay handling.
 *
 * Overlay-only event types:
 *   - level_up         -> opens the level-up overlay
 *   - streak_milestone -> opens the streak overlay
 *
 * Every other type passes through silently — toast.js owns those.
 *
 * @since 1.2.0
 * @refactored 1.5.0 — toast stack moved out to single-owner toast.js;
 *                    broker subscription added so live overlay events
 *                    actually surface.
 */

import { store, getContext } from '@wordpress/interactivity';

const NS = 'wb-gamification';

const initialState = {
	levelUp: {
		active:    false,
		iconUrl:   '',
		levelName: '',
	},
	streakMilestone: {
		active: false,
		days:   '',
	},
	// Translatable fallback strings, delivered from the server via
	// wp_interactivity_state( 'wb-gamification', [ 'i18n' => [ ... ] ] ). Kept as
	// English defaults so the store still works if the injection is absent.
	i18n: {
		levelUp: 'Level up!',
	},
};

const { state, actions } = store( NS, {
	state: {
		...initialState,
	},

	actions: {
		dismissLevelUp() {
			state.levelUp = { ...state.levelUp, active: false };
		},

		dismissStreakMilestone() {
			state.streakMilestone = { ...state.streakMilestone, active: false };
		},
	},

	callbacks: {
		init() {
			// Track the ids we've already rendered as an overlay so a
			// live broker delivery of the same event (e.g. the broker
			// replays the last payload to late subscribers) doesn't
			// re-open an overlay the user just dismissed.
			const seenOverlayIds = new Set();

			// NOTE: there used to be a focusOverlayDismiss() here that moved focus into
			// the overlay's dismiss button on open, "per the WAI-ARIA alertdialog
			// pattern". That pattern stopped applying the moment NotificationBridge::
			// render() switched this overlay's markup from role="alertdialog"
			// aria-modal="true" to role="status" aria-live="polite" (see the comment
			// there) -- a level-up is an ANNOUNCEMENT, not a dialog, and is never
			// focused. This function survived that markup change as dead code and kept
			// yanking keyboard focus into an aria-live region on every level-up/streak
			// toast, which is exactly the trap the markup change was written to remove.
			// Removed rather than fixed forward: there is nothing left for this store to
			// do on open besides flip `active` to true.

			/**
			 * Open the matching overlay for one event. Returns true if
			 * the event was an overlay type and was rendered (or skipped
			 * because the dedupe set caught it); false for toast-type
			 * events the toast stack should handle.
			 *
			 * @param {Object} event Single notification payload.
			 * @return {boolean}
			 */
			function applyOverlay( event ) {
				if ( ! event || typeof event !== 'object' ) {
					return false;
				}
				if ( event.type !== 'level_up' && event.type !== 'streak_milestone' ) {
					return false;
				}

				const dedupeKey = event._id != null
					? `id:${ event._id }`
					: `fp:${ event.type }|${ event.message || '' }|${ event._ts || '' }`;
				if ( seenOverlayIds.has( dedupeKey ) ) {
					return true;
				}
				seenOverlayIds.add( dedupeKey );

				if ( event.type === 'level_up' ) {
					state.levelUp = {
						active:    true,
						iconUrl:   event.icon_url || '',
						// Read the canonical `levelName` field first (PHP
						// queues it explicitly at NotificationBridge:232);
						// fall back to the translated message for the design
						// that reads "You reached X!". Pre-1.4.1 this
						// preferred event.message and the overlay always
						// rendered the localised sentence instead of the
						// bare level name. Closes audit DATA-FLOW-
						// NOTIFICATIONS-2026-05-27.md §G10.
						levelName: event.levelName || event.message || event.detail || ( state.i18n && state.i18n.levelUp ) || 'Level up!',
					};
				} else {
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
				}
				return true;
			}

			// 1. Page-load seed — drain whatever was queued before the
			//    broker came online. Same payload toast.js also reads.
			const seed = Array.isArray( window.wbGamNotifications )
				? window.wbGamNotifications
				: [];
			let levelUpShown = false;
			let streakShown  = false;
			for ( const event of seed ) {
				// First-of-type-only rule: if PHP queued multiple level_up
				// events during one window (rare but possible on long-
				// running tabs), only the first overlays. Subsequent same-
				// type seed events skip — they were aggregated by the
				// server already and the user gets one celebration.
				if ( event && event.type === 'level_up' && levelUpShown ) {
					continue;
				}
				if ( event && event.type === 'streak_milestone' && streakShown ) {
					continue;
				}
				if ( applyOverlay( event ) ) {
					if ( event.type === 'level_up' ) {
						levelUpShown = true;
					} else if ( event.type === 'streak_milestone' ) {
						streakShown = true;
					}
				}
			}

			// 2. Live broker subscription — heartbeat-delivered and
			//    SSE-delivered events. The broker replays its last payload
			//    synchronously when we subscribe, so any tick that fired
			//    between page-paint and this code running still reaches us.
			function subscribeToBroker() {
				if ( ! window.wbGamRealtime || typeof window.wbGamRealtime.subscribe !== 'function' ) {
					return false;
				}
				window.wbGamRealtime.subscribe( 'toasts', ( events ) => {
					if ( ! Array.isArray( events ) ) {
						return;
					}
					for ( const event of events ) {
						applyOverlay( event );
					}
				} );
				return true;
			}

			if ( ! subscribeToBroker() ) {
				document.addEventListener( 'wbGamRealtimeReady', subscribeToBroker, { once: true } );
			}

			// 3. Escape closes any open overlay — backstop for the case
			//    a theme stylesheet blocks the dismiss button.
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

			// Suppress unused-import warning when @wordpress/interactivity's
			// getContext stays untranspiled in dev builds — kept around so
			// future overlay variants that read per-element context can
			// hop right in without re-importing.
			void getContext;
		},
	},
} );
