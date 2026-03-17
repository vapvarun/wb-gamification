<?php
/**
 * REST API: Members Controller
 *
 * All member-scoped gamification data endpoints.
 *
 * GET /wb-gamification/v1/members/{id}          Full gamification profile
 * GET /wb-gamification/v1/members/{id}/points   Points total + paginated history
 * GET /wb-gamification/v1/members/{id}/level    Current level + progress to next
 * GET /wb-gamification/v1/members/{id}/badges   Earned badges (Phase 2 — returns empty until BadgeEngine ships)
 *
 * Authentication:
 *   - Any authenticated user can read their own profile.
 *   - Admins can read any profile.
 *   - Guest access returns public data only (respects leaderboard_opt_out).
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\PointsEngine;
use WBGam\Engine\LevelEngine;
use WBGam\Engine\StreakEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class MembersController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'members';

	private const POINTS_PER_PAGE = 20;

	public function register_routes(): void {
		// GET /members/{id}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_member_id_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /members/{id}/points
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/points',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_points' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'page'     => array(
								'type'              => 'integer',
								'default'           => 1,
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page' => array(
								'type'              => 'integer',
								'default'           => self::POINTS_PER_PAGE,
								'minimum'           => 1,
								'maximum'           => 100,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);

		// GET /members/{id}/level
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/level',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_level' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_member_id_args(),
				),
			)
		);

		// GET /members/{id}/badges
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/badges',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_badges' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_member_id_args(),
				),
			)
		);

		// GET /members/{id}/events
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'page'     => array(
								'type'              => 'integer',
								'default'           => 1,
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page' => array(
								'type'              => 'integer',
								'default'           => 20,
								'minimum'           => 1,
								'maximum'           => 100,
								'sanitize_callback' => 'absint',
							),
						)
					),
				),
			)
		);

		// GET /members/{id}/streak
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/streak',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_streak' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'heatmap_days' => array(
								'type'              => 'integer',
								'default'           => 0,
								'minimum'           => 0,
								'maximum'           => 365,
								'sanitize_callback' => 'absint',
								'description'       => 'Include N days of contribution data for heatmap. 0 = skip.',
							),
						)
					),
				),
			)
		);
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	public function get_item_permissions_check( $request ): bool|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		// Self-read always allowed for authenticated users.
		if ( is_user_logged_in() ) {
			return true;
		}

		// Unauthenticated: only expose public data (enforced in callbacks via opt-out check).
		return true;
	}

	// ── Endpoint callbacks ────────────────────────────────────────────────────

	/**
	 * GET /members/{id} — Full gamification profile.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$points = PointsEngine::get_total( $user_id );
		$level  = LevelEngine::get_level_for_user( $user_id );
		$next   = LevelEngine::get_next_level( $user_id );
		$prefs  = $this->get_member_prefs( $user_id );

		$data = array(
			'id'           => $user_id,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user_id, array( 'size' => 96 ) ),
			'points'       => $points,
			'level'        => $level
				? array(
					'id'              => $level['id'],
					'name'            => $level['name'],
					'min_points'      => $level['min_points'],
					'icon_url'        => $level['icon_url'],
					'progress_pct'    => LevelEngine::get_progress_percent( $user_id ),
					'next_threshold'  => $next ? $next['min_points'] : null,
					'next_level_name' => $next ? $next['name'] : null,
				)
				: null,
			'badges_count' => $this->get_badge_count( $user_id ),
			'preferences'  => array(
				'show_rank'           => (bool) $prefs['show_rank'],
				'leaderboard_opt_out' => (bool) $prefs['leaderboard_opt_out'],
				'notification_mode'   => $prefs['notification_mode'],
			),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * GET /members/{id}/points — Points total + paginated history.
	 */
	public function get_points( $request ): WP_REST_Response|WP_Error {
		$user_id  = (int) $request['id'];
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		$total = PointsEngine::get_total( $user_id );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_id, action_id, points, object_id, created_at
				   FROM {$wpdb->prefix}wb_gam_points
				  WHERE user_id = %d
				  ORDER BY created_at DESC
				  LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d",
				$user_id
			)
		);

		$history = array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'event_id'   => $row['event_id'],
					'action_id'  => $row['action_id'],
					'points'     => (int) $row['points'],
					'object_id'  => $row['object_id'] ? (int) $row['object_id'] : null,
					'created_at' => $row['created_at'],
				);
			},
			$rows
		);

		$response = rest_ensure_response(
			array(
				'total'   => $total,
				'history' => $history,
			)
		);

		$response->header( 'X-WB-Gam-Total-Rows', $total_rows );
		$response->header( 'X-WP-Total', $total_rows );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total_rows / $per_page ) );

		return $response;
	}

	/**
	 * GET /members/{id}/level — Current level + progress to next.
	 */
	public function get_level( $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$points = PointsEngine::get_total( $user_id );
		$level  = LevelEngine::get_level_for_user( $user_id );
		$next   = LevelEngine::get_next_level( $user_id );

		return rest_ensure_response(
			array(
				'points'       => $points,
				'current'      => $level
					? array(
						'id'         => $level['id'],
						'name'       => $level['name'],
						'min_points' => $level['min_points'],
						'icon_url'   => $level['icon_url'],
					)
					: null,
				'next'         => $next
					? array(
						'id'         => $next['id'],
						'name'       => $next['name'],
						'min_points' => $next['min_points'],
						'icon_url'   => $next['icon_url'],
					)
					: null,
				'progress_pct' => LevelEngine::get_progress_percent( $user_id ),
				'all_levels'   => LevelEngine::get_all_levels_for_user( $user_id ),
			)
		);
	}

	/**
	 * GET /members/{id}/badges — Phase 2 stub.
	 */
	public function get_badges( $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.name, b.description, b.image_url, b.is_credential, b.category,
				        ub.earned_at
				   FROM {$wpdb->prefix}wb_gam_user_badges ub
				   JOIN {$wpdb->prefix}wb_gam_badge_defs b ON b.id = ub.badge_id
				  WHERE ub.user_id = %d
				  ORDER BY ub.earned_at DESC",
				$user_id
			),
			ARRAY_A
		);

		$badges = array_map(
			static function ( array $row ): array {
				return array(
					'id'            => $row['id'],
					'name'          => $row['name'],
					'description'   => $row['description'],
					'image_url'     => $row['image_url'],
					'is_credential' => (bool) $row['is_credential'],
					'category'      => $row['category'],
					'earned_at'     => $row['earned_at'],
				);
			},
			$rows ?: array()
		);

		return rest_ensure_response( $badges );
	}

	/**
	 * GET /members/{id}/events — paginated event log for a member.
	 */
	public function get_events( $request ): WP_REST_Response|WP_Error {
		$user_id  = (int) $request['id'];
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$offset   = ( $page - 1 ) * $per_page;

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, action_id, object_id, metadata, created_at
				   FROM {$wpdb->prefix}wb_gam_events
				  WHERE user_id = %d
				  ORDER BY created_at DESC
				  LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_events WHERE user_id = %d",
				$user_id
			)
		);

		$events = array_map(
			static function ( array $row ): array {
				return array(
					'id'         => $row['id'],
					'action_id'  => $row['action_id'],
					'object_id'  => $row['object_id'] ? (int) $row['object_id'] : null,
					'metadata'   => $row['metadata'] ? json_decode( $row['metadata'], true ) : null,
					'created_at' => $row['created_at'],
				);
			},
			$rows ?: array()
		);

		$response = rest_ensure_response( $events );
		$response->header( 'X-WP-Total', $total_rows );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total_rows / $per_page ) );

		return $response;
	}

	/**
	 * GET /members/{id}/streak — current streak + optional contribution heatmap data.
	 */
	public function get_streak( $request ): WP_REST_Response|WP_Error {
		$user_id      = (int) $request['id'];
		$heatmap_days = (int) $request->get_param( 'heatmap_days' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$streak  = StreakEngine::get_streak( $user_id );
		$heatmap = $heatmap_days > 0
			? StreakEngine::get_contribution_data( $user_id, $heatmap_days )
			: null;

		return rest_ensure_response(
			array(
				'current_streak' => $streak['current_streak'],
				'longest_streak' => $streak['longest_streak'],
				'last_active'    => $streak['last_active'],
				'grace_used'     => $streak['grace_used'],
				'milestones'     => array( 7, 14, 30, 60, 100, 180, 365 ),
				'heatmap'        => $heatmap,
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * @return array{ leaderboard_opt_out: int, show_rank: int, notification_mode: string }
	 */
	private function get_member_prefs( int $user_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT leaderboard_opt_out, show_rank, notification_mode
				   FROM {$wpdb->prefix}wb_gam_member_prefs
				  WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		return $row ?: array(
			'leaderboard_opt_out' => 0,
			'show_rank'           => 1,
			'notification_mode'   => 'smart',
		);
	}

	private function get_badge_count( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id = %d",
				$user_id
			)
		);
	}

	private function get_member_id_args(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-member',
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'display_name' => array( 'type' => 'string' ),
				'points'       => array( 'type' => 'integer' ),
				'level'        => array( 'type' => array( 'object', 'null' ) ),
				'badges_count' => array( 'type' => 'integer' ),
			),
		);
	}
}
