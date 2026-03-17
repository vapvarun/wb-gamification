<?php
/**
 * REST API: Recap Controller (Phase 3)
 *
 * GET /wb-gamification/v1/members/{id}/recap?year=2024
 *
 * Returns the "Year in Community" recap data for a member.
 *
 * Permissions:
 *   - Authenticated users can view their own recap.
 *   - Admins (manage_options) can view any member's recap.
 *
 * Response is fully JSON-serialisable and intended to drive a shareable
 * recap card UI (Interactivity API block, or static OG-image generation
 * in a future phase).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\RecapEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class RecapController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'members';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/recap',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_recap' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id'   => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'year' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 2020,
							'maximum'           => 2099,
							'sanitize_callback' => 'absint',
							'description'       => 'Four-digit year. Defaults to previous calendar year.',
						),
					),
				),
			)
		);
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	public function get_recap( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];
		$year    = (int) $request->get_param( 'year' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		// Default year = previous calendar year.
		if ( $year <= 0 ) {
			$year = (int) gmdate( 'Y' ) - 1;
		}

		$recap = RecapEngine::get_recap( $user_id, $year );

		$user          = get_userdata( $user_id );
		$recap['meta'] = array(
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 96 ) ),
		);

		return rest_ensure_response( $recap );
	}

	// ── Permissions ──────────────────────────────────────────────────────────

	public function permissions_check( WP_REST_Request $request ): bool|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to view recap data.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}

		// Self-read always allowed.
		if ( get_current_user_id() === $user_id ) {
			return true;
		}

		// Admins can view any recap.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You may only view your own recap.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}
}
