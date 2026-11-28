<?php
/**
 * REST API: Actions Controller
 * Exposes all registered gamification actions - useful for headless/app setups.
 *
 * GET /wp-json/wb-gamification/v1/actions
 * GET /wp-json/wb-gamification/v1/actions/{id}
 *
 * @package WB_Gamification
 */

namespace WBGam\API;

use WBGam\Engine\Registry;
use WP_REST_Controller;
use WP_REST_Request;
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

		// PUT/POST /actions/{id}/overrides - admin-only edit of cooldown,
		// daily_cap, weekly_cap, the on/off switch (`enabled`) and whether members
		// may claim the action through Submit Achievement (`submittable`) without
		// touching the manifest, plus the amount (`points`) and `point_type`: Settings > Earning saves every
		// row change here. Clear a limit by sending 0; DELETE restores the whole row to its defaults.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>.+?)/overrides',
			array(
				array(
					'methods'             => array( 'POST', 'PUT' ),
					'callback'            => array( $this, 'update_overrides' ),
					'permission_callback' => array( $this, 'overrides_permissions_check' ),
					'args'                => array(
						'id'          => array(
							'required' => true,
							'type'     => 'string',
						),
						'cooldown'    => array(
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'daily_cap'   => array(
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'weekly_cap'  => array(
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'enabled'     => array(
							'type' => 'boolean',
						),
						'points'      => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => Registry::POINTS_MAX,
						),
						'point_type'  => array(
							'type'        => 'string',
							'description' => 'Point type slug this action pays in. Empty clears the choice (the manifest or the default type applies).',
						),
						'submittable' => array(
							'type'        => 'boolean',
							'description' => 'Whether members may claim this action through Submit Achievement. Off until the owner turns it on (or the manifest says so).',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_overrides' ),
					'permission_callback' => array( $this, 'overrides_permissions_check' ),
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
	 * Overrides write gate - site admin only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function overrides_permissions_check( $request ): bool|\WP_Error {
		// Editing which actions award points, and how many, IS rule management -- so it honours the
		// granular cap an owner can delegate, not a bare manage_options that only an administrator can
		// ever hold. Capabilities::user_can() is `manage_options OR the cap`, so an admin still passes.
		//
		// Without this, an owner could tick "Editor -> manage rules" in Staff permissions and the API
		// would still refuse them: a delegation UI that grants a capability the code ignores is worse
		// than no UI, because it tells the owner they have done something they have not.
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_rules' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit action settings.', 'wb-gamification' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Persist per-action overrides for cooldown / daily_cap / weekly_cap / submittable.
	 *
	 * `submittable` is stored as a real boolean so an owner's "no" is kept
	 * apart from "never set" (which falls back to the manifest).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_overrides( $request ) {
		// The registered id as-is: third-party ids may hold "/", "." or capitals, and the
		// engine stores each setting under the raw id. Registry lookup is the validation.
		$id = (string) $request->get_param( 'id' );
		if ( null === Registry::get_action( $id ) ) {
			return new \WP_Error( 'rest_action_invalid', __( 'Unknown action.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$type = $request->get_param( 'point_type' );
		if ( null !== $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' !== $type && ! ( new \WBGam\Services\PointTypeService() )->get( $type ) ) {
				return new \WP_Error( 'rest_point_type_invalid', __( 'Unknown point type.', 'wb-gamification' ), array( 'status' => 400 ) );
			}
			if ( '' === $type ) {
				delete_option( 'wb_gam_point_type_' . $id );
			} else {
				update_option( 'wb_gam_point_type_' . $id, $type );
			}
		}
		if ( null !== $request->get_param( 'points' ) ) {
			update_option( 'wb_gam_points_' . $id, min( Registry::POINTS_MAX, max( 0, (int) $request->get_param( 'points' ) ) ) );
		}

		$option = get_option( 'wb_gam_action_overrides', array() );
		$option = is_array( $option ) ? $option : array();
		$row    = isset( $option[ $id ] ) && is_array( $option[ $id ] ) ? $option[ $id ] : array();

		foreach ( array( 'cooldown', 'daily_cap', 'weekly_cap' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$row[ $field ] = (int) $request->get_param( $field );
			}
		}
		if ( null !== $request->get_param( 'submittable' ) ) {
			$row['submittable'] = (bool) $request->get_param( 'submittable' );
		}

		$option[ $id ] = $row;
		update_option( 'wb_gam_action_overrides', $option, false );

		if ( null !== $request->get_param( 'enabled' ) ) {
			update_option( 'wb_gam_enabled_' . $id, $request->get_param( 'enabled' ) ? 1 : 0 );
		}

		return rest_ensure_response( self::row( $id ) );
	}

	/**
	 * What an action's settings are now, as the Earning screen shows them.
	 *
	 * @param string $id Registered action id.
	 * @return array<string, mixed>
	 */
	private static function row( string $id ): array {
		$action    = (array) Registry::get_action( $id );
		$overrides = get_option( 'wb_gam_action_overrides', array() );
		return array(
			'action_id'   => $id,
			'enabled'     => (bool) get_option( 'wb_gam_enabled_' . $id, true ),
			'points'      => (int) get_option( 'wb_gam_points_' . $id, $action['default_points'] ?? 0 ),
			'point_type'  => (string) get_option( 'wb_gam_point_type_' . $id, '' ),
			'submittable' => Registry::is_submittable( $id ),
			'overrides'   => is_array( $overrides[ $id ] ?? null ) ? $overrides[ $id ] : array(),
			'effective'   => array_map(
				'intval',
				array_intersect_key(
					$action + array(
						'cooldown'   => 0,
						'daily_cap'  => 0,
						'weekly_cap' => 0,
					),
					array_flip( array( 'cooldown', 'daily_cap', 'weekly_cap' ) )
				)
			),
		);
	}

	/**
	 * Restore an action to its defaults: the plugin's amount, switched on, its limits, its
	 * point type and its "members can submit" choice (Settings > Earning, "Restore defaults").
	 *
	 * @since 1.7.0 also resets the amount, the switch and the point type (before: limits only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_overrides( $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( null === Registry::get_action( $id ) ) {
			return new \WP_Error( 'rest_action_invalid', __( 'Unknown action.', 'wb-gamification' ), array( 'status' => 404 ) );
		}
		$option = get_option( 'wb_gam_action_overrides', array() );
		$option = is_array( $option ) ? $option : array();
		unset( $option[ $id ] );
		update_option( 'wb_gam_action_overrides', $option, false );
		foreach ( array( 'wb_gam_points_', 'wb_gam_enabled_', 'wb_gam_point_type_' ) as $prefix ) {
			delete_option( $prefix . $id );
		}
		return rest_ensure_response( self::row( $id ) + array( 'reset' => true ) );
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
				'penalty'        => array(
					'type'        => 'boolean',
					'description' => 'Whether the action takes points away. Its points value is the amount taken (a positive number).',
					'readonly'    => true,
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
				'submittable'    => array(
					'type'        => 'boolean',
					'description' => 'Whether members may claim this action through Submit Achievement.',
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
			'category_label' => Registry::category_label( (string) ( $action['category'] ?? 'general' ) ),
			'icon'           => $action['icon'] ?? '',
			'default_points' => $action['default_points'],
			'repeatable'     => $action['repeatable'] ?? true,
			'penalty'        => (bool) ( $action['penalty'] ?? false ),
			'cooldown'       => $action['cooldown'] ?? 0,
			'daily_cap'      => $action['daily_cap'] ?? 0,
			'weekly_cap'     => $action['weekly_cap'] ?? 0,
			'points'         => (int) get_option( 'wb_gam_points_' . $action['id'], $action['default_points'] ),
			'enabled'        => (bool) get_option( 'wb_gam_enabled_' . $action['id'], true ),
			'submittable'    => Registry::is_submittable( (string) $action['id'] ),
		);
	}
}
