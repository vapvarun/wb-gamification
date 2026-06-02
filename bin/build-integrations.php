<?php
/**
 * bin/build-integrations.php
 *
 * Regenerates `audit/manifest.json`'s `.integrations[]` array from ground
 * truth so the integration surface stays legible as the plugin grows. Two
 * sources feed each integration:
 *
 *   1. Manifest files under `integrations/` (and `integrations/contrib/`) —
 *      each returns `array( 'plugin' => ..., 'version' => ..., 'triggers' =>
 *      [...] )` and guards on a `defined()` / `class_exists()` check. We
 *      static-parse the plugin name, version, the detect guard, and the
 *      trigger count (no execution — the files `exit` without ABSPATH).
 *   2. Adapter classes under `src/Integrations/` — the PHP that wires hooks
 *      (e.g. JetonomyIntegration, DisplayDefer, WooCommerce\AccountIntegration).
 *      Grouped to an integration by sub-directory; top-level adapters with no
 *      manifest (GraphQL, ActivityPub) become `tier: platform` entries.
 *
 * Why a generator: this plugin is integration-heavy and growing. A hand-kept
 * list rots the moment someone adds integrations/foo.php. The generator owns
 * the section; `bin/cut-release.sh --check` fails if it drifts.
 *
 * Preserves the human-curated `purpose` string per integration (keyed by
 * slug) so prior context survives a regen; stamps an empty `purpose` for
 * newly-discovered integrations.
 *
 * Idempotent: running twice in a row produces zero diff.
 *
 * Usage:
 *     php bin/build-integrations.php
 *     php bin/build-integrations.php --dry-run     Print what would change
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

if ( ! is_readable( $manifest_fp ) ) {
	fwrite( STDERR, "ERROR: missing {$manifest_fp}\n" );
	exit( 1 );
}

$manifest = json_decode( (string) file_get_contents( $manifest_fp ), true );
if ( ! is_array( $manifest ) ) {
	fwrite( STDERR, "ERROR: malformed manifest.json\n" );
	exit( 1 );
}

// Preserve curated purpose strings across regens (keyed by slug).
$existing_purpose = array();
foreach ( $manifest['integrations'] ?? array() as $entry ) {
	if ( ! empty( $entry['slug'] ) && ! empty( $entry['purpose'] ) ) {
		$existing_purpose[ $entry['slug'] ] = $entry['purpose'];
	}
}

/**
 * Slugify a manifest filename or adapter sub-directory to a stable key.
 * Manifest basenames are already lowercase/no-separator (woocommerce.php);
 * adapter dirs are PascalCase (WooCommerce) and lowercase to the same slug.
 *
 * @param string $name Raw name (without extension).
 * @return string
 */
function wbgam_int_slug( string $name ): string {
	return strtolower( $name );
}

/**
 * Halve JSON_PRETTY_PRINT's 4-space indent to 2-space so generator output
 * matches the rest of the manifest (same convention as build-hooks-fired.php).
 *
 * @param string $json Encoded JSON with 4-space indentation.
 * @return string
 */
function wbgam_int_two_space( string $json ): string {
	return (string) preg_replace_callback(
		'/^( +)/m',
		static function ( array $m ): string {
			return str_repeat( ' ', (int) ( strlen( $m[1] ) / 2 ) );
		},
		$json
	);
}

// ─── 1. Manifest files (integrations/*.php + integrations/contrib/*.php) ──────
$by_slug = array();

$manifest_files = array_merge(
	glob( $root . '/integrations/*.php' ) ?: array(),
	glob( $root . '/integrations/contrib/*.php' ) ?: array()
);
sort( $manifest_files );

foreach ( $manifest_files as $file ) {
	$src  = (string) file_get_contents( $file );
	$base = basename( $file, '.php' );
	$slug = wbgam_int_slug( $base );
	$tier = ( false !== strpos( $file, '/contrib/' ) ) ? 'contrib' : 'first-party';

	$name = $base;
	if ( preg_match( "/'plugin'\s*=>\s*'([^']+)'/", $src, $m ) ) {
		$name = $m[1];
	}

	$version = null;
	if ( preg_match( "/'version'\s*=>\s*'([^']+)'/", $src, $m ) ) {
		$version = $m[1];
	}

	// Trigger count: each trigger array carries exactly one top-level 'id' key.
	$trigger_count = preg_match_all( "/'id'\s*=>\s*'/", $src );

	// Detect guard: collect defined()/class_exists()/function_exists() tokens in
	// the region BEFORE the 'plugin' payload key (that region is header +
	// activation guard). ABSPATH is the universal exit guard, never an
	// integration signal, so it is excluded. Empty detect = loads
	// unconditionally (e.g. core WordPress); its triggers simply never fire
	// until the host hook does.
	$plugin_pos   = strpos( $src, "'plugin'" );
	$guard_region = ( false !== $plugin_pos ) ? substr( $src, 0, $plugin_pos ) : $src;
	$detect       = '';
	if ( preg_match_all( "/(defined|class_exists|function_exists)\(\s*'([^']+)'\s*\)/", $guard_region, $mm, PREG_SET_ORDER ) ) {
		$parts = array();
		foreach ( $mm as $hit ) {
			if ( 'ABSPATH' === $hit[2] ) {
				continue;
			}
			$parts[] = $hit[1] . "('" . $hit[2] . "')";
		}
		$detect = implode( ' || ', array_unique( $parts ) );
	}

	$by_slug[ $slug ] = array(
		'slug'             => $slug,
		'name'             => $name,
		'tier'             => $tier,
		'manifest_file'    => 'integrations/' . ( 'contrib' === $tier ? 'contrib/' : '' ) . basename( $file ),
		'manifest_version' => $version,
		'detect'           => $detect,
		'trigger_count'    => (int) $trigger_count,
		'adapter_classes'  => array(),
		'purpose'          => $existing_purpose[ $slug ] ?? '',
	);
}

