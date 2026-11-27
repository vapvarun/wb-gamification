/**
 * Settings > Earning (assets/js/admin-action-overrides.js): every saved change keeps its own Undo
 * for 10 seconds, not just the latest one (card 10551797792). Runs the real file in jsdom with a
 * fake clock and a fake REST endpoint that holds each action's points.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../assets/js/admin-action-overrides.js' ), 'utf8' );

function row( id, points ) {
	return `<li data-wb-gam-earn-row="${ id }" data-search="${ id }">
		<input type="checkbox" checked data-wb-gam-action-override="enabled">
		<input type="number" min="0" max="9999" value="${ points }" data-wb-gam-action-override="points">
		<input type="number" data-wb-gam-action-override="cooldown"><input type="number" data-wb-gam-action-override="daily_cap"><input type="number" data-wb-gam-action-override="weekly_cap">
		<span data-wb-gam-earn-limit-summary></span></li>`;
}

function page( { failNext = false } = {} ) {
	const dom = new JSDOM(
		`<body><select data-wb-gam-earn-plugin><option value=""></option></select><input data-wb-gam-earn-search>
		<span data-wb-gam-earn-count></span><p data-wb-gam-earn-none hidden></p>
		<details data-wb-gam-earn-group="X"><button data-wb-gam-earn-restore></button><button data-wb-gam-earn-alloff></button>
		<ul>${ row( 'a', 1 ) }${ row( 'b', 1 ) }${ row( 'c', 1 ) }</ul></details>
		<div data-wb-gam-earn-status hidden><span data-wb-gam-earn-status-text></span>
		<button data-wb-gam-earn-undo>Undo <span data-wb-gam-earn-undo-count></span></button></div></body>`,
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	let now = 0;
	let seq = 0;
	const timers = new Map();
	w.setTimeout = ( fn, ms ) => ( timers.set( ++seq, { fn, at: now + ( ms || 0 ) } ), seq );
	w.clearTimeout = ( id ) => timers.delete( id );
	const tick = ( ms ) => {
		now += ms;
		[ ...timers ].filter( ( [ , t ] ) => t.at <= now ).forEach( ( [ id, t ] ) => {
			timers.delete( id );
			t.fn();
		} );
	};

	const server = { a: 1, b: 1, c: 1 };
	const state = { fail: failNext };
	w.fetch = ( url, opts ) => {
		const id = decodeURIComponent( url.split( '/actions/' )[ 1 ].split( '/overrides' )[ 0 ] );
		if ( state.fail ) {
			return Promise.resolve( { ok: false } );
		}
		Object.assign( server, { [ id ]: JSON.parse( opts.body ).points } );
		return Promise.resolve( { ok: true, json: () => Promise.resolve( { points: server[ id ] } ) } );
	};
	w.wbGamActionOverrides = {
		restBase: 'http://x/wp-json/wb-gamification/v1/actions/',
		nonce: 'n',
		i18n: { saved: 'Saved.', notSaved: 'Not saved', undone: 'Change undone.', restored: 'r', switchedOff: 's', noLimit: 'No limit', count: '%d', countOf: '%1$d of %2$d', perDay: '%d/day', perWeek: '%d/wk', apart: '%s apart', hours: '%dh', minutes: '%dm', seconds: '%ds' },
	};
	w.eval( SRC );
	const d = w.document;
	const set = async ( id, value ) => {
		const input = d.querySelector( `[data-wb-gam-earn-row="${ id }"] [data-wb-gam-action-override="points"]` );
		input.value = String( value );
		input.dispatchEvent( new w.Event( 'blur' ) );
		await new Promise( ( r ) => setImmediate( r ) );
		await new Promise( ( r ) => setImmediate( r ) );
	};
	const undo = async () => {
		d.querySelector( '[data-wb-gam-earn-undo]' ).click();
		await new Promise( ( r ) => setImmediate( r ) );
		await new Promise( ( r ) => setImmediate( r ) );
	};
	return { d, set, undo, tick, server, state };
}

test( 'two changes in the window can both be undone, newest first', async () => {
	const p = page();
	await p.set( 'a', 2 );
	await p.tick( 3000 );
	await p.set( 'b', 3 );
	assert.deepStrictEqual( [ p.server.a, p.server.b ], [ 2, 3 ] );
	await p.undo();
	assert.deepStrictEqual( [ p.server.a, p.server.b ], [ 2, 1 ], 'first Undo reverses the newest change' );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-undo]' ).hidden, false, 'the earlier change can still be undone' );
	await p.undo();
	assert.deepStrictEqual( [ p.server.a, p.server.b ], [ 1, 1 ], 'second Undo reverses the earlier change' );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-undo]' ).hidden, true, 'nothing left to undo' );
} );

test( 'each change loses its Undo after its own 10 seconds, not before', async () => {
	const p = page();
	await p.set( 'a', 2 );
	p.tick( 6000 );
	await p.set( 'b', 3 );
	p.tick( 5000 ); // a is 11s old (gone), b is 5s old (still undoable).
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-undo-count]' ).textContent, '', 'one pending: no count' );
	await p.undo();
	assert.deepStrictEqual( [ p.server.a, p.server.b ], [ 2, 1 ] );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-undo]' ).hidden, true, 'a expired and stays changed' );
} );

test( 'editing the same field twice undoes back to the value before the series', async () => {
	const p = page();
	await p.set( 'a', 2 );
	await p.set( 'a', 5 );
	await p.undo();
	assert.strictEqual( p.server.a, 1 );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-undo]' ).hidden, true );
} );

test( 'a failed save restores the value, says Not saved and offers no Undo of its own', async () => {
	const p = page();
	p.state.fail = true;
	await p.set( 'a', 9 );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-row="a"] [data-wb-gam-action-override="points"]' ).value, '1' );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-status-text]' ).textContent, 'Not saved' );
	assert.strictEqual( p.d.querySelector( '[data-wb-gam-earn-undo]' ).hidden, true );
} );
