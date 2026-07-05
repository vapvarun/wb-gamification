/**
 * WB Gamification — competitor import screen.
 *
 * Lists detected source plugins, previews a migration (dry-run reconciliation),
 * and runs the real import. Talks to the ImportController REST routes through
 * the shared apiFetch wrapper (which carries the request timeout). All DOM is
 * built with createElement/textContent — no innerHTML with dynamic data.
 *
 * @package WBGamification
 * @since   1.6.2
 */
( function () {
	'use strict';

	var cfg = window.wbGamImport;
	var api = window.wbGamAdminRest;
	var app = document.getElementById( 'wb-gam-import-app' );
	if ( ! cfg || ! api || ! app ) {
		return;
	}
	var i18n = cfg.i18n || {};
	var settings = { restUrl: cfg.restUrl, nonce: cfg.nonce };

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = String( text );
		}
		return n;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	/**
	 * Render one reconciliation table (points / badges / ranks) into a card.
	 *
	 * @param {string} title   Section title.
	 * @param {Object} recMap  {uid: {...}} reconciliation object.
	 * @param {Array}  cols    [{key|fn, label}] column defs.
	 * @return {HTMLElement|null}
	 */
	function recTable( title, recMap, cols ) {
		if ( ! recMap || ! Object.keys( recMap ).length ) {
			return null;
		}
		var wrap = el( 'div', 'wb-gam-import__section' );
		wrap.appendChild( el( 'h4', 'wb-gam-import__section-title', title ) );
		var table = el( 'table', 'widefat striped wb-gam-import__table' );
		var thead = el( 'thead' );
		var htr = el( 'tr' );
		cols.forEach( function ( c ) {
			htr.appendChild( el( 'th', null, c.label ) );
		} );
		thead.appendChild( htr );
		table.appendChild( thead );
		var tbody = el( 'tbody' );
		Object.keys( recMap ).forEach( function ( uid ) {
			var rec = recMap[ uid ];
			var tr = el( 'tr' );
			cols.forEach( function ( c ) {
				var val = c.fn ? c.fn( rec, uid ) : rec[ c.key ];
				var td = el( 'td', null, val );
				if ( c.matchCell ) {
					td.className = rec.match ? 'wb-gam-import__ok' : 'wb-gam-import__bad';
					td.textContent = rec.match ? ( i18n.match || 'Reconciles' ) : ( i18n.mismatch || 'MISMATCH' );
				}
				tr.appendChild( td );
			} );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		wrap.appendChild( table );
		return wrap;
	}

	function sourceValue( rec ) {
		var keys = Object.keys( rec );
		for ( var i = 0; i < keys.length; i++ ) {
			var k = keys[ i ];
			if ( k !== 'match' && k.indexOf( 'imported' ) !== 0 && k !== 'our_level' ) {
				return rec[ k ];
			}
		}
		return '';
	}

	function renderResult( container, result ) {
		clear( container );

		var points = recTable( i18n.points || 'Points', result.reconciliation, [
			{ key: 'imported_sum', label: i18n.imported || 'Imported', fn: function ( r ) { return r.imported_sum; } },
			{ label: i18n.source || 'Source', fn: sourceValue },
			{ label: i18n.match || 'Reconciles', matchCell: true },
		] );
		if ( points ) { container.appendChild( points ); }

		var badgeMap = result.achievement_reconciliation || result.badge_reconciliation;
		var badges = recTable( i18n.badges || 'Badges', badgeMap, [
			{ label: i18n.imported || 'Imported', fn: function ( r ) { var k = Object.keys( r ).filter( function ( x ) { return x.indexOf( 'imported' ) === 0; } )[ 0 ]; return r[ k ]; } },
			{ label: i18n.source || 'Source', fn: sourceValue },
			{ label: i18n.match || 'Reconciles', matchCell: true },
		] );
		if ( badges ) { container.appendChild( badges ); }

		var ranks = recTable( i18n.ranks || 'Ranks', result.rank_reconciliation, [
			{ key: 'our_level', label: 'WB level', fn: function ( r ) { return r.our_level; } },
			{ label: i18n.source || 'Source', fn: sourceValue },
			{ label: i18n.match || 'Reconciles', matchCell: true },
		] );
		if ( ranks ) {
			container.appendChild( ranks );
			container.appendChild( el( 'p', 'wb-gam-import__note', i18n.rankNote || '' ) );
		}

		if ( result.ingest ) {
			var ing = result.ingest;
			container.appendChild( el( 'p', 'wb-gam-import__ingest',
				( i18n.done || 'Import complete.' ) + ' imported=' + ing.imported +
				' skipped=' + ing.skipped_duplicate + ' failed=' + ing.failed +
				' badges=' + ing.badges_awarded ) );
		}
	}

	function run( slug, dryRun, resultBox, btns ) {
		btns.forEach( function ( b ) { b.disabled = true; } );
		clear( resultBox );
		resultBox.appendChild( el( 'p', 'wb-gam-import__loading', dryRun ? ( i18n.previewing || 'Previewing...' ) : ( i18n.importing || 'Importing...' ) ) );

		api.apiFetch( 'POST', '/import/' + slug, { dry_run: dryRun }, settings ).then( function ( res ) {
			btns.forEach( function ( b ) { b.disabled = false; } );
			if ( ! res.ok ) {
				clear( resultBox );
				resultBox.appendChild( el( 'p', 'wb-gam-import__bad', ( res.data && res.data.message ) || i18n.error || 'Request failed.' ) );
				return;
			}
			renderResult( resultBox, res.data );
		} );
	}

	function renderSources( sources ) {
		clear( app );
		var any = sources.some( function ( s ) { return s.available; } );
		if ( ! any ) {
			app.appendChild( el( 'p', 'wb-gam-import__empty', i18n.noSources || 'No source data found.' ) );
			return;
		}
		sources.forEach( function ( s ) {
			var card = el( 'div', 'wb-gam-import__card' );
			var head = el( 'div', 'wb-gam-import__card-head' );
			head.appendChild( el( 'h3', 'wb-gam-import__card-title', s.label ) );
			head.appendChild( el( 'span', s.available ? 'wb-gam-import__badge wb-gam-import__badge--on' : 'wb-gam-import__badge', s.available ? ( i18n.available || 'Data found' ) : ( i18n.unavailable || 'No data' ) ) );
			card.appendChild( head );

			if ( s.available ) {
				var actions = el( 'div', 'wb-gam-import__actions' );
				var resultBox = el( 'div', 'wb-gam-import__result' );
				var preview = el( 'button', 'button button-secondary', i18n.preview || 'Preview (dry run)' );
				var importBtn = el( 'button', 'button button-primary', i18n.import || 'Run import' );
				var btns = [ preview, importBtn ];
				var confirming = false;
				preview.addEventListener( 'click', function () {
					confirming = false;
					importBtn.textContent = i18n.import || 'Run import';
					run( s.slug, true, resultBox, btns );
				} );
				importBtn.addEventListener( 'click', function () {
					// Two-click confirm (no native confirm() — UX-audit F8).
					if ( ! confirming ) {
						confirming = true;
						importBtn.textContent = i18n.confirmBtn || 'Click again to confirm';
						return;
					}
					confirming = false;
					importBtn.textContent = i18n.import || 'Run import';
					run( s.slug, false, resultBox, btns );
				} );
				actions.appendChild( preview );
				actions.appendChild( importBtn );
				card.appendChild( actions );
				card.appendChild( resultBox );
			}
			app.appendChild( card );
		} );
	}

	// Boot: detect sources.
	app.appendChild( el( 'p', 'wb-gam-import__loading', i18n.loading || 'Detecting...' ) );
	api.apiFetch( 'GET', '/import/sources', null, settings ).then( function ( res ) {
		if ( ! res.ok ) {
			clear( app );
			app.appendChild( el( 'p', 'wb-gam-import__bad', ( res.data && res.data.message ) || i18n.error || 'Request failed.' ) );
			return;
		}
		renderSources( ( res.data && res.data.sources ) || [] );
	} );
}() );
