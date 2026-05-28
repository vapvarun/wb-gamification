<?php
/**
 * bin/build-hooks-fired.php
 *
 * Regenerates `audit/manifest.json`'s `.hooks_fired[]` array from
 * ground truth — every `do_action()` and `apply_filters()` call site
 * under `src/` and `integrations/`, with `consumed_by[]` derived from
 * the matching `add_action()` / `add_filter()` registrations.
 *
 * Why a generator: the hand-curated section drifted badly. At 2026-05-28
 * the manifest declared 43 actions (ground truth: 54) and 12 filters
 * (ground truth: 47, and 11 of the 12 listed referenced
 * `wb_gamification_*` hooks that don't exist anywhere in the code —
 * outright fabricated). Hand-curating 100+ entries on every release was
 * never realistic; the generator owns this now.
 *
 * Preserves the human-curated `purpose` strings for hooks that still
 * exist (so prior context survives), and stamps an empty `purpose`
 * for newly-discovered hooks. Reviewers can fill `purpose` later;
 * the generator never overwrites a non-empty string.
 *
 * Idempotent: running twice in a row produces zero diff.
 *
 * Usage:
 *     php bin/build-hooks-fired.php
 *     php bin/build-hooks-fired.php --dry-run     Print what would change
 *
 * Exit codes:
 *   0  ok (or no-op)
 *   1  missing manifest / malformed JSON
 *   2  write failed
 */

declare( strict_types = 1 );

$root        = dirname( __DIR__ );
$dry_run     = in_array( '--dry-run', $argv, true );
$manifest_fp = $root . '/audit/manifest.json';
$summary_fp  = $root . '/audit/manifest.summary.json';
$scan_dirs   = array( $root . '/src', $root . '/integrations' );

if ( ! is_readable( $manifest_fp ) ) {
	fwrite( STDERR, "ERROR: missing {$manifest_fp}\n" );
	exit( 1 );
}

$manifest = json_decode( file_get_contents( $manifest_fp ), true );
if ( ! is_array( $manifest ) ) {
	fwrite( STDERR, "ERROR: malformed manifest.json\n" );
	exit( 1 );
}

// Index existing entries by (type, name) so we can preserve purpose strings.
$existing_by_key = array();
foreach ( $manifest['hooks_fired'] ?? array() as $entry ) {
	$key = ( $entry['type'] ?? '' ) . ':' . ( $entry['name'] ?? '' );
	$existing_by_key[ $key ] = $entry;
}

// Scan: every do_action() and apply_filters() with a literal hook name.
// We capture name + file:line + (optional) the preceding comment block
// for derive_purpose() heuristic later.
$fired = array(); // [type][name] => array of fire-site dicts

foreach ( $scan_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
			continue;
		}
		scan_file_for_fires( (string) $file->getRealPath(), $root, $fired );
	}
}

// Scan for listeners: every add_action() / add_filter() with a literal hook name.
// This populates consumed_by[]. We capture file:line + the callback (class or
// closure indicator) so downstream tooling can detect dead listeners.
$consumed = array(); // [type][name] => list of consumer descriptors

foreach ( $scan_dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
			continue;
		}
		scan_file_for_listeners( (string) $file->getRealPath(), $root, $consumed );
	}
}

