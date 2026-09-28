/**
 * WB Gamification — Hub Interactivity API store
 *
 * Handles:
 *  - Slide-in panel open / close (a native <dialog>, via assets/js/dialog.js)
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

import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Human-readable titles for each panel key.
 *
 * These English strings are the last-resort fallback only. The live titles are
 * delivered already-translated from the server (render.php emits them, wrapped
 * in __(), as the `data-wb-gam-panel-titles` JSON attribute on the interactive
 * root) because Interactivity script modules cannot use script translations.
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

/**
 * Server-provided, already-translated panel titles keyed by panel key.
 *
 * Read once from the interactive root's `data-wb-gam-panel-titles` attribute.
 * Falls back to the English map above only if the attribute is missing or
 * malformed.
 *
 * @return {Object<string, string>} Translated titles keyed by panel key.
 */
function getServerPanelTitles() {
	const root = document.querySelector( '[data-wp-interactive="wb-gamification/hub"]' );
	if ( root && root.dataset.wbGamPanelTitles ) {
		try {
			const parsed = JSON.parse( root.dataset.wbGamPanelTitles );
			if ( parsed && 'object' === typeof parsed ) {
				return parsed;
			}
		} catch ( e ) {
			// Malformed JSON — fall through to the English fallback map.
		}
	}
	return {};
}

const SERVER_PANEL_TITLES = getServerPanelTitles();

/**
 * Resolve the display title for a panel key, preferring the server-translated
 * value and falling back to the English map.
 *
 * @param {string} key Panel key.
 * @return {string} Translated (or fallback) panel title.
 */
function panelTitleFor( key ) {
	return SERVER_PANEL_TITLES[ key ] || PANEL_TITLES[ key ] || '';
}

/**
 * The panel dialog. It is a native <dialog>, so the browser owns the focus trap, Escape and the inert
 * page behind it; window.wbGam.dialog (assets/js/dialog.js) adds focus-return and backdrop click.
 *
 * @return {HTMLDialogElement|null} The dialog, or null when the block is not on the page.
 */
function panelDialog() {
	return document.querySelector( 'dialog.gam-panel' );
}

/**
 * Put the panel back to its closed state. Runs on EVERY way of closing (back button, Escape, backdrop
 * click), because it is the dialog's onClose callback rather than something the back button does.
 */
function resetPanel() {
	state.panelOpen    = false;
	state._activePanel = '';

	const body = document.getElementById( 'gam-panel-body' );
	if ( body ) {
		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}
	}

	document.body.style.overflow = '';
}

/**
 * Inject a panel's template into the dialog and open it.
 *
 * @param {string}       key    Panel key.
 * @param {Element|null} opener Element focus returns to on close (null for the URL pre-open).
 */
function showPanel( key, opener ) {
	const tpl    = document.getElementById( `gam-tpl-${ key }` );
	const body   = document.getElementById( 'gam-panel-body' );
	const dialog = panelDialog();
	if ( ! tpl || ! body || ! dialog || ! window.wbGam?.dialog ) {
		return;
	}

	// Clear previous content, clone template into panel body.
	while ( body.firstChild ) {
		body.removeChild( body.firstChild );
	}
	body.appendChild( tpl.content.cloneNode( true ) );

	state.panelTitle   = panelTitleFor( key );
	state._activePanel = key;
	state.panelOpen    = true;

	document.body.style.overflow = 'hidden';

	window.wbGam.dialog.bind( dialog );
	window.wbGam.dialog.open( dialog, {
		opener,
		initialFocus: '.gam-panel__back',
		onClose: resetPanel,
	} );
}

const { state, actions } = store( 'wb-gamification/hub', {
	state: {
		panelOpen:    false,
		panelTitle:   '',
		_activePanel: '',
	},

	actions: {
		/**
		 * Open a panel.
		 *
		 * Reads the `panel` key from the element's `data-wp-context` and opens the matching
		 * `<template>` in the dialog. The element that fired it is where focus returns on close.
		 */
		openPanel() {
			const key = getContext().panel;

			if ( ! key || ! VALID_PANELS.includes( key ) ) {
				return;
			}

			showPanel( key, getElement()?.ref || null );
		},

		/**
		 * Keyboard parity for the tiles, which are role="button" divs (a real <button> cannot hold the
		 * card's block content). Enter and Space open the panel, like a button.
		 *
		 * @param {KeyboardEvent} event
		 */
		onTileKey( event ) {
			if ( event.target !== event.currentTarget ) {
				return;
			}
			if ( event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				actions.openPanel();
			}
		},

		/**
		 * Close the open panel. The dialog's own close handling then runs resetPanel().
		 */
		closePanel() {
			const dialog = panelDialog();
			if ( dialog && window.wbGam?.dialog ) {
				window.wbGam.dialog.close( dialog );
			}
		},
	},

	callbacks: {
		/**
		 * Runs on mount.
		 *
		 * If the wrapper's context contains a valid `preOpen` key, opens that panel after the DOM settles.
		 */
		init() {
			// Auto-open from URL parameter (?panel=badges, etc.).
			const preOpen = getContext().preOpen;

			if ( preOpen && VALID_PANELS.includes( preOpen ) ) {
				requestAnimationFrame( () => showPanel( preOpen, null ) );
			}
		},
	},
} );
