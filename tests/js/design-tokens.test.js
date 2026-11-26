/**
 * design-tokens.css: inside BuddyNext a gamification button takes BuddyNext's button pair, the
 * fill --bn-accent-solid with its label --bn-accent-fg. Taking the raw accent-500 as the fill and
 * our own lightness rule as the label gave black on BuddyNext's default blue (owner screenshot,
 * level-up "Awesome!" button). Standalone keeps its own rule as the fallback.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const CSS = fs
	.readFileSync( path.join( __dirname, '../../src/shared/design-tokens.css' ), 'utf8' )
	.replace( /\/\*[\s\S]*?\*\//g, '' );

const values = ( token ) =>
	[ ...CSS.matchAll( new RegExp( token + ':\\s*([^;]+);', 'g' ) ) ].map( ( m ) => m[ 1 ].trim() );

test( 'the popup fill is BuddyNext\'s button fill first', () => {
	const fills = values( '--wb-gam-pop-accent' );
	assert.ok( fills.length > 0 );
	fills.forEach( ( v ) => assert.match( v, /^var\(--bn-accent-solid,/ ) );
} );

test( 'every popup label colour is BuddyNext\'s pair first', () => {
	const labels = values( '--wb-gam-pop-accent-fg' );
	assert.ok( labels.length > 0 );
	labels.forEach( ( v ) => assert.match( v, /^var\(--bn-accent-fg,/ ) );
} );
