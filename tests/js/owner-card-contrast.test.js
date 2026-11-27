/**
 * Owner colours stay readable (card 10523784929): an owner's Accent or Card background under a light OR a dark
 * theme. The PHP side (CSS::card_tokens(), tests/Unit/Blocks/CSSTest.php) measures a card colour and sets literal
 * tokens; what is left to CSS is the accent as text, held to an OKLCH lightness range:
 *
 *   --wb-gam-color-accent-text: oklch(from accent clamp(l-min, l, l-max) min(c, 0.11) h)
 *
 * This file models that rule (the same maths as the CSS, plus the bound CSS.php derives from the card) and sweeps
 * every hue, lightness and chroma against the surfaces it can land on, and pins the rules the CSS must keep.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const ROOT = path.join( __dirname, '../..' );

/* ── WCAG ─────────────────────────────────────────────────────────────────── */
const lin = ( c ) => ( c <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
const gam = ( c ) => ( c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow( c, 1 / 2.4 ) - 0.055 );
const Y = ( [ r, g, b ] ) => 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b ); // channels 0..1
const ratio = ( a, b ) => ( Math.max( Y( a ), Y( b ) ) + 0.05 ) / ( Math.min( Y( a ), Y( b ) ) + 0.05 );
const hex = ( h ) => [ 1, 3, 5 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) / 255 );
const mix = ( a, p, b ) => a.map( ( v, i ) => v * p + b[ i ] * ( 1 - p ) );

/* ── OKLCH -> sRGB, gamut-mapped by reducing chroma (what CSS gamut mapping converges to) ─── */
function oklchToRgb( L, C, h ) {
	const toRgb = ( c ) => {
		const a = c * Math.cos( ( h * Math.PI ) / 180 );
		const b = c * Math.sin( ( h * Math.PI ) / 180 );
		const l = Math.pow( L + 0.3963377774 * a + 0.2158037573 * b, 3 );
		const m = Math.pow( L - 0.1055613458 * a - 0.0638541728 * b, 3 );
		const s = Math.pow( L - 0.0894841775 * a - 1.291485548 * b, 3 );
		return [
			4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
			-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
			-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
		];
	};
	let lo = 0;
	let hi = C;
	let rgb = toRgb( hi );
	const inGamut = ( v ) => v.every( ( x ) => x >= -0.0005 && x <= 1.0005 );
	if ( ! inGamut( rgb ) ) {
		for ( let i = 0; i < 24; i++ ) {
			const mid = ( lo + hi ) / 2;
			inGamut( toRgb( mid ) ) ? ( lo = mid ) : ( hi = mid );
		}
		rgb = toRgb( lo );
	}
	return rgb.map( ( x ) => gam( Math.min( 1, Math.max( 0, x ) ) ) );
}

// The bound CSS.php card_tokens() sets from the card (rows = card, 5% and 7% toward the ink).
const INK_DARK = hex( '#1a1a2e' );
const INK_LIGHT = hex( '#f5f5f7' );
function bounds( card, isLight ) {
	const ink = isLight ? INK_DARK : INK_LIGHT;
	const rows = [ card, mix( ink, 0.05, card ), mix( ink, 0.07, card ) ].map( Y );
	if ( isLight ) {
		return { min: 0, max: Math.cbrt( Math.max( 0, ( Math.min( ...rows ) + 0.05 ) / ( 4.5 * 1.1 ) - 0.05 ) ) };
	}
	return { min: Math.cbrt( Math.min( 1, 4.5 * 1.15 * ( Math.max( ...rows ) + 0.05 ) - 0.05 ) ), max: 1 };
}

const clampL = ( l, { min, max } ) => Math.min( max, Math.max( min, l ) );

// Surfaces the theme's own scheme gives (design-tokens.css light base / dark: Reign #161616-#262a34).
const LIGHT_SURFACES = [ '#ffffff', '#f8fafc', '#f1f5f9' ].map( hex );
const DARK_SURFACES = [ '#161616', '#1f2937', '#262a34' ].map( hex );

