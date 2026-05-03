<?php
/**
 * REST API: Community Challenges Controller
 *
 * GET    /wb-gamification/v1/community-challenges       List all (admin = all, public = active)
 * POST   /wb-gamification/v1/community-challenges       Create a new community challenge
 * GET    /wb-gamification/v1/community-challenges/{id}  Read one
 * PATCH  /wb-gamification/v1/community-challenges/{id}  Update fields
 * DELETE /wb-gamification/v1/community-challenges/{id}  Delete + cascade contributions
 *
 * Community challenges are global, accumulating goals (Pokémon GO-style)
 * stored in `wb_gam_community_challenges` (separate table from individual
 * challenges). Contributions cascade-delete from
 * `wb_gam_community_challenge_contributions`.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */

namespace WBGam\API;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WBGam\Engine\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for community challenge management.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class CommunityChallengesController extends WP_REST_Controller {

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
	protected $rest_base = 'community-challenges';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'status' => array(
							'type'              => 'string',
							'default'           => 'active',
							'enum'              => array( 'active', 'inactive', 'all' ),
							'sanitize_callback' => 'sanitize_key',
							'description'       => 'Filter by status. "all" requires admin.',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => $this->save_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array_merge(
						array( 'id' => array( 'type' => 'integer' ) ),
						$this->save_args( false )
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Capability gate for write + admin reads.
	 *
	 * @return true|WP_Error
	 */
	public function admin_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) || Capabilities::user_can( 'wb_gam_manage_challenges' ) ) {
			return true;
		}
		return new WP_Error(
			'wb_gam_rest_forbidden',
			__( 'You do not have permission to manage community challenges.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * List community challenges.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$status = (string) $request->get_param( 'status' );

		// "all" is admin-only; non-admins forced to "active".
		if ( 'all' === $status && true !== $this->admin_check() ) {
			$status = 'active';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_community_challenges';

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ) ?: array();
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC",
					$status
				),
				ARRAY_A
			) ?: array();
		}

		$items = array_map( array( $this, 'shape' ), $rows );

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => count( $items ),
				'pages'    => 1,
				'has_more' => false,
			),
			200
		);
	}

	/**
	 * Read a single community challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$id  = (int) $request['id'];
		$row = $this->fetch_one( $id );
		if ( null === $row ) {
			return new WP_Error(
				'wb_gam_community_challenge_not_found',
				__( 'Community challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}
		return new WP_REST_Response( $row, 200 );
	}

	/**
	 * Create a new community challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = $this->collect_payload( $request );

		/**
		 * Filter — abort the create by returning WP_Error.
		 *
		 * @param array           $data    Sanitised payload.
		 * @param WP_REST_Request $request Request.
		 */
		$filtered = apply_filters( 'wb_gam_before_create_community_challenge', $data, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$data = $filtered;
		}

		$data['global_progress'] = 0;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT.
		$wpdb->insert(
			$wpdb->prefix . 'wb_gam_community_challenges',
			$data,
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		$id  = (int) $wpdb->insert_id;
		$row = $this->fetch_one( $id );

		do_action( 'wb_gam_after_create_community_challenge', $row, $request );
		// Backwards-compatible legacy hook (kept until 1.1.0).
		do_action( 'wb_gamification_community_challenge_created', $id, $data );

		return new WP_REST_Response( $row, 201 );
	}

	/**
	 * Update an existing community challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$current = $this->fetch_one( $id );
		if ( null === $current ) {
			return new WP_Error(
				'wb_gam_community_challenge_not_found',
				__( 'Community challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$updates = array();
		$formats = array();
		foreach ( array(
			'title'         => '%s',
			'description'   => '%s',
			'target_count'  => '%d',
			'target_action' => '%s',
			'starts_at'     => '%s',
			'ends_at'       => '%s',
			'bonus_points'  => '%d',
			'status'        => '%s',
		) as $field => $fmt ) {
			if ( null !== $request->get_param( $field ) ) {
				$updates[ $field ] = $request->get_param( $field );
				$formats[]         = $fmt;
			}
		}

		if ( empty( $updates ) ) {
			return new WP_REST_Response( $current, 200 );
		}

		/**
		 * Filter — abort the update by returning WP_Error.
		 *
		 * @param array           $updates Sanitised payload subset.
		 * @param array           $current Pre-update row.
		 * @param WP_REST_Request $request Request.
		 */
		$filtered = apply_filters( 'wb_gam_before_update_community_challenge', $updates, $current, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$updates = $filtered;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE.
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_community_challenges',
			$updates,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		$fresh = $this->fetch_one( $id );

		do_action( 'wb_gam_after_update_community_challenge', $fresh, $current, $request );
		do_action( 'wb_gamification_community_challenge_updated', $id, $updates );

		return new WP_REST_Response( $fresh, 200 );
	}

	/**
	 * Delete a community challenge + cascade its contributions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$current = $this->fetch_one( $id );
		if ( null === $current ) {
			return new WP_Error(
				'wb_gam_community_challenge_not_found',
				__( 'Community challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		do_action( 'wb_gam_before_delete_community_challenge', $current, $request );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_community_challenges', array( 'id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Cascade delete.
		$wpdb->delete(
			$wpdb->prefix . 'wb_gam_community_challenge_contributions',
			array( 'challenge_id' => $id ),
			array( '%d' )
		);

		do_action( 'wb_gam_after_delete_community_challenge', $current, $request );
		do_action( 'wb_gamification_community_challenge_deleted', $id );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'id'       => $id,
				'previous' => $current,
			),
			200
		);
	}

	/**
	 * Collect + sanitise the create/update payload from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function collect_payload( WP_REST_Request $request ): array {
		return array(
			'title'         => (string) $request->get_param( 'title' ),
			'description'   => (string) $request->get_param( 'description' ),
			'target_count'  => max( 1, (int) $request->get_param( 'target_count' ) ),
			'target_action' => (string) $request->get_param( 'target_action' ),
			'starts_at'     => (string) $request->get_param( 'starts_at' ),
			'ends_at'       => (string) $request->get_param( 'ends_at' ),
			'bonus_points'  => max( 0, (int) $request->get_param( 'bonus_points' ) ),
			'status'        => (string) ( $request->get_param( 'status' ) ?? 'active' ),
		);
	}

	/**
	 * REST args schema for create/update endpoints.
	 *
	 * @param bool $required_for_create Whether title/target_count are required.
	 * @return array<string, array<string, mixed>>
	 */
	private function save_args( bool $required_for_create = true ): array {
		return array(
			'title'         => array(
				'required'          => $required_for_create,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'target_count'  => array(
				'required' => $required_for_create,
				'type'     => 'integer',
				'minimum'  => 1,
			),
			'target_action' => array(
				'type'              => 'string',
				'default'           => '*',
				'sanitize_callback' => 'sanitize_key',
			),
			'starts_at'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'ends_at'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'bonus_points'  => array(
				'type'    => 'integer',
				'default' => 100,
				'minimum' => 0,
			),
			'status'        => array(
				'type' => 'string',
				'enum' => array( 'active', 'inactive' ),
			),
		);
	}

	/**
	 * Fetch a single row, shaped for the REST response.
	 *
	 * @param int $id Challenge ID.
	 * @return array<string, mixed>|null
	 */
	private function fetch_one( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row read.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wb_gam_community_challenges WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? $this->shape( $row ) : null;
	}

	/**
	 * Normalise a DB row into the public REST shape.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private function shape( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'title'           => (string) $row['title'],
			'description'     => (string) ( $row['description'] ?? '' ),
			'target_count'    => (int) $row['target_count'],
			'target_action'   => (string) ( $row['target_action'] ?? '*' ),
			'global_progress' => (int) ( $row['global_progress'] ?? 0 ),
			'starts_at'       => (string) ( $row['starts_at'] ?? '' ),
			'ends_at'         => (string) ( $row['ends_at'] ?? '' ),
			'bonus_points'    => (int) ( $row['bonus_points'] ?? 0 ),
			'status'          => (string) ( $row['status'] ?? 'active' ),
			'percent'         => (int) $row['target_count'] > 0
				? (int) round( ( (int) $row['global_progress'] / (int) $row['target_count'] ) * 100 )
				: 0,
		);
	}

	/**
	 * JSON schema for a community challenge.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-community-challenge',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'title'           => array( 'type' => 'string' ),
				'description'     => array( 'type' => 'string' ),
				'target_count'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'target_action'   => array( 'type' => 'string' ),
				'global_progress' => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'starts_at'       => array( 'type' => 'string' ),
				'ends_at'         => array( 'type' => 'string' ),
				'bonus_points'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'status'          => array(
					'type' => 'string',
					'enum' => array( 'active', 'inactive' ),
				),
				'percent'         => array(
					'type'     => 'integer',
					'readonly' => true,
				),
			),
		);
	}
}
