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

import { store, getContext } from '@wordpress/interactivity';

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
		},
		cancelRedeem() {
			const ctx = getContext();
			ctx.confirming = false;
		},
		* confirmRedeem() {
			const ctx = getContext();
			ctx.confirming = false;
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
