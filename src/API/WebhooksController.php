<?php
/**
 * REST API: Webhooks Controller
 *
 * CRUD management for outbound webhook registrations.
 *
 * GET    /wb-gamification/v1/webhooks        List registered webhooks
 * POST   /wb-gamification/v1/webhooks        Register a new webhook
 * GET    /wb-gamification/v1/webhooks/{id}   Get single webhook
 * PUT    /wb-gamification/v1/webhooks/{id}   Update a webhook
 * DELETE /wb-gamification/v1/webhooks/{id}   Remove a webhook
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

use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class WebhooksController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'webhooks';

	/** @var string[] */
	private const VALID_EVENTS = [
		'points_awarded',
		'badge_earned',
		'level_changed',
		'streak_milestone',
		'challenge_completed',
		'kudos_given',
	];

	public function register_routes(): void {
		// Collection.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'admin_check' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'admin_check' ],
					'args'                => $this->webhook_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);

		// Single item.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'admin_check' ],
					'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'admin_check' ],
					'args'                => $this->webhook_args( required: false ),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'admin_check' ],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	// ── Callbacks ───────────────────────────────────────────────────────────────

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, url, events, is_active, created_at FROM {$wpdb->prefix}wb_gam_webhooks ORDER BY id ASC",
			ARRAY_A
		) ?: [];

		return rest_ensure_response( array_map( [ $this, 'prepare_item' ], $rows ) );
	}

	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->fetch_row( (int) $request['id'] );
		return $row
			? rest_ensure_response( $this->prepare_item( $row ) )
			: new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), [ 'status' => 404 ] );
	}

	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$events_json = wp_json_encode( $request['events'] );
		$secret      = wp_generate_password( 32, false );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_webhooks',
			[
				'url'       => esc_url_raw( $request['url'] ),
				'secret'    => $secret,
				'events'    => $events_json,
				'is_active' => 1,
			],
			[ '%s', '%s', '%s', '%d' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'rest_insert_failed', __( 'Could not create webhook.', 'wb-gamification' ), [ 'status' => 500 ] );
		}

		$row = $this->fetch_row( $wpdb->insert_id );
		// Return secret only on creation — not exposed in subsequent GET requests.
		$data           = $this->prepare_item( $row );
		$data['secret'] = $secret;

		return new WP_REST_Response( $data, 201 );
	}

	public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), [ 'status' => 404 ] );
		}

		$data = [];
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

		$wpdb->update( $wpdb->prefix . 'wb_gam_webhooks', $data, [ 'id' => $id ] );

		return rest_ensure_response( $this->prepare_item( $this->fetch_row( $id ) ) );
	}

	public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->fetch_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Webhook not found.', 'wb-gamification' ), [ 'status' => 404 ] );
		}

		$wpdb->delete( $wpdb->prefix . 'wb_gam_webhooks', [ 'id' => $id ], [ '%d' ] );

		return new WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	private function fetch_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, events, is_active, created_at FROM {$wpdb->prefix}wb_gam_webhooks WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	private function prepare_item( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'url'        => $row['url'],
			'events'     => json_decode( $row['events'] ?? '[]', true ) ?: [],
			'is_active'  => (bool) $row['is_active'],
			'created_at' => $row['created_at'],
		];
	}

	private function webhook_args( bool $required = true ): array {
		return [
			'url' => [
				'required'          => $required,
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
				'description'       => 'HTTPS URL to receive event payloads.',
				'validate_callback' => static function ( $url ) {
					return filter_var( $url, FILTER_VALIDATE_URL ) && str_starts_with( $url, 'https://' );
				},
			],
			'events' => [
				'required'    => $required,
				'type'        => 'array',
				'items'       => [
					'type' => 'string',
					'enum' => self::VALID_EVENTS,
				],
				'description' => 'Event types to subscribe to.',
			],
			'is_active' => [
				'type'    => 'boolean',
				'default' => true,
			],
		];
	}

	public function admin_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'Admin only.', 'wb-gamification' ), [ 'status' => 403 ] );
	}

	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-webhook',
			'type'       => 'object',
			'properties' => [
				'id'         => [ 'type' => 'integer' ],
				'url'        => [ 'type' => 'string', 'format' => 'uri' ],
				'events'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'is_active'  => [ 'type' => 'boolean' ],
				'created_at' => [ 'type' => 'string' ],
			],
		];
	}
}
