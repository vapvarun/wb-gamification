<?php
/**
 * REST API: API Keys Controller
 *
 * GET    /wb-gamification/v1/api-keys              List all API keys (sensitive — admin only)
 * POST   /wb-gamification/v1/api-keys              Create a new key (returns full secret ONCE)
 * PATCH  /wb-gamification/v1/api-keys/{id}/revoke  Deactivate a key (keeps audit trail)
 * DELETE /wb-gamification/v1/api-keys/{id}         Permanently delete a key
 *
 * STORAGE MODEL (v1.1+):
 *   Keys are stored as SHA-256 hashes in a dedicated wb_gam_api_keys table.
 *   The full secret is returned ONLY in the create response — the database
 *   has no way to reverse the hash, so subsequent reads return prefix +
 *   suffix only. revoke + delete operate on the row id, not the secret
 *   (admins don't have the secret after creation).
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

		// PATCH /api-keys/{id}/revoke.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'revoke_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// DELETE /api-keys/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
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
		$rows  = ApiKeyAuth::get_keys();
		$items = array_map( array( $this, 'shape_row' ), $rows );

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

		// Re-list to find the freshly-created row by prefix+suffix match.
		// We can't query by hash here without re-hashing; we trust that
		// create_key inserted the most-recent row with our prefix+suffix.
		$prefix  = substr( $secret, 0, 14 );
		$suffix  = substr( $secret, -4 );
		$row     = null;
		foreach ( ApiKeyAuth::get_keys() as $candidate ) {
			if ( $candidate['key_prefix'] === $prefix && $candidate['key_suffix'] === $suffix ) {
				$row = $candidate;
				break;
			}
		}
		$row = $row ?: array(
			'id'         => 0,
			'label'      => $label,
			'site_id'    => $site_id,
			'user_id'    => get_current_user_id(),
			'key_prefix' => $prefix,
			'key_suffix' => $suffix,
			'is_active'  => 1,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'last_used'  => null,
		);

		do_action( 'wb_gam_after_create_api_key', (int) $row['id'], $row, $request );

		// Full secret returned ONCE — clients must capture it now.
		return new WP_REST_Response(
			array_merge(
				$this->shape_row( $row ),
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
		$id = (int) $request->get_param( 'id' );

		if ( ! ApiKeyAuth::revoke_key( $id ) ) {
			return new WP_Error(
				'wb_gam_api_key_not_found',
				__( 'API key not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$row = $this->find_row_by_id( $id );

		do_action( 'wb_gam_after_revoke_api_key', $id, $row, $request );

		return new WP_REST_Response( $this->shape_row( $row ?: array() ), 200 );
	}

	/**
	 * Permanently delete a key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = $this->find_row_by_id( $id );
		if ( ! $row ) {
			return new WP_Error(
				'wb_gam_api_key_not_found',
				__( 'API key not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		ApiKeyAuth::delete_key( $id );

		do_action( 'wb_gam_after_delete_api_key', $id, $row, $request );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'id'       => $id,
				'previous' => $this->shape_row( $row ),
			),
			200
		);
	}

	/**
	 * Look up a single key row by its DB id. Returns null if missing.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	private function find_row_by_id( int $id ): ?array {
		foreach ( ApiKeyAuth::get_keys() as $row ) {
			if ( (int) $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Shape a single row from ApiKeyAuth::get_keys() for the REST response.
	 *
	 * The hash never leaves this controller — only metadata + display
	 * markers (prefix, suffix) the admin can use to identify the key
	 * without recovering it.
	 *
	 * @param array<string, mixed> $row Row from ApiKeyAuth::get_keys().
	 * @return array<string, mixed>
	 */
	private function shape_row( array $row ): array {
		$prefix = (string) ( $row['key_prefix'] ?? '' );
		$suffix = (string) ( $row['key_suffix'] ?? '' );
		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'label'       => isset( $row['label'] ) ? (string) $row['label'] : '',
			'site_id'     => isset( $row['site_id'] ) ? (string) $row['site_id'] : '',
			'user_id'     => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'key_prefix'  => $prefix,
			'key_suffix'  => $suffix,
			'key_preview' => '' !== $prefix ? ( $prefix . '…' . $suffix ) : '',
			'active'      => ! empty( $row['is_active'] ),
			'created_at'  => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'last_used'   => isset( $row['last_used'] ) ? (string) ( $row['last_used'] ?? '' ) : '',
		);
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
				'id'          => array(
					'type'        => 'integer',
					'description' => 'Stable row id used for revoke + delete.',
					'readonly'    => true,
				),
				'key_prefix'  => array(
					'type'        => 'string',
					'description' => 'First 14 characters ("wbgam_" + 8 chars). Safe to display.',
					'readonly'    => true,
				),
				'key_suffix'  => array(
					'type'        => 'string',
					'description' => 'Last 4 characters. Safe to display.',
					'readonly'    => true,
				),
				'key_preview' => array(
					'type'        => 'string',
					'description' => 'prefix + ellipsis + suffix, ready for tables.',
					'readonly'    => true,
				),
				'secret'      => array(
					'type'        => 'string',
					'description' => 'Full key value — present ONLY in the create response. Never returned by GET.',
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
			),
		);
	}
}
