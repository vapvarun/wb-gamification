/**
 * Hub block — currency conversion modal driver.
 *
 * Wires every `[data-wb-gam-convert-open]` button on the hub to the shared
 * `<dialog data-wb-gam-convert-dialog>` modal: filters destination options
 * by source slug, computes a live "spend X → get Y" preview as the member
 * types, and posts to the REST endpoint to debit + credit atomically.
 *
 * Dependencies (declared on the script handle):
 *   - wp-api-fetch  → POST helper that auto-attaches the REST nonce
 *   - wp-i18n       → translatable preview / error strings
 *
 * Localised data (window.wbGamHubConvert):
 *   - restUrl: REST namespace base (e.g. /wp-json/wb-gamification/v1/)
 *   - nonce:   wp_rest cookie nonce (already on apiFetch via dependency)
 */
( function () {
	if ( typeof window === 'undefined' ) {
		return;
	}

	const cfg = window.wbGamHubConvert || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const i18n = ( window.wp && window.wp.i18n ) || {};
	const __ = i18n.__ ? i18n.__ : ( s ) => s;
	const sprintf = i18n.sprintf
		? i18n.sprintf
		: ( s, ...a ) => {
				let i = 0;
				return s.replace( /%[sd]/g, () => String( a[ i++ ] ) );
		  };

	if ( ! apiFetch || ! cfg.restUrl ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );

	function init( dialog ) {
		const form = dialog.querySelector( '[data-wb-gam-convert-form]' );
		const balanceEl = dialog.querySelector( '[data-wb-gam-convert-balance]' );
		const fromLabelEl = dialog.querySelector( '[data-wb-gam-convert-from-label]' );
		const toSelect = dialog.querySelector( '[data-wb-gam-convert-to]' );
		const amountInput = dialog.querySelector( '[data-wb-gam-convert-amount]' );
		const previewEl = dialog.querySelector( '[data-wb-gam-convert-preview]' );
		const submitBtn = dialog.querySelector( '[data-wb-gam-convert-submit]' );

		if ( ! form || ! toSelect || ! amountInput || ! submitBtn ) {
			return;
		}

		let activeFromType = '';
		let activeBalance = 0;

		const filterOptions = ( fromType ) => {
			let firstVisible = null;
			Array.prototype.forEach.call( toSelect.options, ( opt ) => {
				const matches = opt.dataset.fromType === fromType;
				opt.hidden = ! matches;
				opt.disabled = ! matches;
				if ( matches && ! firstVisible ) {
					firstVisible = opt;
				}
			} );
			if ( firstVisible ) {
				toSelect.value = firstVisible.value;
			}
		};

		const updatePreview = () => {
			const opt = toSelect.options[ toSelect.selectedIndex ];
			if ( ! opt || opt.hidden ) {
				previewEl.textContent = '';
				return;
			}
			const fromAmount = parseInt( opt.dataset.fromAmount || '0', 10 );
			const toAmount = parseInt( opt.dataset.toAmount || '0', 10 );
			const minConvert = parseInt( opt.dataset.min || '1', 10 );
			const amount = parseInt( amountInput.value || '0', 10 );

			if ( ! fromAmount || ! amount ) {
				previewEl.textContent = '';
				return;
			}
			if ( amount < minConvert ) {
				previewEl.textContent = sprintf(
					/* translators: %d minimum convert amount */
					__( 'Minimum conversion: %d', 'wb-gamification' ),
					minConvert
				);
				return;
			}
			if ( amount > activeBalance ) {
				previewEl.textContent = __( 'Amount exceeds your balance.', 'wb-gamification' );
				return;
			}
			const credited = Math.floor( ( amount * toAmount ) / fromAmount );
			previewEl.textContent = sprintf(
				/* translators: 1: spent amount, 2: from-label, 3: credited amount, 4: to-label */
				__( 'Spend %1$d %2$s — receive %3$d %4$s.', 'wb-gamification' ),
				amount,
				fromLabelEl.textContent || '',
				credited,
				opt.textContent.split( '=' ).pop().trim().replace( /^\d+\s*/, '' )
			);
		};

		// Open handlers — every Convert button on every currency tile.
		document
			.querySelectorAll( '[data-wb-gam-convert-open]' )
			.forEach( ( btn ) => {
				btn.addEventListener( 'click', ( ev ) => {
					ev.preventDefault();
					activeFromType = btn.dataset.fromType || '';
					activeBalance = parseInt( btn.dataset.balance || '0', 10 );
					if ( balanceEl ) {
						balanceEl.textContent = String( activeBalance );
					}
					if ( fromLabelEl ) {
						fromLabelEl.textContent = btn.dataset.fromLabel || '';
					}
					amountInput.value = '';
					previewEl.textContent = '';
					filterOptions( activeFromType );
					if ( typeof dialog.showModal === 'function' ) {
						dialog.showModal();
					} else {
						dialog.setAttribute( 'open', 'open' );
					}
					amountInput.focus();
				} );
			} );

		// Close handlers — × button + Cancel.
		dialog.querySelectorAll( '[data-wb-gam-convert-close]' ).forEach( ( el ) => {
			el.addEventListener( 'click', ( ev ) => {
				ev.preventDefault();
				dialog.close();
			} );
		} );

		// Live preview.
		toSelect.addEventListener( 'change', updatePreview );
		amountInput.addEventListener( 'input', updatePreview );

		// Submit — POST and refresh the page so balance + tiles update.
		form.addEventListener( 'submit', ( ev ) => {
			ev.preventDefault();
			const opt = toSelect.options[ toSelect.selectedIndex ];
			if ( ! opt || opt.hidden ) {
				return;
			}
			const amount = parseInt( amountInput.value || '0', 10 );
			if ( ! amount ) {
				return;
			}
			const fromType = activeFromType;
			const toType = opt.value;

			submitBtn.disabled = true;
			apiFetch( {
				path: 'wb-gamification/v1/point-types/' + encodeURIComponent( fromType ) + '/convert',
				method: 'POST',
				data: { to_type: toType, amount },
			} )
				.then( () => {
					dialog.close();
					window.location.reload();
				} )
				.catch( ( err ) => {
					const message = ( err && err.message ) || __( 'Conversion failed. Try again.', 'wb-gamification' );
					previewEl.textContent = message;
					submitBtn.disabled = false;
				} );
		} );
	}

	function boot() {
		document
			.querySelectorAll( '[data-wb-gam-convert-dialog]' )
			.forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
