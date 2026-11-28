/**
 * Form controls (QA smoke walk, BuddyX 5.1.3): text, fill, border, placeholder and autofill of every input,
 * textarea and select in our blocks come from the card's own tokens in ONE rule in design-tokens.css, !important,
 * because BuddyX styles `input[type=text]`, `textarea` and `select` (and their :focus) at (0,1,1) / (0,2,1)
 * and put rgb(80,80,80) text on a navy card (1.95:1) and a white fill in the recipient field.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const ROOT = path.join( __dirname, '../..' );
const read = ( f ) => fs.readFileSync( path.join( ROOT, f ), 'utf8' );
const tokens = read( 'src/shared/design-tokens.css' );

const blocks = [ ...tokens.matchAll( /\n(:is\(\[class\*="wp-block-wb-gamification"\][^{]*) \{([^}]*)\}/g ) ].filter( ( m ) => /input|textarea|select/.test( m[ 1 ] ) );
const find = ( re ) => blocks.find( ( m ) => re.test( m[ 1 ] ) );

test( 'one shared rule sets colour, fill and border of every text control from card tokens, !important', () => {
	const rest = blocks.find( ( m ) => /^:is\([^)]*\) :is\(input:not\(.*\), textarea, select\)$/.test( m[ 1 ].trim() ) );
	assert.ok( rest, 'shared control rule exists' );
	assert.match( rest[ 2 ], /color: var\(--wb-gam-color-text\) !important;/ );
	assert.match( rest[ 2 ], /background-color: var\(--wb-gam-color-surface\) !important;/ );
	assert.match( rest[ 2 ], /border-color: var\(--wb-gam-field-border\) !important;/ );
	for ( const t of [ 'checkbox', 'radio', 'hidden', 'submit', 'button' ] ) {
		assert.ok( rest[ 1 ].includes( `"${ t }"` ), `${ t } is excluded` );
	}
	for ( const s of [ 'wp-block-wb-gamification', '.wb-gam-give-kudos', '.wb-gam-submit-achievement', '.wbgam-convert-form' ] ) {
		assert.ok( rest[ 1 ].includes( s ), `${ s } is in scope` );
	}
} );

test( 'the border is the only boundary, so it is mixed toward the text colour (3:1 on any card)', () => {
	const m = tokens.match( /--wb-gam-field-border: color-mix\(in srgb, var\(--wb-gam-color-text\) (\d+)%, var\(--wb-gam-color-surface\)\)/ );
	assert.ok( m );
	// Cards with the ink the plugin picks for them (the one with more contrast): mid grey takes black.
	const lin = ( c ) => ( c <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
	const Y = ( v ) => 0.2126 * lin( v ) + 0.7152 * lin( v ) + 0.0722 * lin( v );
	const mix = ( ink, bg ) => ink * ( m[ 1 ] / 100 ) + bg * ( 1 - m[ 1 ] / 100 );
	for ( const [ ink, bg ] of [ [ 0, 128 / 255 ], [ 0, 1 ], [ 26 / 255, 1 ], [ 245 / 255, 20 / 255 ] ] ) {
		const a = Y( mix( ink, bg ) ), b = Y( bg );
		assert.ok( ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 ) >= 3, `ink ${ ink } on ${ bg }` );
	}
} );

test( 'focus border, placeholder, autofill and options are shared and !important', () => {
	const focus = find( /:is\(:focus, :focus-visible\)$/ );
	assert.ok( focus );
	assert.match( focus[ 2 ], /border-color: var\(--wb-gam-focus-ring, [^;]*\) !important;/ );
	const ph = find( /::placeholder$/ );
	assert.ok( ph );
	assert.match( ph[ 2 ], /color: var\(--wb-gam-color-text-light\) !important;/ );
	assert.match( ph[ 2 ], /opacity: 1 !important;/ );
	const af = find( /:-webkit-autofill$/ );
	assert.ok( af );
	assert.match( af[ 2 ], /-webkit-text-fill-color: var\(--wb-gam-color-text\) !important;/ );
	const opt = find( /select option$/ );
	assert.ok( opt );
	assert.match( opt[ 2 ], /background-color: var\(--wb-gam-color-surface\) !important;/ );
} );

test( 'the shared rule outranks BuddyX input[type=text]:focus (0,2,1): !important beats its normal declaration', () => {
	// Theme declarations are not !important (checked in BuddyX 5.1.3 and BuddyX Pro 5.1.4 global.min.css), so !important is decisive.
	for ( const m of blocks ) {
		assert.ok( /!important/.test( m[ 2 ] ), m[ 1 ].slice( -40 ) );
	}
} );

test( 'no component stylesheet re-declares colour, fill or border colour of a form control', () => {
	const files = [ 'assets/css/hub.css', 'assets/css/give-kudos.css', 'assets/css/frontend.css', 'assets/css/popups.css' ];
	for ( const e of fs.readdirSync( path.join( ROOT, 'src/Blocks' ), { withFileTypes: true } ).filter( ( x ) => x.isDirectory() ) ) {
		files.push( `src/Blocks/${ e.name }/style.css` );
	}
	const bad = [];
	for ( const f of files ) {
		if ( ! fs.existsSync( path.join( ROOT, f ) ) ) {
			continue;
		}
		for ( const m of read( f ).matchAll( /([^{}]+)\{([^{}]*)\}/g ) ) {
			const sel = m[ 1 ].replace( /\/\*[\s\S]*?\*\//g, '' ).trim();
			const control = /(__input|__textarea|\binput\b|\btextarea\b|\bselect\b)/.test( sel ) && ! /checkbox|__choice/.test( sel );
			if ( control && /(^|[;\s])(color|background(-color)?|border(-color)?|border):/.test( m[ 2 ].replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /border-(width|style|radius)[^;]*;/g, '' ) ) ) {
				bad.push( `${ f }: ${ sel.slice( 0, 70 ) }` );
			}
		}
	}
	assert.deepStrictEqual( bad, [] );
} );
