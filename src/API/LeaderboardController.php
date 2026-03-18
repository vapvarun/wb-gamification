<?php
/**
 * REST API: Leaderboard Controller
 *
 * GET /wb-gamification/v1/leaderboard         Top-N members for a period
 * GET /wb-gamification/v1/leaderboard/me      Current user's private rank
 *
 * Query params for both endpoints:
 *   period     = all | month | week | day   (default: all)
 *   limit      = 1–100                      (default: 10, leaderboard only)
 *   scope_type = e.g. 'bp_group'            (default: '' = site-wide)
 *   scope_id   = integer                    (default: 0)
 *
 * Authentication:
 *   - Public leaderboard is publicly readable.
 *   - /leaderboard/me requires the user to be logged in.
 *   - Opt-out users are excluded from the public list; they can still read
 *     their own private rank via /leaderboard/me.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\LeaderboardEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for the gamification leaderboard.
 *
 * Handles GET /wb-gamification/v1/leaderboard and GET /wb-gamification/v1/leaderboard/me.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class LeaderboardController extends WP_REST_Controller {

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
	protected $rest_base = 'leaderboard';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /leaderboard.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_leaderboard' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_scope_args( true ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /leaderboard/group/{group_id} — scoped to a BuddyPress group.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/group/(?P<group_id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_group_leaderboard' ),
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_scope_args( true ),
						array(
							'group_id' => array(
								'required'          => true,
								'type'              => 'integer',
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);

		// GET /leaderboard/me — current user's private rank.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_my_rank' ),
					'permission_callback' => array( $this, 'require_logged_in' ),
					'args'                => $this->get_scope_args( false ),
				),
			)
		);
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	/**
	 * Check if the current user is logged in.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function require_logged_in( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to view your rank.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	// ── Endpoint callbacks ─────────────────────────────────────────────────────

	/**
	 * Retrieve the top-N members for a given period and scope.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing leaderboard rows.
	 */
	public function get_leaderboard( WP_REST_Request $request ): WP_REST_Response {
		$period     = $this->validate_period( $request->get_param( 'period' ) );
		$limit      = (int) $request->get_param( 'limit' );
		$scope_type = sanitize_key( (string) $request->get_param( 'scope_type' ) );
		$scope_id   = (int) $request->get_param( 'scope_id' );

		$rows = LeaderboardEngine::get_leaderboard( $period, $limit, $scope_type, $scope_id );

		return rest_ensure_response(
			array(
				'period' => $period,
				'scope'  => array(
					'type' => $scope_type,
					'id'   => $scope_id,
				),
				'rows'   => $rows,
			)
		);
	}

	/**
	 * Retrieve the current user's private rank (even when opted out of public display).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing the user's rank data.
	 */
	public function get_my_rank( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = get_current_user_id();
		$period     = $this->validate_period( $request->get_param( 'period' ) );
		$scope_type = sanitize_key( (string) $request->get_param( 'scope_type' ) );
		$scope_id   = (int) $request->get_param( 'scope_id' );

		$rank = LeaderboardEngine::get_user_rank( $user_id, $period, $scope_type, $scope_id );

		return rest_ensure_response(
			array(
				'user_id'        => $user_id,
				'period'         => $period,
				'scope'          => array(
					'type' => $scope_type,
					'id'   => $scope_id,
				),
				'rank'           => $rank['rank'],
				'points'         => $rank['points'],
				'points_to_next' => $rank['points_to_next'],
			)
		);
	}

	/**
	 * Retrieve a BuddyPress group-scoped leaderboard.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_group_leaderboard( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$group_id = (int) $request['group_id'];
		$period   = $this->validate_period( $request->get_param( 'period' ) );
		$limit    = (int) $request->get_param( 'limit' );

		// Resolve group name if BP is active.
		$group_name = '';
		if ( function_exists( 'groups_get_group' ) ) {
			$group = groups_get_group( $group_id );
			if ( empty( $group->id ) ) {
				return new WP_Error( 'rest_not_found', __( 'Group not found.', 'wb-gamification' ), array( 'status' => 404 ) );
			}
			$group_name = $group->name;
		}

		$rows = LeaderboardEngine::get_leaderboard( $period, $limit, 'bp_group', $group_id );

		return rest_ensure_response(
			array(
				'group_id'   => $group_id,
				'group_name' => $group_name,
				'period'     => $period,
				'rows'       => $rows,
			)
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Validate and normalise the period query param.
	 *
	 * @param mixed $period Raw period value from the request.
	 * @return string One of 'all', 'month', 'week', or 'day'.
	 */
	private function validate_period( mixed $period ): string {
		$allowed = array( 'all', 'month', 'week', 'day' );
		return in_array( $period, $allowed, true ) ? $period : 'all';
	}

	/**
	 * Shared query-param args for both endpoints.
	 *
	 * @param bool $include_limit Whether to include the `limit` param (leaderboard only).
	 */
	private function get_scope_args( bool $include_limit ): array {
		$args = array(
			'period'     => array(
				'type'              => 'string',
				'default'           => 'all',
				'enum'              => array( 'all', 'month', 'week', 'day' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'scope_type' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'scope_id'   => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
		);

		if ( $include_limit ) {
			$args['limit'] = array(
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			);
		}

		return $args;
	}

	/**
	 * Retrieve the JSON schema for a leaderboard response.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-leaderboard',
			'type'       => 'object',
			'properties' => array(
				'period' => array( 'type' => 'string' ),
				'scope'  => array(
					'type'       => 'object',
					'properties' => array(
						'type' => array( 'type' => 'string' ),
						'id'   => array( 'type' => 'integer' ),
					),
				),
				'rows'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'rank'         => array( 'type' => 'integer' ),
							'user_id'      => array( 'type' => 'integer' ),
							'display_name' => array( 'type' => 'string' ),
							'avatar_url'   => array( 'type' => 'string' ),
							'points'       => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}
}
