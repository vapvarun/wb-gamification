<?php
/**
 * REST API: Redemption Controller
 *
 * GET    /wb-gamification/v1/redemptions/items          List all reward items
 * POST   /wb-gamification/v1/redemptions/items          Create reward item (admin)
 * GET    /wb-gamification/v1/redemptions/items/{id}     Get single item
 * PUT    /wb-gamification/v1/redemptions/items/{id}     Update item (admin)
 * DELETE /wb-gamification/v1/redemptions/items/{id}     Delete item (admin)
 *
 * POST   /wb-gamification/v1/redemptions                Redeem an item (logged-in user)
 * GET    /wb-gamification/v1/redemptions/me             User's redemption history
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\RedemptionEngine;
use WBGam\Engine\PointsEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for the points redemption store.
 *
 * Handles reward item catalog CRUD and the redemption flow at
 * GET|POST /wb-gamification/v1/redemptions/items and POST /wb-gamification/v1/redemptions.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class RedemptionController extends WP_REST_Controller {

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
	protected $rest_base = 'redemptions';

	/**
	 * Allowed reward types for redemption items.
	 *
	 * @var string[]
	 */
	private const VALID_REWARD_TYPES = array( 'discount_pct', 'discount_fixed', 'custom' );

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Reward items catalog.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/items',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->item_args( required: true ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/items/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->item_args( required: false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
			)
		);

		// Redeem.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'redeem' ),
					'permission_callback' => array( $this, 'require_logged_in' ),
					'args'                => array(
						'item_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// My history.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_history' ),
					'permission_callback' => array( $this, 'require_logged_in' ),
				),
			)
		);
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	/**
	 * Retrieve all available reward items along with the current user's balance.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing reward items and balance.
	 */
	public function get_items($request): WP_REST_Response {
		$items   = RedemptionEngine::get_items();
		$balance = is_user_logged_in() ? PointsEngine::get_total( get_current_user_id() ) : null;

		return rest_ensure_response(
			array(
				'items'           => array_map( array( $this, 'prepare_item_for_response' ), $items ),
				'current_balance' => $balance,
			)
		);
	}

	/**
	 * Retrieve a single reward item by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item($request): WP_REST_Response|WP_Error {
		$item = RedemptionEngine::get_item( (int) $request['id'] );
		return $item
			? rest_ensure_response( $this->prepare_item_for_response( $item ) )
			: new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), array( 'status' => 404 ) );
	}

	/**
	 * Create a new reward item (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function create_item($request): WP_REST_Response|WP_Error {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_redemption_items',
			array(
				'title'         => sanitize_text_field( $request['title'] ),
				'description'   => sanitize_textarea_field( $request['description'] ?? '' ),
				'points_cost'   => absint( $request['points_cost'] ),
				'reward_type'   => $request['reward_type'],
				'reward_config' => wp_json_encode( $request['reward_config'] ?? array() ),
				'stock'         => isset( $request['stock'] ) ? absint( $request['stock'] ) : null,
				'is_active'     => 1,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'rest_insert_failed', __( 'Could not create reward item.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( $this->prepare_item_for_response( RedemptionEngine::get_item( $wpdb->insert_id ) ), 201 );
	}

	/**
	 * Update an existing reward item (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function update_item($request): WP_REST_Response|WP_Error {
		global $wpdb;

		$id   = (int) $request['id'];
		$item = RedemptionEngine::get_item( $id );
		if ( ! $item ) {
			return new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$data = array();
		if ( isset( $request['title'] ) ) {
			$data['title'] = sanitize_text_field( $request['title'] ); }
		if ( isset( $request['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $request['description'] ); }
		if ( isset( $request['points_cost'] ) ) {
			$data['points_cost'] = absint( $request['points_cost'] ); }
		if ( isset( $request['reward_type'] ) ) {
			$data['reward_type'] = $request['reward_type']; }
		if ( isset( $request['reward_config'] ) ) {
			$data['reward_config'] = wp_json_encode( $request['reward_config'] ); }
		if ( isset( $request['stock'] ) ) {
			$data['stock'] = absint( $request['stock'] ); }
		if ( isset( $request['is_active'] ) ) {
			$data['is_active'] = (int) $request['is_active']; }

		if ( $data ) {
			$wpdb->update( $wpdb->prefix . 'wb_gam_redemption_items', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no cache needed.
		}

		return rest_ensure_response( $this->prepare_item_for_response( RedemptionEngine::get_item( $id ) ) );
	}

	/**
	 * Delete a reward item (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function delete_item($request): WP_REST_Response|WP_Error {
		global $wpdb;

		$id = (int) $request['id'];
		if ( ! RedemptionEngine::get_item( $id ) ) {
			return new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( $wpdb->prefix . 'wb_gam_redemption_items', array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no cache needed.
		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Redeem a reward item using the current user's points.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function redeem($request ): WP_REST_Response|WP_Error {
		$result = RedemptionEngine::redeem( get_current_user_id(), (int) $request['item_id'] );

		if ( ! $result['success'] ) {
			return new WP_Error( 'redemption_failed', $result['error'], array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Retrieve the current user's redemption history.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing the user's redemption history.
	 */
	public function get_my_history($request ): WP_REST_Response {
		return rest_ensure_response( RedemptionEngine::get_user_redemptions( get_current_user_id() ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Shape a raw reward item DB row into the REST response format.
	 *
	 * @param array $item Raw row from the redemption items table.
	 * @return array Formatted item data for the REST response.
	 */
	public function prepare_item_for_response( $item, $request = null ): array {
		return array(
			'id'            => (int) $item['id'],
			'title'         => $item['title'],
			'description'   => $item['description'],
			'points_cost'   => (int) $item['points_cost'],
			'reward_type'   => $item['reward_type'],
			'reward_config' => json_decode( $item['reward_config'] ?? '{}', true ) ?: array(),
			'stock'         => null !== $item['stock'] ? (int) $item['stock'] : null,
			'is_active'     => (bool) $item['is_active'],
		);
	}

	/**
	 * Return REST API argument definitions for reward item create/update requests.
	 *
	 * @param bool $required Whether the fields should be required.
	 * @return array Argument definition array.
	 */
	private function item_args( bool $required ): array {
		return array(
			'title'         => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'points_cost'   => array(
				'required' => $required,
				'type'     => 'integer',
				'minimum'  => 1,
			),
			'reward_type'   => array(
				'required' => $required,
				'type'     => 'string',
				'enum'     => self::VALID_REWARD_TYPES,
			),
			'reward_config' => array(
				'type'    => 'object',
				'default' => array(),
			),
			'stock'         => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'is_active'     => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}

	/**
	 * Check if the current user is an administrator.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_check(): bool|WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Admin only.', 'wb-gamification' ), array( 'status' => 403 ) );
	}

	/**
	 * Check if the current user is logged in.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function require_logged_in(): bool|WP_Error {
		return is_user_logged_in()
			? true
			: new WP_Error( 'rest_not_logged_in', __( 'Login required.', 'wb-gamification' ), array( 'status' => 401 ) );
	}
}
