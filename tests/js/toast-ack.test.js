/**
 * toast.js acknowledges only what the member has SEEN. The server keeps one position per member and
 * re-delivers every row above it, so a page that vanishes first loses nothing. The page moves the
 * position to the highest row of the contiguous DISPLAYED prefix of the rows it received; a reward
 * held behind a level-up card pins it, and rows already shown above the pin are remembered in the tab
 * (sessionStorage) so the next page does not show them again.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../assets/js/toast.js' ), 'utf8' );

function storage() {
	const m = new Map();
	return { getItem: ( k ) => ( m.has( k ) ? m.get( k ) : null ), setItem: ( k, v ) => m.set( k, String( v ) ), raw: m };
}

/** One page load. `session` is the tab's sessionStorage, shared by the pages of one tab. */
function page( { seed, restFails, session = storage(), hang } = {} ) {
	const w = new JSDOM( '<!doctype html><body></body>', { runScripts: 'outside-only' } ).window;
	Object.defineProperty( w, 'sessionStorage', { value: session } );
	let now = 0;
	let seq = 0;
	const timers = new Map();
	w.setTimeout = ( fn, ms ) => ( timers.set( ++seq, { fn, at: now + ( ms || 0 ) } ), seq );
	w.clearTimeout = ( id ) => timers.delete( id );
	const tick = ( ms ) => {
		now += ms;
		let ran = true;
		while ( ran ) {
			ran = false;
			for ( const [ id, t ] of [ ...timers ] ) {
				if ( t.at <= now ) {
					timers.delete( id );
					t.fn();
					ran = true;
				}
			}
		}
	};
	const drawn = [];
	const acks = [];
	w.wbGamToast = { restUrl: '/wp-json/wb-gamification/v1/', nonce: 'n', userId: 7, i18n: {} };
	w.wbGam = {
		rest: ( url, opts ) => {
			if ( /toasts\/ack$/.test( url ) ) {
				acks.push( { url, opts } );
				if ( hang ) {
					return new Promise( () => {} );
				}
				return restFails ? Promise.reject( new Error( 'offline' ) ) : Promise.resolve( { ok: true, status: 200, data: {} } );
			}
			return Promise.resolve( { ok: true, data: [] } );
		},
		// A stand-in renderer: a toast is drawn (a handle) but NOT on screen until paint() attaches its element,
		// the way a summary waits behind a level-up card.
		toast( o ) {
			const call = { o, updates: [], handle: null };
			call.handle = {
				gone: false,
				el: null,
				update: ( n ) => call.updates.push( n ),
			};
			call.paint = () => {
				call.handle.el = w.document.createElement( 'div' );
				w.document.body.appendChild( call.handle.el );
			};
			drawn.push( call );
			return call.handle;
		},
	};
	let feed = null;
	w.wbGamRealtime = { subscribe: ( k, fn ) => ( feed = fn ) };
	if ( seed ) {
		w.wbGamNotifications = seed;
	}
	w.eval( SRC );
	const settle = () => new Promise( ( r ) => setImmediate( r ) );
	return { w, tick, drawn, acks, settle, session, send: ( list ) => feed( list ), ids: () => acks.map( ( a ) => a.opts.body.id ) };
}

const pts = ( id, ack, n = 5 ) => ( { _id: id, _ack: ack, type: 'points', points: n, message: '+' + n + ' Points', unit_many: 'Points' } );
const lvl = ( id, ack ) => ( { _id: id, _ack: ack, type: 'level_up', title: 'Champion' } );

test( 'a summary held behind a level-up card is not acknowledged until it is on screen', () => {
	const p = page();
	p.send( [ pts( 10, 12 ), pts( 11, 12 ), lvl( 12, 12 ) ] );
	p.w.wbGamDelivery.shown( [ 12 ] ); // The Moment card opened.
	p.tick( 1600 ); // The collect window closes: the summary is handed to the renderer, which holds it.
	assert.strictEqual( p.drawn.length, 1 );
	p.tick( 5000 );
	assert.deepStrictEqual( p.ids(), [], 'handed over but not displayed: a reload now must re-deliver the summary' );
	p.drawn[ 0 ].paint(); // The card closed, the summary shows.
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 12 ], 'now every row is displayed: the whole window is acknowledged' );
} );

test( 'a reload while the summary is held: the summary shows once on the next page, the level-up card does not repeat', () => {
	const session = storage();
	const rows = () => [ pts( 10, 12 ), pts( 11, 12 ), lvl( 12, 12 ) ];

	const a = page( { seed: rows(), session } );
	a.w.wbGamDelivery.shown( [ 12 ] ); // The level-up card showed; the summary is still held; the page is reloaded.
	a.tick( 2500 );
	assert.deepStrictEqual( a.ids(), [] );
	assert.strictEqual( a.w.wbGamDelivery.consumed( 12 ), true );

	const b = page( { seed: rows(), session } );
	assert.strictEqual( b.w.wbGamDelivery.consumed( 12 ), true, 'the next page knows the card was shown: the store skips it' );
	assert.strictEqual( b.w.wbGamDelivery.consumed( 10 ), false );
	b.tick( 1600 );
	assert.strictEqual( b.drawn.length, 1, 'the summary is drawn on the next page' );
	assert.strictEqual( b.drawn[ 0 ].o.title, '+{count} Points' );
	assert.strictEqual( b.drawn[ 0 ].o.count.to, 10 );
	b.drawn[ 0 ].paint();
	b.tick( 200 );
	assert.deepStrictEqual( b.ids(), [ 12 ], 'shown, so the position moves past the card row too' );
} );

