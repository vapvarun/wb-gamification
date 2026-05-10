#!/usr/bin/env node
/**
 * bin/build-min.js — minify every .css in assets/css/ to a .min.css sibling.
 * The release zip ships both LTR and RTL sources alongside their .min.css
 * variants so plugin consumers can pick the served path (debug = source,
 * production = minified).
 *
 * Source files: assets/css/*.css and assets/css/*-rtl.css (excluding *.min.css).
 * Output: assets/css/<name>.min.css and assets/css/<name>-rtl.min.css
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

// Minify both LTR sources and -rtl.css. Skip already-minified output to avoid
// recursive .min.min.css.
const sources = fs.readdirSync(CSS_DIR).filter((f) => {
	return f.endsWith('.css') && !f.endsWith('.min.css');
});

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
for (const filename of sources) {
	const srcPath = path.join(CSS_DIR, filename);
	const minPath = path.join(CSS_DIR, filename.replace(/\.css$/, '.min.css'));

	const css = fs.readFileSync(srcPath, 'utf8');
	const result = minifier.minify(css);
	if (result.errors && result.errors.length) {
		process.stderr.write(`build:min — errors minifying ${filename}:\n  ${result.errors.join('\n  ')}\n`);
		process.exit(1);
	}

	fs.writeFileSync(minPath, result.styles);
	generated += 1;
	process.stdout.write(`  min  ${filename} → ${path.basename(minPath)} (${(result.styles.length / 1024).toFixed(1)} KB)\n`);
}

process.stdout.write(`build:min — ${generated} file${generated === 1 ? '' : 's'} minified.\n`);
