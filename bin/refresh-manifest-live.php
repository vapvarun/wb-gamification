<?php
/**
 * Refresh audit/manifest.json from the LIVE registries: plugin header, REST routes, shortcodes.
 *
 * The static scanner (bin/write-manifest.mjs) cannot see routes built by concatenation or
 * shortcodes registered through ShortcodeHandler, and it mis-reads an examples/ file as the plugin
 * header, so those three sections came out wrong (every route collapsed to "/wb-gamification/v1/",
 * zero shortcodes, a LearnDash sample as the plugin name). WordPress already knows the real answer
 * at runtime, so ask it. Every other manifest section is left untouched.
 *
 * Run: wp eval-file bin/refresh-manifest-live.php
 *
 * @package WB_Gamification
 */

$root     = dirname( __DIR__ );
$path     = $root . '/audit/manifest.json';
$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
$rel      = static fn( string $file ): string => ltrim( str_replace( $root, '', $file ), '/' );
// The files are kept at 2-space indentation; PHP pretty-prints with 4.
$two_space = static fn( string $json ): string => (string) preg_replace_callback( '/^( +)/m', static fn( $m ) => str_repeat( ' ', (int) ( strlen( $m[1] ) / 2 ) ), $json );

// Header: the real main file.
$h                  = get_plugin_data( $root . '/wb-gamification.php', false, false );
$manifest['header'] = array(
	'name'        => $h['Name'],
	'slug'        => 'wb-gamification',
	'version'     => $h['Version'],
	'textDomain'  => $h['TextDomain'],
	'domainPath'  => $h['DomainPath'],
	'requiresWP'  => $h['RequiresWP'],
	'requiresPHP' => $h['RequiresPHP'],
	'description' => $h['Description'],
	'author'      => wp_strip_all_tags( $h['Author'] ),
	'pluginURI'   => $h['PluginURI'],
	'network'     => (bool) $h['Network'],
);

// REST: every handler under the namespace, with its permission callback and where it lives.
$endpoints = array();
foreach ( rest_get_server()->get_routes( 'wb-gamification/v1' ) as $route => $handlers ) {
	if ( '/wb-gamification/v1' === $route ) {
		continue;
	}
	foreach ( $handlers as $handler ) {
		$cb   = $handler['permission_callback'] ?? null;
		$call = $handler['callback'] ?? null;
		$file = '';
		if ( is_array( $call ) ) {
			$ref  = new ReflectionMethod( $call[0], $call[1] );
			$file = $rel( (string) $ref->getFileName() ) . ':' . $ref->getStartLine();
		}
		$endpoints[] = array(
			'route'      => $route,
			'methods'    => array_keys( array_filter( (array) $handler['methods'] ) ),
			'permission' => is_array( $cb ) ? ( is_object( $cb[0] ) ? ( new ReflectionClass( $cb[0] ) )->getShortName() : $cb[0] ) . '::' . $cb[1] : ( is_string( $cb ) ? $cb : 'closure' ),
			'callback'   => $file,
		);
	}
}
$manifest['rest'] = array(
	'namespace' => 'wb-gamification/v1',
	'endpoints' => $endpoints,
);

// Shortcodes registered by this plugin.
global $shortcode_tags;
$shortcodes = array();
foreach ( $shortcode_tags as $tag => $cb ) {
	$class = is_array( $cb ) ? ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) : '';
	if ( str_starts_with( $class, 'WBGam\\' ) ) {
		$shortcodes[] = $tag;
	}
}
sort( $shortcodes );
$manifest['shortcodes'] = $shortcodes;

$manifest['generated'] = array(
	'generator' => 'bin/refresh-manifest-live.php (header, rest, shortcodes) + wp-plugin-qa scanner (other sections)',
	'at'        => gmdate( 'c' ),
);

file_put_contents( $path, $two_space( wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- local file.

// Keep the summary's counts in step.
$sum_path = $root . '/audit/manifest.summary.json';
$sum      = json_decode( (string) file_get_contents( $sum_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
$sum['plugin']['version']                   = $h['Version'];
$sum['counts']['rest_endpoints']            = count( array_unique( array_column( $endpoints, 'route' ) ) );
$sum['counts']['rest_endpoint_method_rows'] = count( $endpoints );
$sum['counts']['shortcodes']                = count( $shortcodes );
file_put_contents( $sum_path, $two_space( wp_json_encode( $sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- local file.

printf( "manifest: %s %s | %d REST handlers on %d routes | %d shortcodes\n", $h['Name'], $h['Version'], count( $endpoints ), $sum['counts']['rest_endpoints'], count( $shortcodes ) );
