/**
 * Year Recap — frontend Interactivity API store.
 *
 * Replaces the inline `onclick` handler in the legacy block. The
 * share button uses the Web Share API when available, falling back
 * to clipboard copy otherwise.
 */

import { store, getContext } from '@wordpress/interactivity';

const NS = 'wb-gamification/recap';

store( NS, {
	state: {
		get copied() {
			return getContext().copied === true;
		},
	},
	actions: {
		* share() {
			const ctx = getContext();
			const url = window.location.href;
			const title = ctx.shareTitle || document.title;
			const text  = ctx.shareText || '';

			if ( navigator.share ) {
				try {
					yield navigator.share( { title, text, url } );
				} catch ( err ) {
					// User cancelled or share unavailable — silent.
				}
				return;
			}

			if ( navigator.clipboard ) {
				try {
					yield navigator.clipboard.writeText( url );
					ctx.copied = true;
					setTimeout( () => {
						ctx.copied = false;
					}, 2400 );
				} catch ( err ) {
					// Clipboard blocked.
				}
			}
		},
	},
} );
