/**
 * toast.js: awards that land together become ONE summary toast (TOASTS.md). Runs the real file in
 * jsdom with a stand-in wbGam.toast that records what it was asked to draw.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../assets/js/toast.js' ), 'utf8' );

function page() {
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
	w.wbGamToast = { restUrl: '/', nonce: 'n', i18n: {} };
	w.wbGam = {
		rest: () => Promise.resolve( { ok: true, data: [] } ),
		toast( o ) {
			const call = { o, updates: [] };
			drawn.push( call );
			return { gone: false, update: ( n ) => call.updates.push( n ) };
		},
	};
	let feed = null;
	w.wbGamRealtime = { subscribe: ( k, fn ) => ( feed = fn ) };
	w.eval( SRC );
	return { tick, drawn, send: ( list ) => feed( list ) };
}

let id = 0;
const pts = ( n, detail ) => ( { _id: ++id, type: 'points', points: n, message: '+' + n + ' Points', unit_many: 'Points', detail } );

test( 'a post, a streak day and a badge within a second are one toast', () => {
	const p = page();
	p.send( [ pts( 10, 'Publish a blog post' ), pts( 5, '5-day streak' ) ] );
	p.tick( 400 );
	p.send( [ { _id: ++id, type: 'badge', message: 'Badge earned: Conversation Starter', name: 'Conversation Starter', image: '/b.png' } ] );
	p.tick( 1500 );
	assert.strictEqual( p.drawn.length, 1, 'one toast, not three' );
	const o = p.drawn[ 0 ].o;
	assert.strictEqual( o.title, '+{count} Points and a new badge' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( o.count ) ), { from: 0, to: 15 } );
	assert.strictEqual( o.body, 'Publish a blog post, 5-day streak, Conversation Starter' );
	assert.strictEqual( o.icon, '/b.png', 'the badge picture is the icon' );
	assert.strictEqual( o.key, 'wbgam-awards' );
} );

test( 'repeats of one action add up', () => {
	const p = page();
	p.send( [ pts( 2, 'Leave a comment' ), pts( 2, 'Leave a comment' ), pts( 2, 'Leave a comment' ) ] );
	p.tick( 1500 );
	assert.strictEqual( p.drawn[ 0 ].o.body, 'Leave a comment ×3' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( p.drawn[ 0 ].o.count ) ), { from: 0, to: 6 } );
} );

test( 'a later award updates the summary on screen and counts on from its total', () => {
	const p = page();
	p.send( [ pts( 5, 'Leave a comment' ) ] );
	p.tick( 1500 );
	assert.strictEqual( p.drawn[ 0 ].o.title, '+5 Points', 'one award keeps the server wording' );
	p.send( [ pts( 3, 'Leave a comment' ) ] );
	p.tick( 1500 );
	assert.strictEqual( p.drawn.length, 1 );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( p.drawn[ 0 ].updates[ 0 ].count ) ), { from: 5, to: 8 } );
} );

test( 'first points: welcome title, site line and the progress button', () => {
	const p = page();
	p.send( [ pts( 10, 'Join the site' ), { _id: ++id, type: 'welcome', message: 'Welcome aboard!', detail: 'Your first points in Garden', url: '/gamification/', url_label: 'See progress' } ] );
	p.tick( 1500 );
	const o = p.drawn[ 0 ].o;
	assert.strictEqual( o.title, '+10 Points. Welcome aboard!' );
	assert.strictEqual( o.body, 'Your first points in Garden' );
	assert.strictEqual( o.hrefLabel, 'See progress' );
} );

test( 'a level-up is not a toast, and approvals are not merged', () => {
	const p = page();
	p.send( [ { _id: ++id, type: 'level_up', message: 'Level 3' }, { _id: ++id, type: 'submission', outcome: 'approved', message: 'Approved: Run 5k' } ] );
	p.tick( 1500 );
	assert.strictEqual( p.drawn.length, 1 );
	assert.strictEqual( p.drawn[ 0 ].o.title, 'Approved: Run 5k' );
} );
