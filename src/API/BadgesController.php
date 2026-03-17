<?php
/**
 * REST API: Badges Controller
 *
 * GET  /wb-gamification/v1/badges           All badge definitions + rarity counts
 * GET  /wb-gamification/v1/badges/{id}      Single badge definition + rarity
 * POST /wb-gamification/v1/badges/{id}/award  Admin-only manual award
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

class BadgesController extends WP_REST_Controller {

	protected $namespace = 'wb-gamification/v1';
	protected $rest_base = 'badges';

	public function register_routes(): void {
		// GET /badges
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'user_id' => [
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Include earned status for this user. 0 = skip.',
						],
						'category' => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Filter by badge category.',
						],
					],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);

		// GET /badges/{id}
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => '__return_true',
					'args'                => $this->get_badge_id_args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);

		// POST /badges/{id}/award
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9_-]+)/award',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'award_badge' ],
					'permission_callback' => [ $this, 'award_permissions_check' ],
					'args'                => array_merge(
						$this->get_badge_id_args(),
						[
							'user_id' => [
								'required'          => true,
								'type'              => 'integer',
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
								'description'       => 'User to award the badge to.',
							],
						]
					),
				],
			]
		);
	}

	// ── Permission checks ──────────────────────────────────────────────────────

	public function award_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Only administrators can award badges manually.', 'wb-gamification' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// ── Endpoint callbacks ─────────────────────────────────────────────────────

	/**
	 * GET /badges — All badge definitions with optional earned status + rarity.
	 */
	public function get_items( WP_REST_Request $request ): WP_REST_Response {
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
	 * GET /badges/{id} — Single badge definition.
	 */
	public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$badge_id = sanitize_key( $request['id'] );
		$def      = BadgeEngine::get_badge_def( $badge_id );

		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				[ 'status' => 404 ]
			);
		}

		$rarity              = $this->get_rarity_map();
		$def['rarity_pct']   = $rarity[ $badge_id ] ?? 0.0;
		$def['earner_count'] = $this->get_earner_count( $badge_id );

		// Earned status for current user.
		if ( is_user_logged_in() ) {
			$def['earned']    = BadgeEngine::has_badge( get_current_user_id(), $badge_id );
		}

		return rest_ensure_response( $def );
	}

	/**
	 * POST /badges/{id}/award — Admin-only manual award.
	 */
	public function award_badge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$badge_id = sanitize_key( $request['id'] );
		$user_id  = (int) $request->get_param( 'user_id' );

		$def = BadgeEngine::get_badge_def( $badge_id );
		if ( null === $def ) {
			return new WP_Error(
				'rest_badge_not_found',
				__( 'Badge not found.', 'wb-gamification' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'rest_user_invalid',
				__( 'User not found.', 'wb-gamification' ),
				[ 'status' => 404 ]
			);
		}

		$awarded = BadgeEngine::award_badge( $user_id, $badge_id );

		return rest_ensure_response(
			[
				'awarded'  => $awarded,
				'badge_id' => $badge_id,
				'user_id'  => $user_id,
				'message'  => $awarded
					/* translators: %s: badge name */
					? sprintf( __( 'Badge "%s" awarded successfully.', 'wb-gamification' ), $def['name'] )
					/* translators: %s: badge name */
					: sprintf( __( 'User already holds badge "%s".', 'wb-gamification' ), $def['name'] ),
			]
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

		$total_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		if ( $total_users <= 0 ) {
			return [];
		}

		$rows = $wpdb->get_results(
			"SELECT badge_id, COUNT(DISTINCT user_id) AS earner_count
			   FROM {$wpdb->prefix}wb_gam_user_badges
			  GROUP BY badge_id",
			ARRAY_A
		);

		$map = [];
		foreach ( $rows as $row ) {
			$map[ $row['badge_id'] ] = round( ( (int) $row['earner_count'] / $total_users ) * 100, 1 );
		}

		return $map;
	}

	/**
	 * Count of distinct users who hold a specific badge.
	 */
	private function get_earner_count( string $badge_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}wb_gam_user_badges WHERE badge_id = %s",
				$badge_id
			)
		);
	}

	private function get_badge_id_args(): array {
		return [
			'id' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-badge',
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'string' ],
				'name'          => [ 'type' => 'string' ],
				'description'   => [ 'type' => 'string' ],
				'image_url'     => [ 'type' => [ 'string', 'null' ] ],
				'is_credential' => [ 'type' => 'boolean' ],
				'category'      => [ 'type' => 'string' ],
				'earned'        => [ 'type' => 'boolean' ],
				'earned_at'     => [ 'type' => [ 'string', 'null' ] ],
				'rarity_pct'    => [ 'type' => 'number' ],
			],
		];
	}
}
