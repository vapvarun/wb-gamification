/**
 * Accent buttons (card 10523784929, QA rc3 F-2): the fill, the border and the label of every accent button come from
 * the one pair the helper computed (--wb-gam-pop-accent / --wb-gam-pop-accent-fg / --wb-gam-pop-accent-hover),
 * declared once in design-tokens.css with !important so a theme's own button rule (BuddyX `button[type=submit]`
 * (0,1,1) and its :hover (0,2,1)) cannot split the pair (white label on the theme's red fill, 3.46:1).
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

const rest = tokens.match( /\n:is\(([^)]*)\) \{([^}]*)\}/ );
const state = tokens.match( /\n:is\(([^)]*)\):is\(:hover, :active\):not\(:disabled\) \{([^}]*)\}/ );

const BUTTONS = [
	'.wb-gam-give-kudos__submit',
	'.wb-gam-submit-achievement__submit',
	'.wb-gam-recap__share-btn',
	'.gam-nudge__action',
	'.wb-gam-btn--primary',
	'.wb-gam-share-card__cta-primary',
	'.wb-gam-profile-privacy__toggle',
	'.gam-lb-pill--active',
];

test( 'one shared rule sets fill, border and label of every accent button from the helper tokens, !important', () => {
	assert.ok( rest && state, 'shared accent-button rules exist' );
	for ( const b of BUTTONS ) {
		assert.ok( rest[ 1 ].includes( b ), `${ b } in the rest rule` );
		assert.ok( state[ 1 ].includes( b ), `${ b } in the hover/active rule` );
	}
	assert.match( rest[ 2 ], /background-color: var\(--wb-gam-pop-accent\) !important;/ );
	assert.match( rest[ 2 ], /color: var\(--wb-gam-pop-accent-fg\) !important;/ );
	assert.match( rest[ 2 ], /border-color: var\(--wb-gam-pop-accent\) !important;/ );
	assert.match( state[ 2 ], /background-color: var\(--wb-gam-pop-accent-hover\) !important;/ );
	assert.ok( ! /color: [^;]*accent-fg[^;]*;/.test( state[ 2 ].replace( /background-color[^;]*;/, '' ) ), 'the label never changes on hover' );
} );

test( 'no stylesheet pairs an accent fill with a label for an accent button outside the shared rule', () => {
	const files = [ 'assets/css/hub.css', 'assets/css/give-kudos.css', 'assets/css/frontend.css', 'assets/css/popups.css', 'assets/css/share-page.css' ];
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
			// An accent fill and its label on one selector must be a shared button, a non-control (chip, disc, avatar) or a hover/focus pair.
			if ( /background(-color)?:\s*var\(--wb-gam-pop-accent(-hover)?\)/.test( m[ 2 ] ) && /(^|[^-])color:\s*var\(--wb-gam-pop-accent-fg\)/.test( m[ 2 ] ) ) {
				if ( BUTTONS.some( ( b ) => sel.includes( b ) ) ) {
					bad.push( `${ f }: ${ sel.slice( 0, 60 ) }` );
				}
			}
		}
	}
	assert.deepStrictEqual( bad, [], 'a shared accent button re-declares fill and label (the theme beats it, and the shared rule makes it dead code)' );
} );

test( 'no stylesheet gives a shared accent button its own fill or hover fill', () => {
	const files = [ 'assets/css/hub.css', 'assets/css/give-kudos.css', 'assets/css/frontend.css', 'assets/css/popups.css', 'assets/css/share-page.css' ];
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
			if ( BUTTONS.some( ( b ) => sel.split( ',' ).some( ( p ) => p.trim().startsWith( b ) ) ) && /(^|[;\s])background(-color)?:/.test( m[ 2 ] ) ) {
				bad.push( `${ f }: ${ sel.slice( 0, 60 ) }` );
			}
		}
	}
	assert.deepStrictEqual( bad, [] );
} );

test( 'the Redeem button disabled state beats the shared accent rule (both !important, the longer selector wins)', () => {
	const css = read( 'src/Blocks/redemption-store/style.css' );
	const m = css.match( /\.wb-gam-redemption__btn\[ disabled \]:not\( \.is-loading \) \{([^}]*)\}/ );
	assert.ok( m );
	assert.match( m[ 1 ], /background: var\( --wb-gam-color-disabled \) !important;/ );
	assert.match( m[ 1 ], /color: var\( --wb-gam-color-disabled-text \) !important;/ );
} );
