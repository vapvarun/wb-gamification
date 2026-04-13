<?php
/**
 * REST API: OpenAPI 3.0 Specification Controller
 *
 * GET /wb-gamification/v1/openapi.json
 *
 * Auto-generates an OpenAPI 3.0.3 spec from the plugin's registered
 * REST routes, their schemas, and argument definitions. Public endpoint
 * (no authentication required) so external tools, Swagger UI, and AI
 * agents can discover the API surface automatically.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller that serves the OpenAPI 3.0.3 specification
 * for all WB Gamification routes.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class OpenApiController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wb-gamification/v1';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/openapi.json',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_spec' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Build and return the full OpenAPI 3.0.3 specification.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response The OpenAPI spec as JSON.
	 */
	public function get_spec( WP_REST_Request $request ): WP_REST_Response {
		$spec = array(
			'openapi'    => '3.0.3',
			'info'       => array(
				'title'       => 'WB Gamification API',
				'description' => 'Gamification engine REST API for points, badges, levels, leaderboards, challenges, kudos, and more.',
				'version'     => defined( 'WB_GAM_VERSION' ) ? WB_GAM_VERSION : '1.0.0',
				'contact'     => array(
					'name' => 'Wbcom Designs',
					'url'  => 'https://wbcomdesigns.com',
				),
			),
			'servers'    => array(
				array( 'url' => rest_url( $this->namespace ) ),
			),
			'paths'      => $this->build_paths(),
			'components' => array(
				'securitySchemes' => array(
					'cookieAuth' => array(
						'type'        => 'apiKey',
						'in'          => 'header',
						'name'        => 'X-WP-Nonce',
						'description' => 'WordPress REST API nonce for cookie-based auth.',
					),
					'apiKeyAuth' => array(
						'type'        => 'apiKey',
						'in'          => 'header',
						'name'        => 'X-WB-Gam-Key',
						'description' => 'API key for cross-site authentication.',
					),
				),
			),
			'security'   => array(
				array( 'cookieAuth' => array() ),
				array( 'apiKeyAuth' => array() ),
			),
		);

		return new WP_REST_Response( $spec, 200 );
	}

	/**
	 * Build the OpenAPI paths object from registered WP REST routes.
	 *
	 * Iterates over all routes in the plugin namespace, converts WordPress
	 * regex path parameters to OpenAPI `{param}` syntax, and extracts
	 * schemas and argument definitions from each handler.
	 *
	 * @return array<string, array<string, array<string, mixed>>> OpenAPI paths object.
	 */
	private function build_paths(): array {
		$server = rest_get_server();
		$routes = $server->get_routes( $this->namespace );
		$paths  = array();

		foreach ( $routes as $route => $handlers ) {
			// Skip the openapi.json route itself.
			if ( str_contains( $route, 'openapi' ) ) {
				continue;
			}

			// Convert WP route params (?P<name>pattern) to OpenAPI {name}.
			$path = preg_replace(
				'/\(\?P<([^>]+)>[^)]+\)/',
				'{$1}',
				str_replace( '/' . $this->namespace, '', $route )
			);

			if ( empty( $path ) ) {
				$path = '/';
			}

			$path_item = array();

			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) {
					continue;
				}

				foreach ( $handler['methods'] as $method => $enabled ) {
					if ( ! $enabled ) {
						continue;
					}

					$method_lower = strtolower( $method );

					// Build the operation object.
					$operation = array(
						'summary'   => $this->route_to_summary( $path, $method_lower ),
						'responses' => array(
							'200' => array( 'description' => 'Successful response' ),
						),
					);

					// Attach response schema if the handler provides one.
					if ( ! empty( $handler['schema'] ) && is_callable( $handler['schema'] ) ) {
						$schema = call_user_func( $handler['schema'] );
						if ( ! empty( $schema ) ) {
							$operation['responses']['200']['content'] = array(
								'application/json' => array(
									'schema' => $this->convert_schema( $schema ),
								),
							);
						}
					}

					// Add request body for write methods.
					if ( in_array( $method_lower, array( 'post', 'put', 'patch' ), true ) && ! empty( $handler['args'] ) ) {
						$properties = array();
						$required   = array();

						foreach ( $handler['args'] as $arg_name => $arg_config ) {
							$properties[ $arg_name ] = array(
								'type'        => $arg_config['type'] ?? 'string',
								'description' => $arg_config['description'] ?? '',
							);
							if ( ! empty( $arg_config['required'] ) ) {
								$required[] = $arg_name;
							}
						}

						$body_schema = array(
							'type'       => 'object',
							'properties' => $properties,
						);

						if ( ! empty( $required ) ) {
							$body_schema['required'] = $required;
						}

						$operation['requestBody'] = array(
							'content' => array(
								'application/json' => array(
									'schema' => $body_schema,
								),
							),
						);
					}

					// Add path parameters.
					preg_match_all( '/\{(\w+)\}/', $path, $matches );
					if ( ! empty( $matches[1] ) ) {
						$operation['parameters'] = array();
						foreach ( $matches[1] as $param ) {
							$operation['parameters'][] = array(
								'name'     => $param,
								'in'       => 'path',
								'required' => true,
								'schema'   => array( 'type' => 'string' ),
							);
						}
					}

					$path_item[ $method_lower ] = $operation;
				}
			}

			if ( ! empty( $path_item ) ) {
				$paths[ $path ] = $path_item;
			}
		}

		return $paths;
	}

	/**
	 * Generate a human-readable summary for a route + method combination.
	 *
	 * @param string $path         The OpenAPI path (e.g. "/members/{id}").
	 * @param string $method_lower The HTTP method in lowercase (e.g. "get").
	 * @return string A short summary like "Get members".
	 */
	private function route_to_summary( string $path, string $method_lower ): string {
		$segments = explode( '/', ltrim( $path, '/' ) );
		$resource = trim( $segments[0] ?? '', '{}' );
		$verbs    = array(
			'get'    => 'Get',
			'post'   => 'Create',
			'put'    => 'Update',
			'patch'  => 'Update',
			'delete' => 'Delete',
		);
		$verb     = $verbs[ $method_lower ] ?? ucfirst( $method_lower );
		return $verb . ' ' . str_replace( '-', ' ', $resource );
	}

	/**
	 * Convert a WordPress REST API schema to a clean OpenAPI schema.
	 *
	 * Strips WordPress-specific keys (`$schema`, `links`) that are not
	 * valid in an OpenAPI 3.0 schema object.
	 *
	 * @param array<string, mixed> $schema WordPress REST API schema array.
	 * @return array<string, mixed> Cleaned schema suitable for OpenAPI.
	 */
	private function convert_schema( array $schema ): array {
		unset( $schema['$schema'], $schema['links'] );
		return $schema;
	}
}
