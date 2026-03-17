<?php
/**
 * REST API: Events Controller
 *
 * POST /wb-gamification/v1/events
 *
 * Ingest an event from any source — headless frontend, mobile app, WP-CLI,
 * third-party automation (Zapier, Make, n8n).
 *
 * The endpoint validates the action_id against the Registry, builds a
 * WBGam\Engine\Event, and calls Engine::process() — identical to the internal
 * flow. Authentication via WordPress Application Passwords or REST nonce.
 *
 * Permission levels:
 *   - Authenticated users can fire events for themselves (user_id omitted or
 *     matches their own ID).
 *   - Admins (manage_options) can fire events for any user_id.
 *
 * Rate-limiting: relies on host-level / Cloudflare limits for now.
 * Phase 4 will add a per-user token-bucket via options/transients.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\Engine;
use WBGam\Engine\Event;
use WBGam\Engine\Registry;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class EventsController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'events';

	public function register_routes(): void {
		// POST /events
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'action_id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Registered gamification action ID.',
						],
						'user_id' => [
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'User to credit. 0 = current user.',
						],
						'object_id' => [
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Related object (post ID, comment ID, etc.).',
						],
						'metadata' => [
							'type'              => 'object',
							'default'           => [],
							'sanitize_callback' => [ $this, 'sanitize_metadata' ],
							'description'       => 'Arbitrary key/value context data.',
						],
					],
				],
			]
		);
	}

	// ── Callback ────────────────────────────────────────────────────────────────

	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action_id = (string) $request['action_id'];
		$user_id   = (int) $request['user_id'];
		$object_id = (int) $request['object_id'];
		$metadata  = (array) $request['metadata'];

		// Resolve user_id.
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return new WP_Error(
				'rest_no_user',
				__( 'Could not determine target user.', 'wb-gamification' ),
				[ 'status' => 400 ]
			);
		}

		// Validate action is registered.
		$action_def = Registry::get_action( $action_id );
		if ( ! $action_def ) {
			return new WP_Error(
				'rest_unknown_action',
				sprintf(
					/* translators: %s = action_id */
					__( 'Unknown action: %s', 'wb-gamification' ),
					$action_id
				),
				[ 'status' => 400 ]
			);
		}

		// Non-admins can only fire events for themselves.
		if ( ! current_user_can( 'manage_options' ) && $user_id !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You may only fire events for yourself.', 'wb-gamification' ),
				[ 'status' => 403 ]
			);
		}

		$event = new Event(
			[
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'object_id' => $object_id ?: null,
				'metadata'  => $metadata,
			]
		);

		$result = Engine::process( $event );

		return new WP_REST_Response(
			[
				'processed' => true,
				'event_id'  => $event->id,
				'action_id' => $action_id,
				'user_id'   => $user_id,
				'points'    => $result['points'] ?? 0,
				'skipped'   => $result['skipped'] ?? false,
				'reason'    => $result['reason'] ?? null,
			],
			201
		);
	}

	// ── Permissions ─────────────────────────────────────────────────────────────

	public function create_item_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to fire gamification events.', 'wb-gamification' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Sanitize the metadata object — only allow scalar values, strip keys with
	 * potentially sensitive names, and truncate strings.
	 *
	 * @param mixed $meta
	 * @return array
	 */
	public function sanitize_metadata( $meta ): array {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$blocked_keys = [ 'password', 'secret', 'token', 'key', 'auth', 'nonce', 'cookie' ];
		$clean        = [];

		foreach ( $meta as $k => $v ) {
			$k = sanitize_key( (string) $k );

			// Drop blocked keys.
			foreach ( $blocked_keys as $blocked ) {
				if ( str_contains( $k, $blocked ) ) {
					continue 2;
				}
			}

			// Allow only scalar values, truncated to 500 chars.
			if ( is_scalar( $v ) ) {
				$clean[ $k ] = is_string( $v ) ? substr( sanitize_text_field( $v ), 0, 500 ) : $v;
			}
		}

		return $clean;
	}
}
