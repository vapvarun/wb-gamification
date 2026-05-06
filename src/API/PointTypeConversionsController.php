<?php
/**
 * REST API: Point Type Conversions Controller
 *
 * GET    /point-type-conversions          Public — list active conversion rules
 * POST   /point-type-conversions          Admin — create rule
 * PUT    /point-type-conversions/{id}     Admin — update rule
 * DELETE /point-type-conversions/{id}     Admin — delete rule
 * POST   /point-types/{from}/convert      Authenticated — convert balance
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WBGam\Services\PointTypeConversionService;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for currency-conversion catalogue + conversion endpoint.
 */
class PointTypeConversionsController extends WP_REST_Controller {

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
	protected $rest_base = 'point-type-conversions';

	private PointTypeConversionService $service;

	/**
	 * @param PointTypeConversionService|null $service Optional service (DI for tests).
	 */
	public function __construct( ?PointTypeConversionService $service = null ) {
		$this->service = $service ?? new PointTypeConversionService();
	}

	/**
	 * Register routes.
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
					'args'                => $this->rule_args( true ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'handle_update' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => $this->rule_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'handle_delete' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		// Member-facing: convert balance.
		register_rest_route(
			$this->namespace,
			'/point-types/(?P<from>[a-z0-9_-]+)/convert',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_convert' ),
					'permission_callback' => array( $this, 'authenticated_permission_check' ),
					'args'                => array(
						'to_type' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'amount'  => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Admin gate — manage_options.
	 */
	public function admin_permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Member gate — must be logged in to convert their own balance.
	 */
	public function authenticated_permission_check(): bool {
		return is_user_logged_in();
	}

	/**
	 * Argument schema for create / update.
	 *
	 * @param bool $is_create True for POST, false for PUT.
	 */
	private function rule_args( bool $is_create ): array {
		return array(
			'from_type'        => array(
				'required'          => $is_create,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'to_type'          => array(
				'required'          => $is_create,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'from_amount'      => array(
				'required' => $is_create,
				'type'     => 'integer',
				'minimum'  => 1,
			),
			'to_amount'        => array(
				'required' => $is_create,
				'type'     => 'integer',
				'minimum'  => 1,
			),
			'min_convert'      => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'cooldown_seconds' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'max_per_day'      => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'is_active'        => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}

	// ── Callbacks ───────────────────────────────────────────────────────────────

	public function handle_list( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->service->list_active(), 200 );
	}

	public function handle_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->create_rule(
			array(
				'from_type'        => (string) $request['from_type'],
				'to_type'          => (string) $request['to_type'],
				'from_amount'      => (int) $request['from_amount'],
				'to_amount'        => (int) $request['to_amount'],
				'min_convert'      => (int) ( $request['min_convert'] ?? 1 ),
				'cooldown_seconds' => (int) ( $request['cooldown_seconds'] ?? 0 ),
				'max_per_day'      => (int) ( $request['max_per_day'] ?? 0 ),
			)
		);

		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'] );
		}

		return new WP_REST_Response(
			array(
				'id' => $result['id'],
			),
			201
		);
	}

	public function handle_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request['id'];
		$input = array();

		foreach ( array( 'from_amount', 'to_amount', 'min_convert', 'cooldown_seconds', 'max_per_day' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = (int) $request[ $key ];
			}
		}
		if ( $request->has_param( 'is_active' ) ) {
			$input['is_active'] = (bool) $request['is_active'];
		}

		$result = $this->service->update_rule( $id, $input );
		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'] );
		}
		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	public function handle_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->delete_rule( (int) $request['id'] );
		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'] );
		}
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /point-types/{from}/convert — member converts their own balance.
	 */
	public function handle_convert( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->convert(
			get_current_user_id(),
			(string) $request['from'],
			(string) $request['to_type'],
			(int) $request['amount']
		);

		if ( ! $result['ok'] ) {
			return $this->error_response( $result['error'], $result );
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Map service error code → WP_Error with HTTP status + message.
	 *
	 * @param string $code  Service error code.
	 * @param array  $extra Optional extra context returned to the caller.
	 */
	private function error_response( string $code, array $extra = array() ): WP_Error {
		$map = array(
			'same_type'        => array( 400, __( 'Source and destination currencies must differ.', 'wb-gamification' ) ),
			'invalid_type'     => array( 400, __( 'One or both point types are unknown.', 'wb-gamification' ) ),
			'pair_exists'      => array( 409, __( 'A conversion rule for this pair already exists.', 'wb-gamification' ) ),
			'invalid_rate'     => array( 400, __( 'Rate amounts must be positive integers.', 'wb-gamification' ) ),
			'invalid_user'     => array( 401, __( 'You must be signed in to convert balance.', 'wb-gamification' ) ),
			'no_rule'          => array( 404, __( 'No conversion rule is configured for this pair.', 'wb-gamification' ) ),
			'below_min'        => array( 400, __( 'Amount is below the minimum allowed for this conversion.', 'wb-gamification' ) ),
			'below_unit'       => array( 400, __( 'Amount is below one full conversion unit.', 'wb-gamification' ) ),
			'cooldown'         => array( 429, __( 'You converted recently. Please wait before converting again.', 'wb-gamification' ) ),
			'daily_cap'        => array( 429, __( 'Daily conversion cap reached for this pair.', 'wb-gamification' ) ),
			'insufficient'     => array( 400, __( 'Insufficient balance to perform this conversion.', 'wb-gamification' ) ),
			'debit_failed'     => array( 500, __( 'Could not debit the source balance.', 'wb-gamification' ) ),
			'credit_failed'    => array( 500, __( 'Could not credit the destination balance.', 'wb-gamification' ) ),
			'not_found'        => array( 404, __( 'Conversion rule not found.', 'wb-gamification' ) ),
			'insert_failed'    => array( 500, __( 'Failed to save the conversion rule.', 'wb-gamification' ) ),
			'update_failed'    => array( 500, __( 'Failed to update the conversion rule.', 'wb-gamification' ) ),
			'delete_failed'    => array( 500, __( 'Failed to delete the conversion rule.', 'wb-gamification' ) ),
		);

		$status  = isset( $map[ $code ] ) ? $map[ $code ][0] : 500;
		$message = isset( $map[ $code ] ) ? $map[ $code ][1] : __( 'Unexpected error.', 'wb-gamification' );

		$data = array_merge( array( 'status' => $status ), $extra );

		return new WP_Error( 'wb_gam_convert_' . $code, $message, $data );
	}
}
