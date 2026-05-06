/**
 * Admin: API Keys — REST-driven (replaces 3 admin-post.php form-post handlers).
 *
 * /wb-gamification/v1/api-keys              — POST + GET (list)
 * /wb-gamification/v1/api-keys/{id}/revoke  — PATCH
 * /wb-gamification/v1/api-keys/{id}         — DELETE
 *
 * v1.1+ ID-based routes — server stores hashed keys, can't look up by full
 * secret because the secret is never persisted. Each row has a stable id
 * the admin UI uses for revoke/delete. Full secret is only ever returned
 * in the create response.
 *
 * Depends on assets/js/admin-rest-utils.js (window.wbGamAdminRest).
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	const settings = window.wbGamApiKeysSettings;
	const utils    = window.wbGamAdminRest;
	if ( ! settings || ! utils ) {
		return;
	}

	const root = document.querySelector( '[data-wb-gam-api-keys-root]' );
	if ( ! root ) {
		return;
	}

	const i18n = settings.i18n || {};

	/**
	 * Render the active-keys table from a fresh GET response.
	 *
	 * @param {Array<object>} items REST list payload (`items` field).
	 */
	function render( items ) {
		const tbody = root.querySelector( '[data-wb-gam-api-keys-tbody]' );
		const empty = root.querySelector( '[data-wb-gam-api-keys-empty]' );
		const card  = root.querySelector( '[data-wb-gam-api-keys-card]' );
		if ( ! tbody ) {
			return;
		}
		utils.clearChildren( tbody );

		if ( ! items.length ) {
			if ( card )  { card.style.display  = 'none'; }
			if ( empty ) { empty.style.display = ''; }
			return;
		}
		if ( card )  { card.style.display  = ''; }
		if ( empty ) { empty.style.display = 'none'; }

		items.forEach( function ( item ) {
			const tr = document.createElement( 'tr' );
			tr.dataset.keyPreview = item.key_preview || '';
			tr.dataset.keyId      = String( item.id || '' );

			tr.appendChild( cellText( item.label || '', { strong: true } ) );
			tr.appendChild( cellCode( item.site_id || '—' ) );
			tr.appendChild( cellCode( item.key_preview || '' ) );
			tr.appendChild( cellText( item.created_at || '' ) );
			tr.appendChild( cellText( item.last_used || '—' ) );

			const tdStatus = document.createElement( 'td' );
			const pill = document.createElement( 'span' );
			pill.className = 'wbgam-pill ' + ( item.active ? 'wbgam-pill--active' : 'wbgam-pill--danger' );
			pill.textContent = item.active ? ( i18n.active || 'Active' ) : ( i18n.revoked || 'Revoked' );
			tdStatus.appendChild( pill );
			tr.appendChild( tdStatus );

			const tdActions = document.createElement( 'td' );
			if ( item.active ) {
				const revoke = document.createElement( 'button' );
				revoke.type = 'button';
				revoke.className = 'wbgam-btn wbgam-btn--sm wbgam-btn--secondary';
				revoke.textContent = i18n.revoke || 'Revoke';
				revoke.dataset.wbGamApiKeyAction = 'revoke';
				tdActions.appendChild( revoke );
			}
			const del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'wbgam-btn wbgam-btn--sm wbgam-btn--danger';
			del.style.marginInlineStart = '4px';
			del.textContent = i18n.delete || 'Delete';
			del.dataset.wbGamApiKeyAction = 'delete';
			tdActions.appendChild( del );

			tr.appendChild( tdActions );
			tbody.appendChild( tr );
		} );
	}

	function cellText( text, opts ) {
		opts = opts || {};
		const td = document.createElement( 'td' );
		if ( opts.strong ) {
			const strong = document.createElement( 'strong' );
			strong.textContent = text;
			td.appendChild( strong );
		} else {
			td.textContent = text;
		}
		return td;
	}

	function cellCode( text ) {
		const td = document.createElement( 'td' );
		const code = document.createElement( 'code' );
		code.textContent = text;
		td.appendChild( code );
		return td;
	}

	/**
	 * Surface the freshly-created secret in a copy-once panel.
	 *
	 * @param {string} secret Full key value.
	 */
	function showFreshSecret( secret ) {
		const panel = root.querySelector( '[data-wb-gam-api-keys-fresh]' );
		const code  = root.querySelector( '[data-wb-gam-api-keys-fresh-code]' );
		if ( ! panel || ! code ) {
			return;
		}
		code.textContent = secret;
		panel.classList.remove( 'wbgam-is-hidden' );

		// Wire the inline-banner dismiss button (idempotent — only attaches once).
		const dismiss = panel.querySelector( '[data-wb-gam-banner-dismiss]' );
		if ( dismiss && ! dismiss.dataset.wired ) {
			dismiss.dataset.wired = '1';
			dismiss.addEventListener( 'click', function () {
				panel.classList.add( 'wbgam-is-hidden' );
			} );
		}
	}

	async function refresh() {
		const result = await utils.apiFetch( 'GET', '/api-keys', null, settings );
		if ( ! result.ok ) {
			utils.toastError( result, i18n.refresh_failed || 'Failed to load API keys.' );
			return;
		}
		const items = ( result.data && Array.isArray( result.data.items ) ) ? result.data.items : [];
		render( items );
	}

	async function onCreate( event ) {
		event.preventDefault();
		const form = event.currentTarget;
		const labelField = form.querySelector( '[name="key_label"]' );
		const siteField  = form.querySelector( '[name="site_id"]' );
		if ( ! labelField || ! labelField.value.trim() ) {
			utils.toast( i18n.label_required || 'Label is required.', 'error' );
			return;
		}

		const button = event.submitter || form.querySelector( '[data-wb-gam-api-keys-create]' );
		if ( button ) { button.disabled = true; }

		try {
			const result = await utils.apiFetch( 'POST', '/api-keys', {
				label:   labelField.value.trim(),
				site_id: siteField ? siteField.value.trim() : '',
			}, settings );
			if ( result.ok && result.data && result.data.secret ) {
				utils.toast( i18n.created || 'API key generated.', 'success' );
				showFreshSecret( result.data.secret );
				labelField.value = '';
				if ( siteField ) { siteField.value = ''; }
				await refresh();
			} else {
				utils.toastError( result, i18n.create_failed || 'Failed to generate API key.' );
			}
		} finally {
			if ( button ) { button.disabled = false; }
		}
	}

	async function onRowAction( event ) {
		const button = event.target.closest( '[data-wb-gam-api-key-action]' );
		if ( ! button ) {
			return;
		}
		const tr = button.closest( 'tr[data-key-preview]' );
		if ( ! tr ) {
			return;
		}

		// Server-side action targets the row by stable id. Pre-1.1 the action
		// targeted the full key, which broke after migration to hashed
		// storage (the server can't look up by full key anymore — it only
		// has the hash). The id is exposed by the list response and rendered
		// into tr.dataset.keyId on every refresh.
		const keyId = tr.dataset.keyId;
		if ( ! keyId ) {
			utils.toast( i18n.row_no_key || 'Reload the page and try again.', 'error' );
			return;
		}

		const action = button.dataset.wbGamApiKeyAction;
		if ( action === 'delete' ) {
			const ok = await utils.confirmAction( {
				message:     i18n.confirm_delete || 'Delete this key permanently?',
				tone:        'danger',
				confirmText: i18n.delete || 'Delete',
			} );
			if ( ! ok ) {
				return;
			}
			button.disabled = true;
			const result = await utils.apiFetch( 'DELETE', '/api-keys/' + encodeURIComponent( keyId ), null, settings );
			if ( result.ok ) {
				utils.toast( i18n.deleted || 'API key deleted.', 'success' );
				await refresh();
			} else {
				utils.toastError( result, i18n.delete_failed || 'Failed to delete key.' );
				button.disabled = false;
			}
		} else if ( action === 'revoke' ) {
			button.disabled = true;
			const result = await utils.apiFetch( 'PATCH', '/api-keys/' + encodeURIComponent( keyId ) + '/revoke', null, settings );
			if ( result.ok ) {
				utils.toast( i18n.revoked || 'API key revoked.', 'success' );
				await refresh();
			} else {
				utils.toastError( result, i18n.revoke_failed || 'Failed to revoke key.' );
				button.disabled = false;
			}
		}
	}

	const createForm = root.querySelector( '[data-wb-gam-api-keys-create-form]' );
	if ( createForm ) {
		createForm.addEventListener( 'submit', onCreate );
	}

	root.addEventListener( 'click', onRowAction );
	// Keyboard parity for the delegated row-action listener.
	root.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		const btn = e.target.closest( '[data-wb-gam-api-key-action]' );
		if ( btn ) {
			e.preventDefault();
			btn.click();
		}
	} );
} )();
