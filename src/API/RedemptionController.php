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
// Silencing convention-driven false positives so Plugin Check signal stays clean:
//   - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
//     this file performs custom-table work. .phpcs.xml already excludes these
//     for the local WPCS gate; this annotation extends the same intent to
//     Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

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
	 * Must stay in sync with the fulfilment switches in
	 * {@see \WBGam\Engine\RedemptionEngine::redeem()} and
	 * {@see \WBGam\Engine\RedemptionEngine::create_woo_coupon()}.
	 * Missing a type here makes the items POST/PATCH reject a reward
	 * the engine could actually fulfil (regression #9927682021).
	 *
	 * @var string[]
	 */
	private const VALID_REWARD_TYPES = array(
		'discount_pct',
		'discount_fixed',
		'free_shipping',
		'free_product',
		'wbcom_credits',
		'custom',
	);

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
	public function get_items( $request ): WP_REST_Response {
		$items = RedemptionEngine::get_items();

		// Per-type balance map for the current user — frontend uses this to
		// flag insufficient-balance state per reward currency. `current_balance`
		// stays as the primary-type total for back-compat with single-currency
		// consumers.
		$user_id  = get_current_user_id();
		$balance  = is_user_logged_in() ? PointsEngine::get_total( $user_id ) : null;
		$by_type  = is_user_logged_in() ? PointsEngine::get_totals_by_type( $user_id ) : array();

		return rest_ensure_response(
			array(
				'items'           => array_map( array( $this, 'prepare_item_for_response' ), $items ),
				'current_balance' => $balance,
				'balances_by_type' => $by_type,
			)
		);
	}

	/**
	 * Retrieve a single reward item by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
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
	public function create_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$point_type = ( new \WBGam\Services\PointTypeService() )->resolve( (string) ( $request['point_type'] ?? '' ) );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_redemption_items',
			array(
				'title'         => sanitize_text_field( $request['title'] ),
				'description'   => sanitize_textarea_field( $request['description'] ?? '' ),
				'points_cost'   => absint( $request['points_cost'] ),
				'point_type'    => $point_type,
				'reward_type'   => $request['reward_type'],
				'reward_config' => wp_json_encode( $request['reward_config'] ?? array() ),
				'stock'         => isset( $request['stock'] ) ? absint( $request['stock'] ) : null,
				'is_active'     => 1,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
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
	public function update_item( $request ): WP_REST_Response|WP_Error {
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
		if ( isset( $request['point_type'] ) ) {
			$data['point_type'] = ( new \WBGam\Services\PointTypeService() )->resolve( (string) $request['point_type'] ); }
		if ( isset( $request['reward_type'] ) ) {
			$data['reward_type'] = $request['reward_type']; }
		if ( isset( $request['reward_config'] ) ) {
			$data['reward_config'] = wp_json_encode( $request['reward_config'] ); }
		if ( isset( $request['stock'] ) ) {
			$data['stock'] = absint( $request['stock'] ); }
		if ( isset( $request['is_active'] ) ) {
			$data['is_active'] = (int) $request['is_active']; }

		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no cache needed.
			$updated = $wpdb->update( $wpdb->prefix . 'wb_gam_redemption_items', $data, array( 'id' => $id ) );
			if ( false === $updated ) {
				return new WP_Error( 'rest_update_failed', __( 'Could not update reward item.', 'wb-gamification' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( $this->prepare_item_for_response( RedemptionEngine::get_item( $id ) ) );
	}

	/**
	 * Delete a reward item (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id = (int) $request['id'];
		if ( ! RedemptionEngine::get_item( $id ) ) {
			return new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no cache needed.
		$deleted = $wpdb->delete( $wpdb->prefix . 'wb_gam_redemption_items', array( 'id' => $id ), array( '%d' ) );
		if ( false === $deleted ) {
			return new WP_Error( 'rest_delete_failed', __( 'Could not delete reward item.', 'wb-gamification' ), array( 'status' => 500 ) );
		}
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
	public function redeem( $request ): WP_REST_Response|WP_Error {
		$result = RedemptionEngine::redeem( get_current_user_id(), (int) $request['item_id'] );

		if ( ! $result['success'] ) {
			// Map every known engine reason to its dedicated error code.
			// RedemptionEngine guarantees a `reason` key on every failure
			// return; the default arm exists only for future-proofing
			// (new engine reasons that haven't reached the controller yet).
			$code = match ( (string) ( $result['reason'] ?? '' ) ) {
				'not_found'    => 'wb_gam_redemption_not_found',
				'inactive'     => 'wb_gam_redemption_inactive',
				'out_of_stock' => 'wb_gam_redemption_out_of_stock',
				'insufficient' => 'wb_gam_redemption_insufficient',
				default        => 'wb_gam_redemption_failed',
			};
			return new WP_Error( $code, $result['error'], array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Retrieve the current user's redemption history.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing the user's redemption history.
	 */
	public function get_my_history( $request ): WP_REST_Response {
		return rest_ensure_response( RedemptionEngine::get_user_redemptions( get_current_user_id() ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Shape a raw reward item DB row into the REST response format.
	 *
	 * @param array           $item    Raw row from the redemption items table.
	 * @param WP_REST_Request $request Full details about the request.
	 * @return array Formatted item data for the REST response.
	 */
	public function prepare_item_for_response( $item, $request = null ): array {
		return array(
			'id'            => (int) $item['id'],
			'title'         => $item['title'],
			'description'   => $item['description'],
			'points_cost'   => (int) $item['points_cost'],
			'point_type'    => (string) ( $item['point_type'] ?? 'points' ),
			'reward_type'   => $item['reward_type'],
			'reward_config' => json_decode( $item['reward_config'] ?? '{}', true ) ?: array(),
			'stock'         => null !== $item['stock'] ? (int) $item['stock'] : null,
			'is_active'     => (bool) $item['is_active'],
		);
	}

	/**
	 * Retrieve the JSON schema for a redemption item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-redemption',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => 'Redemption ID.',
				),
				'user_id'     => array(
					'type'        => 'integer',
					'description' => 'User who redeemed.',
				),
				'item_id'     => array(
					'type'        => 'integer',
					'description' => 'Reward item ID.',
				),
				'item_title'  => array(
					'type'        => 'string',
					'description' => 'Reward item name.',
				),
				'points_cost' => array(
					'type'        => 'integer',
					'description' => 'Points spent.',
				),
				'status'      => array(
					'type'        => 'string',
					'description' => 'Redemption status.',
				),
				'coupon_code' => array(
					'type'        => 'string',
					'description' => 'Generated coupon code.',
				),
				'redeemed_at' => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => 'When the redemption occurred.',
				),
			),
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
			'point_type'    => array(
				'type'              => 'string',
				'default'           => '',
				'description'       => 'Optional point-type slug. Empty = primary type. Unknown slug falls back to primary.',
				'sanitize_callback' => 'sanitize_key',
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
	 * Check if the current user can manage the redemption store catalog.
	 *
	 * Accepts manage_options or the granular wb_gam_manage_rewards cap.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_check(): bool|WP_Error {
		return \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_rewards' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'You do not have permission to manage rewards.', 'wb-gamification' ), array( 'status' => 403 ) );
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
