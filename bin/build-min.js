#!/usr/bin/env node
/**
 * bin/build-min.js — minify every .css in assets/css/ (recursive —
 * descends into subdirectories like assets/css/admin/pages/) to a
 * .min.css sibling. The release zip ships both LTR and RTL sources
 * alongside their .min.css variants so plugin consumers can pick the
 * served path (debug = source, production = minified).
 *
 * Source files: assets/css/**\/*.css and assets/css/**\/*-rtl.css
 * (excluding *.min.css).
 * Output: assets/css/**\/<name>.min.css and
 * assets/css/**\/<name>-rtl.min.css alongside each source.
 *
 * Invocation: npm run build:min
 */
'use strict';

const fs = require('fs');
const path = require('path');
const CleanCSS = require('clean-css');

const ROOT = path.resolve(__dirname, '..');
const CSS_DIR = path.join(ROOT, 'assets', 'css');

if (!fs.existsSync(CSS_DIR)) {
	process.stdout.write('build:min — assets/css/ does not exist; nothing to do.\n');
	process.exit(0);
}

/**
 * Walk a directory recursively, yielding absolute paths to every .css source
 * file (excludes *.min.css siblings — keeps both LTR and -rtl.css).
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
		if (entry.name.endsWith('.min.css')) {
			continue;
		}
		out.push(full);
	}
	return out;
}

const sources = walkCss(CSS_DIR);

if (sources.length === 0) {
	process.stdout.write('build:min — no source CSS files found.\n');
	process.exit(0);
}

const minifier = new CleanCSS({
	level: 1, // safe one-pass minification (no risky structural rewrites).
	returnPromise: false,
	rebase: false,
});

let generated = 0;
for (const srcPath of sources) {
	const filename = path.basename(srcPath);
	const dir = path.dirname(srcPath);
	const minPath = path.join(dir, filename.replace(/\.css$/, '.min.css'));

	const css = fs.readFileSync(srcPath, 'utf8');
	const result = minifier.minify(css);
	if (result.errors && result.errors.length) {
		process.stderr.write(`build:min — errors minifying ${filename}:\n  ${result.errors.join('\n  ')}\n`);
		process.exit(1);
	}

	fs.writeFileSync(minPath, result.styles);
	generated += 1;
	const rel = path.relative(CSS_DIR, srcPath);
	process.stdout.write(`  min  ${rel} → ${path.relative(CSS_DIR, minPath)} (${(result.styles.length / 1024).toFixed(1)} KB)\n`);
}

process.stdout.write(`build:min — ${generated} file${generated === 1 ? '' : 's'} minified.\n`);
