<?php
/**
 * bin/build-readme.php
 *
 * Inlines feature counts from audit/manifest.json into readme.txt so the
 * customer-facing readme.txt cannot drift from the canonical inventory.
 *
 * Counts are substituted in-place via tightly-scoped regexes. The script
 * only touches the active sections (above == Changelog ==) — historical
 * changelog entries are preserved verbatim so old release notes don't
 * get rewritten.
 *
 * What gets inlined (`audit/manifest.json` → `readme.txt`):
 *
 *   manifest count                    →  readme.txt phrase
 *   ─────────────────────────────────────────────────────────────────
 *   counts.rest_endpoints            →  "N REST endpoints"
 *                                        "N endpoints across M controllers"
 *   counts.blocks                    →  "N Gutenberg Blocks"
 *   counts.shortcodes                →  "N Shortcodes"
 *   counts.tables                    →  "all N database tables"
 *                                        "removes all N tables"
 *   counts.hooks_fired_actions       →  "N action hooks and M filter hooks"
 *   counts.hooks_fired_filters       →  (same phrase, M side)
 *   derived: controller count        →  "N endpoints across M controllers"
 *
 * Anti-drift contract: this script is idempotent. Running it twice in a
 * row produces zero diff. bin/cut-release.sh --check exits non-zero if a
 * single run produces any diff at all.
 *
 * Usage:
 *     php bin/build-readme.php
 *     php bin/build-readme.php --dry-run   Print the substitution count
 *                                          without writing.
 *
 * Exit codes:
 *   0  ok (or no-op)
 *   1  missing input files / malformed manifest
 *   2  write failed
 */

declare( strict_types = 1 );

$root        = dirname( __DIR__ );
$dry_run     = in_array( '--dry-run', $argv, true );
$manifest_fp = $root . '/audit/manifest.json';
$summary_fp  = $root . '/audit/manifest.summary.json';
$readme_fp   = $root . '/readme.txt';

foreach ( array( $manifest_fp, $summary_fp, $readme_fp ) as $needed ) {
	if ( ! is_readable( $needed ) ) {
		fwrite( STDERR, "ERROR: missing input: {$needed}\n" );
		exit( 1 );
	}
}

// Counts live in manifest.summary.json (aggregated index). The full
// manifest.json holds the raw .rest.endpoints[] list we walk for derived
// counts (controller count).
$summary = json_decode( file_get_contents( $summary_fp ), true );
if ( ! is_array( $summary ) || empty( $summary['counts'] ) ) {
	fwrite( STDERR, "ERROR: malformed manifest.summary.json (no .counts)\n" );
	exit( 1 );
}
$c = $summary['counts'];

$manifest = json_decode( file_get_contents( $manifest_fp ), true );
if ( ! is_array( $manifest ) ) {
	fwrite( STDERR, "ERROR: malformed manifest.json\n" );
	exit( 1 );
}

// Derived: number of distinct REST controller classes (handlers ending in "Controller").
$controllers = array();
foreach ( $manifest['rest']['endpoints'] ?? array() as $endpoint ) {
	$handler = (string) ( $endpoint['handler'] ?? '' );
	$class   = explode( '::', $handler, 2 )[0] ?? '';
	if ( '' !== $class && str_ends_with( $class, 'Controller' ) ) {
		$controllers[ $class ] = true;
	}
}
$controller_count = count( $controllers );

$readme         = file_get_contents( $readme_fp );
$original_hash  = sha1( $readme );

// Split at "== Changelog ==" — we only rewrite the active prose above.
// Historical changelogs keep whatever numbers they shipped with.
$marker = "\n== Changelog ==\n";
$split  = explode( $marker, $readme, 2 );
if ( count( $split ) !== 2 ) {
	fwrite( STDERR, "ERROR: readme.txt missing '== Changelog ==' marker; refusing to rewrite\n" );
	exit( 1 );
}
$head   = $split[0];
$tail   = $marker . $split[1];

// ─── Substitution table ──────────────────────────────────────────────────
// Each entry: regex on $head, replacement that injects the current count.
// Regexes are anchored on the SURROUNDING WORDING (the noun), not just the
// digits, so the script can't accidentally rewrite an unrelated number.
$subs = array(

	// "56 REST endpoints" (anywhere in active prose)
	array(
		'pattern'     => '/\b(\d+)(\s+REST\s+endpoints)\b/',
		'replacement' => (string) $c['rest_endpoints'] . '${2}',
		'label'       => 'REST endpoints',
	),

	// "56 endpoints across 19 controllers" — keeps both numbers honest.
	array(
		'pattern'     => '/\b(\d+)\s+endpoints\s+across\s+(\d+)\s+controllers\b/',
		'replacement' => $c['rest_endpoints'] . ' endpoints across ' . $controller_count . ' controllers',
		'label'       => 'endpoints across controllers',
	),

	// "19 Gutenberg Blocks"
	array(
		'pattern'     => '/\*\*\d+\s+Gutenberg\s+Blocks\*\*/',
		'replacement' => '**' . $c['blocks'] . ' Gutenberg Blocks**',
		'label'       => 'Gutenberg Blocks (heading)',
	),

	// "17 Shortcodes"
	array(
		'pattern'     => '/\*\*\d+\s+Shortcodes\*\*/',
		'replacement' => '**' . $c['shortcodes'] . ' Shortcodes**',
		'label'       => 'Shortcodes (heading)',
	),

	// "all 23 database tables"
	array(
		'pattern'     => '/\ball\s+\d+\s+database\s+tables\b/',
		'replacement' => 'all ' . $c['tables'] . ' database tables',
		'label'       => 'database tables (doctor command)',
	),

	// "removes all 23 tables"
	array(
		'pattern'     => '/\bremoves\s+all\s+\d+\s+tables\b/',
		'replacement' => 'removes all ' . $c['tables'] . ' tables',
		'label'       => 'tables (uninstall)',
	),

	// "53 action hooks and 46 filter hooks"
	array(
		'pattern'     => '/\b\d+\s+action\s+hooks\s+and\s+\d+\s+filter\s+hooks\b/',
		'replacement' => $c['hooks_fired_actions'] . ' action hooks and ' . $c['hooks_fired_filters'] . ' filter hooks',
		'label'       => 'action+filter hooks',
	),
);

$diff_summary = array();
foreach ( $subs as $sub ) {
	$count = 0;
	$head  = preg_replace( $sub['pattern'], $sub['replacement'], $head, -1, $count );
	if ( null === $head ) {
		fwrite( STDERR, "ERROR: regex failed for '{$sub['label']}'\n" );
		exit( 1 );
	}
	if ( $count > 0 ) {
		$diff_summary[] = sprintf( '  · %s (matched %d)', $sub['label'], $count );
	}
}

$updated = $head . $tail;
$new_hash = sha1( $updated );

if ( $original_hash === $new_hash ) {
	echo "build-readme: no changes (readme.txt already matches manifest)\n";
	exit( 0 );
}

if ( $dry_run ) {
	echo "build-readme: would write readme.txt with substitutions in:\n";
	foreach ( $diff_summary as $line ) {
		echo $line . "\n";
	}
	exit( 0 );
}

if ( false === file_put_contents( $readme_fp, $updated ) ) {
	fwrite( STDERR, "ERROR: failed to write {$readme_fp}\n" );
	exit( 2 );
}

echo "build-readme: updated readme.txt — substitutions in:\n";
foreach ( $diff_summary as $line ) {
	echo $line . "\n";
}
exit( 0 );