// Build the new hooks_fired array. Sort by (type, name) so diffs read
// alphabetically — easier for humans to skim.
$new_entries = array();
foreach ( array( 'action', 'filter' ) as $type ) {
	if ( empty( $fired[ $type ] ) ) {
		continue;
	}
	ksort( $fired[ $type ] );
	foreach ( $fired[ $type ] as $name => $sites ) {
		$key  = "{$type}:{$name}";
		$prev = $existing_by_key[ $key ] ?? null;

		$entry = array(
			'name' => $name,
			'type' => $type,
		);

		// Preserve human-curated purpose if present. New entries get '' so
		// reviewers know there's prose to add but the generator never lies
		// about what the hook is for.
		$purpose      = (string) ( $prev['purpose'] ?? '' );
		$entry['purpose'] = $purpose;

		// fired_at[] is the new derivable field — where each do_action /
		// apply_filters lives. Useful for cross-referencing the manifest
		// against the codebase without grep.
		$entry['fired_at'] = array_values(
			array_unique(
				array_map(
					static function ( array $site ): string {
						return $site['rel_path'] . ':' . $site['line'];
					},
					$sites
				)
			)
		);
		sort( $entry['fired_at'] );

		// consumed_by[] is the listeners. Empty = no listeners (which may
		// be intentional for public extension hooks). Tooling that wants
		// to flag dead-looking ones can compare against a curated allowlist.
		$entry['consumed_by'] = $consumed[ $type ][ $name ] ?? array();
		sort( $entry['consumed_by'] );

		$new_entries[] = $entry;
	}
}

$manifest['hooks_fired'] = $new_entries;

// Encode with 2-space indent to match the rest of the file.
$encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$encoded = preg_replace_callback(
	'/^( +)/m',
	static function ( array $m ): string {
		return str_repeat( ' ', (int) ( strlen( $m[1] ) / 2 ) );
	},
	$encoded
);
$encoded .= "\n";

$current      = (string) file_get_contents( $manifest_fp );
$action_count = count( array_filter( $new_entries, static fn ( $e ) => 'action' === $e['type'] ) );
$filter_count = count( array_filter( $new_entries, static fn ( $e ) => 'filter' === $e['type'] ) );

// Always sync summary.counts.hooks_fired_* — it's a derived index, the
// source of truth is the .hooks_fired[] array we just rebuilt. If the
// summary drifted (count mismatch) we fix it here even when the manifest
// itself didn't need a rewrite.
$summary_updated = false;
if ( is_readable( $summary_fp ) ) {
	$summary = json_decode( (string) file_get_contents( $summary_fp ), true );
	if ( is_array( $summary ) && isset( $summary['counts'] ) ) {
		$expected = array(
			'hooks_fired_actions' => $action_count,
			'hooks_fired_filters' => $filter_count,
			'hooks_fired_total'   => $action_count + $filter_count,
		);
		$changed = false;
		foreach ( $expected as $key => $val ) {
			if ( (int) ( $summary['counts'][ $key ] ?? -1 ) !== $val ) {
				$summary['counts'][ $key ] = $val;
				$changed                   = true;
			}
		}
		if ( $changed && ! $dry_run ) {
			$summary_encoded = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$summary_encoded = preg_replace_callback(
				'/^( +)/m',
				static function ( array $m ): string {
					return str_repeat( ' ', (int) ( strlen( $m[1] ) / 2 ) );
				},
				(string) $summary_encoded
			);
			$summary_encoded .= "\n";
			file_put_contents( $summary_fp, $summary_encoded );
			$summary_updated = true;
		}
	}
}

if ( $encoded === $current ) {
	$msg = "build-hooks-fired: no changes ({$action_count} actions + {$filter_count} filters in sync)";
	if ( $summary_updated ) {
		$msg .= ' [summary.counts re-synced]';
	}
	echo $msg . "\n";
	exit( 0 );
}

if ( $dry_run ) {
	$action_count = count( array_filter( $new_entries, static fn ( $e ) => 'action' === $e['type'] ) );
	$filter_count = count( array_filter( $new_entries, static fn ( $e ) => 'filter' === $e['type'] ) );
	$prev_count   = count( $manifest['hooks_fired'] ?? array() );
	echo "build-hooks-fired: would update manifest.json\n";
	echo "  before: " . count( $existing_by_key ) . " entries\n";
	echo "  after:  " . ( $action_count + $filter_count ) . " entries ({$action_count} actions + {$filter_count} filters)\n";
	exit( 0 );
}

if ( false === file_put_contents( $manifest_fp, $encoded ) ) {
	fwrite( STDERR, "ERROR: failed to write {$manifest_fp}\n" );
	exit( 2 );
}

