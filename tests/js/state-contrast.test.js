/**
 * Accent buttons: hover / active feedback must keep WCAG AA (cards 10552177800 Year Recap 4.36:1,
 * 10552177366 Give Kudos 3.79:1).
 *
 * The label is picked from the RESTING accent, so a hover that changes the fill (opacity, brightness(),
 * a darker colour-mix) while the label stays put can pull the pair below 4.5:1. The fix is one token,
 * --wb-gam-pop-accent-hover, the accent moved 12% AWAY from its label; this file pins both the maths
 * and the rule that no labelled button changes its fill any other way.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const ROOT = path.join( __dirname, '../..' );

/* ── WCAG maths (sRGB 0-255 triples) ─────────────────────────────────────── */
const lin = ( c ) => ( ( c /= 255 ) <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
const Y = ( [ r, g, b ] ) => 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
const ratio = ( a, b ) => ( Math.max( Y( a ), Y( b ) ) + 0.05 ) / ( Math.min( Y( a ), Y( b ) ) + 0.05 );
const hex = ( h ) => [ 1, 3, 5 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) );
// color-mix( in srgb, a p%, b ): channel-wise in gamma-encoded sRGB.
const mix = ( a, p, b ) => a.map( ( v, i ) => v * p + b[ i ] * ( 1 - p ) );
const BLACK = [ 0, 0, 0 ];
const WHITE = [ 255, 255, 255 ];

/* ── The CSS, modelled ───────────────────────────────────────────────────── */
// --wb-gam-pop-accent-fg: black above Y 0.1791 (where black and white tie), white below.
const label = ( fill ) => ( Y( fill ) > 0.1791 ? BLACK : WHITE );
// --wb-gam-pop-accent-hover: the accent 88%, plus 12% of the colour OPPOSITE the label.
const hover = ( fill ) => mix( fill, 0.88, label( fill ) === BLACK ? WHITE : BLACK );

const ACCENTS = {
	'Reign default #1d76da': '#1d76da',
	'very light #fff3b0': '#fff3b0',
	'very dark #101030': '#101030',
	'white #ffffff': '#ffffff',
	'black #000000': '#000000',
	'amber #f59e0b': '#f59e0b',
	'mid blue near the tie #2f7fd8': '#2f7fd8',
};

test( 'resting, hover and active are AA for default, light, dark and mid-tone accents', () => {
	for ( const [ name, h ] of Object.entries( ACCENTS ) ) {
		const fill = hex( h );
		assert.ok( ratio( label( fill ), fill ) >= 4.5, name + ' resting ' + ratio( label( fill ), fill ) );
		assert.ok( ratio( label( fill ), hover( fill ) ) >= 4.5, name + ' hover ' + ratio( label( fill ), hover( fill ) ) );
	}
} );

test( 'any sRGB accent keeps a 4.5:1 label, resting and hovered', () => {
	let worst = 99;
	for ( let r = 0; r < 256; r += 5 ) {
		for ( let g = 0; g < 256; g += 5 ) {
			for ( let b = 0; b < 256; b += 5 ) {
				const fill = [ r, g, b ];
				worst = Math.min( worst, ratio( label( fill ), fill ), ratio( label( fill ), hover( fill ) ) );
			}
		}
	}
	assert.ok( worst >= 4.5, 'worst pair ' + worst );
} );

test( 'witness: the retired rules failed on the same accents', () => {
	// Old hover: accent 88% + 12% black under a label picked from the resting accent.
	const fill = hex( '#1d76da' );
	assert.ok( ratio( BLACK, mix( fill, 0.88, BLACK ) ) < 4.0, 'give-kudos hover was ' + ratio( BLACK, mix( fill, 0.88, BLACK ) ) );
	// Old year-recap hover: opacity .9 over the recap's dark card (#1a1a2e).
	const card = hex( '#1a1a2e' );
	assert.ok( ratio( BLACK, mix( fill, 0.9, card ) ) < 4.5, 'year-recap hover was ' + ratio( BLACK, mix( fill, 0.9, card ) ) );
} );

