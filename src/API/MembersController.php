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
use WBGam\Engine\BadgeEngine;
use WBGam\Engine\StreakEngine;
use WBGam\Engine\NotificationBridge;
use WBGam\Engine\Privacy;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;
// Silencing convention-driven false positives so Plugin Check signal stays clean:
// - WordPress.DB.DirectDatabaseQuery.DirectQuery + .NoCaching + .SchemaChange:
// this file performs custom-table work. .phpcs.xml already excludes these
// for the local WPCS gate; this annotation extends the same intent to
// Plugin Check's internal phpcs invocation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
				// POST /members/{id}/streak — admin adjust (support/moderation).
				// Audited to wb_gam_events; never a silent mutation.
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_streak' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'current_streak' => array(
								'type'              => 'integer',
								'required'          => false,
								'minimum'           => 0,
								'sanitize_callback' => 'absint',
								'description'       => 'New current streak. Omit to leave unchanged.',
							),
							'longest_streak' => array(
								'type'              => 'integer',
								'required'          => false,
								'minimum'           => 0,
								'sanitize_callback' => 'absint',
								'description'       => 'New longest streak. Omit to derive as max(current, existing).',
							),
							'reason'         => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
								'description'       => 'Audit reason recorded on the event.',
							),
						)
					),
				),
				// DELETE /members/{id}/streak — admin reset current streak to 0
				// (longest preserved). Audited to wb_gam_events.
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'reset_streak' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'reason' => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
								'description'       => 'Audit reason recorded on the event.',
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

		// GET/POST /members/me/profile-visibility — the member's own
		// profile-privacy choice (writes wb_gam_profile_public). Self-only:
		// always operates on the current user, never an arbitrary id, so the
		// permission gate is simply "logged in". This is the member-facing
		// write surface the read gates (Privacy::can_view_public_profile,
		// ProfilePage::is_publicly_visible) have always consumed.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/profile-visibility',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile_visibility' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_profile_visibility' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
					'args'                => array(
						'public' => array(
							'type'        => 'boolean',
							'required'    => true,
							'description' => 'Whether the member wants their profile publicly visible.',
						),
					),
				),
			)
		);

		// GET /members — admin roster: searchable, paginated list of members
		// with their gamification stats. Lives under this plugin's own
		// namespace (wb-gamification/v1), so it never collides with WP core's
		// /wp/v2/users or BuddyPress's /buddypress/v1/members.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_members' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
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
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// POST /members/{id}/exclude — toggle the per-user earning veto
		// (wb_gam_sandboxed). Admin-only. Ties into Settings > Access.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/exclude',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_excluded' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'excluded' => array(
								'type'    => 'boolean',
								'default' => true,
							),
						)
					),
				),
			)
		);

		// POST /members/{id}/reset-points — zero a member's balance via a
		// balancing debit (audit-preserving). Admin-only.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reset-points',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_points' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array_merge(
						$this->get_member_id_args(),
						array(
							'type' => array(
								'type'              => 'string',
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Admin-only gate for the roster + member-management actions. Listing every
	 * member's data and adjusting/excluding accounts is a site-management
	 * capability, so it requires manage_options (not the self+admin T1/T2 gate).
	 *
	 * @return true|WP_Error
	 */
	public function admin_permissions_check(): bool|WP_Error {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_members' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage members.', 'wb-gamification' ),
				array( 'status' => is_user_logged_in() ? 403 : 401 )
			);
		}
		return true;
	}

	/**
	 * GET /members — paginated, searchable roster with gamification stats.
	 *
	 * Uses WP_User_Query so it lists every member (search by name/login/email)
	 * and primes points + badges for the page in a fixed number of queries
	 * (no N+1). For ranking by points use the Leaderboard; this roster is for
	 * finding and managing individual members.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_members( WP_REST_Request $request ): WP_REST_Response {
		$page   = max( 1, (int) $request['page'] );
		$per    = min( 100, max( 1, (int) $request['per_page'] ) );
		$search = trim( (string) $request['search'] );

		$args = array(
			'number'      => $per,
			'paged'       => $page,
			'orderby'     => 'display_name',
			'order'       => 'ASC',
			'fields'      => array( 'ID', 'display_name', 'user_login' ),
			'count_total' => true,
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'display_name', 'user_email', 'user_nicename' );
		}

		$query = new \WP_User_Query( $args );
		$users = $query->get_results();
		$total = (int) $query->get_total();

		$ids = array_map( static fn( $u ) => (int) $u->ID, $users );

		// Prime per-page caches so the row loop is N+1-free.
		if ( ! empty( $ids ) ) {
			PointsEngine::prime_totals( $ids );
			BadgeEngine::prime_earned_badges( $ids );
		}

		$items = array();
		foreach ( $users as $user ) {
			$uid     = (int) $user->ID;
			$level   = LevelEngine::get_level_for_user( $uid );
			$items[] = array(
				'id'          => $uid,
				'name'        => $user->display_name,
				'login'       => $user->user_login,
				'avatar'      => get_avatar_url( $uid, array( 'size' => 48 ) ),
				'points'      => PointsEngine::get_total( $uid ),
				'level'       => $level ? (string) $level['name'] : '',
				'badges'      => count( BadgeEngine::get_user_badges( $uid ) ),
				'excluded'    => PointsEngine::is_excluded_user( $uid ) || (bool) get_user_meta( $uid, 'wb_gam_sandboxed', true ),
				'profile_url' => (string) get_edit_user_link( $uid ),
			);
		}

		$pages = $per > 0 ? (int) ceil( $total / $per ) : 1;

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $total,
				'pages'    => $pages,
				'has_more' => ( $page * $per ) < $total,
			),
			200
		);
	}

	/**
	 * POST /members/{id}/exclude — toggle the per-user earning veto.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_excluded( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! get_userdata( $id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$excluded = (bool) $request['excluded'];
		if ( $excluded ) {
			update_user_meta( $id, 'wb_gam_sandboxed', 1 );
		} else {
			delete_user_meta( $id, 'wb_gam_sandboxed' );
		}

		return new WP_REST_Response(
			array(
				'user_id'  => $id,
				'excluded' => $excluded,
			),
			200
		);
	}

	/**
	 * POST /members/{id}/reset-points — zero a member's balance via a balancing
	 * debit so the ledger keeps a full audit trail.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_points( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! get_userdata( $id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$type    = (string) $request['type'];
		$type    = '' !== $type ? $type : null;
		$balance = PointsEngine::get_total( $id, $type );

		if ( $balance > 0 ) {
			$result = PointsEngine::debit( $id, $balance, 'manual_admin_reset', '', $type );
			if ( empty( $result['success'] ) ) {
				return new WP_Error( 'rest_reset_failed', __( 'Failed to reset points.', 'wb-gamification' ), array( 'status' => 500 ) );
			}
		}

		return new WP_REST_Response(
			array(
				'user_id'     => $id,
				'reset_from'  => $balance,
				'new_balance' => 0,
			),
			200
		);
	}

	/**
	 * POST /members/{id}/streak — admin adjust a member's streak values.
	 *
	 * Support/moderation surface for fixing a member's broken or wrong streak.
	 * Delegates to StreakEngine::admin_set(), which audits the change to
	 * wb_gam_events and fires wb_gam_streak_adjusted. Omitting current_streak
	 * or longest_streak leaves that value unchanged.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_streak( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! get_userdata( $id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		// null (not provided) leaves the value unchanged; a provided 0 is a real set.
		$current = null !== $request->get_param( 'current_streak' ) ? (int) $request['current_streak'] : null;
		$longest = null !== $request->get_param( 'longest_streak' ) ? (int) $request['longest_streak'] : null;
		$reason  = (string) $request->get_param( 'reason' );

		$after = StreakEngine::admin_set( $id, $current, $longest, $reason, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'user_id'        => $id,
				'current_streak' => $after['current_streak'],
				'longest_streak' => $after['longest_streak'],
				'last_active'    => $after['last_active'],
			),
			200
		);
	}

	/**
	 * DELETE /members/{id}/streak — admin reset a member's current streak to 0.
	 *
	 * The longest_streak is preserved (all-time record). Delegates to
	 * StreakEngine::admin_reset(), which audits to wb_gam_events and fires
	 * wb_gam_streak_reset.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_streak( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( ! get_userdata( $id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$reason = (string) $request->get_param( 'reason' );
		$after  = StreakEngine::admin_reset( $id, $reason, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'user_id'        => $id,
				'current_streak' => $after['current_streak'],
				'longest_streak' => $after['longest_streak'],
			),
			200
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

	/**
	 * Self-service gate: any logged-in member may read/write their OWN data.
	 *
	 * Used by the `/me/*` routes that act exclusively on the current user, so
	 * there is no target id to authorise against — being logged in is the whole
	 * check.
	 *
	 * @return true|WP_Error
	 */
	public function logged_in_permissions_check(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'wb-gamification' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * GET /members/me/profile-visibility — the current member's own choice.
	 *
	 * @return WP_REST_Response { public: bool, site_enabled: bool }
	 */
	public function get_profile_visibility(): WP_REST_Response {
		$user_id = get_current_user_id();
		return new WP_REST_Response(
			array(
				'public'       => ! \WBGam\Engine\ProfilePage::member_opted_private( $user_id ),
				'site_enabled' => (bool) get_option( 'wb_gam_profile_public_enabled', '1' ),
			),
			200
		);
	}

	/**
	 * POST /members/me/profile-visibility — set the current member's choice.
	 *
	 * @param WP_REST_Request $request Request carrying the boolean `public` flag.
	 * @return WP_REST_Response { public: bool, site_enabled: bool }
	 */
	public function set_profile_visibility( $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$public  = (bool) $request->get_param( 'public' );

		\WBGam\Engine\ProfilePage::set_member_visibility( $user_id, $public );

		return new WP_REST_Response(
			array(
				'public'       => $public,
				'site_enabled' => (bool) get_option( 'wb_gam_profile_public_enabled', '1' ),
			),
			200
		);
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
		$total   = PointsEngine::get_total( $user_id, null === $type_scope ? null : $type_scope );
		$by_type = PointsEngine::get_totals_by_type( $user_id );
		$primary = $pt_service->default_slug();

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
					// Human label resolved from the manifest registry so clients
					// (JS blocks, mobile apps, support tools) don't have to
					// duplicate the lookup. Falls back to a title-cased
					// action_id when the source plugin is deactivated.
					'label'      => \WBGam\Engine\Registry::label_for( (string) $row['action_id'] ),
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

		$response->header( 'X-WB-Gam-Total-Rows', (string) $total_rows );
		$response->header( 'X-WP-Total', (string) $total_rows );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total_rows / $per_page ) );

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
					'label'      => \WBGam\Engine\Registry::label_for( (string) $row['action_id'] ),
					'object_id'  => $row['object_id'] ? (int) $row['object_id'] : null,
					'metadata'   => $row['metadata'] ? json_decode( $row['metadata'], true ) : null,
					'created_at' => $row['created_at'],
				);
			},
			$rows ?: array()
		);

		$response = rest_ensure_response( $events );
		$response->header( 'X-WP-Total', (string) $total_rows );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total_rows / $per_page ) );

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
	 * Retrieve pending toast notifications for the current user.
	 *
	 * Delegates to {@see NotificationBridge::read_pending()} with a
	 * dedicated 'rest' cursor. Non-destructive: the footer renderer and
	 * heartbeat channel maintain their own cursors so all three consumers
	 * deliver the same notice exactly once, with no race for the transient.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Toast notifications array.
	 */
	public function get_toasts( $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$result  = NotificationBridge::read_pending( $user_id, 'rest' );

		// Normalize toast data for frontend consumption.
		$normalized = array_map(
			static function ( array $toast ): array {
				$type    = $toast['type'] ?? 'points';
				$message = '';

				switch ( $type ) {
					case 'points':
						// Currency label is resolved when the toast is queued
						// (NotificationBridge) and embedded in `message`. This
						// fallback only triggers for toasts that were pushed
						// without a pre-formatted message (legacy callers).
						// Use the primary currency label so "+5 XP" still
						// reads correctly on multi-currency sites.
						if ( empty( $toast['message'] ) ) {
							$pt_service   = new \WBGam\Services\PointTypeService();
							$pt_record    = $pt_service->get( $pt_service->default_slug() );
							$label_plural = (string) ( $pt_record['label'] ?? __( 'points', 'wb-gamification' ) );
							$message      = sprintf(
								/* translators: 1: signed point delta, 2: currency label. */
								__( '+%1$d %2$s', 'wb-gamification' ),
								$toast['points'] ?? 0,
								$label_plural
							);
						} else {
							$message = $toast['message'];
						}
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
							/* translators: %d: streak day count. */
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