$msg = "build-hooks-fired: rewrote .hooks_fired ({$action_count} actions + {$filter_count} filters)";
if ( $summary_updated ) {
	$msg .= ' [summary.counts re-synced]';
}
echo $msg . "\n";
exit( 0 );


/**
 * Scan one file for do_action / apply_filters with a literal hook name.
 *
 * @param string $abs_path File to scan.
 * @param string $root     Plugin root (for relative-path formatting).
 * @param array<string,array<string,array<int,array{rel_path:string,line:int}>>> $out Mutated.
 */
function scan_file_for_fires( string $abs_path, string $root, array &$out ): void {
	$source = (string) file_get_contents( $abs_path );
	if ( '' === $source ) {
		return;
	}
	$rel_path = ltrim( str_replace( $root, '', $abs_path ), '/' );

	// Capture: (do_action|apply_filters) ( whitespace ('|") hook_name ('|")
	// Hook name = wb_gam_[a-z0-9_]+ (we only care about plugin-prefixed hooks).
	$pattern = '/\b(do_action|apply_filters)\s*\(\s*[\'"]((?:wb_gam_|wb_gamification_)[a-z0-9_]+)[\'"]/m';
	if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		return;
	}

	foreach ( $matches[1] as $i => $func_match ) {
		$fn     = (string) $func_match[0];
		$name   = (string) $matches[2][ $i ][0];
		$offset = (int) $matches[2][ $i ][1];
		$line   = substr_count( $source, "\n", 0, $offset ) + 1;

		$type = ( 'do_action' === $fn ) ? 'action' : 'filter';

		if ( ! isset( $out[ $type ][ $name ] ) ) {
			$out[ $type ][ $name ] = array();
		}
		$out[ $type ][ $name ][] = array(
			'rel_path' => $rel_path,
			'line'     => $line,
		);
	}
}

/**
 * Scan one file for add_action / add_filter with a literal hook name.
 *
 * Populates consumed_by[] entries shaped as
 *   "<file>:<line> <callback>"
 * where callback is the parsed second-arg expression (best-effort —
 * static method, instance method, closure, or literal function name).
 *
 * @param string $abs_path
 * @param string $root
 * @param array<string,array<string,list<string>>> $out Mutated.
 */
function scan_file_for_listeners( string $abs_path, string $root, array &$out ): void {
	$source = (string) file_get_contents( $abs_path );
	if ( '' === $source ) {
		return;
	}
	$rel_path = ltrim( str_replace( $root, '', $abs_path ), '/' );

	// add_action / add_filter ( quote hookname quote , callback )
	// callback can be: array(self,'foo'), array('Class','foo'), 'global_fn',
	// [self::class,'foo'], a closure (we capture "closure"), etc.
	// We grab the chunk between the hook name and the next comma at depth 0.
	$pattern = '/\b(add_action|add_filter)\s*\(\s*[\'"]((?:wb_gam_|wb_gamification_)[a-z0-9_]+)[\'"]\s*,\s*([^,)]+(?:\([^)]*\))?)/m';
	if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		return;
	}

	foreach ( $matches[1] as $i => $func_match ) {
		$fn       = (string) $func_match[0];
		$name     = (string) $matches[2][ $i ][0];
		$callback = trim( (string) $matches[3][ $i ][0] );
		$offset   = (int) $matches[2][ $i ][1];
		$line     = substr_count( $source, "\n", 0, $offset ) + 1;

		$type = ( 'add_action' === $fn ) ? 'action' : 'filter';

		// Normalise callback to something compact + readable.
		$callback = preg_replace( '/\s+/', ' ', $callback );
		$callback = (string) $callback;
		if ( strlen( $callback ) > 80 ) {
			$callback = substr( $callback, 0, 77 ) . '...';
		}

		$descriptor = sprintf( '%s:%d %s', $rel_path, $line, $callback );

		if ( ! isset( $out[ $type ][ $name ] ) ) {
			$out[ $type ][ $name ] = array();
		}
		$out[ $type ][ $name ][] = $descriptor;
	}
}
