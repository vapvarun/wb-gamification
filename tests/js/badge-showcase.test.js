/**
 * Badge Showcase (view.js): a big collection shows its first cards per tab and a "Show all N badges"
 * button (card 10531044195). Runs the real file in jsdom.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '../../src/Blocks/badge-showcase/view.js' ), 'utf8' );

function board( earned, locked, size = 12 ) {
	const cards = [];
	for ( let i = 0; i < earned; i++ ) cards.push( `<li class="wb-gam-badge-showcase__badge" data-state="earned">E${ i }</li>` );
	for ( let i = 0; i < locked; i++ ) cards.push( `<li class="wb-gam-badge-showcase__badge" data-state="locked">L${ i }</li>` );
	const dom = new JSDOM(
		`<div data-wb-gam-badge-showcase data-filter="all" data-page-size="${ size }">
			<button data-wb-gam-filter="earned"></button><button data-wb-gam-filter="locked"></button>
			<ul>${ cards.join( '' ) }</ul>
			<button data-wb-gam-more hidden data-label="Show all %d badges"></button></div>`,
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	w.wbGam = { onMount: ( sel, fn ) => w.document.querySelectorAll( sel ).forEach( fn ) };
	w.eval( SRC );
	const d = w.document;
	return {
		visible: () => [ ...d.querySelectorAll( 'li' ) ].filter( ( li ) => ! li.hidden && ( d.querySelector( '[data-filter]' ).dataset.filter === 'all' || li.dataset.state === d.querySelector( '[data-filter]' ).dataset.filter ) ).length,
		more: d.querySelector( '[data-wb-gam-more]' ),
		tab: ( f ) => d.querySelector( `[data-wb-gam-filter="${ f }"]` ).click(),
		active: () => d.activeElement.textContent,
	};
}

test( '40 earned badges: 12 cards and "Show all 40 badges"', () => {
	const b = board( 40, 0 );
	assert.strictEqual( b.visible(), 12 );
	assert.strictEqual( b.more.hidden, false );
	assert.strictEqual( b.more.textContent, 'Show all 40 badges' );
} );

test( 'Show all reveals every card and focuses the first new one', () => {
	const b = board( 40, 0 );
	b.more.click();
	assert.strictEqual( b.visible(), 40 );
	assert.strictEqual( b.more.hidden, true );
	assert.strictEqual( b.active(), 'E12' );
} );

test( 'each tab folds on its own count, and a new tab starts folded', () => {
	const b = board( 30, 5 );
	b.more.click();
	b.tab( 'locked' );
	assert.strictEqual( b.visible(), 5 );
	assert.strictEqual( b.more.hidden, true, 'five locked badges need no button' );
	b.tab( 'earned' );
	assert.strictEqual( b.visible(), 12 );
	assert.strictEqual( b.more.textContent, 'Show all 30 badges' );
} );

test( 'a small collection has no button', () => {
	const b = board( 9, 0 );
	assert.strictEqual( b.visible(), 9 );
	assert.strictEqual( b.more.hidden, true );
} );
