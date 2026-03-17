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

class KudosController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'kudos';

	public function register_routes(): void {
		// GET /kudos — recent kudos feed (public)
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
				// POST /kudos — give kudos (must be logged in)
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'receiver_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'description'       => 'User ID of the member receiving kudos.',
						),
						'message'     => array(
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

		// GET /kudos/me — current user's kudos stats
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
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	public function create_item_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to give kudos.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	public function require_logged_in( WP_REST_Request $request ): bool|WP_Error {
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
	 * GET /kudos — recent kudos feed.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$feed  = KudosEngine::get_recent( $limit );

		return rest_ensure_response( $feed );
	}

	/**
	 * POST /kudos — give kudos to another member.
	 */
	public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$giver_id    = get_current_user_id();
		$receiver_id = (int) $request->get_param( 'receiver_id' );
		$message     = (string) $request->get_param( 'message' );

		if ( ! get_userdata( $receiver_id ) ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'Recipient not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$result = KudosEngine::send( $giver_id, $receiver_id, $message );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 422 )
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
	 * GET /kudos/me — current user's sent/received counts + daily budget.
	 */
	public function get_my_stats( WP_REST_Request $request ): WP_REST_Response {
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
