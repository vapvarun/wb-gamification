/**
 * WB Gamification: competitor import screen.
 *
 * Lists the detected source plugins, previews a migration (counts only, nothing is written), starts the
 * real import as a background run, and follows its progress. The run itself lives on the server in
 * chained jobs (ImportRunner), one page each, so this screen only starts it and watches: closing the tab
 * does not stop it, and reopening the screen shows the run that is already going.
 *
 * Polling is a setTimeout chain (not setInterval) that backs off from 2s to 10s, runs only while a run
 * is active, and pauses while the tab is hidden. All DOM is built with createElement/textContent: no
 * innerHTML with dynamic data.
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

	var POLL_MIN_MS = 2000;
	var POLL_MAX_MS = 10000;

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

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	// Replace %1$s style tokens in a translated string with values, in order, and collapse a printf
	// `%%` to a single percent sign (the translated strings are written for PHP's sprintf).
	function fmt( template, values ) {
		var out = String( template );
		values.forEach( function ( v, n ) {
			out = out.replace( '%' + ( n + 1 ) + '$s', String( v ) ).replace( '%' + ( n + 1 ) + '$d', String( v ) );
		} );
		return out.replace( /%%/g, '%' );
	}

	function isActive( status ) {
		return status === 'queued' || status === 'running';
	}

	function errorText( res ) {
		return ( res && res.data && res.data.message ) || t( 'error', 'Request failed.' );
	}

	/**
	 * A small table from rows. Cells are text, never HTML.
	 *
	 * @param {Array}  cols [{label, fn}] column definitions.
	 * @param {Array}  rows Row objects.
	 * @return {HTMLElement}
	 */
	function table( cols, rows ) {
		var wrap = el( 'div', 'wb-gam-import__table-wrap' );
		var tbl = el( 'table', 'widefat striped wb-gam-import__table' );
		var head = el( 'tr' );
		cols.forEach( function ( c ) {
			head.appendChild( el( 'th', null, c.label ) );
		} );
		tbl.appendChild( el( 'thead' ) ).appendChild( head );
		var body = el( 'tbody' );
		rows.forEach( function ( row ) {
			var tr = el( 'tr' );
			cols.forEach( function ( c ) {
				tr.appendChild( el( 'td', null, c.fn( row ) ) );
			} );
			body.appendChild( tr );
		} );
		tbl.appendChild( body );
		wrap.appendChild( tbl );
		return wrap;
	}

	/**
	 * The preview: what a run would do, and what an earlier run already left. Writes nothing.
	 *
	 * @param {HTMLElement} box Container.
	 * @param {Object}      p   Preview from POST /import/{source} with dry_run=true.
	 */
	function renderPreview( box, p ) {
		clear( box );
		box.appendChild( el( 'p', 'wb-gam-import__summary', fmt( t( 'previewSummary', 'A run would import %1$s point rows, %2$s badge awards and %3$s rank tiers, in about %4$s background jobs.' ), [ p.points_rows, p.awards, p.ranks, p.jobs ] ) ) );

		var already = p.already_imported || {};
		if ( already.events > 0 || already.badges > 0 ) {
			box.appendChild( el( 'p', 'wb-gam-import__note', fmt( t( 'alreadyImported', 'An earlier run already imported %1$s events and %2$s badge awards. Those rows are skipped, so running again is safe.' ), [ already.events, already.badges ] ) ) );
		}

		if ( p.sample && p.sample.length ) {
			box.appendChild( el( 'h4', 'wb-gam-import__section-title', t( 'sample', 'First rows the run would import' ) ) );
			box.appendChild( table(
				[
					{ label: t( 'user', 'User' ), fn: function ( r ) { return r.user_id; } },
					{ label: t( 'action', 'Action' ), fn: function ( r ) { return r.action_id; } },
					{ label: t( 'points', 'Points' ), fn: function ( r ) { return r.points; } },
					{ label: t( 'when', 'When (UTC)' ), fn: function ( r ) { return r.occurred_at; } },
				],
				p.sample
			) );
		}
		box.appendChild( el( 'p', 'wb-gam-import__note', t( 'previewNote', 'Preview only: nothing was written. After the import, every member is checked against the source plugin\'s own totals.' ) ) );
	}

	/**
	 * Build a live progress panel for a source and follow it until the run ends.
	 *
	 * @param {string}      slug Source slug.
	 * @param {HTMLElement} box  Container (emptied).
	 * @param {Object}      run  Latest progress object.
	 * @param {Function}    done Called once when the run leaves the active states.
	 */
	function followRun( slug, box, run, done ) {
		clear( box );

		var panel = el( 'div', 'wb-gam-import__panel' );
		var status = el( 'p', 'wb-gam-import__status' );
		var bar = el( 'progress', 'wb-gam-import__bar' );
		bar.max = 100;
		bar.setAttribute( 'aria-label', t( 'progress', 'Import progress' ) );
		var detail = el( 'p', 'wb-gam-import__note' );
		var alert = el( 'div', 'wb-gam-import__alert' );
		alert.hidden = true;
		var result = el( 'div', 'wb-gam-import__result-body' );
		panel.appendChild( status );
		panel.appendChild( bar );
		panel.appendChild( detail );
		panel.appendChild( alert );
		panel.appendChild( result );
		box.appendChild( panel );

		var timer = 0;
		var delay = POLL_MIN_MS;
		var finished = false;

		function phaseLabel( r ) {
			var labels = {
				levels: t( 'phaseLevels', 'Creating levels' ),
				points: t( 'phasePoints', 'Importing points' ),
				awards: t( 'phaseAwards', 'Importing badges' ),
				recompute: t( 'phaseRecompute', 'Working out badges and levels for each member' ),
				reconcile: t( 'phaseReconcile', 'Checking every member against the source' ),
				done: t( 'phaseDone', 'Finished' ),
			};
			return labels[ r.phase ] || r.phase;
		}

		function paint( r ) {
			bar.value = r.percent;
			// Queued means the job is waiting for the background queue (WP-Cron), which can take up to a
			// minute to pick it up. Say so, rather than show a phase that has not started as if it hung.
			status.textContent = r.status === 'queued'
				? t( 'queued', 'Waiting for the background queue to start. This can take up to a minute.' )
				: fmt( t( 'statusLine', '%1$s: %2$s%%' ), [ phaseLabel( r ), r.percent ] );
			if ( r.phase_total > 0 && isActive( r.status ) ) {
				detail.textContent = fmt( t( 'phaseCounts', '%1$s of %2$s' ), [ r.phase_done, r.phase_total ] );
			} else {
				detail.textContent = '';
			}
			detail.hidden = '' === detail.textContent;

			clear( alert );
			alert.hidden = true;
			if ( r.stalled || r.status === 'failed' ) {
				alert.hidden = false;
				var message = r.status === 'failed'
					? fmt( t( 'failed', 'The import paused at its last checkpoint: %1$s' ), [ r.error || '' ] )
					: t( 'stalled', 'The background queue has stopped moving. Resume to continue from where it stopped. If this keeps happening, WP-Cron may be disabled: run the import from WP-CLI with --sync.' );
				alert.appendChild( el( 'p', 'wb-gam-import__warning', message ) );
				var resume = el( 'button', 'button button-primary', t( 'resume', 'Resume import' ) );
				resume.type = 'button';
				resume.addEventListener( 'click', function () {
					resume.disabled = true;
					api.apiFetch( 'POST', '/import/' + slug + '/resume', {}, settings ).then( function ( res ) {
						if ( ! res.ok ) {
							resume.disabled = false;
							alert.appendChild( el( 'p', 'wb-gam-import__bad', errorText( res ) ) );
							return;
						}
						delay = POLL_MIN_MS;
						schedule( 0 );
					} );
				} );
				alert.appendChild( resume );
			}

			if ( r.status === 'complete' ) {
				renderComplete( result, r );
			}
		}

		function step() {
			if ( finished ) {
				return;
			}
			// A hidden tab does not need a live bar; look again when it comes back.
			if ( document.hidden ) {
				schedule( POLL_MAX_MS );
				return;
			}
			api.apiFetch( 'GET', '/import/' + slug + '/progress', null, settings ).then( function ( res ) {
				if ( finished ) {
					return;
				}
				if ( ! res.ok ) {
					detail.textContent = errorText( res );
					schedule( POLL_MAX_MS );
					return;
				}
				paint( res.data );
				if ( isActive( res.data.status ) || res.data.stalled ) {
					delay = Math.min( Math.round( delay * 1.5 ), POLL_MAX_MS );
					schedule( delay );
				} else {
					finished = true;
					done( res.data );
				}
			} );
		}

		function schedule( ms ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( step, ms );
		}

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && ! finished ) {
				schedule( 0 );
			}
		} );

		paint( run );
		if ( isActive( run.status ) || run.stalled ) {
			schedule( POLL_MIN_MS );
		} else {
			finished = true;
			done( run );
		}
	}

	/**
	 * The finished run: totals, and reconciliation with the mismatches that were sampled.
	 *
	 * @param {HTMLElement} box Container.
	 * @param {Object}      r   Progress of a completed run.
	 */
	function renderComplete( box, r ) {
		clear( box );
		var totals = r.totals || {};
		box.appendChild( el( 'p', 'wb-gam-import__ingest', fmt( t( 'totals', 'Imported %1$s, skipped %2$s already imported, failed %3$s, badges awarded %4$s, levels created %5$s.' ), [ totals.imported, totals.skipped_duplicate, totals.failed, totals.badges_awarded, totals.levels_created ] ) ) );

		var m = r.mismatches || { points: 0, badges: 0, ranks: 0 };
		var count = m.points + m.badges + m.ranks;
		if ( count === 0 ) {
			box.appendChild( el( 'p', 'wb-gam-import__ok', t( 'reconciled', 'Every member reconciles against the source: points, badges and ranks.' ) ) );
			return;
		}

		box.appendChild( el( 'p', 'wb-gam-import__bad', fmt( t( 'mismatches', '%1$s points, %2$s badge and %3$s rank mismatches.' ), [ m.points, m.badges, m.ranks ] ) ) );
		if ( r.sample && r.sample.length ) {
			box.appendChild( el( 'h4', 'wb-gam-import__section-title', fmt( t( 'firstMismatches', 'First %1$s mismatches' ), [ r.sample.length ] ) ) );
			box.appendChild( table(
				[
					{ label: t( 'user', 'User' ), fn: function ( x ) { return x.user_id; } },
					{ label: t( 'kind', 'Kind' ), fn: function ( x ) { return x.kind; } },
					{ label: t( 'imported', 'Imported' ), fn: function ( x ) { return x.ours; } },
					{ label: t( 'source', 'Source' ), fn: function ( x ) { return x.source; } },
				],
				r.sample
			) );
		}
		if ( m.ranks > 0 ) {
			box.appendChild( el( 'p', 'wb-gam-import__note', t( 'rankNote', 'Rank mismatches usually mean this site already has levels that collide with the imported tiers.' ) ) );
		}
	}

	function renderSources( sources ) {
		clear( app );
		var any = sources.some( function ( s ) { return s.available; } );
		if ( ! any ) {
			app.appendChild( el( 'p', 'wb-gam-import__empty', t( 'noSources', 'No source data found.' ) ) );
			return;
		}

		sources.forEach( function ( s ) {
			var card = el( 'div', 'wb-gam-import__card' );
			var head = el( 'div', 'wb-gam-import__card-head' );
			head.appendChild( el( 'h3', 'wb-gam-import__card-title', s.label ) );
			head.appendChild( el( 'span', s.available ? 'wb-gam-import__badge wb-gam-import__badge--on' : 'wb-gam-import__badge', s.available ? t( 'available', 'Data found' ) : t( 'unavailable', 'No data' ) ) );
			card.appendChild( head );

			if ( s.available ) {
				var actions = el( 'div', 'wb-gam-import__actions' );
				var box = el( 'div', 'wb-gam-import__result' );
				var preview = el( 'button', 'button button-secondary', t( 'preview', 'Preview (dry run)' ) );
				var importBtn = el( 'button', 'button button-primary', t( 'import', 'Run import' ) );
				preview.type = 'button';
				importBtn.type = 'button';
				var confirming = false;

				function busy( on ) {
					preview.disabled = on;
					importBtn.disabled = on;
				}

				preview.addEventListener( 'click', function () {
					confirming = false;
					importBtn.textContent = t( 'import', 'Run import' );
					busy( true );
					clear( box );
					box.appendChild( el( 'p', 'wb-gam-import__loading', t( 'previewing', 'Previewing...' ) ) );
					api.apiFetch( 'POST', '/import/' + s.slug, { dry_run: true }, settings ).then( function ( res ) {
						busy( false );
						if ( ! res.ok ) {
							clear( box );
							box.appendChild( el( 'p', 'wb-gam-import__bad', errorText( res ) ) );
							return;
						}
						renderPreview( box, res.data );
					} );
				} );

				importBtn.addEventListener( 'click', function () {
					// Two-click confirm (no native confirm(): UX-audit F8).
					if ( ! confirming ) {
						confirming = true;
						importBtn.textContent = t( 'confirmBtn', 'Click again to confirm' );
						return;
					}
					confirming = false;
					importBtn.textContent = t( 'import', 'Run import' );
					busy( true );
					api.apiFetch( 'POST', '/import/' + s.slug, { dry_run: false }, settings ).then( function ( res ) {
						if ( ! res.ok ) {
							busy( false );
							clear( box );
							box.appendChild( el( 'p', 'wb-gam-import__bad', errorText( res ) ) );
							return;
						}
						followRun( s.slug, box, res.data, function () { busy( false ); } );
					} );
				} );

				actions.appendChild( preview );
				actions.appendChild( importBtn );
				card.appendChild( actions );
				card.appendChild( box );

				// A run that is already going (or stopped) shows up straight away: the server owns it,
				// not this tab.
				if ( s.run && s.run.status !== 'idle' ) {
					busy( isActive( s.run.status ) || s.run.stalled );
					api.apiFetch( 'GET', '/import/' + s.slug + '/progress', null, settings ).then( function ( res ) {
						if ( res.ok ) {
							followRun( s.slug, box, res.data, function () { busy( false ); } );
						}
					} );
				}
			}
			app.appendChild( card );
		} );
	}

	// Boot: detect sources.
	app.appendChild( el( 'p', 'wb-gam-import__loading', t( 'loading', 'Detecting...' ) ) );
	api.apiFetch( 'GET', '/import/sources', null, settings ).then( function ( res ) {
		if ( ! res.ok ) {
			clear( app );
			app.appendChild( el( 'p', 'wb-gam-import__bad', errorText( res ) ) );
			return;
		}
		renderSources( ( res.data && res.data.sources ) || [] );
	} );
}() );
