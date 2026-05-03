<?php
/**
 * REST API: Badges Controller
 *
 * GET    /wb-gamification/v1/badges              All badge definitions + rarity counts
 * GET    /wb-gamification/v1/badges/{id}         Single badge definition + rarity
 * PUT    /wb-gamification/v1/badges/{id}         Update badge definition (admin)
 * DELETE /wb-gamification/v1/badges/{id}         Delete badge definition (admin)
 * POST   /wb-gamification/v1/badges/{id}/award   Admin-only manual award
 *
 * All badge definitions are visible (locked-but-visible model) — unearned
 * badges appear greyed-out in the UI so members can see what to work toward.
 *
 * Rarity: percentage of members who have earned each badge, computed in a
 * single aggregation query to avoid N+1 DB round-trips.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\BadgeEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for badge definitions and awards.
 *
 * Handles GET /wb-gamification/v1/badges, GET /wb-gamification/v1/badges/{id},
 * and POST /wb-gamification/v1/badges/{id}/award.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class BadgesController extends WP_REST_Controller {

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
	protected $rest_base = 'badges';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /badges  +  POST /badges.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'user_id'  => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Include earned status for this user. 0 = skip.',
						),
						'category' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Filter by badge category.',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->save_args( true ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /badges/{id} + PUT /badges/{id} + DELETE /badges/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_badge_id_args(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array_merge(
						$this->get_badge_id_args(),
						$this->save_args( false )
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->get_badge_id_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// POST /badges/{id}/award.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)/award',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'award_badge' ),
					'permission_callback' => array( $this, 'award_permissions_check' ),
					'args'                => array_merge(
						$this->get_badge_id_args(),
						array(
							'user_id' => array(
								'required'          => true,
								'type'              => 'integer',
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
								'description'       => 'User to award the badge to.',
							),
						)
					),
				),
			)
		);
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	/**
	 * Check if the current user can manage badge definitions and rules.
	 *
	 * Accepts either manage_options (default WP admin gate) or the granular
	 * wb_gam_manage_badges plugin cap, so site owners can delegate badge
	 * management to non-admin roles via a role-manager plugin.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_check(): bool|WP_Error {
		return \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_badges' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'You do not have permission to manage badges.', 'wb-gamification' ), array( 'status' => 403 ) );
	}

	/**
	 * Check if the current user can manually award a badge.
	 *
	 * Same surface as admin_check — manual award is a managing-badges action.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function award_permissions_check( $request ): bool|WP_Error {
		if ( ! \WBGam\Engine\Capabilities::user_can( 'wb_gam_manage_badges' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to award badges.', 'wb-gamification' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// ── Endpoint callbacks ─────────────────────────────────────────────────────

	/**
	 * Retrieve all badge definitions with optional earned status and rarity scores.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing all badge definitions.
	 */
	public function get_items( $request ): WP_REST_Response {
		$user_id  = (int) $request->get_param( 'user_id' );
		$category = (string) $request->get_param( 'category' );

		// Fall back to current user when ?user_id omitted but user is logged in.
		if ( 0 === $user_id && is_user_logged_in() ) {
			$user_id = get_current_user_id();
		}

		$badges = BadgeEngine::get_all_badges_for_user( $user_id );

		// Filter by category if requested.
		if ( '' !== $category ) {
			$badges = array_values(
				array_filter( $badges, fn( $b ) => $b['category'] === $category )
			);
		}

		// Attach rarity scores in one aggregation query.
		$rarity = $this->get_rarity_map();
		foreach ( $badges as &$badge ) {
			$badge['rarity_pct'] = $rarity[ $badge['id'] ] ?? 0.0;
		}
		unset( $badge );

		return rest_ensure_response( $badges );
	}

	/**
	 * Retrieve a single badge definition by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$badge_id = sanitize_key( $request['id'] );
		$def      = BadgeEngine::get_badge_def( $badge_id );

		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$rarity              = $this->get_rarity_map();
		$def['rarity_pct']   = $rarity[ $badge_id ] ?? 0.0;
		$def['earner_count'] = $this->get_earner_count( $badge_id );

		// Earned status for current user.
		if ( is_user_logged_in() ) {
			$def['earned'] = BadgeEngine::has_badge( get_current_user_id(), $badge_id );
		}

		return rest_ensure_response( $def );
	}

	/**
	 * Update a badge definition (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	/**
	 * Build the args schema for badge create + update.
	 *
	 * @param bool $on_create Whether `id` and `name` are required (true on create).
	 * @return array<string, array<string, mixed>>
	 */
	private function save_args( bool $on_create ): array {
		$args = array(
			'name'          => array(
				'required'          => $on_create,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'image_url'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'category'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'is_credential' => array(
				'type' => 'boolean',
			),
			'closes_at'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'UTC timestamp (Y-m-d H:i:s) after which the badge stops awarding. Empty string clears the cutoff.',
			),
			'max_earners'   => array(
				'type'        => array( 'integer', 'null' ),
				'minimum'     => 1,
				'description' => 'Cap on how many members may earn this badge. Null = unlimited.',
			),
			'condition'     => array(
				'type'        => 'object',
				'description' => 'Auto-award rule. Shape: { type: "admin_awarded"|"point_milestone"|"action_count", points?: int, action_id?: string, count?: int }.',
			),
		);
		if ( $on_create ) {
			$args = array_merge(
				array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => 'Unique badge identifier (a-z, 0-9, dash, underscore).',
					),
				),
				$args
			);
		}
		return $args;
	}

	/**
	 * Build the badge_defs row + format array from a request.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $with_id  Whether to include the id column (insert).
	 * @return array{row: array<string, mixed>, formats: array<int, string>}
	 */
	private function collect_badge_row( WP_REST_Request $request, bool $with_id ): array {
		$row = array();
		$formats = array();
		if ( $with_id ) {
			$row['id'] = sanitize_key( (string) $request->get_param( 'id' ) );
			$formats[] = '%s';
		}
		$nullable_fields = array(
			'name'        => array( '%s', 'sanitize_text_field' ),
			'description' => array( '%s', 'sanitize_textarea_field' ),
			'image_url'   => array( '%s', 'esc_url_raw' ),
			'category'    => array( '%s', 'sanitize_key' ),
		);
		foreach ( $nullable_fields as $field => $spec ) {
			if ( null !== $request->get_param( $field ) ) {
				$row[ $field ] = call_user_func( $spec[1], (string) $request->get_param( $field ) );
				$formats[]     = $spec[0];
			}
		}
		if ( null !== $request->get_param( 'is_credential' ) ) {
			$row['is_credential'] = $request->get_param( 'is_credential' ) ? 1 : 0;
			$formats[]            = '%d';
		}
		// closes_at: empty string clears the cutoff; non-empty stored verbatim (callers send UTC).
		if ( null !== $request->get_param( 'closes_at' ) ) {
			$raw                = (string) $request->get_param( 'closes_at' );
			$row['closes_at']   = '' === $raw ? null : $raw;
			$formats[]          = '%s';
		}
		if ( null !== $request->get_param( 'max_earners' ) ) {
			$raw                = $request->get_param( 'max_earners' );
			$row['max_earners'] = ( null === $raw || '' === $raw ) ? null : max( 1, (int) $raw );
			$formats[]          = '%d';
		}
		return array(
			'row'     => $row,
			'formats' => $formats,
		);
	}

	/**
	 * Persist the auto-award condition rule for a badge.
	 *
	 * Replaces any existing rule row in `wb_gam_rules` for this badge. When
	 * `condition.type === 'admin_awarded'` the rule row is deleted (admin
	 * award only, no automatic trigger).
	 *
	 * @param string                                                                    $badge_id  Badge id.
	 * @param array{type?: string, points?: int, action_id?: string, count?: int}|null  $condition Condition payload.
	 * @return void
	 */
	private function persist_condition( string $badge_id, ?array $condition ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE-then-INSERT for rule replacement.
		$wpdb->delete(
			$wpdb->prefix . 'wb_gam_rules',
			array(
				'rule_type' => 'badge_condition',
				'target_id' => $badge_id,
			),
			array( '%s', '%s' )
		);

		if ( ! is_array( $condition ) ) {
			return;
		}

		$type = sanitize_key( (string) ( $condition['type'] ?? 'admin_awarded' ) );
		if ( 'admin_awarded' === $type || '' === $type ) {
			return;
		}

		$config = array( 'condition_type' => $type );
		if ( 'point_milestone' === $type ) {
			$config['points'] = max( 1, (int) ( $condition['points'] ?? 100 ) );
		} elseif ( 'action_count' === $type ) {
			$config['action_id'] = sanitize_key( (string) ( $condition['action_id'] ?? '' ) );
			$config['count']     = max( 1, (int) ( $condition['count'] ?? 1 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT.
		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_rules',
			array(
				'rule_type'   => 'badge_condition',
				'target_id'   => $badge_id,
				'rule_config' => wp_json_encode( $config ),
				'is_active'   => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		wp_cache_delete( 'wb_gam_badge_rules', 'wb_gamification' );
	}

	/**
	 * Create a new badge definition (admin only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$badge_id = sanitize_key( (string) $request->get_param( 'id' ) );
		if ( '' === $badge_id ) {
			return new WP_Error(
				'rest_badge_invalid_id',
				__( 'A badge id is required.', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== BadgeEngine::get_badge_def( $badge_id ) ) {
			return new WP_Error(
				'rest_badge_exists',
				__( 'A badge with this id already exists. Use PATCH /badges/{id} to update.', 'wb-gamification' ),
				array( 'status' => 409 )
			);
		}

		$collected = $this->collect_badge_row( $request, true );
		$row       = $collected['row'];
		$formats   = $collected['formats'];

		/**
		 * Filter — abort badge creation by returning WP_Error.
		 *
		 * @param array           $row     Sanitised badge row.
		 * @param WP_REST_Request $request REST request.
		 */
		$filtered = apply_filters( 'wb_gam_before_create_badge', $row, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$row = $filtered;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wb_gam_badge_defs',
			$row,
			$formats
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'rest_badge_create_failed',
				__( 'Failed to create badge.', 'wb-gamification' ),
				array( 'status' => 500 )
			);
		}

		$condition_param = $request->get_param( 'condition' );
		$this->persist_condition( $badge_id, is_array( $condition_param ) ? $condition_param : null );

		$created = BadgeEngine::get_badge_def( $badge_id );
		do_action( 'wb_gam_after_create_badge', $created, $request );

		return rest_ensure_response( $created );
	}

	public function update_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$badge_id = sanitize_key( $request['id'] );
		$def      = BadgeEngine::get_badge_def( $badge_id );

		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$collected = $this->collect_badge_row( $request, false );
		$data      = $collected['row'];
		$formats   = $collected['formats'];

		/**
		 * Filter — abort the update by returning WP_Error.
		 *
		 * @param array           $data    Sanitised diff (only changed fields).
		 * @param array|null      $def     Pre-update badge definition.
		 * @param WP_REST_Request $request REST request.
		 */
		$filtered = apply_filters( 'wb_gam_before_update_badge', $data, $def, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$data = $filtered;
		}

		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST write operation.
			$wpdb->update(
				$wpdb->prefix . 'wb_gam_badge_defs',
				$data,
				array( 'id' => $badge_id ),
				$formats,
				array( '%s' )
			);
		}

		if ( null !== $request->get_param( 'condition' ) ) {
			$this->persist_condition( $badge_id, (array) $request->get_param( 'condition' ) );
		}

		do_action( 'wb_gam_after_update_badge', $badge_id, $data, $request );

		// Return refreshed badge definition.
		$updated               = BadgeEngine::get_badge_def( $badge_id );
		$rarity                = $this->get_rarity_map();
		$updated['rarity_pct'] = $rarity[ $badge_id ] ?? 0.0;

		return rest_ensure_response( $updated );
	}

	/**
	 * Delete a badge definition and cascade-delete user badges (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$badge_id = sanitize_key( $request['id'] );
		$def      = BadgeEngine::get_badge_def( $badge_id );

		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		// Cascade delete user badges first.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_user_badges', array( 'badge_id' => $badge_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cascade delete.

		// Delete associated rules.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cascade delete.
			$wpdb->prefix . 'wb_gam_rules',
			array(
				'rule_type' => 'badge_condition',
				'target_id' => $badge_id,
			),
			array( '%s', '%s' )
		);

		// Delete the definition.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_badge_defs', array( 'id' => $badge_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST delete operation.

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'badge_id' => $badge_id,
			),
			200
		);
	}

	/**
	 * Manually award a badge to a user (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function award_badge( $request ): WP_REST_Response|WP_Error {
		$badge_id = sanitize_key( $request['id'] );
		$user_id  = (int) $request->get_param( 'user_id' );

		$def = BadgeEngine::get_badge_def( $badge_id );
		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'User not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$awarded = BadgeEngine::award_badge( $user_id, $badge_id );

		return rest_ensure_response(
			array(
				'awarded'  => $awarded,
				'badge_id' => $badge_id,
				'user_id'  => $user_id,
				'message'  => $awarded
					/* translators: %s: badge name */
					? sprintf( __( 'Badge "%s" awarded successfully.', 'wb-gamification' ), $def['name'] )
					/* translators: %s: badge name */
					: sprintf( __( 'User already holds badge "%s".', 'wb-gamification' ), $def['name'] ),
			)
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Return a map of badge_id → rarity percentage (% of users who have it).
	 *
	 * Single aggregation query — avoids N queries for N badges.
	 *
	 * @return array<string, float>
	 */
	private function get_rarity_map(): array {
		global $wpdb;

		$total_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time aggregation; result varies per request.
		if ( $total_users <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregation query; not suitable for generic caching without badge-level cache keys.
		$rows = $wpdb->get_results(
			"SELECT badge_id, COUNT(DISTINCT user_id) AS earner_count
			   FROM {$wpdb->prefix}wb_gam_user_badges
			  GROUP BY badge_id",
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['badge_id'] ] = round( ( (int) $row['earner_count'] / $total_users ) * 100, 1 );
		}

		return $map;
	}

	/**
	 * Count of distinct users who hold a specific badge.
	 *
	 * @param string $badge_id Badge identifier.
	 * @return int Number of distinct users who earned the badge.
	 */
	private function get_earner_count( string $badge_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-badge count shown on single-badge page; caching is handled by calling code if needed.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id = %s",
				$badge_id
			)
		);
	}

	/**
	 * Return the shared `id` argument definition for badge routes.
	 *
	 * @return array Argument definition array.
	 */
	private function get_badge_id_args(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Retrieve the JSON schema for a badge item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-badge',
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'string' ),
				'name'          => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
				'image_url'     => array( 'type' => array( 'string', 'null' ) ),
				'is_credential' => array( 'type' => 'boolean' ),
				'category'      => array( 'type' => 'string' ),
				'earned'        => array( 'type' => 'boolean' ),
				'earned_at'     => array( 'type' => array( 'string', 'null' ) ),
				'rarity_pct'    => array( 'type' => 'number' ),
			),
		);
	}
}