function sweep( surfaces, bound, name ) {
	let worst = 99;
	for ( let h = 0; h < 360; h += 10 ) {
		for ( let L = 0; L <= 1; L += 0.05 ) {
			for ( const C of [ 0, 0.04, 0.11, 0.2, 0.37 ] ) {
				const text = oklchToRgb( clampL( L, bound ), Math.min( C, 0.11 ), h );
				for ( const s of surfaces ) {
					const r = ratio( text, s );
					worst = Math.min( worst, r );
					assert.ok( r >= 4.5, `${ name }: L ${ L.toFixed( 2 ) } C ${ C } h ${ h } on ${ s.map( ( v ) => Math.round( v * 255 ) ) } is ${ r.toFixed( 2 ) }` );
				}
			}
		}
	}
	return worst;
}

test( 'an accent only, light scheme: any accent reads as text on the light surfaces', () => {
	sweep( LIGHT_SURFACES, { min: 0, max: 0.5 }, 'light accent-only' );
} );

test( 'an accent only, dark scheme: any accent reads as text on the dark surfaces', () => {
	sweep( DARK_SURFACES, { min: 0.72, max: 1 }, 'dark accent-only' );
} );

test( 'the theme accent on an owner card: the bound set from the card reads on the card and its rows', () => {
	const cards = [ '#ffeedd', '#ffffff', '#fafafa', '#f0f4ff', '#ffd54a', '#cfd8dc', '#14213d', '#000000', '#101418', '#223344', '#00695c', '#7b1fa2' ];
	for ( const c of cards ) {
		const card = hex( c );
		const isLight = ratio( INK_DARK, card ) >= ratio( INK_LIGHT, card );
		const ink = isLight ? INK_DARK : INK_LIGHT;
		const rows = [ card, mix( ink, 0.05, card ), mix( ink, 0.07, card ) ];
		sweep( rows, bounds( card, isLight ), 'card ' + c );
	}
} );

/* ── The CSS rules that make it hold ───────────────────────────────────────── */
const read = ( f ) => fs.readFileSync( path.join( ROOT, f ), 'utf8' );

test( 'the dark card re-derives every token the light card does (one list of selectors)', () => {
	const css = read( 'src/shared/design-tokens.css' );
	assert.match( css, /^:root,\n\.wb-gam-scheme-light,\n\.wb-gam-scheme-dark \{/ );
	assert.match( css, /\.wb-gam-scheme-light,\n\t\.wb-gam-scheme-dark \{\n\t\t--wb-gam-color-accent-text: oklch\(from var\(--wb-gam-color-accent\) clamp\(var\(--wb-gam-accent-l-min\), l, var\(--wb-gam-accent-l-max\)\) min\(c, 0\.11\) h\);/ );
} );

test( 'no focus ring colour is taken from the accent without the card-derived ring token', () => {
	const files = [ 'assets/css/popups.css', 'assets/css/hub.css', 'assets/css/give-kudos.css', 'src/shared/block-card.css' ];
	const blocks = fs.readdirSync( path.join( ROOT, 'src/Blocks' ), { withFileTypes: true } ).filter( ( e ) => e.isDirectory() ).map( ( e ) => `src/Blocks/${ e.name }/style.css` );
	const bad = [];
	for ( const f of [ ...files, ...blocks ] ) {
		if ( ! fs.existsSync( path.join( ROOT, f ) ) ) {
			continue;
		}
		const css = read( f );
		for ( const m of css.matchAll( /([^{}]*:focus(?:-visible)?[^{}]*)\{([^{}]*)\}/g ) ) {
			for ( const o of m[ 2 ].matchAll( /outline:\s*\d+px\s+solid\s+([^;]+);/g ) ) {
				if ( ! /transparent|--wb-gam-focus-ring/.test( o[ 1 ] ) && /accent|primary/.test( o[ 1 ] ) ) {
					bad.push( f + ': ' + m[ 1 ].trim().slice( 0, 60 ) + ' -> ' + o[ 1 ] );
				}
			}
		}
	}
	assert.deepStrictEqual( bad, [] );
} );

test( 'Year Recap keeps no white ink or fixed gradient over an owner card', () => {
	const css = read( 'src/Blocks/year-recap/style.css' );
	assert.doesNotMatch( css, /rgba\(\s*255,\s*255,\s*255/ );
	assert.match( css, /\.wb-gam-year-recap\.wb-gam-scheme-light,\n\.wb-gam-year-recap\.wb-gam-scheme-dark \{[^}]*background-image: none;/ );
} );
