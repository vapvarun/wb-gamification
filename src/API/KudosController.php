<?php
/**
 * REST API: Kudos Controller
 *
 * POST /wb-gamification/v1/kudos              Give kudos to a member
 * GET  /wb-gamification/v1/kudos              Recent kudos feed
 * GET  /wb-gamification/v1/kudos/me           Current user's kudos sent/received counts
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\KudosEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for member kudos (peer recognition).
 *
 * Handles POST /wb-gamification/v1/kudos, GET /wb-gamification/v1/kudos,
 * and GET /wb-gamification/v1/kudos/me.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class KudosController extends WP_REST_Controller {

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
	protected $rest_base = 'kudos';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /kudos — recent kudos feed (public).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'limit' => array(
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 50,
							'sanitize_callback' => 'absint',
						),
					),
				),
				// POST /kudos — give kudos (must be logged in).
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'receiver_id'     => array(
							'required'          => false,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'description'       => 'User ID of the member receiving kudos. One of receiver_id or recipient_login is required.',
						),
						'recipient_login' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_user',
							'description'       => 'Login (username) or email of the member receiving kudos. Used by frontend forms where the giver does not know the recipient user_id.',
						),
						'message'         => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Optional short message (max 255 chars).',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /kudos/me — current user's kudos stats.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_stats' ),
					'permission_callback' => array( $this, 'require_logged_in' ),
				),
			)
		);

		// DELETE /kudos/{id} — admin revoke (reverses both point awards,
		// soft-marks the row, audited). Admin-only.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'reason' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Audit reason recorded on the reversal events.',
						),
					),
				),
			)
		);
	}

	/**
	 * Gate: only admins may moderate (revoke) kudos.
	 *
	 * @return true|WP_Error
	 */
	public function admin_permissions_check(): bool|WP_Error {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to moderate kudos.', 'wb-gamification' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}
		return true;
	}

	/**
	 * DELETE /kudos/{id} — revoke a kudos.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_item( $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request['id'];
		$reason = (string) $request->get_param( 'reason' );
		$result = KudosEngine::revoke( $id, $reason, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	/**
	 * Check if the current user can give kudos.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to give kudos.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Check if the current user is logged in.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function require_logged_in( $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	// ── Endpoint callbacks ─────────────────────────────────────────────────────

	/**
	 * Retrieve the recent kudos feed.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing recent kudos.
	 */
	public function get_items( $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$feed  = KudosEngine::get_recent( $limit );

		return rest_ensure_response( $feed );
	}

	/**
	 * Give kudos to another member.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$giver_id    = get_current_user_id();
		$receiver_id = (int) $request->get_param( 'receiver_id' );
		$message     = (string) $request->get_param( 'message' );

		// Frontend forms send `recipient_login` (a user_login / slug) instead
		// of `receiver_id` — the public WP users REST endpoint only returns
		// post authors, so member→ID resolution has to happen server-side.
		//
		// Pre-1.4.1 also accepted `email` lookups here. Removed in 1.4.1
		// because an email-as-recipient path lets any logged-in user POST
		// arbitrary emails and infer email-to-account binding by error
		// code (`rest_user_invalid` vs success). Subscriber-level account
		// enumeration. Frontend forms only ever post login/slug now; the
		// email path was unused but discoverable. Closes audit
		// DATA-FLOW-ADMIN-REST-2026-05-27.md §G14.
		if ( $receiver_id <= 0 ) {
			$login = (string) $request->get_param( 'recipient_login' );
			if ( '' !== $login ) {
				$user = get_user_by( 'login', $login ) ?: get_user_by( 'slug', $login );
				if ( $user ) {
					$receiver_id = (int) $user->ID;
				}
			}
		}

		if ( $receiver_id <= 0 || ! get_userdata( $receiver_id ) ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Recipient not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$result = KudosEngine::send( $giver_id, $receiver_id, $message );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			// Cooldown / rate-limit responses use HTTP 429 per RFC 6585; other
			// errors (self-kudos, invalid user, db error) stay at 422.
			$status = ( 'wb_gam_kudos_cooldown' === $code ) ? 429 : 422;
			return new WP_Error(
				$code,
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		$response = rest_ensure_response(
			array(
				'success'         => true,
				'receiver_id'     => $receiver_id,
				'daily_remaining' => max(
					0,
					(int) get_option( 'wb_gam_kudos_daily_limit', 5 ) - KudosEngine::get_daily_sent_count( $giver_id )
				),
			)
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Retrieve the current user's kudos sent/received counts and daily budget.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing kudos stats.
	 */
	public function get_my_stats( $request ): WP_REST_Response {
		$user_id     = get_current_user_id();
		$daily_limit = (int) get_option( 'wb_gam_kudos_daily_limit', 5 );
		$sent_today  = KudosEngine::get_daily_sent_count( $user_id );

		return rest_ensure_response(
			array(
				'user_id'         => $user_id,
				'received_total'  => KudosEngine::get_received_count( $user_id ),
				'daily_limit'     => $daily_limit,
				'sent_today'      => $sent_today,
				'daily_remaining' => max( 0, $daily_limit - $sent_today ),
			)
		);
	}

	// ── Schema ─────────────────────────────────────────────────────────────────

	/**
	 * Retrieve the JSON schema for a kudos item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-kudos',
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'giver_id'      => array( 'type' => 'integer' ),
				'giver_name'    => array( 'type' => 'string' ),
				'receiver_id'   => array( 'type' => 'integer' ),
				'receiver_name' => array( 'type' => 'string' ),
				'message'       => array( 'type' => array( 'string', 'null' ) ),
				'created_at'    => array( 'type' => 'string' ),
			),
		);
	}
}
