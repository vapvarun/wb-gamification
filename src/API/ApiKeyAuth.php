<?php
/**
 * REST API: API Key Authentication
 *
 * Provides API key authentication for remote sites connecting to
 * the gamification center in standalone mode.
 *
 * Two deployment modes:
 *   1. Local mode — plugin on same site, uses WordPress cookie/nonce auth.
 *   2. Standalone center mode — dedicated site, remote clients authenticate
 *      via API keys sent in the X-WB-Gam-Key header or ?api_key query param.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

defined( 'ABSPATH' ) || exit;

/**
 * API key authentication handler for the WB Gamification REST API.
 *
 * Authenticates requests to the wb-gamification/v1 namespace using API keys
 * and injects remote site context into event metadata.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class ApiKeyAuth {

	/**
	 * Option key for storing API keys.
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'wb_gam_api_keys';

	/**
	 * Initialize API key authentication hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'authenticate' ), 20 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'set_site_context' ), 10, 3 );

		// Inject remote site_id into event metadata for cross-site attribution.
		add_filter(
			'wb_gamification_event_metadata',
			function ( $metadata ) {
				if ( ! empty( $GLOBALS['wb_gam_remote_site_id'] ) ) {
					$metadata['_site_id'] = $GLOBALS['wb_gam_remote_site_id'];
				}
				return $metadata;
			}
		);

		// CORS headers for cross-origin API key authenticated requests.
		add_action(
			'rest_api_init',
			function () {
				// Only add custom CORS for our namespace when API key auth is active.
				add_filter(
					'rest_pre_serve_request',
					function ( $value ) {
						if ( ! empty( $GLOBALS['wb_gam_remote_site_id'] ) ) {
							$origin = get_http_origin();
							if ( $origin ) {
								header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
								header( 'Access-Control-Allow-Credentials: true' );
								header( 'Access-Control-Allow-Headers: X-WB-Gam-Key, Content-Type, Authorization, X-WP-Nonce' );
								header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
							}
						}
						return $value;
					}
				);
			}
		);
	}

	/**
	 * Authenticate via X-WB-Gam-Key header or ?api_key query param.
	 *
	 * Only applies to the wb-gamification/v1 namespace. Does not override
	 * existing authentication (cookie/nonce).
	 *
	 * @param \WP_Error|null|true $result Existing authentication result.
	 * @return \WP_Error|null|true Authentication result.
	 */
	public static function authenticate( $result ) {
		// Don't override existing auth (cookie/nonce).
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}

		// Only apply to our namespace.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URI path comparison only, no output.
		$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( strpos( $rest_route, 'wb-gamification/v1' ) === false ) {
			return $result;
		}

		$api_key = self::get_key_from_request();
		if ( ! $api_key ) {
			return $result; // No key provided — let WP handle auth normally.
		}

		$keys     = self::get_keys();
		$key_data = $keys[ $api_key ] ?? null;

		if ( ! $key_data || ! $key_data['active'] ) {
			return new \WP_Error(
				'wb_gam_invalid_api_key',
				__( 'Invalid or inactive API key.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}

		// Set the user context to the key's associated user.
		wp_set_current_user( (int) $key_data['user_id'] );

		// Store site_id for event attribution.
		if ( ! empty( $key_data['site_id'] ) ) {
			$GLOBALS['wb_gam_remote_site_id'] = sanitize_text_field( $key_data['site_id'] );
		}

		// Update last_used timestamp.
		$keys[ $api_key ]['last_used'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION_KEY, $keys );

		return true;
	}

	/**
	 * Inject site_id into event metadata for remote events.
	 *
	 * @param mixed            $result  Response to replace the requested response.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed Unmodified result.
	 */
	public static function set_site_context( $result, $server, $request ) {
		if ( isset( $GLOBALS['wb_gam_remote_site_id'] ) ) {
			$request->set_param( '_site_id', $GLOBALS['wb_gam_remote_site_id'] );
		}
		return $result;
	}

	/**
	 * Extract the API key from the request headers or query params.
	 *
	 * Checks the X-WB-Gam-Key header first, falls back to ?api_key query param.
	 *
	 * @return string API key string, or empty if not provided.
	 */
	private static function get_key_from_request(): string {
		// Header: X-WB-Gam-Key.
		$headers = getallheaders();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $name => $value ) {
				if ( strtolower( $name ) === 'x-wb-gam-key' ) {
					return sanitize_text_field( $value );
				}
			}
		}

		// Query param fallback.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- API key auth, not a form submission.
		return isset( $_GET['api_key'] ) ? sanitize_text_field( wp_unslash( $_GET['api_key'] ) ) : '';
	}

	/**
	 * Get all registered API keys.
	 *
	 * @return array<string, array{user_id: int, site_id: string, label: string, active: bool, created: string, last_used: string, permissions: array}> Registered keys.
	 */
	public static function get_keys(): array {
		return (array) get_option( self::OPTION_KEY, array() );
	}

	/**
	 * Generate a new API key.
	 *
	 * @param string $label   Human-readable label for the key.
	 * @param int    $user_id WordPress user ID to associate with the key.
	 * @param string $site_id Optional remote site identifier.
	 * @return string The generated API key.
	 */
	public static function create_key( string $label, int $user_id, string $site_id = '' ): string {
		$key  = 'wbgam_' . wp_generate_password( 40, false );
		$keys = self::get_keys();

		$keys[ $key ] = array(
			'user_id'     => $user_id,
			'site_id'     => $site_id,
			'label'       => sanitize_text_field( $label ),
			'active'      => true,
			'created'     => gmdate( 'Y-m-d H:i:s' ),
			'last_used'   => '',
			'permissions' => array( 'read', 'write' ),
		);

		update_option( self::OPTION_KEY, $keys );

		return $key;
	}

	/**
	 * Revoke (deactivate) an API key without deleting it.
	 *
	 * @param string $key The API key to revoke.
	 * @return bool True if the key was found and revoked.
	 */
	public static function revoke_key( string $key ): bool {
		$keys = self::get_keys();

		if ( isset( $keys[ $key ] ) ) {
			$keys[ $key ]['active'] = false;
			update_option( self::OPTION_KEY, $keys );
			return true;
		}

		return false;
	}

	/**
	 * Permanently delete an API key.
	 *
	 * @param string $key The API key to delete.
	 * @return bool True if the option was updated.
	 */
	public static function delete_key( string $key ): bool {
		$keys = self::get_keys();
		unset( $keys[ $key ] );
		return update_option( self::OPTION_KEY, $keys );
	}
}
