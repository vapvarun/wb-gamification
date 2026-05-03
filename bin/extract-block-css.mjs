#!/usr/bin/env node
/**
 * One-shot Phase F helper — extracts per-block CSS sections from
 * `assets/css/frontend.css` into `src/Blocks/<slug>/style.css` files,
 * then rewrites frontend.css to keep only shared utilities (design
 * tokens, toasts, overlays, empty states, progress-fill).
 *
 * Run once via `node bin/extract-block-css.mjs`. Idempotent: refuses
 * to overwrite an existing per-block style.css.
 *
 * @see plans/WBCOM-BLOCK-STANDARD-MIGRATION.md Phase F
 */

import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const ROOT       = join( __dirname, '..' );
const FRONTEND   = join( ROOT, 'assets/css/frontend.css' );
const BLOCKS_DIR = join( ROOT, 'src/Blocks' );

// Section-header → block slug mapping. Keys match the comment text
// exactly (case-sensitive, trimmed of borders).
const PER_BLOCK_SECTIONS = {
	'Leaderboard':     'leaderboard',
	'Member Points':   'member-points',
	'Badge Showcase':  'badge-showcase',
	'Kudos Feed':      'kudos-feed',
	'Level Progress':  'level-progress',
	'Challenges':      'challenges',
	'Streak':          'streak',
	'Top Members':     'top-members',
	'Year Recap block': 'year-recap',
	'Points History':  'points-history',
	'Earning Guide':   null, // Earning-guide rules don't have a section header — collected at end.
};

// Sections we KEEP in frontend.css (shared utilities).
const KEEP_SECTIONS = new Set( [
	'Design tokens',
	'Toasts',
	'Overlays (level-up, streak milestone)',
	'Empty states (shared)',
] );

const HEADER_RE = /^\/\* ── (.+?) ─+ \*\/$/;

function parse( source ) {
	const lines = source.split( '\n' );
	const sections = []; // { title, startLine, endLine, body }

	let current = null;
	for ( let i = 0; i < lines.length; i++ ) {
		const m = lines[ i ].match( HEADER_RE );
		if ( m ) {
			if ( current ) {
				current.endLine = i - 1;
				sections.push( current );
			}
			current = {
				title: m[ 1 ].trim(),
				startLine: i,
				endLine: lines.length - 1,
				body: '',
			};
			continue;
		}
	}
	if ( current ) {
		sections.push( current );
	}

	for ( const s of sections ) {
		s.body = lines.slice( s.startLine, s.endLine + 1 ).join( '\n' );
	}

	return { lines, sections };
}

function extractEarningGuide( lines ) {
	// Earning-guide rules are between the last regular block section and
	// the @media-only responsive baseline. They start at the
	// `.wb-gam-earning-guide__category` selector.
	const startIdx = lines.findIndex( ( l ) => /^\.wb-gam-earning-guide__category \{/.test( l ) );
	if ( startIdx === -1 ) {
		return null;
	}
	// End at the next /* ── */ section or @media block.
	let endIdx = lines.length - 1;
	for ( let i = startIdx; i < lines.length; i++ ) {
		if ( /^@media/.test( lines[ i ] ) ) {
			endIdx = i - 1;
			break;
		}
	}
	while ( endIdx > startIdx && lines[ endIdx ].trim() === '' ) {
		endIdx--;
	}
	return { startLine: startIdx, endLine: endIdx, body: lines.slice( startIdx, endIdx + 1 ).join( '\n' ) };
}

function writeBlockStyle( slug, body, header ) {
	const blockDir = join( BLOCKS_DIR, slug );
	if ( ! existsSync( blockDir ) ) {
		mkdirSync( blockDir, { recursive: true } );
	}
	const target = join( blockDir, 'style.css' );
	if ( existsSync( target ) ) {
		console.log( `· skipped ${ slug } (style.css already exists — leaving it alone)` );
		return false;
	}
	const intro = `/**\n * ${ header } — frontend stylesheet.\n *\n * Phase F migration: extracted from assets/css/frontend.css so the\n * block ships its own CSS via block.json (\`style\` field).\n */\n\n`;
	writeFileSync( target, intro + body.trim() + '\n' );
	console.log( `✓ wrote src/Blocks/${ slug }/style.css` );
	return true;
}

function main() {
	const source = readFileSync( FRONTEND, 'utf8' );
	const { lines, sections } = parse( source );

	const removeRanges = []; // [start, end] inclusive

	for ( const section of sections ) {
		const slug = PER_BLOCK_SECTIONS[ section.title ];
		if ( slug ) {
			if ( writeBlockStyle( slug, section.body, section.title ) ) {
				removeRanges.push( [ section.startLine, section.endLine ] );
			}
			continue;
		}
		if ( ! KEEP_SECTIONS.has( section.title ) ) {
			console.log( `! unknown section "${ section.title }" — leaving in frontend.css` );
		}
	}

	const earning = extractEarningGuide( lines );
	if ( earning ) {
		const earningBody =
			'/* ── Earning Guide ─────────────────────────────────────────────── */\n' +
			earning.body;
		if ( writeBlockStyle( 'earning-guide', earningBody, 'Earning Guide' ) ) {
			removeRanges.push( [ earning.startLine, earning.endLine ] );
		}
	}

	// Build the trimmed frontend.css by skipping the removed line ranges.
	removeRanges.sort( ( a, b ) => a[ 0 ] - b[ 0 ] );
	const keepLines = [];
	let cursor = 0;
	for ( const [ start, end ] of removeRanges ) {
		for ( let i = cursor; i < start; i++ ) {
			keepLines.push( lines[ i ] );
		}
		cursor = end + 1;
	}
	for ( let i = cursor; i < lines.length; i++ ) {
		keepLines.push( lines[ i ] );
	}

	// Collapse runs of >2 blank lines.
	const collapsed = [];
	let blanks = 0;
	for ( const l of keepLines ) {
		if ( l.trim() === '' ) {
			blanks++;
			if ( blanks <= 2 ) {
				collapsed.push( l );
			}
		} else {
			blanks = 0;
			collapsed.push( l );
		}
	}

	writeFileSync( FRONTEND, collapsed.join( '\n' ) );
	console.log( `\n✓ trimmed assets/css/frontend.css (was ${ lines.length } lines, now ${ collapsed.length })` );
}

main();
