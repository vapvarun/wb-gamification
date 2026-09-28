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
 *   point_type = currency slug              (default: '' = primary)
 *
 * The leaderboard route also takes `cursor`: the `next_cursor` of the previous page. Paging is
 * forward-only (keyset), so there is no page or offset input. The response carries `total`,
 * `has_more`, `next_cursor` and `offset`, and the X-WP-Total / X-WP-TotalPages headers.
 * A cursor is login-only unless the `wb_gam_leaderboard_anon_paging` filter opens it, because deep
 * paging would otherwise let an anonymous visitor read the whole member roster.
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
	 * @return WP_REST_Response|WP_Error Response containing one page of leaderboard rows.
	 */
	public function get_leaderboard( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$period     = $this->validate_period( $request->get_param( 'period' ) );
		$scope_type = sanitize_key( (string) $request->get_param( 'scope_type' ) );
		$scope_id   = (int) $request->get_param( 'scope_id' );

		return $this->page_response(
			$request,
			$period,
			$scope_type,
			$scope_id,
			array(
				'period' => $period,
				'scope'  => array(
					'type' => $scope_type,
					'id'   => $scope_id,
				),
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

		$rank = LeaderboardEngine::get_user_rank( $user_id, $period, $scope_type, $scope_id, sanitize_key( (string) $request->get_param( 'point_type' ) ) );

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

		// Resolve group name if BP is active.
		$group_name = '';
		if ( function_exists( 'groups_get_group' ) ) {
			/** @var object{id: int, name: string} $group BP_Groups_Group row. */
			$group = groups_get_group( $group_id );
			if ( empty( $group->id ) ) {
				return new WP_Error( 'rest_not_found', __( 'Group not found.', 'wb-gamification' ), array( 'status' => 404 ) );
			}
			$group_name = $group->name;
		}

		return $this->page_response(
			$request,
			$period,
			'bp_group',
			$group_id,
			array(
				'group_id'   => $group_id,
				'group_name' => $group_name,
				'period'     => $period,
			)
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Build one page of a board: the shared body of the site-wide and group routes.
	 *
	 * @param WP_REST_Request      $request    The request (limit, cursor, point_type).
	 * @param string               $period     Validated period.
	 * @param string               $scope_type Scope type, empty = site-wide.
	 * @param int                  $scope_id   Scope id.
	 * @param array<string, mixed> $envelope   Route-specific fields that lead the response.
	 * @return WP_REST_Response|WP_Error
	 */
	private function page_response(
		WP_REST_Request $request,
		string $period,
		string $scope_type,
		int $scope_id,
		array $envelope
	): WP_REST_Response|WP_Error {
		$limit      = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$point_type = sanitize_key( (string) $request->get_param( 'point_type' ) );
		$cursor     = (string) $request->get_param( 'cursor' );

		// Deep paging reads every member's name, avatar and points. The first page is public (it is
		// the board); walking the whole roster is not, unless the site owner opens it.
		if ( '' !== $cursor && ! is_user_logged_in() && ! apply_filters( 'wb_gam_leaderboard_anon_paging', false ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to page through the leaderboard.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}

		$page = LeaderboardEngine::get_leaderboard_page( $period, $limit, $scope_type, $scope_id, $point_type, $cursor );

		if ( ! empty( $page['invalid_cursor'] ) ) {
			return new WP_Error(
				'wb_gam_invalid_cursor',
				__( 'That page link is not valid for this leaderboard. Start again from the first page.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		$response = rest_ensure_response(
			array_merge(
				$envelope,
				array(
					'rows'        => $page['rows'],
					'total'       => $page['total'],
					'has_more'    => $page['has_more'],
					'next_cursor' => $page['next_cursor'],
					'offset'      => $page['offset'],
				)
			)
		);
		$response->header( 'X-WP-Total', (string) $page['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $page['total'] / $limit ) ) );

		return $response;
	}

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
			'point_type' => array(
				'type'              => 'string',
				'default'           => '',
				'description'       => 'Currency slug. Empty is the primary currency; an unknown slug falls back to it.',
				'sanitize_callback' => 'sanitize_key',
			),
		);

		if ( $include_limit ) {
			$args['limit']  = array(
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			);
			$args['cursor'] = array(
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 200,
				'description'       => 'The next_cursor of the previous page. Forward-only; login required unless the site owner opens it.',
				'sanitize_callback' => 'sanitize_text_field',
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
				'period'      => array( 'type' => 'string' ),
				'scope'       => array(
					'type'       => 'object',
					'properties' => array(
						'type' => array( 'type' => 'string' ),
						'id'   => array( 'type' => 'integer' ),
					),
				),
				'total'       => array( 'type' => 'integer' ),
				'has_more'    => array( 'type' => 'boolean' ),
				'next_cursor' => array( 'type' => 'string' ),
				'offset'      => array( 'type' => 'integer' ),
				'rows'        => array(
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
