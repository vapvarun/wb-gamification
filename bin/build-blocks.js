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

const { existsSync, mkdirSync, readdirSync, writeFileSync } = require( 'fs' );
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
		'# Phase A bootstrap — no blocks migrated yet. See plans/WBCOM-BLOCK-STANDARD-MIGRATION.md.\n'
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

process.exit( result.status === null ? 1 : result.status );
