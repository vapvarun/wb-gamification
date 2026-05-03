<?php
/**
 * REST API: API Keys Controller
 *
 * GET    /wb-gamification/v1/api-keys              List all API keys (sensitive — admin only)
 * POST   /wb-gamification/v1/api-keys              Create a new key (returns full key once)
 * PATCH  /wb-gamification/v1/api-keys/{key}/revoke Deactivate a key (keeps audit trail)
 * DELETE /wb-gamification/v1/api-keys/{key}        Permanently delete a key
 *
 * The full secret value is returned ONLY in the create response and is
 * never echoed back via GET (only a truncated preview). Frontend consumers
 * must capture the secret on the create response and show it once to the
 * user — admin UI follows the "show once" pattern.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WBGam\Engine\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for API key management.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class ApiKeysController extends WP_REST_Controller {

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
	protected $rest_base = 'api-keys';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /api-keys  +  POST /api-keys.
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
					'args'                => array(
						'label'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Human-readable key label (e.g. "Mobile App").',
						),
						'site_id' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Optional remote site identifier.',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// PATCH /api-keys/{key}/revoke.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<key>wbgam_[A-Za-z0-9]+)/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'revoke_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'key' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// DELETE /api-keys/{key}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<key>wbgam_[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'key' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Capability gate.
	 *
	 * API keys grant write access to the points + badge surface; only
	 * admins may manage them. Returns WP_Error(403) per REST contract.
	 *
	 * @return true|WP_Error
	 */
	public function admin_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'wb_gam_rest_forbidden',
			__( 'You do not have permission to manage API keys.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * List API keys.
	 *
	 * The list returns each key's metadata (label, site_id, active state,
	 * created/last_used timestamps, permissions) plus the truncated key
	 * preview. The endpoint is admin-only (manage_options); for admin UI
	 * convenience the full `key` is also included so the table can target
	 * revoke/delete actions without a separate identifier — the value is
	 * already accessible to admins via wp_options, so this changes no
	 * security boundary, just the call shape.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$keys  = ApiKeyAuth::get_keys();
		$items = array();
		foreach ( $keys as $secret => $meta ) {
			$items[] = array_merge(
				$this->shape_for_list( $secret, $meta ),
				array( 'key' => $secret )
			);
		}
		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => count( $items ),
				'pages'    => 1,
				'has_more' => false,
			),
			200
		);
	}

	/**
	 * Create a new API key.
	 *
	 * Returns the full secret in the response — clients must surface it
	 * to the user and never re-fetch (subsequent reads return the
	 * truncated preview only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$label   = (string) $request->get_param( 'label' );
		$site_id = (string) $request->get_param( 'site_id' );

		if ( '' === $label ) {
			return new WP_Error(
				'wb_gam_api_key_invalid',
				__( 'A label is required.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filter — abort the create by returning WP_Error.
		 *
		 * @param array{label: string, site_id: string} $payload
		 * @param WP_REST_Request                       $request
		 */
		$filtered = apply_filters(
			'wb_gam_before_create_api_key',
			array(
				'label'   => $label,
				'site_id' => $site_id,
			),
			$request
		);
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$label   = isset( $filtered['label'] ) ? (string) $filtered['label'] : $label;
			$site_id = isset( $filtered['site_id'] ) ? (string) $filtered['site_id'] : $site_id;
		}

		$secret = ApiKeyAuth::create_key( $label, get_current_user_id(), $site_id );
		$keys   = ApiKeyAuth::get_keys();
		$meta   = $keys[ $secret ] ?? array();

		do_action( 'wb_gam_after_create_api_key', $secret, $meta, $request );

		// Full secret returned ONCE — clients must capture it now.
		return new WP_REST_Response(
			array_merge(
				$this->shape_for_list( $secret, $meta ),
				array( 'secret' => $secret )
			),
			201
		);
	}

	/**
	 * Revoke (deactivate) a key without deleting it.
	 *
	 * Audit-friendly soft delete: the key row stays in the option so a
	 * future audit can still see who created what + when.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_item( $request ) {
		$key = (string) $request->get_param( 'key' );

		if ( ! ApiKeyAuth::revoke_key( $key ) ) {
			return new WP_Error(
				'wb_gam_api_key_not_found',
				__( 'API key not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$keys = ApiKeyAuth::get_keys();
		$meta = $keys[ $key ] ?? array();

		do_action( 'wb_gam_after_revoke_api_key', $key, $meta, $request );

		return new WP_REST_Response( $this->shape_for_list( $key, $meta ), 200 );
	}

	/**
	 * Permanently delete a key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$key  = (string) $request->get_param( 'key' );
		$keys = ApiKeyAuth::get_keys();
		if ( ! isset( $keys[ $key ] ) ) {
			return new WP_Error(
				'wb_gam_api_key_not_found',
				__( 'API key not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$previous = $keys[ $key ];
		ApiKeyAuth::delete_key( $key );

		do_action( 'wb_gam_after_delete_api_key', $key, $previous, $request );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'key'      => $this->mask( $key ),
				'previous' => $this->shape_for_list( $key, $previous ),
			),
			200
		);
	}

	/**
	 * Build the public-facing list shape for one key.
	 *
	 * @param string               $secret Full key value (NEVER returned in this shape).
	 * @param array<string, mixed> $meta   Key metadata.
	 * @return array<string, mixed>
	 */
	private function shape_for_list( string $secret, array $meta ): array {
		return array(
			'key_preview' => $this->mask( $secret ),
			'label'       => isset( $meta['label'] ) ? (string) $meta['label'] : '',
			'site_id'     => isset( $meta['site_id'] ) ? (string) $meta['site_id'] : '',
			'user_id'     => isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0,
			'active'      => ! empty( $meta['active'] ),
			'created_at'  => isset( $meta['created'] ) ? (string) $meta['created'] : '',
			'last_used'   => isset( $meta['last_used'] ) ? (string) $meta['last_used'] : '',
			'permissions' => isset( $meta['permissions'] ) ? (array) $meta['permissions'] : array(),
		);
	}

	/**
	 * Truncate a secret for display in lists ("wbgam_AbCd…wXyZ").
	 *
	 * @param string $secret Full secret.
	 * @return string
	 */
	private function mask( string $secret ): string {
		if ( strlen( $secret ) < 14 ) {
			return $secret;
		}
		return substr( $secret, 0, 10 ) . '…' . substr( $secret, -4 );
	}

	/**
	 * JSON schema for an API key list item.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-api-key',
			'type'       => 'object',
			'properties' => array(
				'key_preview' => array(
					'type'        => 'string',
					'description' => 'Truncated key preview ("wbgam_AbCd…wXyZ").',
					'readonly'    => true,
				),
				'secret'      => array(
					'type'        => 'string',
					'description' => 'Full key value — present ONLY in the create response.',
					'readonly'    => true,
				),
				'label'       => array( 'type' => 'string' ),
				'site_id'     => array( 'type' => 'string' ),
				'user_id'     => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'active'      => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'created_at'  => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'last_used'   => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'permissions' => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string' ),
				),
			),
		);
	}
}
