/**
 * WB Gamification - settings import / export (Tools section).
 *
 * Export: fetch the settings document from the REST endpoint and trigger a
 * client-side JSON file download. Import: read a chosen JSON file and POST it
 * to the import endpoint. Uses the shared wbGamAdminRest helper.
 */
( function () {
	'use strict';

	var cfg = window.wbGamTools;
	var rest = window.wbGamAdminRest;
	if ( ! cfg || ! rest ) {
		return;
	}

	var i18n = cfg.i18n || {};
	var exportBtn = document.getElementById( 'wb-gam-export-settings' );
	var importBtn = document.getElementById( 'wb-gam-import-settings' );
	var fileInput = document.getElementById( 'wb-gam-import-file' );
	var recomputeBtn = document.getElementById( 'wb-gam-recompute-leaderboard' );
	var resetBtn = document.getElementById( 'wb-gam-reset-progress' );

	function toast( msg, tone ) {
		if ( rest.toast ) {
			rest.toast( msg, tone );
		}
	}

	function sprintf2( tpl, a, b ) {
		return String( tpl ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	if ( exportBtn ) {
		exportBtn.addEventListener( 'click', async function () {
			exportBtn.disabled = true;
			toast( i18n.exporting, 'info' );
			var res = await rest.apiFetch( 'GET', '/tools/export-settings', null, cfg );
			exportBtn.disabled = false;
			if ( ! res || ! res.ok || ! res.data ) {
				toast( i18n.exportError, 'error' );
				return;
			}
			var json = JSON.stringify( res.data, null, 2 );
			var blob = new Blob( [ json ], { type: 'application/json' } );
			var url = window.URL.createObjectURL( blob );
			var a = document.createElement( 'a' );
			a.href = url;
			a.download = 'wb-gamification-settings.json';
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			window.URL.revokeObjectURL( url );
		} );
	}

	if ( recomputeBtn ) {
		recomputeBtn.addEventListener( 'click', async function () {
			recomputeBtn.disabled = true;
			toast( i18n.recomputing, 'info' );
			var res = await rest.apiFetch( 'POST', '/tools/recompute-leaderboard', {}, cfg );
			recomputeBtn.disabled = false;
			toast( res && res.ok ? i18n.recomputed : i18n.recomputeError, res && res.ok ? 'success' : 'error' );
		} );
	}

	if ( resetBtn ) {
		resetBtn.addEventListener( 'click', async function () {
			// Destructive: require the modal confirm helper; abort if unavailable.
			if ( ! rest.confirmAction ) {
				return;
			}
			var ok = await rest.confirmAction( i18n.resetConfirm );
			if ( ! ok ) {
				return;
			}
			resetBtn.disabled = true;
			toast( i18n.resetting, 'info' );
			var res = await rest.apiFetch( 'POST', '/tools/reset-progress', { confirm: true }, cfg );
			resetBtn.disabled = false;
			if ( res && res.ok && res.data ) {
				toast( String( i18n.resetDone ).replace( '%d', res.data.tables || 0 ), 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			} else {
				toast( i18n.resetError, 'error' );
			}
		} );
	}

	if ( importBtn && fileInput ) {
		importBtn.addEventListener( 'click', async function () {
			var file = fileInput.files && fileInput.files[ 0 ];
			if ( ! file ) {
				toast( i18n.noFile, 'error' );
				return;
			}

			var ok = rest.confirmAction ? await rest.confirmAction( i18n.importConfirm ) : true;
			if ( ! ok ) {
				return;
			}

			var text;
			try {
				text = await file.text();
			} catch ( e ) {
				toast( i18n.importError, 'error' );
				return;
			}

			var doc;
			try {
				doc = JSON.parse( text );
			} catch ( e ) {
				toast( i18n.importError, 'error' );
				return;
			}

			importBtn.disabled = true;
			var res = await rest.apiFetch( 'POST', '/tools/import-settings', { document: doc }, cfg );
			importBtn.disabled = false;

			if ( res && res.ok && res.data ) {
				toast( sprintf2( i18n.imported, res.data.applied || 0, res.data.skipped || 0 ), 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 1200 );
			} else {
				toast( i18n.importError, 'error' );
			}
		} );
	}
} )();
