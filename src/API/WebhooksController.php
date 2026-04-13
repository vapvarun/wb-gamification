<?php
/**
 * REST API: Webhooks Controller
 *
 * CRUD management for outbound webhook registrations.
 *
 * GET    /wb-gamification/v1/webhooks              List registered webhooks
 * POST   /wb-gamification/v1/webhooks              Register a new webhook
 * GET    /wb-gamification/v1/webhooks/{id}         Get single webhook
 * PUT    /wb-gamification/v1/webhooks/{id}         Update a webhook
 * DELETE /wb-gamification/v1/webhooks/{id}         Remove a webhook
 * GET    /wb-gamification/v1/webhooks/{id}/log     Delivery log (last 50)
 * DELETE /wb-gamification/v1/webhooks/{id}/log     Clear delivery log
 *
 * All endpoints require manage_options.
 *
 * Webhook events available:
 *   points_awarded | badge_earned | level_changed
 *   streak_milestone | challenge_completed | kudos_given
 *
 * Each registered webhook stores a HMAC secret. Deliveries are signed
 * via WebhookDispatcher (X-WB-Gam-Signature: sha256=<hex>).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\WebhookDispatcher;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for outbound webhook management.
 *
 * Handles CRUD for webhook registrations at GET|POST /wb-gamification/v1/webhooks
 * and GET|PUT|DELETE /wb-gamification/v1/webhooks/{id}.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class WebhooksController extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks';

	/**
	 * Allowed webhook event types.
	 *
	 * @var string[]
	 */
	private const VALID_EVENTS = array(
		'points_awarded',
		'badge_earned',
		'level_changed',
		'streak_milestone',
		'challenge_completed',
		'kudos_given',
	);

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Collection.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->webhook_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// Single item.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
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
					'args'                => $this->webhook_args( required: false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// Delivery log for a single webhook.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_delivery_log' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_delivery_log' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);
	}

	// ── Callbacks ───────────────────────────────────────────────────────────────

	/**
	 * Retrieve all registered webhooks.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing registered webhooks.
	 */
	public function get_items($request): WP_REST_Response {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only webhook list; small, infrequently changed.
		$rows = $wpdb->get_results(
			"SELECT id, url, events, is_active, created_at FROM {$wpdb->prefix}wb_gam_webhooks ORDER BY id ASC",
			ARRAY_A
		) ?: array();

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ) );
	}

	/**
	 * Retrieve a single webhook by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item($request): WP_REST_Response|WP_Error {
		$row = $this->fetch_row( (int) $request['id'] );
		return $row
			? rest_ensure_response( $this->prepare_item( $row ) )
			: new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), array( 'status' => 404 ) );
	}

	/**
	 * Register a new outbound webhook.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function create_item($request): WP_REST_Response|WP_Error {
		global $wpdb;

		$events_json = wp_json_encode( $request['events'] );
		$secret      = wp_generate_password( 32, false );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_webhooks',
			array(
				'url'       => esc_url_raw( $request['url'] ),
				'secret'    => $secret,
				'events'    => $events_json,
				'is_active' => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'rest_insert_failed', __( 'Could not create webhook.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		$row = $this->fetch_row( $wpdb->insert_id );
		// Return secret only on creation — not exposed in subsequent GET requests.
		$data           = $this->prepare_item( $row );
		$data['secret'] = $secret;

		return new WP_REST_Response( $data, 201 );
	}

	/**
	 * Update an existing webhook registration.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function update_item($request): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$data = array();
		if ( isset( $request['url'] ) ) {
			$data['url'] = esc_url_raw( $request['url'] );
		}
		if ( isset( $request['events'] ) ) {
			$data['events'] = wp_json_encode( $request['events'] );
		}
		if ( isset( $request['is_active'] ) ) {
			$data['is_active'] = (int) $request['is_active'];
		}

		if ( empty( $data ) ) {
			return rest_ensure_response( $this->prepare_item( $row ) );
		}

		$wpdb->update( $wpdb->prefix . 'wb_gam_webhooks', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no cache needed.

		return rest_ensure_response( $this->prepare_item( $this->fetch_row( $id ) ) );
	}

	/**
	 * Delete a registered webhook.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function delete_item($request): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( $wpdb->prefix . 'wb_gam_webhooks', array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no cache needed.

		// Clean up delivery log when a webhook is deleted.
		WebhookDispatcher::clear_delivery_log( $id );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Retrieve the delivery log for a webhook.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_delivery_log( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$log = WebhookDispatcher::get_delivery_log( $id );

		return rest_ensure_response(
			array(
				'webhook_id' => $id,
				'entries'    => $log,
				'count'      => count( $log ),
			)
		);
	}

	/**
	 * Clear the delivery log for a webhook.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function clear_delivery_log( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		WebhookDispatcher::clear_delivery_log( $id );

		return rest_ensure_response(
			array(
				'cleared'    => true,
				'webhook_id' => $id,
			)
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Fetch a single webhook row from the database by ID.
	 *
	 * @param int $id Webhook row ID.
	 * @return array|null Row as associative array, or null if not found.
	 */
	private function fetch_row( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only lookup; fetched immediately before a write, caching would be misleading.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, events, is_active, created_at FROM {$wpdb->prefix}wb_gam_webhooks WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Shape a raw webhook DB row into the REST response format.
	 *
	 * @param array $row Raw row from the webhooks table.
	 * @return array Formatted webhook data for the REST response.
	 */
	private function prepare_item( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'url'        => $row['url'],
			'events'     => json_decode( $row['events'] ?? '[]', true ) ?: array(),
			'is_active'  => (bool) $row['is_active'],
			'created_at' => $row['created_at'],
		);
	}

	/**
	 * Return REST API argument definitions for webhook create/update requests.
	 *
	 * @param bool $required Whether the fields should be required.
	 * @return array Argument definition array.
	 */
	private function webhook_args( bool $required = true ): array {
		return array(
			'url'       => array(
				'required'          => $required,
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
				'description'       => 'HTTPS URL to receive event payloads.',
				'validate_callback' => static function ( $url ) {
					return filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' );
				},
			),
			'events'    => array(
				'required'    => $required,
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => self::VALID_EVENTS,
				),
				'description' => 'Event types to subscribe to.',
			),
			'is_active' => array(
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
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'Admin only.', 'wb-gamification' ), array( 'status' => 403 ) );
	}

	/**
	 * Retrieve the JSON schema for a webhook item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-webhook',
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'url'        => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'events'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'is_active'  => array( 'type' => 'boolean' ),
				'created_at' => array( 'type' => 'string' ),
			),
		);
	}
}
