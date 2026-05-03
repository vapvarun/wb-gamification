<?php
/**
 * REST API: Levels Controller
 *
 * GET    /wb-gamification/v1/levels       List all level definitions
 * POST   /wb-gamification/v1/levels       Create a new level
 * PATCH  /wb-gamification/v1/levels/{id}  Update an existing level
 * DELETE /wb-gamification/v1/levels/{id}  Delete a level (protects starting level)
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
 * REST API controller for level definitions.
 *
 * Returns the configured level tiers (name, minimum points threshold, icon)
 * sorted by min_points ascending so consumers can render level-progress UIs.
 * Write operations require the wb_gam_manage_levels plugin cap (or
 * manage_options) and fire before_/after_ hooks for extension.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
final class LevelsController extends WP_REST_Controller {

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
	protected $rest_base = 'levels';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /levels  +  POST /levels.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'name'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Display name for the level (e.g. "Champion").',
						),
						'min_points' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
							'description'       => 'Minimum points required to reach this level.',
						),
						'icon_url'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
							'description'       => 'Optional icon URL.',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// PATCH /levels/{id}  +  DELETE /levels/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'id'         => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'minimum'           => 1,
						),
						'name'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'min_points' => array(
							'type'              => 'integer',
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'icon_url'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
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
							'minimum'           => 1,
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Capability gate for write operations.
	 *
	 * Accepts manage_options OR the granular wb_gam_manage_levels cap, so
	 * site owners can delegate level configuration to a custom role. Returns
	 * `WP_Error(403)` (NOT bare `false`) so JSON clients see a structured
	 * error per REST contract.
	 *
	 * @return true|WP_Error
	 */
	public function admin_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) || Capabilities::user_can( 'wb_gam_manage_levels' ) ) {
			return true;
		}
		return new WP_Error(
			'wb_gam_rest_forbidden',
			__( 'You do not have permission to manage levels.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Retrieve all level definitions, sorted by min_points ascending.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing level definitions.
	 */
	public function get_items( $request ): WP_REST_Response {
		$levels = $this->fetch_all();
		return new WP_REST_Response( $levels, 200 );
	}

	/**
	 * Create a new level.
	 *
	 * Fires `wb_gam_before_create_level` (filterable, returning WP_Error
	 * aborts) and `wb_gam_after_create_level` (action, post-write).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$payload = array(
			'name'       => (string) $request->get_param( 'name' ),
			'min_points' => (int) $request->get_param( 'min_points' ),
			'icon_url'   => (string) $request->get_param( 'icon_url' ),
		);

		/**
		 * Filter — abort the create by returning WP_Error.
		 *
		 * @param array           $payload  Sanitised level fields.
		 * @param WP_REST_Request $request  Request.
		 */
		$filtered = apply_filters( 'wb_gam_before_create_level', $payload, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		$payload = is_array( $filtered ) ? $filtered : $payload;

		global $wpdb;
		$table = $wpdb->prefix . 'wb_gam_levels';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix; SELECT MAX is cache-bypass by design (we read fresh sort_order).
		$max_sort = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order),0) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT.
		$inserted = $wpdb->insert(
			$table,
			array(
				'name'       => $payload['name'],
				'min_points' => $payload['min_points'],
				'icon_url'   => $payload['icon_url'],
				'sort_order' => $max_sort + 1,
			),
			array( '%s', '%d', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'wb_gam_level_create_failed',
				__( 'Failed to create level.', 'wb-gamification' ),
				array( 'status' => 500 )
			);
		}

		$id = (int) $wpdb->insert_id;
		$row = $this->fetch_one( $id );

		do_action( 'wb_gam_after_create_level', $row, $request );

		return new WP_REST_Response( $row, 201 );
	}

	/**
	 * Update an existing level.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$current = $this->fetch_one( $id );
		if ( null === $current ) {
			return new WP_Error(
				'wb_gam_level_not_found',
				__( 'Level not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$updates = array();
		$formats = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$updates['name'] = (string) $request->get_param( 'name' );
			$formats[]       = '%s';
		}
		if ( null !== $request->get_param( 'min_points' ) ) {
			$updates['min_points'] = (int) $request->get_param( 'min_points' );
			$formats[]             = '%d';
		}
		if ( null !== $request->get_param( 'icon_url' ) ) {
			$updates['icon_url'] = (string) $request->get_param( 'icon_url' );
			$formats[]           = '%s';
		}

		if ( empty( $updates ) ) {
			return new WP_REST_Response( $current, 200 );
		}

		/**
		 * Filter — abort the update by returning WP_Error.
		 *
		 * @param array           $updates  Sanitised changed fields.
		 * @param array           $current  Pre-update level row.
		 * @param WP_REST_Request $request  Request.
		 */
		$filtered = apply_filters( 'wb_gam_before_update_level', $updates, $current, $request );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		$updates = is_array( $filtered ) ? $filtered : $updates;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE.
		$wpdb->update(
			$wpdb->prefix . 'wb_gam_levels',
			$updates,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		$fresh = $this->fetch_one( $id );

		do_action( 'wb_gam_after_update_level', $fresh, $current, $request );

		return new WP_REST_Response( $fresh, 200 );
	}

	/**
	 * Delete a level.
	 *
	 * Refuses to delete the starting level (min_points = 0) — that row is
	 * implicitly assigned to every member; deleting it would leave members
	 * without a baseline tier.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$current = $this->fetch_one( $id );
		if ( null === $current ) {
			return new WP_Error(
				'wb_gam_level_not_found',
				__( 'Level not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		if ( 0 === (int) $current['min_points'] ) {
			return new WP_Error(
				'wb_gam_level_delete_starting_forbidden',
				__( 'The starting level (min_points = 0) cannot be deleted.', 'wb-gamification' ),
				array( 'status' => 409 )
			);
		}

		do_action( 'wb_gam_before_delete_level', $current, $request );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_levels', array( 'id' => $id ), array( '%d' ) );

		do_action( 'wb_gam_after_delete_level', $current, $request );

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
	 * Fetch all levels as plain arrays (the public read shape).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix; small static result set.
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}wb_gam_levels ORDER BY min_points ASC",
			ARRAY_A
		) ?: array();

		return array_map( array( $this, 'shape' ), $rows );
	}

	/**
	 * Fetch a single level by ID, or `null`.
	 *
	 * @param int $id Level ID.
	 * @return array<string, mixed>|null
	 */
	private function fetch_one( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row read.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wb_gam_levels WHERE id = %d", $id ),
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
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'min_points' => (int) $row['min_points'],
			'icon_url'   => (string) ( $row['icon_url'] ?? '' ),
			'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
		);
	}

	/**
	 * Retrieve the JSON schema for a level item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-level',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'type'        => 'integer',
					'description' => 'Level ID.',
					'readonly'    => true,
				),
				'name'       => array(
					'type'        => 'string',
					'description' => 'Display name for the level.',
				),
				'min_points' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Minimum points required to reach this level.',
				),
				'icon_url'   => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'URL to the level icon image.',
				),
				'sort_order' => array(
					'type'        => 'integer',
					'description' => 'Display order; auto-assigned on create.',
					'readonly'    => true,
				),
			),
		);
	}
}
