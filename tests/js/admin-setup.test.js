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
		`<div data-wbgam-setup><form>
			<button type="button" class="wbgam-setup__step" data-wbgam-goto="1"></button><button type="button" class="wbgam-setup__step" data-wbgam-goto="2"></button>
			<button type="submit" name="wbgam_setup" value="skip" formnovalidate id="skip"></button>
			<section data-wbgam-step="1"><h2>1</h2><input type="checkbox" data-wbgam-plugin-toggle value="Jetonomy"></section>
			<section data-wbgam-step="2"><h2>2</h2>
				<label><input type="radio" name="pace" value="balanced" checked><strong>Balanced · recommended</strong></label>
				<details data-wbgam-plugin="Jetonomy"><summary><span data-wbgam-group-count></span></summary><input type="checkbox"><input type="checkbox"><table><tr><td><input type="number" name="points[jt_post]" min="0" max="9999" required value="2"></td></tr></table></details>
				<details data-wbgam-plugin="WordPress"><summary><span data-wbgam-group-count></span></summary><input type="checkbox" checked><table><tr><td><input type="number" name="points[wp_publish_post]" min="0" max="9999" required value="25"></td></tr></table></details>
				<button type="button" data-wbgam-goto="3" id="next"></button>
			</section>
			<section data-wbgam-step="3"><h2>3</h2></section>
			<section data-wbgam-step="4"><dd data-wbgam-sum="plugins"></dd><dd data-wbgam-sum="earning"></dd><button type="submit" name="wbgam_setup" value="launch" id="launch"></button></section>
		</form></div>`,
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	w.wbGamSetup = { i18n: { groupCount: '%1$d of %2$d on', plugins: '%s, and the WordPress basics.', onlyWp: 'The WordPress basics.', earning: '%1$d actions on. Pace: %2$s.', pointsErr: 'Enter a whole number from 0 to 9,999. Use 0 for no points.' } };
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

// Launch while an amount in a hidden step is bad (cards 10551804386, 10551804393).
function watchSubmit( d ) {
	const seen = { submitted: false };
	d.querySelector( 'form' ).addEventListener( 'submit', ( e ) => {
		seen.submitted = true;
		e.preventDefault(); // jsdom cannot navigate.
	} );
	return seen;
}

for ( const [ label, value ] of [ [ 'below range', '-5' ], [ 'above range', '10000' ], [ 'blank', '' ], [ 'a decimal', '1.5' ] ] ) {
	test( `Next with an amount ${ label } stays on its step, opens its plugin, focuses it and says why`, () => {
		const d = page();
		const input = d.querySelector( '[name="points[wp_publish_post]"]' );
		d.querySelector( '[data-wbgam-goto="2"]' ).click();
		input.value = value;
		d.querySelector( '[data-wbgam-plugin="WordPress"]' ).open = false;
		d.querySelector( '#next' ).click();
		assert.strictEqual( d.querySelector( '[data-wbgam-step="2"]' ).hidden, false, 'Next did not leave step 2' );
		assert.strictEqual( d.querySelector( '[data-wbgam-step="3"]' ).hidden, true );
		assert.strictEqual( input.getAttribute( 'aria-invalid' ), 'true' );
		const msg = input.parentNode.querySelector( '.wbgam-setup__error' );
		assert.ok( msg, 'an inline message sits next to the input' );
		assert.strictEqual( msg.getAttribute( 'role' ), 'alert' );
		assert.match( msg.textContent, /whole number from 0 to 9,999/ );
		assert.strictEqual( input.getAttribute( 'aria-describedby' ), msg.id );
		assert.strictEqual( d.activeElement, input, 'focus is on the bad amount' );
		assert.strictEqual( d.querySelector( '[data-wbgam-plugin="WordPress"]' ).open, true, 'its plugin is expanded' );
	} );
}

test( 'Launch from step 4 is stopped and sent back to the bad amount in step 2', () => {
	const d = page();
	const input = d.querySelector( '[name="points[wp_publish_post]"]' );
	const seen = watchSubmit( d );
	d.querySelector( '[data-wbgam-goto="2"]' ).click();
	d.querySelector( '#next' ).click();
	input.value = '-5';
	d.querySelector( '#launch' ).click();
	assert.strictEqual( seen.submitted, false, 'nothing was submitted' );
	assert.strictEqual( d.querySelector( '[data-wbgam-step="2"]' ).hidden, false, 'back on the step that holds the amount' );
	assert.strictEqual( d.activeElement, input );
	assert.ok( input.parentNode.querySelector( '.wbgam-setup__error' ) );
} );

test( 'Skip is never blocked, a valid form launches, and fixing the amount clears its message', () => {
	const d = page();
	const input = d.querySelector( '[name="points[wp_publish_post]"]' );
	const seen = watchSubmit( d );
	input.value = '';
	d.querySelector( '#skip' ).click();
	assert.strictEqual( seen.submitted, true, 'Skip goes through with a blank amount' );
	seen.submitted = false;
	d.querySelector( '#launch' ).click();
	assert.strictEqual( seen.submitted, false );
	input.value = '30';
	input.dispatchEvent( new d.defaultView.Event( 'input', { bubbles: true } ) );
	assert.strictEqual( input.parentNode.querySelector( '.wbgam-setup__error' ), null, 'message goes away once it is valid' );
	assert.strictEqual( input.hasAttribute( 'aria-invalid' ), false );
	d.querySelector( '#launch' ).click();
	assert.strictEqual( seen.submitted, true, 'a valid form launches' );
} );

test( 'a bad amount in a plugin that is switched off does not block Launch', () => {
	const d = page();
	d.querySelector( '[name="points[jt_post]"]' ).value = '-1'; // Jetonomy is not ticked: its group is hidden.
	const seen = watchSubmit( d );
	d.querySelector( '#launch' ).click();
	assert.strictEqual( seen.submitted, true );
} );

test( 'a Launch the server rejected opens the first marked amount on load', () => {
	const dom = new JSDOM(
		`<div data-wbgam-setup><form><section data-wbgam-step="1"><h2>1</h2></section>
		<section data-wbgam-step="2"><h2>2</h2><details data-wbgam-plugin="WordPress"><summary><span data-wbgam-group-count></span></summary><input type="number" name="points[x]" value="" aria-invalid="true"><span class="wbgam-setup__error">e</span></details></section></form></div>`,
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	w.wbGamSetup = { i18n: { groupCount: '%1$d of %2$d on', pointsErr: 'bad' } };
	w.HTMLElement.prototype.scrollIntoView = () => {};
	w.eval( SRC );
	assert.strictEqual( w.document.querySelector( '[data-wbgam-step="2"]' ).hidden, false );
	assert.strictEqual( w.document.activeElement.name, 'points[x]' );
} );
