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
use WBGam\Engine\Privacy;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for member gamification profiles.
 *
 * Handles GET /wb-gamification/v1/members/{id} and sub-resources:
 * points, level, badges, events, and streak.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class MembersController extends WP_REST_Controller {

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
	 * Default number of points history rows per page.
	 *
	 * @var int
	 */
	private const POINTS_PER_PAGE = 20;

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /members/{id}.
		// T1 gate — response is shaped to drop T2 fields for non-self/non-admin.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 't1_permissions_check' ),
					'args'                => $this->get_member_id_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /members/{id}/points.
		// T2 gate — full points history is behavioral data; self+admin only.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/points',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_points' ),
					'permission_callback' => array( $this, 't2_permissions_check' ),
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
							'type'     => array(
								'type'              => 'string',
								'default'           => '',
								'description'       => 'Optional point-type slug to scope total + history. Empty = primary type. Unknown slug falls back to primary.',
								'sanitize_callback' => 'sanitize_key',
							),
						)
					),
				),
			)
		);

		// GET /members/{id}/level.
		// T1 gate — level name + progress is achievement-shaped.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/level',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_level' ),
					'permission_callback' => array( $this, 't1_permissions_check' ),
					'args'                => $this->get_member_id_args(),
				),
			)
		);

		// GET /members/{id}/badges.
		// T1 gate — badge list is the canonical "show off what I earned" payload.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/badges',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_badges' ),
					'permission_callback' => array( $this, 't1_permissions_check' ),
					'args'                => $this->get_member_id_args(),
				),
			)
		);

		// GET /members/{id}/events.
		// T2 gate — full event log w/ metadata is behavioral data; self+admin only.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 't2_permissions_check' ),
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

		// GET /members/{id}/streak.
		// T1 gate — entry allowed for public viewers; callback strips the T2
		// fields (last_active, heatmap, grace_used) when viewer is not self/admin.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/streak',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_streak' ),
					'permission_callback' => array( $this, 't1_permissions_check' ),
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

		// GET /members/me/toasts — read and flush pending toast notifications.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/toasts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_toasts' ),
					'permission_callback' => array( $this, 'get_toasts_permissions_check' ),
				),
			)
		);
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	/**
	 * T1 (achievements / showcase) gate — applies to /members/{id},
	 * /level, /badges, /streak (entry only; callback strips T2 fields).
	 *
	 * Allows the request through when EITHER:
	 *   • viewer is the target themselves OR an admin
	 *   • OR the site kill-switch is ON AND the member's per-account toggle is ON
	 *
	 * Otherwise returns 403. The callback is responsible for shaping the
	 * response — anonymous/peer viewers must NEVER receive T2 fields even
	 * when the gate lets them in. See Privacy::can_view_public_profile().
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if allowed, 404 if user missing, 403 if gated.
	 */
	public function t1_permissions_check( $request ): bool|WP_Error {
		$target_id = (int) $request['id'];

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		if ( ! Privacy::can_view_public_profile( $target_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'This member\'s profile is not public.', 'wb-gamification' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * T2 (behavioral history) gate — applies to /points history and /events.
	 *
	 * Self + admin only, ALWAYS. Behavioral history reveals when/how a member
	 * spends time and is never appropriate to share with peers or anonymous
	 * callers, regardless of any toggle. See Privacy::can_view_private_history().
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if allowed, 404 if user missing, 403 otherwise.
	 */
	public function t2_permissions_check( $request ): bool|WP_Error {
		$target_id = (int) $request['id'];

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		if ( ! Privacy::can_view_private_history( $target_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view this member\'s activity history.', 'wb-gamification' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if the current user can read their own toasts.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the user is logged in, WP_Error otherwise.
	 */
	public function get_toasts_permissions_check( $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to view toasts.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	// ── Endpoint callbacks ────────────────────────────────────────────────────

	/**
	 * Retrieve a member's full gamification profile.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
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

		// T1 fields — always shown when the request is allowed by the gate.
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
		);

		// T2 fields — only when viewer is the owner or admin. Multi-currency
		// breakdown reveals what currencies the user holds + balances; the
		// preferences object includes the user's privacy choices (leaking
		// these defeats the choice itself). See plan/PRIVACY-MODEL.md.
		if ( Privacy::can_view_private_history( $user_id ) ) {
			$prefs                  = $this->get_member_prefs( $user_id );
			$data['points_by_type'] = PointsEngine::get_totals_by_type( $user_id );
			$data['preferences']    = array(
				'show_rank'           => (bool) $prefs['show_rank'],
				'leaderboard_opt_out' => (bool) $prefs['leaderboard_opt_out'],
				'notification_mode'   => $prefs['notification_mode'],
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieve a member's points total and paginated transaction history.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
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

		// Optional ?type= query filter — scopes total + history to a specific currency.
		$type_input = (string) $request->get_param( 'type' );
		$pt_service = new \WBGam\Services\PointTypeService();
		$type_scope = '' !== $type_input ? $pt_service->resolve( $type_input ) : null;

		// `total` continues to mean "primary type total" for back-compat.
		// `by_type` is the multi-currency breakdown (additive).
		$total      = PointsEngine::get_total( $user_id, null === $type_scope ? null : $type_scope );
		$by_type    = PointsEngine::get_totals_by_type( $user_id );
		$primary    = $pt_service->default_slug();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated history; user-specific data not suitable for generic cache.
		$rows = null !== $type_scope
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_id, action_id, points, point_type, object_id, created_at
					   FROM {$wpdb->prefix}wb_gam_points
					  WHERE user_id = %d AND point_type = %s
					  ORDER BY created_at DESC
					  LIMIT %d OFFSET %d",
					$user_id,
					$type_scope,
					$per_page,
					$offset
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, event_id, action_id, points, point_type, object_id, created_at
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Row count for pagination; user-specific, not cacheable generically.
		$total_rows = null !== $type_scope
			? (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_points WHERE user_id = %d AND point_type = %s",
					$user_id,
					$type_scope
				)
			)
			: (int) $wpdb->get_var(
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
					'point_type' => (string) ( $row['point_type'] ?? '' ),
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
				'by_type' => $by_type,
				'primary' => $primary,
			)
		);

		$response->header( 'X-WB-Gam-Total-Rows', $total_rows );
		$response->header( 'X-WP-Total', $total_rows );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total_rows / $per_page ) );

		return $response;
	}

	/**
	 * Retrieve a member's current level and progress toward the next level.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
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
	 * Retrieve all badges earned by a member.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_badges( $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-user badge list; not suitable for a shared cache.
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
	 * Retrieve a member's paginated gamification event log.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Paginated event log; user-specific, not cacheable generically.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Row count for pagination; not cacheable generically.
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
	 * Retrieve a member's current streak and optional contribution heatmap data.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_streak( $request ): WP_REST_Response|WP_Error {
		$user_id      = (int) $request['id'];
		$heatmap_days = (int) $request->get_param( 'heatmap_days' );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$streak  = StreakEngine::get_streak( $user_id );
		$private = Privacy::can_view_private_history( $user_id );

		// T1 fields — current + longest are achievement-shaped. Always returned
		// when the gate lets the request through.
		$data = array(
			'current_streak' => $streak['current_streak'],
			'longest_streak' => $streak['longest_streak'],
			'milestones'     => array( 7, 14, 30, 60, 100, 180, 365 ),
		);

		// T2 fields — last_active timestamp + heatmap reveal a daily activity
		// pattern (when the member was online). Owner/admin only.
		if ( $private ) {
			$data['last_active'] = $streak['last_active'];
			$data['grace_used']  = $streak['grace_used'];
			$data['heatmap']     = $heatmap_days > 0
				? StreakEngine::get_contribution_data( $user_id, $heatmap_days )
				: null;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieve and flush pending toast notifications for the current user.
	 *
	 * Reads the user's notification transient and deletes it, returning
	 * the queued toast messages. Called by the frontend toast.js poller.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Toast notifications array.
	 */
	public function get_toasts( $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$key     = 'wb_gam_notif_' . $user_id;
		$toasts  = get_transient( $key );
		delete_transient( $key );

		$result = is_array( $toasts ) ? $toasts : array();

		// Normalize toast data for frontend consumption.
		$normalized = array_map(
			static function ( array $toast ): array {
				$type    = $toast['type'] ?? 'points';
				$message = '';

				switch ( $type ) {
					case 'points':
						$message = $toast['message'] ?? sprintf(
							/* translators: %d: number of points */
							__( '+%d points', 'wb-gamification' ),
							$toast['points'] ?? 0
						);
						if ( ! empty( $toast['detail'] ) ) {
							$message .= ' ' . $toast['detail'];
						}
						break;

					case 'badge':
					case 'challenge':
					case 'kudos':
						$message = $toast['message'] ?? '';
						break;

					case 'level_up':
						$message = sprintf(
							/* translators: %s: new level name */
							__( 'Level up: %s', 'wb-gamification' ),
							$toast['levelName'] ?? ''
						);
						break;

					case 'streak_milestone':
						$message = sprintf(
							/* translators: %d: streak day count */
							__( '%d-day streak!', 'wb-gamification' ),
							$toast['days'] ?? 0
						);
						$type = 'streak';
						break;

					default:
						$message = $toast['message'] ?? '';
				}

				return array(
					'type'    => $type,
					'message' => $message,
					'icon'    => $toast['icon'] ?? null,
					'detail'  => $toast['detail'] ?? null,
				);
			},
			$result
		);

		return rest_ensure_response( $normalized );
	}

	/**
	 * Retrieve a member's gamification preferences, with defaults for missing rows.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array{ leaderboard_opt_out: int, show_rank: int, notification_mode: string } Preference row.
	 */
	private function get_member_prefs( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- User preferences; cached at caller level if needed.
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

	/**
	 * Return the count of badges earned by a member.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Number of badges earned.
	 */
	private function get_badge_count( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Badge count; user-specific aggregation.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wb_gam_user_badges WHERE user_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Return the shared `id` argument definition for member routes.
	 *
	 * @return array Argument definition array.
	 */
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

	/**
	 * Retrieve the JSON schema for a member item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-member',
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'display_name'   => array( 'type' => 'string' ),
				'points'         => array( 'type' => 'integer' ),
				'points_by_type' => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
					'description'          => 'Map of point-type slug → balance (multi-currency breakdown).',
				),
				'level'          => array( 'type' => array( 'object', 'null' ) ),
				'badges_count'   => array( 'type' => 'integer' ),
			),
		);
	}
}
