<?php
/**
 * REST API: Levels Controller
 *
 * GET /wb-gamification/v1/levels    List all level definitions
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
 * REST API controller for level definitions.
 *
 * Returns the configured level tiers (name, minimum points threshold, icon)
 * sorted by min_points ascending so consumers can render level-progress UIs.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class LevelsController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wb-gamification/v1';

	/**
	 * REST API route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'levels';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Retrieve all level definitions, sorted by min_points ascending.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing level definitions.
	 */
	public function get_items( $request ): WP_REST_Response {
		global $wpdb;

		$levels = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Public read-only; result set is small.
			"SELECT * FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		) ?: array();

		$data = array_map(
			static function ( array $level ): array {
				return array(
					'id'         => (int) $level['id'],
					'name'       => $level['name'],
					'min_points' => (int) $level['min_points'],
					'icon_url'   => $level['icon_url'] ?? '',
				);
			},
			$levels
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Retrieve the JSON schema for a level item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-level',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'type'        => 'integer',
					'description' => 'Level ID.',
				),
				'name'       => array(
					'type'        => 'string',
					'description' => 'Level name.',
				),
				'min_points' => array(
					'type'        => 'integer',
					'description' => 'Minimum points required to reach this level.',
				),
				'icon_url'   => array(
					'type'        => 'string',
					'description' => 'URL to the level icon image.',
				),
			),
		);
	}
}
