/**
 * WB Gamification — Hub Interactivity API store
 *
 * Handles:
 *  - Slide-in panel open / close
 *  - ESC key to dismiss
 *  - URL pre-open (`?panel=badges` etc.)
 *
 * Namespace: wb-gamification/hub
 *
 * The render.php sets `data-wp-interactive="wb-gamification/hub"` and
 * provides `<template id="gam-tpl-{key}">` elements whose innerHTML
 * is injected into the panel body on open.
 *
 * @since 1.0.0
 */

import { store, getContext } from '@wordpress/interactivity';

/**
 * Human-readable titles for each panel key.
 *
 * @type {Object<string, string>}
 */
const PANEL_TITLES = {
	badges:      'My Badges',
	challenges:  'Challenges',
	leaderboard: 'Leaderboard',
	earning:     'How to Earn Points',
	kudos:       'Kudos Feed',
	history:     'Points History',
};

const VALID_PANELS = Object.keys( PANEL_TITLES );

const { state, actions } = store( 'wb-gamification/hub', {
	state: {
		panelOpen:    false,
		panelTitle:   '',
		panelContent: '',
		_activePanel: '',
	},

	actions: {
		/**
		 * Open a panel.
		 *
		 * Reads the `panel` key from the element's `data-wp-context`,
		 * looks up the matching `<template>`, and injects its HTML.
		 */
		openPanel() {
			const ctx = getContext();
			const key = ctx.panel;

			if ( ! key || ! VALID_PANELS.includes( key ) ) {
				return;
			}

			const tpl = document.getElementById( `gam-tpl-${ key }` );
			if ( ! tpl ) {
				return;
			}

			state.panelTitle   = PANEL_TITLES[ key ];
			state.panelContent = tpl.innerHTML;
			state._activePanel = key;
			state.panelOpen    = true;

			document.body.style.overflow = 'hidden';
		},

		/**
		 * Close the currently open panel.
		 *
		 * Restores body scroll and resets all panel state.
		 */
		closePanel() {
			state.panelOpen    = false;
			state.panelContent = '';
			state._activePanel = '';

			document.body.style.overflow = '';
		},

		/**
		 * Prevent clicks inside the panel from bubbling to the backdrop.
		 *
		 * Applied via `data-wp-on--click="actions.stopPropagation"` on the
		 * `.gam-panel` element.
		 *
		 * @param {Event} event
		 */
		stopPropagation( event ) {
			event.stopPropagation();
		},
	},

	callbacks: {
		/**
		 * Runs on mount.
		 *
		 * 1. Registers a global ESC key listener to close the panel.
		 * 2. If the wrapper's context contains a valid `preOpen` key,
		 *    automatically opens that panel after the DOM settles.
		 */
		init() {
			// ESC key handler.
			document.addEventListener( 'keydown', ( event ) => {
				if ( event.key === 'Escape' && state.panelOpen ) {
					actions.closePanel();
				}
			} );

			// Auto-open from URL parameter (?panel=badges, etc.).
			const ctx = getContext();
			const preOpen = ctx.preOpen;

			if (
				preOpen &&
				VALID_PANELS.includes( preOpen ) &&
				document.getElementById( `gam-tpl-${ preOpen }` )
			) {
				requestAnimationFrame( () => {
					state.panelTitle   = PANEL_TITLES[ preOpen ];
					state.panelContent = document.getElementById( `gam-tpl-${ preOpen }` ).innerHTML;
					state._activePanel = preOpen;
					state.panelOpen    = true;

					document.body.style.overflow = 'hidden';
				} );
			}
		},
	},
} );
