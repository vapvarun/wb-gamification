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

/**
 * REST API controller for member year-in-community recap data.
 *
 * Handles GET /wb-gamification/v1/members/{id}/recap.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class RecapController extends WP_REST_Controller {

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
	protected $rest_base = 'members';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
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

	/**
	 * Retrieve the JSON schema for a recap response item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-recap',
			'type'       => 'object',
			'properties' => array(
				'year'              => array(
					'type'        => 'integer',
					'description' => 'The recap year.',
				),
				'points_this_year'  => array(
					'type'        => 'integer',
					'description' => 'Total points earned in the recap year.',
				),
				'total_events'      => array(
					'type'        => 'integer',
					'description' => 'Total gamification events fired.',
				),
				'top_actions'       => array(
					'type'        => 'array',
					'description' => 'Top actions by frequency.',
				),
				'badges_earned'     => array(
					'type'        => 'integer',
					'description' => 'Number of badges earned in the year.',
				),
				'longest_streak'    => array(
					'type'        => 'integer',
					'description' => 'Longest streak during the year.',
				),
				'monthly_breakdown' => array(
					'type'        => 'array',
					'description' => 'Per-month points and event counts.',
				),
				'meta'              => array(
					'type'        => 'object',
					'description' => 'Member metadata (display name, avatar).',
					'properties'  => array(
						'display_name' => array( 'type' => 'string', 'description' => 'Member display name.' ),
						'avatar_url'   => array( 'type' => 'string', 'description' => 'Member avatar URL.' ),
					),
				),
			),
		);
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	/**
	 * Retrieve a member's year-in-community recap data.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
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

	/**
	 * Check if the current user can view the requested member's recap.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
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
