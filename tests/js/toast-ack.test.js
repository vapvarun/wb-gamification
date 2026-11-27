/**
 * toast.js acknowledges what it displayed: the server only moves the member's delivery position
 * on that acknowledgement, so a page that vanishes before showing the toasts (BuddyNext reloads
 * after a post) loses nothing, and one that showed them reports it once.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../assets/js/toast.js' ), 'utf8' );

function page( { seed, restFails, holdLoading } = {} ) {
	const w = new JSDOM( '<!doctype html><body></body>', { runScripts: 'outside-only' } ).window;
	let now = 0;
	let seq = 0;
	const timers = new Map();
	w.setTimeout = ( fn, ms ) => ( timers.set( ++seq, { fn, at: now + ( ms || 0 ) } ), seq );
	w.clearTimeout = ( id ) => timers.delete( id );
	const tick = ( ms ) => {
		now += ms;
		for ( const [ id, t ] of [ ...timers ] ) {
			if ( t.at <= now ) {
				timers.delete( id );
				t.fn();
			}
		}
	};
	const drawn = [];
	const acks = [];
	w.wbGamToast = { restUrl: '/wp-json/wb-gamification/v1/', nonce: 'n', i18n: {} };
	w.wbGam = {
		rest: ( url, opts ) => {
			if ( /toasts\/ack$/.test( url ) ) {
				acks.push( { url, opts } );
				return restFails ? Promise.reject( new Error( 'offline' ) ) : Promise.resolve( { ok: true, data: {} } );
			}
			return Promise.resolve( { ok: true, data: [] } );
		},
		toast( o ) {
			const call = { o, updates: [] };
			drawn.push( call );
			return { gone: false, update: ( n ) => call.updates.push( n ) };
		},
	};
	let feed = null;
	w.wbGamRealtime = { subscribe: ( k, fn ) => ( feed = fn ) };
	if ( seed ) {
		w.wbGamNotifications = seed;
	}
	let state = 'loading'; // toast.js runs in the footer, before DOMContentLoaded (and the Moment store).
	Object.defineProperty( w.document, 'readyState', { get: () => state } );
	w.eval( SRC );
	const ready = () => {
		state = 'complete';
		w.document.dispatchEvent( new w.Event( 'DOMContentLoaded' ) );
	};
	if ( ! holdLoading ) {
		ready();
	}
	return { tick, drawn, acks, ready, send: ( list ) => feed( list ) };
}

const pts = ( id, ack, n = 5 ) => ( { _id: id, _ack: ack, type: 'points', points: n, message: '+' + n + ' Points', unit_many: 'Points' } );

test( 'the page acknowledges the head of what it was handed, after rendering', () => {
	const p = page();
	p.send( [ pts( 1, 3 ), pts( 3, 3 ) ] );
	assert.strictEqual( p.drawn.length, 0, 'the 1.5s collect window has not closed: nothing is on screen yet' );
	p.tick( 1 );
	assert.strictEqual( p.acks.length, 1, 'acknowledged as soon as queued, not after the display hold' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( p.acks[ 0 ].opts.body ) ), { id: 3 } );
	assert.strictEqual( p.acks[ 0 ].opts.method, 'POST' );
	assert.strictEqual( p.acks[ 0 ].opts.keepalive, true, 'must survive the page unloading' );
	assert.strictEqual( p.acks[ 0 ].opts.nonce, 'n' );
} );

test( 'the seed is acknowledged only once the page has finished loading (the Moment store has shown its cards)', () => {
	const p = page( { seed: [ pts( 4, 4 ) ], holdLoading: true } );
	p.tick( 1000 );
	assert.strictEqual( p.acks.length, 0, 'still loading: a reload now must re-deliver' );
	p.ready();
	p.tick( 1 );
	assert.strictEqual( p.acks.length, 1 );
} );

test( 'the seed is acknowledged too', () => {
	const p = page( { seed: [ pts( 4, 4 ) ] } );
	p.tick( 1 );
	assert.strictEqual( p.acks.length, 1 );
	assert.strictEqual( p.acks[ 0 ].opts.body.id, 4 );
} );

test( 'the same row from the seed and the heartbeat paints once and is acknowledged once', () => {
	const p = page( { seed: [ pts( 4, 4 ) ] } );
	p.send( [ pts( 4, 4 ) ] );
	p.tick( 1500 );
	assert.strictEqual( p.drawn.length, 1, 'one toast' );
	assert.strictEqual( p.drawn[ 0 ].updates.length, 0, 'the duplicate was not added to the summary' );
	assert.strictEqual( p.drawn[ 0 ].o.title, '+5 Points', 'counted once' );
	assert.strictEqual( p.acks.length, 1, 'one acknowledgement, not one per delivery' );
} );

test( 'a newer batch is acknowledged again; a repeat of an acknowledged one is not', () => {
	const p = page();
	p.send( [ pts( 1, 2 ), pts( 2, 2 ) ] );
	p.tick( 1 );
	p.send( [ pts( 2, 2 ) ] );
	p.tick( 1 );
	assert.strictEqual( p.acks.length, 1 );
	p.send( [ pts( 5, 5 ) ] );
	p.tick( 1 );
	assert.strictEqual( p.acks.length, 2 );
	assert.strictEqual( p.acks[ 1 ].opts.body.id, 5 );
} );

test( 'an empty read sends no acknowledgement', () => {
	const p = page();
	p.send( [] );
	p.send( undefined );
	p.tick( 5000 );
	assert.strictEqual( p.acks.length, 0 );
} );

test( 'payloads without a position (an older server) send none', () => {
	const p = page();
	p.send( [ { _id: 1, type: 'points', points: 5, message: '+5 Points', unit_many: 'Points' } ] );
	p.tick( 5000 );
	assert.strictEqual( p.acks.length, 0 );
} );

test( 'a network failure lets the next delivery ask again', async () => {
	const p = page( { restFails: true } );
	p.send( [ pts( 1, 1 ) ] );
	p.tick( 1 );
	await new Promise( ( r ) => setImmediate( r ) );
	p.send( [ pts( 1, 1 ) ] );
	p.tick( 1 );
	assert.strictEqual( p.acks.length, 2 );
} );
