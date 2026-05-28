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

/**
 * Safety-net for the "forgot to import style.css in index.js" foot-gun.
 *
 * wp-scripts only compiles a block's style.css into build/Blocks/<slug>/style-index.css
 * if the block's index.js executes `import './style.css'`. Two blocks
 * (cohort-rank, community-challenges) shipped 1.5.0-era visual updates
 * with the file in src/ but no import line — webpack happily produced
 * the JS bundle, and the per-block CSS silently never appeared in build/.
 * The frontend block then rendered without its styles.
 *
 * This sync runs AFTER wp-scripts:
 *
 *   - If src/Blocks/<slug>/style.css exists AND build/.../style-index.css exists
 *     → webpack handled it via the import; leave alone.
 *   - If src/Blocks/<slug>/style.css exists AND build/.../style-index.css is missing
 *     → copy the source through unprocessed AND warn loudly so the author
 *       knows to add the proper import (which would also unlock PostCSS).
 *   - If src/Blocks/<slug>/style.css is missing
 *     → nothing to do.
 *
 * Failure mode this prevents: block ships without its CSS. The block still
 * renders (HTML is fine) so QA can easily miss it. Visual regression catches
 * it only on screenshot diff — far downstream from the build failure.
 */
function syncBlockStyleCss() {
	const slugs = readdirSync( BLOCKS_DIR, { withFileTypes: true } )
		.filter( ( entry ) => entry.isDirectory() )
		.map( ( entry ) => entry.name );

	const orphans = [];
	let copied = 0;

	for ( const slug of slugs ) {
		const src = path.join( BLOCKS_DIR, slug, 'style.css' );
		if ( ! existsSync( src ) ) {
			continue;
		}
		const dest = path.join( BUILD_DIR, 'Blocks', slug, 'style-index.css' );
		if ( existsSync( dest ) ) {
			// webpack handled it via the import. Nothing to do.
			continue;
		}
		const destDir = path.dirname( dest );
		if ( ! existsSync( destDir ) ) {
			mkdirSync( destDir, { recursive: true } );
		}
		copyFileSync( src, dest );
		orphans.push( slug );
		copied++;
	}

	if ( orphans.length > 0 ) {
		process.stderr.write(
			`\nwb-gamification: WARN — ${ orphans.length } block(s) shipped style.css without a matching\n` +
				`  \`import './style.css'\` in their index.js. The source CSS has been copied through\n` +
				`  unprocessed so the block isn't unstyled in production, but PostCSS (autoprefixer,\n` +
				`  nested CSS, etc.) did NOT run on it. Fix by adding the import at the top of each\n` +
				`  block's index.js — webpack will take over and you can remove this safety-net path.\n` +
				`  Affected blocks:\n`
		);
		for ( const slug of orphans ) {
			process.stderr.write( `    · src/Blocks/${ slug }/index.js\n` );
		}
		process.stderr.write( '\n' );
	}

	if ( copied > 0 ) {
		process.stdout.write(
			`wb-gamification: copied ${ copied } unprocessed style.css file(s) into build/ (see WARN above).\n`
		);
	}
}

syncRenderPhp();
syncBlockStyleCss();

process.exit( 0 );
