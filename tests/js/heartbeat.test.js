/**
 * heartbeat.js: a response the server marked with Server-Timing "wbgam-toast" (the member's own
 * action queued a toast, from any plugin's request) makes the page poll at once, so the toast
 * does not wait for the next slow tick.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/heartbeat.js' ),
	'utf8'
);

function page() {
	const dom = new JSDOM( '<!doctype html><body></body>', {
		runScripts: 'outside-only',
	} );
	const w = dom.window;
	const calls = { interval: [], connectNow: 0 };
	let observe = null;
	w.jQuery = () => ( { on() {} } );
	w.wp = {
		heartbeat: {
			interval: ( s ) => calls.interval.push( s ),
			connectNow: () => calls.connectNow++,
		},
	};
	// Stand-in for the browser's resource-timing feed.
	w.PerformanceObserver = function ( cb ) {
		this.observe = () => {
			observe = cb;
		};
	};
	w.eval( SRC );
	const response = ( serverTiming ) =>
		observe && observe( { getEntries: () => [ { serverTiming } ] } );
	return { calls, response };
}

test( 'a response marked wbgam-toast makes the page poll now', () => {
	const p = page();
	p.response( [ { name: 'wbgam-toast' } ] );
	assert.strictEqual( p.calls.connectNow, 1 );
	assert.ok( p.calls.interval.includes( 'fast' ) );
} );

test( 'an unmarked response changes nothing', () => {
	const p = page();
	p.response( [ { name: 'db', duration: 12 } ] );
	assert.strictEqual( p.calls.connectNow, 0 );
	assert.ok( ! p.calls.interval.includes( 'fast' ) );
} );
