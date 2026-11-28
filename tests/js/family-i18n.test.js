/**
 * Integrations tab install button (assets/admin/family.js): its labels come from the server-passed,
 * translated `wbcomFamily.i18n`, never from literals in the script. Runs the real file in jsdom.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../assets/admin/family.js' ), 'utf8' );

function click( { nonce, fetchResult, fetchFails } ) {
	const dom = new JSDOM(
		`<a class="wbcom-family__action" data-action="install" data-slug="x" ${ nonce ? 'data-nonce="n"' : '' }>Install</a>`,
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	w.wbcomFamily = {
		ajax: '/x',
		i18n: { error: 'Fehler', installing: 'Wird installiert...', activated: 'Aktiviert', failed: 'Fehlgeschlagen' },
	};
	w.fetch = () => ( fetchFails ? Promise.reject( new Error( 'x' ) ) : Promise.resolve( { json: () => Promise.resolve( fetchResult ) } ) );
	w.eval( SRC );
	const btn = w.document.querySelector( 'a' );
	btn.dispatchEvent( new w.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
	return { btn, tick: () => new Promise( ( r ) => setTimeout( r, 0 ) ) };
}

test( 'a missing nonce shows the translated error', () => {
	assert.strictEqual( click( { nonce: false } ).btn.textContent, 'Fehler' );
} );

test( 'installing and activated use the translated labels', async () => {
	const { btn, tick } = click( { nonce: true, fetchResult: { success: true } } );
	assert.strictEqual( btn.textContent, 'Wird installiert...' );
	await tick();
	assert.strictEqual( btn.textContent, 'Aktiviert' );
} );

test( 'a network failure shows the translated failure label', async () => {
	const { btn, tick } = click( { nonce: true, fetchFails: true } );
	await tick();
	assert.strictEqual( btn.textContent, 'Fehlgeschlagen' );
} );
