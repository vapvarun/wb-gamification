/**
 * Settings > Earning: every action-row change saves on its own (one save path).
 *
 * Each field tagged `data-wb-gam-action-override` (enabled, points, point_type, cooldown,
 * daily_cap, weekly_cap, submittable) POSTs to /actions/{id}/overrides. A save shows
 * "Saved" with Undo for 10 seconds; a failed save puts the old value back and says so.
 * Limits: an empty field is "no limit" (sent as 0). The toolbar filters the fixed list
 * client-side (search, On / Off / Takes points / Has a limit, plugin).
 *
 * @since 1.4.0, one save path since 1.7.0
 */

( function () {
	'use strict';

	const cfg = window.wbGamActionOverrides;
	if ( ! cfg || ! document.querySelector( '[data-wb-gam-earn-row]' ) ) {
		return;
	}
	const s       = cfg.i18n;
	const UNDO_MS = 10000;
	const LIMITS  = [ 'cooldown', 'daily_cap', 'weekly_cap' ];
	const timers  = new WeakMap();
	let undoTimer = 0;
	let undoFn    = null;

	const fmt  = ( str, ...args ) => args.reduce( ( out, a, i ) => out.replace( '%' + ( i + 1 ) + '$d', a ).replace( '%d', a ).replace( '%s', a ), str );
	const rows = () => Array.from( document.querySelectorAll( '[data-wb-gam-earn-row]' ) );
	const field = ( row, name ) => row.querySelector( '[data-wb-gam-action-override="' + name + '"]' );

	function read( input ) {
		if ( input.type === 'checkbox' ) {
			return input.checked;
		}
		if ( input.type === 'number' ) {
			return '' === input.value ? 0 : parseInt( input.value, 10 );
		}
		return input.value;
	}

	function write( input, value ) {
		if ( input.type === 'checkbox' ) {
			input.checked = !! value;
		} else if ( input.type === 'number' && LIMITS.includes( input.dataset.wbGamActionOverride ) ) {
			input.value = value > 0 ? String( value ) : '';
		} else {
			input.value = String( value );
		}
		input.dataset.prev = JSON.stringify( read( input ) );
	}

	/** Every field of a row as the server holds it now. */
	function snapshot( row ) {
		const out = {};
		row.querySelectorAll( '[data-wb-gam-action-override]' ).forEach( ( input ) => {
			out[ input.dataset.wbGamActionOverride ] = JSON.parse( input.dataset.prev );
		} );
		return out;
	}

	function apply( row, data ) {
		const values = Object.assign( {}, data.effective || {}, data );
		row.querySelectorAll( '[data-wb-gam-action-override]' ).forEach( ( input ) => {
			const name = input.dataset.wbGamActionOverride;
			if ( name in values && ! ( 'point_type' === name && '' === values[ name ] ) ) {
				write( input, values[ name ] );
			}
		} );
		summarise( row );
	}

	function duration( sec ) {
		if ( sec % 3600 === 0 ) {
			return fmt( s.hours, sec / 3600 );
		}
		return sec % 60 === 0 ? fmt( s.minutes, sec / 60 ) : fmt( s.seconds, sec );
	}

	function summarise( row ) {
		const v     = LIMITS.map( ( name ) => read( field( row, name ) ) || 0 );
		const parts = [];
		if ( v[ 1 ] ) {
			parts.push( fmt( s.perDay, v[ 1 ] ) );
		}
		if ( v[ 2 ] ) {
			parts.push( fmt( s.perWeek, v[ 2 ] ) );
		}
		if ( v[ 0 ] ) {
			parts.push( fmt( s.apart, duration( v[ 0 ] ) ) );
		}
		row.querySelector( '[data-wb-gam-earn-limit-summary]' ).textContent = parts.length ? parts.join( ', ' ) : s.noLimit;
		row.toggleAttribute( 'data-limited', parts.length > 0 );
		row.toggleAttribute( 'data-off', ! read( field( row, 'enabled' ) ) );
	}

	function request( id, method, body ) {
		const url = cfg.restBase + encodeURIComponent( id ).replace( /%2F/g, '/' ) + '/overrides';
		return fetch( url + ( url.includes( '?' ) ? '&' : '?' ) + '_locale=user', {
			// A hung host must not leave the row waiting forever: the save fails and the old value returns.
			signal: ( typeof AbortSignal !== 'undefined' && AbortSignal.timeout ) ? AbortSignal.timeout( 15000 ) : undefined,
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( ( r ) => ( r.ok ? r.json() : Promise.reject( r ) ) );
	}

	function status( text, undo ) {
		const box = document.querySelector( '[data-wb-gam-earn-status]' );
		clearTimeout( undoTimer );
		box.querySelector( '[data-wb-gam-earn-status-text]' ).textContent = text;
		box.querySelector( '[data-wb-gam-earn-undo]' ).hidden = ! undo;
		box.toggleAttribute( 'data-error', ! undo && text === s.notSaved );
		box.hidden = false;
		undoFn     = undo || null;
		undoTimer  = setTimeout( () => {
			box.hidden = true;
			undoFn     = null;
		}, UNDO_MS );
	}

	/**
	 * Save changes for several rows; on success offer to put the previous values back.
	 *
	 * @param {Array<{row: Element, body: Object|null}>} jobs  body null = restore defaults.
	 * @param {string}                                   label Status text on success.
	 * @param {boolean}                                  isUndo No Undo for an undo.
	 */
	function commit( jobs, label, isUndo ) {
		const before = jobs.map( ( j ) => ( { row: j.row, body: snapshot( j.row ) } ) );
		return Promise.all(
			jobs.map( ( j ) => request( j.row.dataset.wbGamEarnRow, j.body ? 'POST' : 'DELETE', j.body ).then( ( data ) => apply( j.row, data ) ) )
		).then(
			() => status( label, isUndo ? null : () => commit( before, s.undone, true ) ),
			() => {
				// Put back what the server still holds for the rows that failed.
				before.forEach( ( b ) => apply( b.row, b.body ) );
				status( s.notSaved, null );
			}
		);
	}

	function saveField( input ) {
		const row  = input.closest( '[data-wb-gam-earn-row]' );
		const name = input.dataset.wbGamActionOverride;
		if ( 'points' === name && ( '' === input.value || ! input.checkValidity() ) ) {
			return; // Not a whole number in range: leave the saved amount alone.
		}
		const value = read( input );
		if ( JSON.stringify( value ) === input.dataset.prev ) {
			return;
		}
		const body       = {};
		body[ name ]     = value;
		const prev       = JSON.parse( input.dataset.prev );
		const before     = {};
		before[ name ]   = prev;
		request( row.dataset.wbGamEarnRow, 'POST', body ).then(
			( data ) => {
				apply( row, data );
				input.classList.remove( 'wbgam-input--error' );
				status( s.saved, () => commit( [ { row, body: before } ], s.undone, true ) );
			},
			() => {
				write( input, prev );
				summarise( row );
				input.classList.add( 'wbgam-input--error' );
				status( s.notSaved, null );
			}
		);
	}

	rows().forEach( ( row ) => {
		row.querySelectorAll( '[data-wb-gam-action-override]' ).forEach( ( input ) => {
			input.dataset.prev = JSON.stringify( read( input ) );
			if ( input.type !== 'number' ) {
				input.addEventListener( 'change', () => saveField( input ) );
				return;
			}
			input.addEventListener( 'input', () => {
				clearTimeout( timers.get( input ) );
				timers.set( input, setTimeout( () => saveField( input ), 600 ) );
			} );
			input.addEventListener( 'blur', () => {
				clearTimeout( timers.get( input ) );
				if ( 'points' === input.dataset.wbGamActionOverride && ( '' === input.value || ! input.checkValidity() ) ) {
					write( input, JSON.parse( input.dataset.prev ) );
					return;
				}
				saveField( input );
			} );
		} );
		summarise( row );
	} );

	document.querySelectorAll( '[data-wb-gam-earn-group]' ).forEach( ( group ) => {
		const groupRows = () => Array.from( group.querySelectorAll( '[data-wb-gam-earn-row]' ) );
		group.querySelector( '[data-wb-gam-earn-restore]' ).addEventListener( 'click', () => {
			commit( groupRows().map( ( row ) => ( { row, body: null } ) ), s.restored );
		} );
		group.querySelector( '[data-wb-gam-earn-alloff]' ).addEventListener( 'click', () => {
			const on = groupRows().filter( ( row ) => read( field( row, 'enabled' ) ) );
			if ( on.length ) {
				commit( on.map( ( row ) => ( { row, body: { enabled: false } } ) ), s.switchedOff );
			}
		} );
	} );

	document.querySelector( '[data-wb-gam-earn-undo]' ).addEventListener( 'click', () => {
		const fn = undoFn;
		undoFn   = null;
		if ( fn ) {
			fn();
		}
	} );

	// Toolbar: search + one chip + plugin, over the fixed server-rendered list.
	const search = document.querySelector( '[data-wb-gam-earn-search]' );
	const plugin = document.querySelector( '[data-wb-gam-earn-plugin]' );
	const chips  = Array.from( document.querySelectorAll( '[data-wb-gam-earn-filter]' ) );
	let chip     = 'all';

	function filter() {
		const q     = search.value.trim().toLowerCase();
		const total = rows().length;
		let shown   = 0;
		document.querySelectorAll( '[data-wb-gam-earn-group]' ).forEach( ( group ) => {
			let inGroup = 0;
			group.querySelectorAll( '[data-wb-gam-earn-row]' ).forEach( ( row ) => {
				const match = ( ! q || row.dataset.search.includes( q ) ) &&
					( 'all' === chip ||
						( 'on' === chip && ! row.hasAttribute( 'data-off' ) ) ||
						( 'off' === chip && row.hasAttribute( 'data-off' ) ) ||
						( 'penalty' === chip && row.hasAttribute( 'data-penalty' ) ) ||
						( 'limited' === chip && row.hasAttribute( 'data-limited' ) ) );
				row.hidden = ! match;
				inGroup   += match ? 1 : 0;
			} );
			group.hidden = ! inGroup || ( plugin.value && plugin.value !== group.dataset.wbGamEarnGroup );
			if ( ! group.hidden && ( q || 'all' !== chip || plugin.value ) ) {
				group.open = true;
			}
			shown += group.hidden ? 0 : inGroup;
		} );
		document.querySelector( '[data-wb-gam-earn-count]' ).textContent = shown === total ? fmt( s.count, total ) : fmt( s.countOf, shown, total );
		document.querySelector( '[data-wb-gam-earn-none]' ).hidden = shown > 0;
	}

	search.addEventListener( 'input', filter );
	plugin.addEventListener( 'change', filter );
	chips.forEach( ( btn ) => btn.addEventListener( 'click', () => {
		chip = btn.dataset.wbGamEarnFilter;
		chips.forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b === btn ) ) );
		filter();
	} ) );
}() );
