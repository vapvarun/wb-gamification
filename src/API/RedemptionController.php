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

class RedemptionController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'redemptions';

	private const VALID_REWARD_TYPES = [ 'discount_pct', 'discount_fixed', 'custom' ];

	public function register_routes(): void {
		// Reward items catalog.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/items', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'admin_check' ],
				'args'                => $this->item_args( required: true ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/items/(?P<id>[\d]+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
				'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => [ $this, 'admin_check' ],
				'args'                => $this->item_args( required: false ),
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => [ $this, 'admin_check' ],
			],
		] );

		// Redeem.
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'redeem' ],
				'permission_callback' => [ $this, 'require_logged_in' ],
				'args'                => [
					'item_id' => [
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			],
		] );

		// My history.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/me', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_my_history' ],
				'permission_callback' => [ $this, 'require_logged_in' ],
			],
		] );
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$items   = RedemptionEngine::get_items();
		$balance = is_user_logged_in() ? PointsEngine::get_total( get_current_user_id() ) : null;

		return rest_ensure_response( [
			'items'           => array_map( [ $this, 'prepare_item_for_response' ], $items ),
			'current_balance' => $balance,
		] );
	}

	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$item = RedemptionEngine::get_item( (int) $request['id'] );
		return $item
			? rest_ensure_response( $this->prepare_item_for_response( $item ) )
			: new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), [ 'status' => 404 ] );
	}

	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_redemption_items',
			[
				'title'         => sanitize_text_field( $request['title'] ),
				'description'   => sanitize_textarea_field( $request['description'] ?? '' ),
				'points_cost'   => absint( $request['points_cost'] ),
				'reward_type'   => $request['reward_type'],
				'reward_config' => wp_json_encode( $request['reward_config'] ?? [] ),
				'stock'         => isset( $request['stock'] ) ? absint( $request['stock'] ) : null,
				'is_active'     => 1,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%d', '%d' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'rest_insert_failed', __( 'Could not create reward item.', 'wb-gamification' ), [ 'status' => 500 ] );
		}

		return new WP_REST_Response( $this->prepare_item_for_response( RedemptionEngine::get_item( $wpdb->insert_id ) ), 201 );
	}

	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id   = (int) $request['id'];
		$item = RedemptionEngine::get_item( $id );
		if ( ! $item ) {
			return new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), [ 'status' => 404 ] );
		}

		$data = [];
		if ( isset( $request['title'] ) )       { $data['title']         = sanitize_text_field( $request['title'] ); }
		if ( isset( $request['description'] ) )  { $data['description']   = sanitize_textarea_field( $request['description'] ); }
		if ( isset( $request['points_cost'] ) )  { $data['points_cost']   = absint( $request['points_cost'] ); }
		if ( isset( $request['reward_type'] ) )  { $data['reward_type']   = $request['reward_type']; }
		if ( isset( $request['reward_config'] )) { $data['reward_config'] = wp_json_encode( $request['reward_config'] ); }
		if ( isset( $request['stock'] ) )        { $data['stock']         = absint( $request['stock'] ); }
		if ( isset( $request['is_active'] ) )    { $data['is_active']     = (int) $request['is_active']; }

		if ( $data ) {
			$wpdb->update( $wpdb->prefix . 'wb_gam_redemption_items', $data, [ 'id' => $id ] );
		}

		return rest_ensure_response( $this->prepare_item_for_response( RedemptionEngine::get_item( $id ) ) );
	}

	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id = (int) $request['id'];
		if ( ! RedemptionEngine::get_item( $id ) ) {
			return new WP_Error( 'rest_not_found', __( 'Reward item not found.', 'wb-gamification' ), [ 'status' => 404 ] );
		}

		$wpdb->delete( $wpdb->prefix . 'wb_gam_redemption_items', [ 'id' => $id ], [ '%d' ] );
		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	public function redeem( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = RedemptionEngine::redeem( get_current_user_id(), (int) $request['item_id'] );

		if ( ! $result['success'] ) {
			return new WP_Error( 'redemption_failed', $result['error'], [ 'status' => 400 ] );
		}

		return new WP_REST_Response( $result, 201 );
	}

	public function get_my_history( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( RedemptionEngine::get_user_redemptions( get_current_user_id() ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private function prepare_item_for_response( array $item ): array {
		return [
			'id'           => (int) $item['id'],
			'title'        => $item['title'],
			'description'  => $item['description'],
			'points_cost'  => (int) $item['points_cost'],
			'reward_type'  => $item['reward_type'],
			'reward_config'=> json_decode( $item['reward_config'] ?? '{}', true ) ?: [],
			'stock'        => $item['stock'] !== null ? (int) $item['stock'] : null,
			'is_active'    => (bool) $item['is_active'],
		];
	}

	private function item_args( bool $required ): array {
		return [
			'title'         => [ 'required' => $required, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'description'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
			'points_cost'   => [ 'required' => $required, 'type' => 'integer', 'minimum' => 1 ],
			'reward_type'   => [ 'required' => $required, 'type' => 'string', 'enum' => self::VALID_REWARD_TYPES ],
			'reward_config' => [ 'type' => 'object', 'default' => [] ],
			'stock'         => [ 'type' => 'integer', 'minimum' => 0 ],
			'is_active'     => [ 'type' => 'boolean', 'default' => true ],
		];
	}

	public function admin_check(): bool|WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Admin only.', 'wb-gamification' ), [ 'status' => 403 ] );
	}

	public function require_logged_in(): bool|WP_Error {
		return is_user_logged_in()
			? true
			: new WP_Error( 'rest_not_logged_in', __( 'Login required.', 'wb-gamification' ), [ 'status' => 401 ] );
	}
}