test( 'a reload after the summary showed but before the acknowledgement landed repeats nothing', () => {
	const session = storage();
	const rows = () => [ pts( 10, 12 ), pts( 11, 12 ), lvl( 12, 12 ) ];
	const a = page( { seed: rows(), session, hang: true } ); // The ack request never completes: the page dies.
	a.w.wbGamDelivery.shown( [ 12 ] );
	a.tick( 1600 );
	a.drawn[ 0 ].paint();
	a.tick( 200 );
	const b = page( { seed: rows(), session } );
	b.tick( 5000 );
	assert.strictEqual( b.drawn.length, 0, 'every row was displayed by the previous page: no second summary' );
	assert.deepStrictEqual( b.ids(), [ 12 ], 'the new page finishes the acknowledgement' );
} );

test( 'the acknowledgement is the displayed prefix, not the head: a held row pins it below itself', () => {
	const p = page();
	p.send( [ { _id: 10, _ack: 12, type: 'skip', message: 'Daily cap' }, pts( 11, 12 ), lvl( 12, 12 ) ] );
	p.drawn[ 0 ].paint();
	p.w.wbGamDelivery.shown( [ 12 ] );
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 10 ], 'row 10 shown, row 11 held: the position stops at 10 although 12 was shown' );
	p.tick( 1500 );
	p.drawn[ 1 ].paint();
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 10, 12 ] );
} );

test( 'an award without a level-up: acknowledged as soon as the summary shows', () => {
	const p = page();
	p.send( [ pts( 4, 5 ), pts( 5, 5 ) ] );
	p.tick( 1600 );
	p.drawn[ 0 ].paint();
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 5 ] );
	assert.strictEqual( p.acks[ 0 ].opts.method, 'POST' );
	assert.strictEqual( p.acks[ 0 ].opts.keepalive, true, 'must survive the page unloading' );
	assert.strictEqual( p.acks[ 0 ].opts.nonce, 'n' );
} );

test( 'rows still in the 1.5s collect window are not acknowledged', () => {
	const p = page();
	p.send( [ pts( 1, 3 ), pts( 3, 3 ) ] );
	p.tick( 1400 );
	assert.strictEqual( p.drawn.length, 0 );
	assert.deepStrictEqual( p.ids(), [] );
} );

test( 'the same row from the seed and the heartbeat paints once and is acknowledged once', () => {
	const p = page( { seed: [ pts( 4, 4 ) ] } );
	p.send( [ pts( 4, 4 ) ] );
	p.tick( 1600 );
	assert.strictEqual( p.drawn.length, 1 );
	assert.strictEqual( p.drawn[ 0 ].updates.length, 0, 'the duplicate was not added to the summary' );
	p.drawn[ 0 ].paint();
	p.tick( 200 );
	p.send( [ pts( 4, 4 ) ] );
	assert.deepStrictEqual( p.ids(), [ 4 ] );
} );

test( 'a skip or submission toast is acknowledged when it is on screen', () => {
	const p = page();
	p.send( [ { _id: 7, _ack: 7, type: 'skip', message: 'Daily cap reached' } ] );
	p.tick( 1 );
	assert.deepStrictEqual( p.ids(), [], 'handed over, not painted' );
	p.drawn[ 0 ].paint();
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 7 ] );
} );

test( 'a toast dismissed from the line before it showed has nothing left to lose', () => {
	const p = page();
	p.send( [ { _id: 7, _ack: 7, type: 'skip', message: 'x' } ] );
	p.drawn[ 0 ].handle.gone = true;
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 7 ] );
} );

test( 'an empty read sends no acknowledgement', () => {
	const p = page();
	p.send( [] );
	p.send( undefined );
	p.tick( 5000 );
	assert.strictEqual( p.acks.length, 0 );
} );

test( 'a failed acknowledgement is asked again by the next delivery; the rows stay on the server meanwhile', async () => {
	const p = page( { restFails: true } );
	p.send( [ pts( 1, 1 ) ] );
	p.tick( 1600 );
	p.drawn[ 0 ].paint();
	p.tick( 200 );
	await p.settle();
	p.send( [ pts( 1, 1 ) ] );
	assert.deepStrictEqual( p.ids(), [ 1, 1 ] );
} );

test( 'two tabs each show the rows once and acknowledge the same position', () => {
	const rows = () => [ pts( 4, 5 ), pts( 5, 5 ) ];
	const t1 = page( { seed: rows() } );
	const t2 = page( { seed: rows() } ); // Another tab: its own sessionStorage.
	[ t1, t2 ].forEach( ( t ) => {
		t.tick( 1600 );
		assert.strictEqual( t.drawn.length, 1 );
		t.drawn[ 0 ].paint();
		t.tick( 200 );
		assert.deepStrictEqual( t.ids(), [ 5 ] );
	} );
} );

test( 'the tab record is pruned once the server is past it, and holds only numbers', async () => {
	const session = storage();
	const p = page( { seed: [ pts( 4, 5 ), lvl( 5, 5 ) ], session } );
	p.w.wbGamDelivery.shown( [ 5 ] );
	assert.deepStrictEqual( JSON.parse( session.raw.get( 'wbgam-shown:7' ) ), [ 5 ] );
	p.tick( 1600 );
	p.drawn[ 0 ].paint();
	p.tick( 200 );
	await p.settle();
	assert.deepStrictEqual( JSON.parse( session.raw.get( 'wbgam-shown:7' ) ), [], 'acknowledged: nothing left to remember' );
} );

test( 'without storage the page still works and nothing is lost', () => {
	const p = page( { session: { getItem() { throw new Error( 'blocked' ); }, setItem() { throw new Error( 'blocked' ); } } } );
	p.send( [ pts( 4, 4 ) ] );
	p.tick( 1600 );
	p.drawn[ 0 ].paint();
	p.tick( 200 );
	assert.deepStrictEqual( p.ids(), [ 4 ] );
} );
