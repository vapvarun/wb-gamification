<?php
/**
 * REST API: Actions Controller
 * Exposes all registered gamification actions — useful for headless/app setups.
 *
 * GET /wp-json/wb-gamification/v1/actions
 * GET /wp-json/wb-gamification/v1/actions/{id}
 *
 * @package WB_Gamification
 */

namespace WBGam\API;

use WBGam\Engine\Registry;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for gamification actions.
 *
 * Handles GET /wb-gamification/v1/actions and GET /wb-gamification/v1/actions/{id}.
 *
 * @package WB_Gamification
 */
class ActionsController extends WP_REST_Controller {

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
	protected $rest_base = 'actions';

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
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Retrieve all registered gamification actions.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing all registered actions.
	 */
	public function get_items( $request ): WP_REST_Response {
		$actions = array_map(
			fn( $action ) => $this->prepare_action_for_response( $action ),
			Registry::get_actions()
		);

		return rest_ensure_response( array_values( $actions ) );
	}

	/**
	 * Retrieve a single gamification action by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$action = Registry::get_action( $request['id'] );

		if ( ! $action ) {
			return new WP_Error( 'not_found', __( 'Action not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_action_for_response( $action ) );
	}

	/**
	 * Shape a raw action definition into the REST response format.
	 *
	 * @param array $action Raw action definition from the Registry.
	 * @return array Formatted action data for the REST response.
	 */
	/**
	 * Retrieve the JSON schema for an action item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-action',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type'        => 'string',
					'description' => 'Unique action identifier.',
				),
				'label'          => array(
					'type'        => 'string',
					'description' => 'Human-readable display label.',
				),
				'description'    => array(
					'type'        => 'string',
					'description' => 'Action description.',
				),
				'category'       => array(
					'type'        => 'string',
					'description' => 'Action category.',
				),
				'icon'           => array(
					'type'        => 'string',
					'description' => 'Icon identifier or URL.',
				),
				'default_points' => array(
					'type'        => 'integer',
					'description' => 'Default points awarded for this action.',
				),
				'repeatable'     => array(
					'type'        => 'boolean',
					'description' => 'Whether the action can be triggered multiple times.',
				),
				'cooldown'       => array(
					'type'        => 'integer',
					'description' => 'Cooldown period in seconds between triggers.',
				),
				'daily_cap'      => array(
					'type'        => 'integer',
					'description' => 'Maximum times this action can fire per day.',
				),
				'weekly_cap'     => array(
					'type'        => 'integer',
					'description' => 'Maximum times this action can fire per week.',
				),
				'points'         => array(
					'type'        => 'integer',
					'description' => 'Current configured points (may differ from default).',
				),
				'enabled'        => array(
					'type'        => 'boolean',
					'description' => 'Whether the action is currently active.',
				),
			),
		);
	}

	/**
	 * Shape a raw action definition into the REST response format.
	 *
	 * @param array $action Raw action definition from the Registry.
	 * @return array Formatted action data for the REST response.
	 */
	private function prepare_action_for_response( array $action ): array {
		return array(
			'id'             => $action['id'],
			'label'          => $action['label'],
			'description'    => $action['description'] ?? '',
			'category'       => $action['category'] ?? 'general',
			'icon'           => $action['icon'] ?? '',
			'default_points' => $action['default_points'],
			'repeatable'     => $action['repeatable'] ?? true,
			'cooldown'       => $action['cooldown'] ?? 0,
			'daily_cap'      => $action['daily_cap'] ?? 0,
			'weekly_cap'     => $action['weekly_cap'] ?? 0,
			'points'         => (int) get_option( 'wb_gam_points_' . $action['id'], $action['default_points'] ),
			'enabled'        => (bool) get_option( 'wb_gam_enabled_' . $action['id'], true ),
		);
	}
}
