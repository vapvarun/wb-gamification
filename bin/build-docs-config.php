<?php
/**
 * bin/build-docs-config.php
 *
 * Keeps `docs/website/docs_config.json` in sync with what's actually on
 * disk. The script is *additive*: it preserves every editorial choice
 * already in docs_config.json (title, slug, order), but appends any
 * .md file that exists on disk but isn't yet catalogued, and errors out
 * if the config references a file that doesn't exist.
 *
 * Why this shape:
 *
 *   - Titles in docs_config.json are often more contextualised than the
 *     in-page H1 ("Points System" vs "# Points"). Humans hand-tune those
 *     for the docs-site navigation. A pure regenerator from H1s would
 *     trample that work.
 *
 *   - Ordering within a category is editorial too — first-time readers
 *     should hit installation.md before how-it-works.md regardless of
 *     alphabetical order.
 *
 *   - But orphan files (on disk, not in config) and dangling entries
 *     (in config, no file) ARE drift we want eliminated.
 *
 * So the contract is: humans curate the editorial fields; the generator
 * guarantees the file list stays honest.
 *
 * Inputs:
 *   docs/website/docs_config.json                  Current catalog
 *   docs/website/<category-folder>/*.md            On-disk truth
 *
 * Output:
 *   docs/website/docs_config.json                  Updated catalog
 *
 * Idempotent: running twice produces identical output.
 *
 * Usage:
 *     php bin/build-docs-config.php
 *     php bin/build-docs-config.php --dry-run
 *
 * Exit codes:
 *   0  ok (or no-op)
 *   1  missing config / malformed JSON
 *   2  drift: config references a .md that doesn't exist on disk
 *   3  write failed
 */

declare( strict_types = 1 );

$root      = dirname( __DIR__ );
$dry_run   = in_array( '--dry-run', $argv, true );
$config_fp = $root . '/docs/website/docs_config.json';
$base_dir  = $root . '/docs/website';

if ( ! is_readable( $config_fp ) ) {
	fwrite( STDERR, "ERROR: missing {$config_fp}\n" );
	exit( 1 );
}

$config = json_decode( file_get_contents( $config_fp ), true );
if ( ! is_array( $config ) || empty( $config['categories'] ) ) {
	fwrite( STDERR, "ERROR: malformed docs_config.json (no .categories)\n" );
	exit( 1 );
}

$short_id     = (string) ( $config['short_id'] ?? 'wbgam' );
$additions    = array();
$dangling     = array();
$total_files  = 0;

foreach ( $config['categories'] as &$category ) {
	$folder = (string) ( $category['folder'] ?? '' );
	if ( '' === $folder ) {
		continue;
	}
	$folder_path = $base_dir . '/' . $folder;
	if ( ! is_dir( $folder_path ) ) {
		fwrite( STDERR, "WARN: category folder missing on disk: {$folder}\n" );
		continue;
	}

	// Index existing entries by filename for quick lookup.
	$existing = array();
	foreach ( $category['docs'] ?? array() as $doc ) {
		$existing[ (string) $doc['file'] ] = $doc;
	}

	// Drift check: every existing entry must resolve to a real file.
	foreach ( $existing as $file => $_doc ) {
		if ( ! is_file( $folder_path . '/' . $file ) ) {
			$dangling[] = $folder . '/' . $file;
		}
	}

	// Walk disk for .md files in this category.
	$on_disk = array();
	foreach ( glob( $folder_path . '/*.md' ) ?: array() as $path ) {
		$on_disk[] = basename( $path );
	}
	sort( $on_disk );

	// Build the new docs list: existing entries in their original order,
	// then new files (those on disk but not yet catalogued) appended.
	$new_docs   = array();
	$max_order  = 0;
	foreach ( $category['docs'] ?? array() as $doc ) {
		if ( ! in_array( (string) $doc['file'], $on_disk, true ) ) {
			// Skip dangling — reported above. Don't emit into output.
			continue;
		}
		$new_docs[] = $doc;
		$max_order  = max( $max_order, (int) ( $doc['order'] ?? 0 ) );
	}

	foreach ( $on_disk as $filename ) {
		if ( isset( $existing[ $filename ] ) ) {
			continue;
		}
		$title         = derive_title_from_h1( $folder_path . '/' . $filename );
		$slug          = derive_slug( $filename, $short_id );
		++$max_order;
		$new_docs[]    = array(
			'file'  => $filename,
			'title' => $title,
			'slug'  => $slug,
			'order' => $max_order,
		);
		$additions[]   = $folder . '/' . $filename;
	}

	$category['docs'] = $new_docs;
	$total_files     += count( $new_docs );
}
unset( $category );

if ( ! empty( $dangling ) ) {
	fwrite( STDERR, "ERROR: docs_config.json references files that don't exist on disk:\n" );
	foreach ( $dangling as $d ) {
		fwrite( STDERR, "  · {$d}\n" );
	}
	fwrite( STDERR, "Remove the offending entries from docs_config.json or restore the files.\n" );
	exit( 2 );
}

// PHP's JSON_PRETTY_PRINT uses 4 spaces; the file convention is 2 spaces.
// Roll our own minimal converter so the diff stays semantic.
$encoded = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$encoded = preg_replace_callback(
	'/^( +)/m',
	static function ( array $m ): string {
		return str_repeat( ' ', (int) ( strlen( $m[1] ) / 2 ) );
	},
	$encoded
) . "\n";
$current = file_get_contents( $config_fp );

if ( $encoded === $current ) {
	echo "build-docs-config: no changes ({$total_files} files in sync)\n";
	exit( 0 );
}

if ( $dry_run ) {
	echo "build-docs-config: would update docs_config.json\n";
	foreach ( $additions as $a ) {
		echo "  + {$a}\n";
	}
	exit( 0 );
}

if ( false === file_put_contents( $config_fp, $encoded ) ) {
	fwrite( STDERR, "ERROR: failed to write {$config_fp}\n" );
	exit( 3 );
}

echo "build-docs-config: updated docs_config.json\n";
foreach ( $additions as $a ) {
	echo "  + {$a}\n";
}
exit( 0 );


/**
 * Pull the first `# H1` from a markdown file. Falls back to a humanised
 * filename when no H1 is present (shouldn't happen for our docs but
 * keeps the generator resilient).
 */
function derive_title_from_h1( string $path ): string {
	$handle = @fopen( $path, 'r' );
	if ( ! $handle ) {
		return humanise_filename( $path );
	}
	while ( ! feof( $handle ) ) {
		$line = (string) fgets( $handle );
		if ( preg_match( '/^# +(.+?)\s*$/', $line, $m ) ) {
			fclose( $handle );
			return trim( $m[1] );
		}
	}
	fclose( $handle );
	return humanise_filename( $path );
}

/**
 * "weekly-emails.md" → "Weekly Emails".
 */
function humanise_filename( string $path ): string {
	$base = pathinfo( $path, PATHINFO_FILENAME );
	$parts = array_map( 'ucfirst', explode( '-', $base ) );
	return implode( ' ', $parts );
}

/**
 * "daily-login-bonus.md" + "wbgam" → "daily-login-bonus-wbgam".
 */
function derive_slug( string $filename, string $short_id ): string {
	$base = pathinfo( $filename, PATHINFO_FILENAME );
	return $base . '-' . $short_id;
}