// ─── 2. Adapter classes (src/Integrations/**/*.php) ──────────────────────────
$adapter_files = array_merge(
	glob( $root . '/src/Integrations/*.php' ) ?: array(),
	glob( $root . '/src/Integrations/*/*.php' ) ?: array()
);
sort( $adapter_files );

foreach ( $adapter_files as $file ) {
	$src = (string) file_get_contents( $file );

	$ns = '';
	if ( preg_match( '/^namespace\s+([^;]+);/m', $src, $m ) ) {
		$ns = trim( $m[1] );
	}
	$class = '';
	if ( preg_match( '/^(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $src, $m ) ) {
		$class = $m[1];
	}
	if ( '' === $class ) {
		continue;
	}
	$fqcn = ( '' !== $ns ) ? $ns . '\\' . $class : $class;

	// Slug = the sub-directory under src/Integrations/, lowercased. Top-level
	// adapters (no sub-dir) are platform integrations keyed by class name.
	$rel = ltrim( str_replace( $root . '/src/Integrations', '', $file ), '/' );
	if ( false !== strpos( $rel, '/' ) ) {
		$slug = wbgam_int_slug( explode( '/', $rel )[0] );
	} else {
		$slug = wbgam_int_slug( $class );
	}

	if ( ! isset( $by_slug[ $slug ] ) ) {
		// Platform / manifest-less adapter (GraphQL, ActivityPub).
		$by_slug[ $slug ] = array(
			'slug'             => $slug,
			'name'             => $class,
			'tier'             => 'platform',
			'manifest_file'    => null,
			'manifest_version' => null,
			'detect'           => '',
			'trigger_count'    => 0,
			'adapter_classes'  => array(),
			'purpose'          => $existing_purpose[ $slug ] ?? '',
		);
	}
	$by_slug[ $slug ]['adapter_classes'][] = $fqcn;
}

// ─── 3. Assemble — sort by tier (first-party, contrib, platform) then slug ───
$tier_rank = array(
	'first-party' => 0,
	'contrib'     => 1,
	'platform'    => 2,
);
$integrations = array_values( $by_slug );
usort(
	$integrations,
	static function ( array $a, array $b ) use ( $tier_rank ): int {
		$ra = $tier_rank[ $a['tier'] ] ?? 9;
		$rb = $tier_rank[ $b['tier'] ] ?? 9;
		return ( $ra === $rb ) ? strcmp( $a['slug'], $b['slug'] ) : ( $ra <=> $rb );
	}
);
foreach ( $integrations as &$entry ) {
	$entry['adapter_classes'] = array_values( array_unique( $entry['adapter_classes'] ) );
	sort( $entry['adapter_classes'] );
}
unset( $entry );

$manifest['integrations'] = $integrations;

// Counts for the summary.
$count_total    = count( $integrations );
$count_triggers = array_sum( array_column( $integrations, 'trigger_count' ) );
$count_adapters = array_sum( array_map( static fn( $e ) => count( $e['adapter_classes'] ), $integrations ) );

$encoded = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false === $encoded ) {
	fwrite( STDERR, "ERROR: failed to encode manifest.json\n" );
	exit( 2 );
}
// Re-indent 4-space (JSON_PRETTY_PRINT) to 2-space to match the rest of the
// file — the same convention every other manifest generator uses.
$encoded  = wbgam_int_two_space( $encoded );
$encoded .= "\n";

$current = is_readable( $manifest_fp ) ? (string) file_get_contents( $manifest_fp ) : '';
$changed = ( $current !== $encoded );

if ( $dry_run ) {
	echo $changed
		? "build-integrations: would update manifest.json ({$count_total} integrations, {$count_triggers} triggers, {$count_adapters} adapters)\n"
		: "build-integrations: no changes ({$count_total} integrations in sync)\n";
	exit( 0 );
}

if ( $changed ) {
	if ( false === file_put_contents( $manifest_fp, $encoded ) ) {
		fwrite( STDERR, "ERROR: failed to write manifest.json\n" );
		exit( 2 );
	}
}

// ─── 4. Summary counts ───────────────────────────────────────────────────────
if ( is_readable( $summary_fp ) ) {
	$summary = json_decode( (string) file_get_contents( $summary_fp ), true );
	if ( is_array( $summary ) ) {
		$summary['counts']['integrations']                 = $count_total;
		$summary['counts']['integration_triggers_total']   = (int) $count_triggers;
		$summary['counts']['integration_adapter_classes']  = (int) $count_adapters;
		$summary_encoded = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false !== $summary_encoded ) {
			file_put_contents( $summary_fp, wbgam_int_two_space( $summary_encoded ) . "\n" );
		}
	}
}

echo $changed
	? "build-integrations: manifest.json updated ({$count_total} integrations, {$count_triggers} triggers, {$count_adapters} adapters)\n"
	: "build-integrations: no changes ({$count_total} integrations in sync)\n";
exit( 0 );
