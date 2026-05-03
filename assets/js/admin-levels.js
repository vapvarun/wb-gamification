/**
 * Admin: Levels tab — REST-driven (replaces admin-post.php form-post handlers).
 *
 * Tier 0.C (REST migration) wired the SettingsPage Levels tab to the
 * canonical /wb-gamification/v1/levels endpoints. Bulk save sends N parallel
 * PATCH requests; create sends POST; delete sends DELETE. The table refreshes
 * in-place via GET on every successful mutation — no full-page reload.
 *
 * Depends on assets/js/admin-rest-utils.js (window.wbGamAdminRest) for the
 * shared toast + apiFetch helpers.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	const settings = window.wbGamLevelsSettings;
	const utils    = window.wbGamAdminRest;
	if ( ! settings || ! settings.restUrl || ! settings.nonce || ! utils ) {
		return;
	}

	const root = document.querySelector( '[data-wb-gam-levels-root]' );
	if ( ! root ) {
		return;
	}

	const i18n = settings.i18n || {};

	/**
	 * Render the levels editor (bulk-edit table) from a fresh GET.
	 *
	 * @param {Array<object>} levels Levels collection from the REST list response.
	 */
	function render( levels ) {
		const tbody = root.querySelector( '[data-wb-gam-levels-tbody]' );
		if ( ! tbody ) {
			return;
		}
		utils.clearChildren( tbody );

		levels.forEach( function ( level ) {
			const tr = document.createElement( 'tr' );
			tr.dataset.id = String( level.id );

			const tdName = document.createElement( 'td' );
			const inputName = document.createElement( 'input' );
			inputName.type = 'text';
			inputName.value = level.name;
			inputName.className = 'wb-gam-input-full';
			inputName.setAttribute( 'aria-label', i18n.aria_name || 'Level name' );
			inputName.dataset.wbGamLevelField = 'name';
			tdName.appendChild( inputName );

			const tdPoints = document.createElement( 'td' );
			const inputPoints = document.createElement( 'input' );
			inputPoints.type = 'number';
			inputPoints.value = String( level.min_points );
			inputPoints.min = '0';
			inputPoints.className = 'wb-gam-input-medium';
			inputPoints.setAttribute( 'aria-label', i18n.aria_points || 'Level minimum points' );
			inputPoints.dataset.wbGamLevelField = 'min_points';
			if ( level.min_points === 0 ) {
				inputPoints.readOnly = true;
				inputPoints.title = i18n.starting_locked || 'Starting level is always 0';
			}
			tdPoints.appendChild( inputPoints );

			const tdActions = document.createElement( 'td' );
			if ( level.min_points > 0 ) {
				const btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'button button-small button-link-delete';
				btn.textContent = i18n.delete || 'Delete';
				btn.dataset.wbGamLevelDelete = String( level.id );
				tdActions.appendChild( btn );
			} else {
				const span = document.createElement( 'span' );
				span.className = 'description';
				span.textContent = i18n.starting_level || 'Starting level';
				tdActions.appendChild( span );
			}

			tr.appendChild( tdName );
			tr.appendChild( tdPoints );
			tr.appendChild( tdActions );
			tbody.appendChild( tr );
		} );
	}

	async function refresh() {
		const result = await utils.apiFetch( 'GET', '/levels', null, settings );
		if ( ! result.ok ) {
			utils.toastError( result, i18n.refresh_failed || 'Failed to load levels.' );
			return;
		}
		render( Array.isArray( result.data ) ? result.data : [] );
	}

	async function onBulkSave( event ) {
		event.preventDefault();

		const rows = root.querySelectorAll( '[data-wb-gam-levels-tbody] tr[data-id]' );
		const requests = [];
		rows.forEach( function ( tr ) {
			const id = parseInt( tr.dataset.id, 10 );
			if ( ! id ) {
				return;
			}
			const nameField = tr.querySelector( '[data-wb-gam-level-field="name"]' );
			const pointsField = tr.querySelector( '[data-wb-gam-level-field="min_points"]' );
			if ( ! nameField || ! pointsField ) {
				return;
			}
			requests.push( utils.apiFetch( 'PATCH', '/levels/' + id, {
				name: nameField.value.trim(),
				min_points: parseInt( pointsField.value, 10 ) || 0,
			}, settings ) );
		} );

		if ( requests.length === 0 ) {
			return;
		}

		const button = event.submitter || root.querySelector( '[data-wb-gam-levels-save]' );
		if ( button ) {
			button.disabled = true;
		}

		try {
			const results = await Promise.all( requests );
			const failures = results.filter( function ( r ) { return ! r.ok; } );
			if ( failures.length === 0 ) {
				utils.toast( i18n.saved || 'Levels saved.', 'success' );
			} else {
				utils.toastError( failures[ 0 ], i18n.save_failed || 'Some levels failed to save.' );
			}
			await refresh();
		} finally {
			if ( button ) {
				button.disabled = false;
			}
		}
	}

	async function onAddLevel( event ) {
		event.preventDefault();

		const form = event.currentTarget;
		const nameField = form.querySelector( '[name="wb_gam_new_level_name"]' );
		const pointsField = form.querySelector( '[name="wb_gam_new_level_points"]' );
		if ( ! nameField || ! pointsField ) {
			return;
		}

		const name = nameField.value.trim();
		const minPoints = parseInt( pointsField.value, 10 ) || 0;
		if ( ! name || minPoints < 1 ) {
			utils.toast( i18n.add_invalid || 'Provide a name and points value.', 'error' );
			return;
		}

		const button = event.submitter || form.querySelector( '[data-wb-gam-levels-add]' );
		if ( button ) {
			button.disabled = true;
		}

		try {
			const result = await utils.apiFetch( 'POST', '/levels', {
				name: name,
				min_points: minPoints,
			}, settings );
			if ( result.ok ) {
				utils.toast( i18n.added || 'Level added.', 'success' );
				nameField.value = '';
				pointsField.value = '';
				await refresh();
			} else {
				utils.toastError( result, i18n.add_failed || 'Failed to add level.' );
			}
		} finally {
			if ( button ) {
				button.disabled = false;
			}
		}
	}

	async function onDelete( event ) {
		const button = event.target.closest( '[data-wb-gam-level-delete]' );
		if ( ! button ) {
			return;
		}
		const id = parseInt( button.dataset.wbGamLevelDelete, 10 );
		if ( ! id ) {
			return;
		}
		const ok = await utils.confirmAction( {
			message: i18n.confirm_delete || 'Delete this level?',
			tone:    'danger',
			confirmText: i18n.delete || 'Delete',
		} );
		if ( ! ok ) {
			return;
		}

		button.disabled = true;
		const result = await utils.apiFetch( 'DELETE', '/levels/' + id, null, settings );
		if ( result.ok ) {
			utils.toast( i18n.deleted || 'Level deleted.', 'success' );
			await refresh();
		} else {
			utils.toastError( result, i18n.delete_failed || 'Failed to delete level.' );
			button.disabled = false;
		}
	}

	const bulkForm = root.querySelector( '[data-wb-gam-levels-bulk-form]' );
	if ( bulkForm ) {
		bulkForm.addEventListener( 'submit', onBulkSave );
	}

	const addForm = root.querySelector( '[data-wb-gam-levels-add-form]' );
	if ( addForm ) {
		addForm.addEventListener( 'submit', onAddLevel );
	}

	root.addEventListener( 'click', onDelete );
	// Keyboard parity for the delegated delete handler.
	root.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		const btn = e.target.closest( '[data-wb-gam-level-delete]' );
		if ( btn ) {
			e.preventDefault();
			btn.click();
		}
	} );
} )();
