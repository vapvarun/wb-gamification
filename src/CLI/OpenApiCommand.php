<?php
/**
 * WP-CLI: Export the OpenAPI 3.0.3 specification to disk.
 *
 * Single source of truth for the API contract surface. The runtime
 * REST endpoint at /wb-gamification/v1/openapi.json serves the same
 * spec at request time; this command writes a static artefact that
 * SDK code-generators (openapi-typescript, openapi-generator), public
 * documentation sites, GraphQL type generators, and AI consumers can
 * fetch without going through HTTP.
 *
 * The static artefact ships at `audit/openapi.json` (alongside
 * manifest.json, the other canonical inventory artefact). cut-release.sh
 * regenerates it on every release prep so the committed artefact
 * matches the controllers it describes. Chose audit/ over dist/ because
 * dist/ is gitignored — and the OpenAPI spec belongs in source control,
 * not as a build output.
 *
 * @package WB_Gamification
 * @since   1.5.1
 */

namespace WBGam\CLI;

use WBGam\API\OpenApiController;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the OpenAPI 3.0.3 spec artefact.
 */
class OpenApiCommand {

	/**
	 * Export the OpenAPI 3.0.3 spec to a JSON file.
	 *
	 * Calls OpenApiController::build_spec() (the same builder the runtime
	 * REST endpoint uses) and writes the result to disk. The output is
	 * pretty-printed JSON for diff-friendliness.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<path>]
	 * : Destination path, relative to the plugin root. Default: audit/openapi.json
	 *
	 * [--check]
	 * : Drift-detection mode. Regenerate the spec and exit non-zero if the
	 *   output differs from what's on disk. Used by bin/cut-release.sh --check
	 *   to prove the committed artefact tracks the controllers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wb-gamification openapi export
	 *     wp wb-gamification openapi export --output=dist/openapi.json
	 *     wp wb-gamification openapi export --check
	 *
	 * @param array<int, string>     $args       Positional args (unused).
	 * @param array<string, string>  $assoc_args Named args.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		$plugin_root = defined( 'WB_GAM_PATH' ) ? rtrim( WB_GAM_PATH, '/' ) : dirname( __DIR__, 2 );
		$rel_output  = (string) ( $assoc_args['output'] ?? 'audit/openapi.json' );
		$abs_output  = $plugin_root . '/' . ltrim( $rel_output, '/' );
		$check_mode  = isset( $assoc_args['check'] );

		// Make sure the REST server has actually loaded our routes.
		// `rest_get_server()` lazy-bootstraps; once that's done OpenApiController
		// can enumerate paths.
		rest_get_server();
		do_action( 'rest_api_init' );

		$controller = new OpenApiController();
		$spec       = $controller->build_spec();

		// Deterministic pretty-print so the committed artefact diffs cleanly.
		$json = (string) wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( '' === $json ) {
			$this->error( 'Failed to encode spec to JSON.' );
			return;
		}
		$json .= "\n";

		if ( $check_mode ) {
			$existing = file_exists( $abs_output ) ? (string) file_get_contents( $abs_output ) : '';
			if ( $existing === $json ) {
				$this->log( sprintf( 'openapi export --check: %s is in sync with the controllers.', $rel_output ) );
				return;
			}
			$this->error(
				sprintf(
					"openapi export --check: %s differs from controller output.\nRun `wp wb-gamification openapi export` to refresh, then commit.",
					$rel_output
				)
			);
			return;
		}

		$dest_dir = dirname( $abs_output );
		if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
			$this->error( "Could not create directory: {$dest_dir}" );
			return;
		}

		if ( false === file_put_contents( $abs_output, $json ) ) {
			$this->error( "Failed to write {$abs_output}" );
			return;
		}

		$path_count = is_array( $spec['paths'] ?? null ) ? count( $spec['paths'] ) : 0;
		$bytes      = filesize( $abs_output );
		$this->log(
			sprintf(
				'openapi export: wrote %s (%d paths, %d KB).',
				$rel_output,
				$path_count,
				(int) round( $bytes / 1024 )
			)
		);
	}

	/**
	 * Print a success line. Uses WP_CLI when available, falls back to
	 * stdout for unit-test invocation.
	 */
	private function log( string $message ): void {
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::log( $message );
			return;
		}
		fwrite( STDOUT, $message . "\n" );
	}

	/**
	 * Emit an error and exit non-zero. Uses WP_CLI::error when available
	 * (which exits 1 for us), otherwise writes to stderr and exits.
	 */
	private function error( string $message ): never {
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::error( $message );
		}
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
