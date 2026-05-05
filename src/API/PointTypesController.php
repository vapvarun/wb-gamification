<?php
/**
 * REST API: Point Types Controller
 *
 * Catalogue of available point currencies. List is public (consumers need
 * to know what types exist to scope queries); writes require admin caps.
 *
 * GET    /wb-gamification/v1/point-types          Public — list all types
 * POST   /wb-gamification/v1/point-types          Admin — create
 * PUT    /wb-gamification/v1/point-types/{slug}   Admin — update
 * DELETE /wb-gamification/v1/point-types/{slug}   Admin — delete (default protected)
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WBGam\Services\PointTypeService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for the point-types catalogue.
 */
class PointTypesController extends WP_REST_Controller {

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
	protected $rest_base = 'point-types';

	private PointTypeService $service;

	/**
	 * @param PointTypeService|null $service Optional service (DI for tests).
	 */
	public function __construct( ?PointTypeService $service = null ) {
		$this->service = $service ?? new PointTypeService();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_list' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_create' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => $this->item_args( true ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_update' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => $this->item_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);
	}

	/**
	 * Permission check shared by every write endpoint.
	 */
	public function admin_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Argument schema for create / update.
	 *
	 * @param bool $is_create True for POST (slug + label required), false for PUT.
	 */
	private function item_args( bool $is_create ): array {
		return array(
			'slug'        => array(
				'required'          => $is_create,
				'type'              => 'string',
				'description'       => 'Unique slug — lowercase, alphanumeric + dash + underscore. Immutable after creation.',
				'sanitize_callback' => 'sanitize_key',
			),
			'label'       => array(
				'required'          => $is_create,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'icon'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'is_default'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'position'    => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
	}

	// ── Callbacks ───────────────────────────────────────────────────────────────

	/**
	 * GET /point-types — public list.
	 */
	public function handle_list( WP_REST_Request $request ): WP_REST_Response {
		$rows = $this->service->list();

		$items = array_map(
			static function ( array $row ): array {
				return array(
					'slug'        => (string) $row['slug'],
					'label'       => (string) $row['label'],
					'description' => $row['description'] ? (string) $row['description'] : null,
					'icon'        => $row['icon'] ? (string) $row['icon'] : null,
					'is_default'  => (int) $row['is_default'] === 1,
					'position'    => (int) $row['position'],
				);
			},
			$rows
		);

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * POST /point-types — admin create.
	 */
	public function handle_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->create(
			array(
				'slug'        => (string) $request['slug'],
				'label'       => (string) $request['label'],
				'description' => isset( $request['description'] ) ? (string) $request['description'] : null,
				'icon'        => isset( $request['icon'] ) ? (string) $request['icon'] : null,
				'is_default'  => (bool) $request['is_default'],
				'position'    => (int) $request['position'],
			)
		);

		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'] );
		}

		$row = $this->service->get( $result['slug'] );
		return new WP_REST_Response( $row, 201 );
	}

	/**
	 * PUT /point-types/{slug} — admin update.
	 */
	public function handle_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug   = (string) $request['slug'];
		$input  = array();

		foreach ( array( 'label', 'description', 'icon', 'position' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request[ $key ];
			}
		}
		if ( $request->has_param( 'is_default' ) && (bool) $request['is_default'] ) {
			$input['is_default'] = true;
		}

		$result = $this->service->update( $slug, $input );

		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'] );
		}

		return new WP_REST_Response( $this->service->get( $slug ), 200 );
	}

	/**
	 * DELETE /point-types/{slug} — admin delete (default protected).
	 */
	public function handle_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug   = (string) $request['slug'];
		$result = $this->service->delete( $slug );

		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'] );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'slug'    => $slug,
			),
			200
		);
	}

	/**
	 * Map a service-layer error code to a WP_Error response.
	 *
	 * @param string $code Service error code.
	 */
	private function error_response( string $code ): WP_Error {
		$map = array(
			'invalid_slug'          => array( 400, __( 'Invalid slug. Use lowercase letters, numbers, dashes, underscores only.', 'wb-gamification' ) ),
			'slug_taken'            => array( 409, __( 'A point type with that slug already exists.', 'wb-gamification' ) ),
			'invalid_label'         => array( 400, __( 'Label cannot be empty.', 'wb-gamification' ) ),
			'not_found'             => array( 404, __( 'Point type not found.', 'wb-gamification' ) ),
			'cannot_delete_default' => array( 409, __( 'Cannot delete the default point type. Promote a different type first.', 'wb-gamification' ) ),
			'insert_failed'         => array( 500, __( 'Failed to save the point type.', 'wb-gamification' ) ),
			'update_failed'         => array( 500, __( 'Failed to update the point type.', 'wb-gamification' ) ),
			'delete_failed'         => array( 500, __( 'Failed to delete the point type.', 'wb-gamification' ) ),
		);

		$status  = isset( $map[ $code ] ) ? $map[ $code ][0] : 500;
		$message = isset( $map[ $code ] ) ? $map[ $code ][1] : __( 'Unexpected error.', 'wb-gamification' );

		return new WP_Error( 'wb_gam_point_type_' . $code, $message, array( 'status' => $status ) );
	}
}