/* ── The source: the CSS says what the model says ────────────────────────── */
const tokens = fs.readFileSync( path.join( ROOT, 'src/shared/design-tokens.css' ), 'utf8' ).replace( /\/\*[\s\S]*?\*\//g, '' );

test( 'design tokens: the label tie-point and the hover pair are what the model assumes', () => {
	assert.match( tokens, /0\.2126 \* r \+ 0\.7152 \* g \+ 0\.0722 \* b - 0\.1791/ );
	assert.doesNotMatch( tokens, /round\(1\.07 - l\)/, 'the oklch-lightness pick measured 3.98:1 on mid-tone accents' );
	const hovers = [ ...tokens.matchAll( /--wb-gam-pop-accent-hover:\s*([^;]+);/g ) ].map( ( m ) => m[ 1 ] );
	assert.ok( hovers.length >= 3, 'light fallback, dark fallback and the @supports pair' );
	hovers.forEach( ( v ) => assert.match( v, /^color-mix\(in srgb, var\(--wb-gam-pop-accent\) 88%, / ) );
} );

test( 'owner accent (CSS.php) derives the same label and hover pair', () => {
	const php = fs.readFileSync( path.join( ROOT, 'src/Blocks/CSS.php' ), 'utf8' );
	assert.match( php, /'--wb-gam-pop-accent-hover'\s*=>/ );
	assert.match( php, /0\.2126 \* r \+ 0\.7152 \* g \+ 0\.0722 \* b - 0\.1791/ );
	assert.doesNotMatch( php, /round\(1\.07 - l\)/ );
} );

function files( dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( e ) => {
		const p = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			return 'admin' === e.name || 'node_modules' === e.name ? [] : files( p );
		}
		return /\.css$/.test( e.name ) && ! /\.min\.css$|-rtl\.css$|^admin/.test( e.name ) ? [ p ] : [];
	} );
}

test( 'a button whose label is --wb-gam-pop-accent-fg changes state only through the hover token', () => {
	const bad = [];
	for ( const file of [ ...files( path.join( ROOT, 'assets/css' ) ), ...files( path.join( ROOT, 'src' ) ) ] ) {
		const css = fs.readFileSync( file, 'utf8' ).replace( /\/\*[\s\S]*?\*\//g, '' );
		const rules = [ ...css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ].map( ( m ) => ( {
			sel: m[ 1 ].replace( /@media[^{]*\{/, '' ).trim(),
			body: m[ 2 ],
		} ) );
		const labelled = new Set();
		rules.forEach( ( r ) => {
			if ( /(^|[;\s])color:\s*var\(\s*--wb-gam-pop-accent-fg\s*\)/.test( r.body ) ) {
				r.sel.split( ',' ).forEach( ( s ) => labelled.add( s.trim() ) );
			}
		} );
		rules.forEach( ( r ) => {
			r.sel.split( ',' ).forEach( ( s ) => {
				s = s.trim();
				if ( ! /:(hover|active)/.test( s ) ) {
					return;
				}
				const base = s.replace( /:(hover|active)/g, '' ).replace( /:not\([^)]*\)/g, '' ).trim();
				if ( ! labelled.has( base ) ) {
					return;
				}
				const fillMoves = /(^|[;\s])(opacity|filter)\s*:/.test( r.body );
				const bg = r.body.match( /(^|[;\s])background(-color)?\s*:\s*([^;]+)/ );
				const okBg = ! bg || /^var\(\s*--wb-gam-pop-accent(-hover)?\s*\)/.test( bg[ 3 ].trim() );
				if ( fillMoves || ! okBg ) {
					bad.push( path.relative( ROOT, file ) + ' ' + s );
				}
			} );
		} );
	}
	assert.deepStrictEqual( bad, [] );
} );
