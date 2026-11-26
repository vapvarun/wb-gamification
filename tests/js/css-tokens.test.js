/**
 * No CSS custom property may be defined as itself (`--x: var(--x)`): that is a cycle, so the property
 * is invalid and every var(--x) quietly resolves to nothing. A token sweep once left
 * --wbgam-gap that way and every admin gap and margin built on it went missing.
 *
 * Run: npm run test:js
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

function cssFiles( dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( e ) => {
		const p = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			return 'node_modules' === e.name ? [] : cssFiles( p );
		}
		return /\.css$/.test( e.name ) && ! /\.min\.css$|-rtl\.css$/.test( e.name ) ? [ p ] : [];
	} );
}

test( 'no custom property is defined as itself', () => {
	const root = path.join( __dirname, '../..' );
	const bad = [];
	for ( const file of [ ...cssFiles( path.join( root, 'assets/css' ) ), ...cssFiles( path.join( root, 'src' ) ) ] ) {
		fs.readFileSync( file, 'utf8' ).split( '\n' ).forEach( ( line, i ) => {
			const m = line.match( /(--[a-z0-9-]+)\s*:\s*var\(\s*(--[a-z0-9-]+)\s*[,)]/ );
			if ( m && m[ 1 ] === m[ 2 ] ) {
				bad.push( path.relative( root, file ) + ':' + ( i + 1 ) );
			}
		} );
	}
	assert.deepStrictEqual( bad, [] );
} );
