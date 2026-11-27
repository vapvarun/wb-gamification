/**
 * Keyboard focus ring (card 10523784929, QA bounce 2): one shared :focus-visible rule for every control in the
 * 19 blocks and the hub, a 2px ring in --wb-gam-focus-ring with a halo in the surface colour either side
 * (box-shadow spans the offset gap and the band outside the ring), so the pair reaches 3:1 on the card AND on
 * any page colour around it. Also: anchors inside an owner-coloured block take the card's text colour.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const ROOT = path.join( __dirname, '../..' );
const read = ( f ) => fs.readFileSync( path.join( ROOT, f ), 'utf8' );
const lin = ( c ) => ( c <= 0.04045 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ) );
const Y = ( [ r, g, b ] ) => 0.2126 * lin( r ) + 0.7152 * lin( g ) + 0.0722 * lin( b );
const ratio = ( a, b ) => ( Math.max( Y( a ), Y( b ) ) + 0.05 ) / ( Math.min( Y( a ), Y( b ) ) + 0.05 );
const hex = ( h ) => [ 1, 3, 5 ].map( ( i ) => parseInt( h.slice( i, i + 2 ), 16 ) / 255 );

const tokens = read( 'src/shared/design-tokens.css' );
const rule = tokens.match( /\n(\[class\*="wp-block-wb-gamification"\][^{]*:focus-visible) \{([^}]*)\}/ );

test( 'one shared focus rule covers every control kind, with a ring, an offset and a surface halo', () => {
	assert.ok( rule, 'shared :focus-visible rule exists in design-tokens.css' );
	for ( const t of [ 'a', 'button', 'input', 'select', 'textarea', 'summary', '[tabindex]' ] ) {
		assert.ok( rule[ 1 ].includes( t ), t );
	}
	assert.match( rule[ 2 ], /outline: 2px solid var\(--wb-gam-focus-ring, /);
	assert.match( rule[ 2 ], /outline-offset: 2px !important;/ );
	assert.match( rule[ 2 ], /box-shadow: 0 0 0 6px var\(--wb-gam-color-surface[^;]*!important;/ );
} );

test( 'no block stylesheet removes or hides the ring (no outline none/0/transparent on :focus-visible)', () => {
	const files = [ 'assets/css/hub.css', 'assets/css/give-kudos.css', 'assets/css/frontend.css', 'assets/css/popups.css' ];
	for ( const e of fs.readdirSync( path.join( ROOT, 'src/Blocks' ), { withFileTypes: true } ).filter( ( x ) => x.isDirectory() ) ) {
		files.push( `src/Blocks/${ e.name }/style.css` );
	}
	const bad = [];
	for ( const f of files ) {
		if ( ! fs.existsSync( path.join( ROOT, f ) ) ) {
			continue;
		}
		for ( const m of read( f ).matchAll( /([^{}]*:focus[^{}]*)\{([^{}]*)\}/g ) ) {
			if ( /:not\(:focus-visible\)/.test( m[ 1 ] ) ) {
				continue; // The mouse-click case.
			}
			if ( /outline(-style)?:\s*(none|0|transparent|\d+px solid transparent)\b/.test( m[ 2 ] ) ) {
				bad.push( f + ': ' + m[ 1 ].trim().slice( 0, 70 ) );
			}
		}
	}
	assert.deepStrictEqual( bad, [] );
} );

test( 'ring plus halo reaches 3:1 on the card and on a light or dark page around it, for every card colour', () => {
	const pages = [ [ 1, 1, 1 ], [ 0, 0, 0 ], hex( '#f0f0f1' ), hex( '#161616' ) ];
	const cards = [ '#ffffff', '#ffeedd', '#14213d', '#000000', '#f3e9d7', '#223344', '#fafafa', '#101418', '#ffd54a', '#7b1fa2' ];
	const dark = hex( '#1a1a2e' );
	const light = hex( '#f5f5f7' );
	for ( const c of cards ) {
		const card = hex( c );
		const ring = ratio( dark, card ) >= ratio( light, card ) ? dark : light; // CSS.php: the ink of the card.
		assert.ok( ratio( ring, card ) >= 3, `${ c }: ring on card` );
		for ( const p of pages ) {
			// Ring against the page, or the halo (the card colour) against the page: one of the two must reach 3:1.
			assert.ok( Math.max( ratio( ring, p ), ratio( card, p ) ) >= 3, `${ c } on page ${ p }` );
		}
	}
} );

test( 'anchors in an owner-coloured block take the card text colour; buttons keep their own', () => {
	const m = tokens.match( /\n(:is\(\.wb-gam-scheme-light, \.wb-gam-scheme-dark\) a:not\([^{]*\)[^{]*) \{([^}]*)\}/ );
	assert.ok( m, 'link rule exists' );
	assert.match( m[ 2 ], /color: var\(--wb-gam-color-text\) !important;/ );
	for ( const k of [ 'btn', '__action', '__cta', '__login' ] ) {
		assert.ok( m[ 1 ].includes( k ), k );
	}
} );

/* Specificity of the block selector list, counting :is() as its most specific argument and :not() the same way. */
const spec = ( sel ) => {
	let a = 0, b = 0, c = 0;
	const walk = ( t ) => {
		t = t.replace( /\[[^\]]*\]/g, () => { b++; return ''; } );
		let m;
		while ( ( m = /:(is|not|where)\(([^()]*)\)/.exec( t ) ) ) {
			if ( m[ 1 ] !== 'where' ) {
				const best = m[ 2 ].split( ',' ).map( ( x ) => spec( x.trim() ) ).sort( ( x, y ) => x[ 0 ] - y[ 0 ] || x[ 1 ] - y[ 1 ] || x[ 2 ] - y[ 2 ] ).pop();
				a += best[ 0 ]; b += best[ 1 ]; c += best[ 2 ];
			}
			t = t.replace( m[ 0 ], '' );
		}
		a += ( t.match( /#[\w-]+/g ) || [] ).length;
		b += ( t.match( /\.[\w-]+|:(?!:)[\w-]+/g ) || [] ).length;
		c += ( t.match( /(^|[\s>+~])[a-z][\w-]*/g ) || [] ).length;
	};
	walk( sel );
	return [ a, b, c ];
};
const gt = ( x, y ) => ( x[ 0 ] - y[ 0 ] || x[ 1 ] - y[ 1 ] || x[ 2 ] - y[ 2 ] ) > 0;

test( 'the ring beats a theme `:focus-visible { outline !important }` (BuddyX 5.1.3): !important and higher specificity', () => {
	assert.match( rule[ 2 ], /outline: 2px solid[^;]*!important;/ );
	assert.match( rule[ 2 ], /box-shadow:[^;]*!important;/ );
	const themeRule = spec( ':focus-visible' ); // (0,1,0), the BuddyX selector.
	assert.deepStrictEqual( themeRule, [ 0, 1, 0 ] );
	for ( const part of rule[ 1 ].split( /,\s*\n?(?=\[|:is)/ ) ) {
		assert.ok( gt( spec( part.trim() ), themeRule ), `${ part.trim().slice( 0, 60 ) } outranks :focus-visible` );
	}
} );

test( 'the ring selector list covers the controls that sit outside a block wrapper', () => {
	for ( const k of [ '.wb-gam-btn', '.wb-gam-close', '.wb-gam-toast__link', '.wb-gam-banner__action', '.wb-gam-share-card__action', '.wb-gam-profile-privacy__toggle', '.gam-stat__convert' ] ) {
		assert.ok( rule[ 1 ].includes( k ), k );
	}
} );
