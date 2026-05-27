#!/usr/bin/env node
/**
 * bin/build-rtl.js — generate -rtl.css siblings for every source CSS in
 * assets/css/ (recursive — descends into subdirectories like
 * assets/css/admin/pages/) that doesn't already have one. RTL is
 * mandatory for the release zip (Hebrew/Arabic/Persian/Urdu locales
 * rely on it); the plugin previously hand-authored these files which
 * drifted out of sync with the LTR sources. This script makes the
 * generation deterministic.
 *
 * Source files: assets/css/**\/*.css (excluding -rtl.css and .min.css).
 * Output: assets/css/**\/<name>-rtl.css alongside each source.
 *
 * Invocation: npm run build:rtl
 */
'use strict';

const fs = require('fs');
const path = require('path');
const rtlcss = require('rtlcss');

const ROOT = path.resolve(__dirname, '..');
const CSS_DIR = path.join(ROOT, 'assets', 'css');

if (!fs.existsSync(CSS_DIR)) {
	process.stdout.write('build:rtl — assets/css/ does not exist; nothing to do.\n');
	process.exit(0);
}

/**
 * Walk a directory recursively, yielding absolute paths to every .css source
 * file (excludes -rtl.css and .min.css siblings).
 *
 * @param {string} dir Absolute directory path.
 * @returns {string[]} Array of absolute file paths.
 */
function walkCss(dir) {
	const out = [];
	const entries = fs.readdirSync(dir, { withFileTypes: true });
	for (const entry of entries) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...walkCss(full));
			continue;
		}
		if (!entry.isFile()) {
			continue;
		}
		if (!entry.name.endsWith('.css')) {
			continue;
		}
		if (entry.name.endsWith('-rtl.css') || entry.name.endsWith('.min.css')) {
			continue;
		}
		out.push(full);
	}
	return out;
}

const sources = walkCss(CSS_DIR);

if (sources.length === 0) {
	process.stdout.write('build:rtl — no LTR source CSS files found.\n');
	process.exit(0);
}

let generated = 0;
for (const ltrPath of sources) {
	const filename = path.basename(ltrPath);
	const dir = path.dirname(ltrPath);
	const rtlPath = path.join(dir, filename.replace(/\.css$/, '-rtl.css'));

	const ltr = fs.readFileSync(ltrPath, 'utf8');
	const rtl = rtlcss.process(ltr);

	const rel = path.relative(CSS_DIR, ltrPath);
	const header = `/*! ${filename.replace(/\.css$/, '-rtl.css')} — auto-generated from ${filename} by bin/build-rtl.js. Do not edit by hand. */\n`;
	fs.writeFileSync(rtlPath, header + rtl);
	generated += 1;
	process.stdout.write(`  rtl  ${rel} → ${path.relative(CSS_DIR, rtlPath)}\n`);
}

process.stdout.write(`build:rtl — ${generated} file${generated === 1 ? '' : 's'} generated.\n`);
