#!/usr/bin/env node
/**
 * Wbcom Block Standard — block build orchestrator.
 *
 * Runs `@wordpress/scripts build` when at least one block exists at
 * `src/Blocks/<slug>/block.json`. Before any blocks have been migrated
 * (Phase A bootstrap) it emits an empty `build/` directory so downstream
 * tooling — the PHP block registrar, CI artefact gates — sees a
 * well-formed output even when there is nothing to compile yet.
 *
 * Invocation: `npm run build` (declared in package.json).
 */
'use strict';

const { copyFileSync, existsSync, mkdirSync, readdirSync, statSync, writeFileSync } = require( 'fs' );
const { spawnSync } = require( 'child_process' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const BLOCKS_DIR = path.join( ROOT, 'src', 'Blocks' );
const BUILD_DIR = path.join( ROOT, 'build' );

function hasMigratedBlocks() {
	if ( ! existsSync( BLOCKS_DIR ) ) {
		return false;
	}
	return readdirSync( BLOCKS_DIR, { withFileTypes: true } )
		.filter( ( entry ) => entry.isDirectory() )
		.some( ( entry ) =>
			existsSync( path.join( BLOCKS_DIR, entry.name, 'block.json' ) )
		);
}

if ( ! hasMigratedBlocks() ) {
	mkdirSync( BUILD_DIR, { recursive: true } );
	writeFileSync(
		path.join( BUILD_DIR, '.gitkeep' ),
		'# Phase A bootstrap — no blocks migrated yet. See plan/WBCOM-BLOCK-STANDARD-MIGRATION.md.\n'
	);
	process.stdout.write(
		'wb-gamification: src/Blocks/ has no block.json yet — emitted empty build/.\n'
	);
	process.exit( 0 );
}

const result = spawnSync(
	process.platform === 'win32' ? 'wp-scripts.cmd' : 'wp-scripts',
	[ 'build' ],
	{
		cwd: ROOT,
		stdio: 'inherit',
		env: {
			...process.env,
			// Enable @wordpress/scripts module pipeline so blocks declaring
			// `viewScriptModule` (the standard for Interactivity API view
			// modules) compile alongside the classic editorScript entry.
			WP_EXPERIMENTAL_MODULES: 'true',
		},
	}
);

if ( result.status === null || result.status !== 0 ) {
	process.exit( result.status === null ? 1 : result.status );
}

/**
 * Force-sync every src/Blocks/<slug>/render.php to build/Blocks/<slug>/render.php
 * after wp-scripts has finished. wp-scripts uses CopyWebpackPlugin which
 * only emits on detected file changes — if a build artefact is deleted
 * or a render.php edit isn't fingerprinted (mtime collision, identical
 * size), the PHP renderer goes stale. Block-level register_block_type()
 * resolves render.php from the build path, so stale here means stale on
 * the live page even with a successful npm run build.
 *
 * Caught during the v1.4.0 leaderboard hydration verification (Basecamp
 * #9914601059 follow-up) where a fresh src/ edit didn't propagate.
 */
function syncRenderPhp() {
	const slugs = readdirSync( BLOCKS_DIR, { withFileTypes: true } )
		.filter( ( entry ) => entry.isDirectory() )
		.map( ( entry ) => entry.name );

	let copied = 0;
	for ( const slug of slugs ) {
		const src = path.join( BLOCKS_DIR, slug, 'render.php' );
		if ( ! existsSync( src ) ) {
			continue;
		}
		const dest = path.join( BUILD_DIR, 'Blocks', slug, 'render.php' );
		const destDir = path.dirname( dest );
		if ( ! existsSync( destDir ) ) {
			mkdirSync( destDir, { recursive: true } );
		}
		// Skip the copy when src + dest are identical (mtime + size) so
		// successive builds stay fast. Force-copy otherwise so any edit,
		// however small, lands in build/.
		let shouldCopy = true;
		if ( existsSync( dest ) ) {
			try {
				const s = statSync( src );
				const d = statSync( dest );
				if ( s.size === d.size && s.mtimeMs <= d.mtimeMs ) {
					shouldCopy = false;
				}
			} catch ( _e ) {
				// Stat fail — fall through to copy.
			}
		}
		if ( shouldCopy ) {
			copyFileSync( src, dest );
			copied++;
		}
	}
	if ( copied > 0 ) {
		process.stdout.write(
			`wb-gamification: synced ${ copied } render.php file(s) into build/.\n`
		);
	}
}

syncRenderPhp();

process.exit( 0 );
