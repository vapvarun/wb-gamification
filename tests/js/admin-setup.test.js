/**
 * Setup steps (assets/js/admin-setup.js): one step at a time, a plugin chosen in step 1 starts with
 * its actions on, and step 4 summarises (card 10549519268). Runs the real file in jsdom.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../assets/js/admin-setup.js' ), 'utf8' );

function page() {
	const dom = new JSDOM(
		`<div data-wbgam-setup>
			<button class="wbgam-setup__step" data-wbgam-goto="1"></button><button class="wbgam-setup__step" data-wbgam-goto="2"></button>
			<section data-wbgam-step="1"><h2>1</h2><input type="checkbox" data-wbgam-plugin-toggle value="Jetonomy"></section>
			<section data-wbgam-step="2"><h2>2</h2>
				<label><input type="radio" name="pace" value="balanced" checked><strong>Balanced · recommended</strong></label>
				<details data-wbgam-plugin="Jetonomy"><summary><span data-wbgam-group-count></span></summary><input type="checkbox"><input type="checkbox"></details>
				<details data-wbgam-plugin="WordPress"><summary><span data-wbgam-group-count></span></summary><input type="checkbox" checked></details>
			</section>
			<section data-wbgam-step="4"><dd data-wbgam-sum="plugins"></dd><dd data-wbgam-sum="earning"></dd></section>
		</div>`,
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	w.wbGamSetup = { i18n: { groupCount: '%1$d of %2$d on', plugins: '%s, and the WordPress basics.', onlyWp: 'The WordPress basics.', earning: '%1$d actions on. Pace: %2$s.' } };
	w.HTMLElement.prototype.scrollIntoView = () => {};
	w.eval( SRC );
	return w.document;
}

test( 'one step shows at a time', () => {
	const d = page();
	assert.deepStrictEqual( [ ...d.querySelectorAll( '[data-wbgam-step]' ) ].filter( ( p ) => ! p.hidden ).map( ( p ) => p.dataset.wbgamStep ), [ '1' ] );
	d.querySelector( '[data-wbgam-goto="2"]' ).click();
	assert.deepStrictEqual( [ ...d.querySelectorAll( '[data-wbgam-step]' ) ].filter( ( p ) => ! p.hidden ).map( ( p ) => p.dataset.wbgamStep ), [ '2' ] );
} );

test( 'choosing a plugin whose actions are all off turns them on, and the summary counts them', () => {
	const d = page();
	assert.strictEqual( d.querySelector( '[data-wbgam-plugin="Jetonomy"]' ).hidden, true, 'not chosen: its actions are hidden' );
	const toggle = d.querySelector( '[data-wbgam-plugin-toggle]' );
	toggle.checked = true;
	toggle.dispatchEvent( new d.defaultView.Event( 'change', { bubbles: true } ) );
	assert.strictEqual( d.querySelector( '[data-wbgam-plugin="Jetonomy"] [data-wbgam-group-count]' ).textContent, '2 of 2 on' );
	assert.strictEqual( d.querySelector( '[data-wbgam-sum="plugins"]' ).textContent, 'Jetonomy, and the WordPress basics.' );
	assert.strictEqual( d.querySelector( '[data-wbgam-sum="earning"]' ).textContent, '3 actions on. Pace: Balanced.' );
} );
