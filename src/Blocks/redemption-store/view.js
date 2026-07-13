/**
 * Redemption Store — frontend Interactivity API store.
 *
 * Phase C of the Wbcom Block Quality Standard migration replaces the
 * pre-existing inline `<script>` + raw `fetch` + `window.confirm`
 * pattern with the Interactivity API: every interactive element uses
 * `data-wp-on--*` directives, the redeem call is a plain `fetch` POST
 * (the `@wordpress/api-fetch` package is not yet exposed as an ES
 * module by core), and the confirmation dialog is in-DOM markup
 * toggled via `data-wp-bind--hidden`.
 *
 * Each block instance carries its own per-instance context (item id,
 * balance, fetch state) via `data-wp-context`. The endpoint URL +
 * REST nonce + i18n strings flow in via `data-*` attributes on the
 * root element so this file holds zero plugin-specific configuration.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase C.4 / C.7
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

const NS = 'wb-gamification/redemption';

const formatNumber = ( value ) => {
	try {
		return new Intl.NumberFormat( document.documentElement.lang || 'en-US' ).format(
			Number( value )
		);
	} catch ( error ) {
		return String( value );
	}
};

const findRoot = ( ctx ) => {
	if ( typeof document === 'undefined' || ! ctx?.itemId ) {
		return null;
	}
	const card = document.querySelector(
		`.wb-gam-redemption__card[data-item-id="${ ctx.itemId }"]`
	);
	return card ? card.closest( '.wb-gam-redemption' ) : null;
};

/**
 * The confirmation dialog belonging to the reward card an action fired from.
 *
 * @param {Element} el Element the action fired on.
 * @return {HTMLDialogElement|null} The dialog, or null.
 */
function dialogFor( el ) {
	if ( ! el ) {
		return null;
	}
	// `.wb-gam-redemption__card` is the reward. The button and its confirmation dialog both live inside
	// it, in `.wb-gam-redemption__action`. (An earlier version of this looked for a
	// `.wb-gam-redemption__item` that does not exist, silently fell through to a page-wide fallback, and
	// found the RIGHT dialog by luck while leaving the opener unresolvable — so focus return failed.)
	const card = el.closest( '.wb-gam-redemption__card' );
	return card ? card.querySelector( 'dialog[data-wb-gam-dialog]' ) : null;
}

store( NS, {
	state: {
		get hasError() {
			return getContext().errorMessage !== '';
		},
		get hasSuccess() {
			const ctx = getContext();
			return ctx.successMessage !== '' || !! ctx.couponCode;
		},
	},
	actions: {
		requestRedeem() {
			const ctx = getContext();
			ctx.confirming = true;
			ctx.errorMessage = '';
			ctx.successMessage = '';
			ctx.couponCode = '';

			// Open the REAL dialog, and move focus into it.
			//
			// This used to toggle a hidden div and stop there. The member pressed "Redeem", a
			// confirmation appeared somewhere below, and their focus stayed on the button they had just
			// pressed — so to a keyboard or screen-reader user, nothing had happened. showModal() moves
			// focus in, traps it, and gives ESC for free.
			const card = getElement()?.ref?.closest( '.wb-gam-redemption__card' );
			const dialog = dialogFor( getElement()?.ref );

			if ( dialog && window.wbGam?.dialog ) {
				window.wbGam.dialog.bind( dialog );
				window.wbGam.dialog.open( dialog, {
					// A FUNCTION, not the button element. The Interactivity API re-renders this card when
					// `confirming` changes, so the button captured now is detached from the document by the
					// time the dialog closes -- and focus would be silently abandoned inside a closed
					// dialog, which is precisely where a keyboard user cannot get out of. Re-find it at
					// close time, against the DOM as it actually is then.
					opener: () => card?.querySelector( '.wb-gam-redemption__btn' ),

					// ESC closes a native dialog without telling the block, so the block's own state would
					// still say "confirming" while nothing is on screen. Next click would then do nothing.
					onClose: () => {
						ctx.confirming = false;
					},
				} );
			}
		},
		cancelRedeem() {
			const ctx = getContext();
			ctx.confirming = false;

			// Closing returns focus to the button that opened it — the shared utility does that.
			const dialog = dialogFor( getElement()?.ref );
			if ( dialog && window.wbGam?.dialog ) {
				window.wbGam.dialog.close( dialog );
			}
		},
		* confirmRedeem() {
			const ctx = getContext();
			ctx.confirming = false;

			const dialog = dialogFor( getElement()?.ref );
			if ( dialog && window.wbGam?.dialog ) {
				window.wbGam.dialog.close( dialog );
			}
			ctx.loading = true;
			ctx.errorMessage = '';
			ctx.successMessage = '';
			ctx.couponCode = '';

			const root = findRoot( ctx );
			const endpoint = root?.dataset?.redemptionEndpoint || '';
			const nonce = root?.dataset?.restNonce || '';

			if ( ! endpoint ) {
				ctx.loading = false;
				ctx.errorMessage =
					root?.dataset?.i18nMissingEndpoint ||
					'Redemption endpoint is unavailable.';
				return;
			}

			try {
				const response = yield fetch( endpoint, {
					signal: ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) ? AbortSignal.timeout( 15000 ) : undefined,
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( { item_id: ctx.itemId } ),
				} );

				const body = yield response.json();

				ctx.loading = false;

				if ( ! response.ok ) {
					ctx.errorMessage =
						( body && body.message ) ||
						root?.dataset?.i18nFailed ||
						'Redemption failed.';
					return;
				}

				if ( body && body.coupon_code ) {
					ctx.couponCode = String( body.coupon_code );
				} else {
					ctx.successMessage =
						root?.dataset?.i18nPending ||
						'Redeemed! Check your email or your account for next steps.';
				}

				ctx.balance = Math.max( 0, Number( ctx.balance ) - Number( ctx.cost ) );
				ctx.redeemed = true;

				const balanceText = root?.querySelector?.( '[data-wb-gam-balance-text]' );
				if ( balanceText ) {
					balanceText.textContent = formatNumber( ctx.balance );
				}

				// Force an immediate broker tick so the redemption-confirmed
				// toast + any badge/level side-effects show up in <1s
				// instead of waiting for the next heartbeat. We've already
				// optimistically debited ctx.balance above; the broker tick
				// also reconciles in case the server-side debit diverged
				// (rare, but the heartbeat user-channel will correct it).
				if ( window.wbGamRealtime && typeof window.wbGamRealtime.ping === 'function' ) {
					window.wbGamRealtime.ping();
				}
			} catch ( error ) {
				ctx.loading = false;
				ctx.errorMessage =
					( error && error.message ) ||
					root?.dataset?.i18nNetwork ||
					'Network error. Please try again.';
			}
		},
		dismissResult() {
			const ctx = getContext();
			ctx.errorMessage = '';
			ctx.successMessage = '';
			ctx.couponCode = '';
		},
	},
} );
