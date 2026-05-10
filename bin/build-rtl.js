#!/usr/bin/env node
/**
 * bin/build-rtl.js — generate -rtl.css siblings for every source CSS in
 * assets/css/ that doesn't already have one. RTL is mandatory for the
 * release zip (Hebrew/Arabic/Persian/Urdu locales rely on it); the plugin
 * previously hand-authored these files which drifted out of sync with
 * the LTR sources. This script makes the generation deterministic.
 *
 * Source files: assets/css/*.css (excluding -rtl.css and .min.css).
 * Output: assets/css/<name>-rtl.css
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

const sources = fs.readdirSync(CSS_DIR).filter((f) => {
	return f.endsWith('.css') && !f.endsWith('-rtl.css') && !f.endsWith('.min.css');
});

if (sources.length === 0) {
	process.stdout.write('build:rtl — no LTR source CSS files found.\n');
	process.exit(0);
}

let generated = 0;
for (const filename of sources) {
	const ltrPath = path.join(CSS_DIR, filename);
	const rtlPath = path.join(CSS_DIR, filename.replace(/\.css$/, '-rtl.css'));

	const ltr = fs.readFileSync(ltrPath, 'utf8');
	const rtl = rtlcss.process(ltr);

	const header = `/*! ${filename.replace(/\.css$/, '-rtl.css')} — auto-generated from ${filename} by bin/build-rtl.js. Do not edit by hand. */\n`;
	fs.writeFileSync(rtlPath, header + rtl);
	generated += 1;
	process.stdout.write(`  rtl  ${filename} → ${path.basename(rtlPath)}\n`);
}

process.stdout.write(`build:rtl — ${generated} file${generated === 1 ? '' : 's'} generated.\n`);
