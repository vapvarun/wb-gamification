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

		// PUT/POST /actions/{id}/overrides — admin-only edit of cooldown,
		// daily_cap, weekly_cap without touching the manifest. Reset by sending
		// 0 for the field or DELETE on this endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_]+)/overrides',
			array(
				array(
					'methods'             => array( 'POST', 'PUT' ),
					'callback'            => array( $this, 'update_overrides' ),
					'permission_callback' => array( $this, 'overrides_permissions_check' ),
					'args'                => array(
						'id'         => array( 'required' => true, 'type' => 'string' ),
						'cooldown'   => array( 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ),
						'daily_cap'  => array( 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ),
						'weekly_cap' => array( 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_overrides' ),
					'permission_callback' => array( $this, 'overrides_permissions_check' ),
					'args'                => array(
						'id' => array( 'required' => true, 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Overrides write gate — site admin only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function overrides_permissions_check( $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit action settings.', 'wb-gamification' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Persist per-action overrides for cooldown / daily_cap / weekly_cap.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_overrides( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		if ( null === Registry::get_action( $id ) ) {
			return new \WP_Error( 'rest_action_invalid', __( 'Unknown action.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$option = get_option( 'wb_gam_action_overrides', array() );
		$option = is_array( $option ) ? $option : array();
		$row    = isset( $option[ $id ] ) && is_array( $option[ $id ] ) ? $option[ $id ] : array();

		foreach ( array( 'cooldown', 'daily_cap', 'weekly_cap' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$row[ $field ] = (int) $request->get_param( $field );
			}
		}

		$option[ $id ] = $row;
		update_option( 'wb_gam_action_overrides', $option, false );

		return rest_ensure_response(
			array(
				'action_id' => $id,
				'overrides' => $row,
				'effective' => array_intersect_key( Registry::get_action( $id ), array_flip( array( 'cooldown', 'daily_cap', 'weekly_cap' ) ) ),
			)
		);
	}

	/**
	 * Reset per-action overrides — fall back to manifest defaults.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_overrides( $request ): \WP_REST_Response {
		$id     = sanitize_key( (string) $request->get_param( 'id' ) );
		$option = get_option( 'wb_gam_action_overrides', array() );
		$option = is_array( $option ) ? $option : array();
		unset( $option[ $id ] );
		update_option( 'wb_gam_action_overrides', $option, false );
		return rest_ensure_response( array( 'action_id' => $id, 'reset' => true ) );
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
