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
		// GET /badges.
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
						array(
							'name'        => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'description' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_textarea_field',
							),
							'image_url'   => array(
								'type'              => 'string',
								'sanitize_callback' => 'esc_url_raw',
							),
							'category'    => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
							),
						)
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

		$data = array();
		if ( isset( $request['name'] ) ) {
			$data['name'] = sanitize_text_field( $request['name'] );
		}
		if ( isset( $request['description'] ) ) {
			$data['description'] = sanitize_textarea_field( $request['description'] );
		}
		if ( isset( $request['image_url'] ) ) {
			$data['image_url'] = esc_url_raw( $request['image_url'] );
		}
		if ( isset( $request['category'] ) ) {
			$data['category'] = sanitize_key( $request['category'] );
		}

		if ( $data ) {
			$wpdb->update( $wpdb->prefix . 'wb_gam_badge_defs', $data, array( 'id' => $badge_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST write operation.
		}

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
