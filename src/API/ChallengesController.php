<?php
/**
 * REST API: Challenges Controller
 *
 * GET    /wb-gamification/v1/challenges              Active challenges + current user's progress
 * POST   /wb-gamification/v1/challenges              Create challenge (admin)
 * GET    /wb-gamification/v1/challenges/{id}         Single challenge + current user's progress
 * PUT    /wb-gamification/v1/challenges/{id}         Update challenge (admin)
 * DELETE /wb-gamification/v1/challenges/{id}         Delete challenge (admin)
 * POST   /wb-gamification/v1/challenges/{id}/complete  User marks progress
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\ChallengeEngine;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for active challenges and user progress.
 *
 * Handles GET /wb-gamification/v1/challenges and GET /wb-gamification/v1/challenges/{id}.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class ChallengesController extends WP_REST_Controller {

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
	protected $rest_base = 'challenges';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /challenges + POST /challenges (admin create).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'user_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
							'description'       => 'Fetch progress for this user. 0 = current user.',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'title'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'action_id'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'target'       => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
						),
						'bonus_points' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 0,
						),
						'starts_at'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'ends_at'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// GET /challenges/{id} + PUT /challenges/{id} + DELETE /challenges/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id'      => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'user_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
					'args'                => array(
						'title'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'action_id'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'target'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'bonus_points' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'starts_at'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'ends_at'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'       => array(
							'type' => 'string',
							'enum' => array( 'active', 'inactive' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_check' ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// POST /challenges/{id}/complete — user marks progress.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/complete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'complete_challenge' ),
					'permission_callback' => function () {
						if ( ! is_user_logged_in() ) {
							return new \WP_Error(
								'rest_not_logged_in',
								__( 'You must be logged in to complete challenges.', 'wb-gamification' ),
								array( 'status' => 401 )
							);
						}
						return true;
					},
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	// ── Permission checks ─────────────────────────────────────────────────────

	/**
	 * Check if the current user is an administrator.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_check(): bool|WP_Error {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Admin only.', 'wb-gamification' ), array( 'status' => 403 ) );
	}

	// ── Callbacks ──────────────────────────────────────────────────────────────

	/**
	 * Retrieve all active challenges with current user progress.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response containing active challenges.
	 */
	public function get_items( $request ): WP_REST_Response {
		$user_id = $this->resolve_user_id( (int) $request->get_param( 'user_id' ) );
		$items   = ChallengeEngine::get_active_challenges( $user_id );

		return rest_ensure_response( $items );
	}

	/**
	 * Retrieve a single active challenge by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$challenge_id = (int) $request['id'];
		$user_id      = $this->resolve_user_id( (int) $request->get_param( 'user_id' ) );

		$all = ChallengeEngine::get_active_challenges( $user_id );

		foreach ( $all as $ch ) {
			if ( $ch['id'] === $challenge_id ) {
				return rest_ensure_response( $ch );
			}
		}

		return new WP_Error(
			'rest_challenge_not_found',
			__( 'Challenge not found or not active.', 'wb-gamification' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Create a new challenge (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$data = array(
			'title'        => sanitize_text_field( $request['title'] ),
			'action_id'    => sanitize_key( $request['action_id'] ),
			'target'       => absint( $request['target'] ?? 10 ),
			'bonus_points' => absint( $request['bonus_points'] ?? 50 ),
			'status'       => 'active',
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s' );

		if ( ! empty( $request['starts_at'] ) ) {
			$data['starts_at'] = sanitize_text_field( $request['starts_at'] );
			$formats[]         = '%s';
		}
		if ( ! empty( $request['ends_at'] ) ) {
			$data['ends_at'] = sanitize_text_field( $request['ends_at'] );
			$formats[]       = '%s';
		}

		$inserted = $wpdb->insert( $wpdb->prefix . 'wb_gam_challenges', $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- REST write operation.

		if ( ! $inserted ) {
			return new WP_Error(
				'rest_insert_failed',
				__( 'Could not create challenge.', 'wb-gamification' ),
				array( 'status' => 500 )
			);
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-after-write for response.
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$wpdb->insert_id
			),
			ARRAY_A
		);

		return new WP_REST_Response( $this->prepare_challenge_row( $row ), 201 );
	}

	/**
	 * Update an existing challenge (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check before update.
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'rest_challenge_not_found',
				__( 'Challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		$data = array();
		if ( isset( $request['title'] ) ) {
			$data['title'] = sanitize_text_field( $request['title'] );
		}
		if ( isset( $request['action_id'] ) ) {
			$data['action_id'] = sanitize_key( $request['action_id'] );
		}
		if ( isset( $request['target'] ) ) {
			$data['target'] = absint( $request['target'] );
		}
		if ( isset( $request['bonus_points'] ) ) {
			$data['bonus_points'] = absint( $request['bonus_points'] );
		}
		if ( isset( $request['starts_at'] ) ) {
			$data['starts_at'] = sanitize_text_field( $request['starts_at'] );
		}
		if ( isset( $request['ends_at'] ) ) {
			$data['ends_at'] = sanitize_text_field( $request['ends_at'] );
		}
		if ( isset( $request['status'] ) ) {
			$data['status'] = sanitize_key( $request['status'] );
		}

		if ( $data ) {
			$wpdb->update( $wpdb->prefix . 'wb_gam_challenges', $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST write operation.
		}

		$updated = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-after-write for response.
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return rest_ensure_response( $this->prepare_challenge_row( $updated ) );
	}

	/**
	 * Delete a challenge (admin only).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id = (int) $request['id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check before delete.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wb_gam_challenges WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'rest_challenge_not_found',
				__( 'Challenge not found.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		// Delete progress logs first, then the challenge itself.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_challenge_log', array( 'challenge_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cascade delete.
		$wpdb->delete( $wpdb->prefix . 'wb_gam_challenges', array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST delete operation.

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Record challenge progress for the current user via the ChallengeEngine.
	 *
	 * This endpoint allows users to signal manual progress on a challenge.
	 * The engine processes the completion logic including bonus point awards.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function complete_challenge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$challenge_id = (int) $request['id'];
		$user_id      = get_current_user_id();

		// Verify challenge exists and is active.
		$challenge = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live status check.
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wb_gam_challenges
				  WHERE id = %d AND status = 'active'
				    AND (starts_at IS NULL OR starts_at <= NOW())
				    AND (ends_at IS NULL OR ends_at >= NOW())",
				$challenge_id
			),
			ARRAY_A
		);

		if ( ! $challenge ) {
			return new WP_Error(
				'rest_challenge_not_found',
				__( 'Challenge not found or not active.', 'wb-gamification' ),
				array( 'status' => 404 )
			);
		}

		// Check if already completed.
		$log = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live progress check.
			$wpdb->prepare(
				"SELECT progress, completed_at FROM {$wpdb->prefix}wb_gam_challenge_log
				  WHERE user_id = %d AND challenge_id = %d",
				$user_id,
				$challenge_id
			),
			ARRAY_A
		);

		if ( $log && null !== $log['completed_at'] ) {
			return rest_ensure_response(
				array(
					'challenge_id' => $challenge_id,
					'user_id'      => $user_id,
					'progress'     => (int) $log['progress'],
					'completed'    => true,
					'message'      => __( 'Challenge already completed.', 'wb-gamification' ),
				)
			);
		}

		// Increment progress.
		$current_progress = $log ? (int) $log['progress'] : 0;
		$new_progress     = $current_progress + 1;

		if ( $log ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Progress update.
				$wpdb->prefix . 'wb_gam_challenge_log',
				array( 'progress' => $new_progress ),
				array(
					'user_id'      => $user_id,
					'challenge_id' => $challenge_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- New progress row.
				$wpdb->prefix . 'wb_gam_challenge_log',
				array(
					'user_id'      => $user_id,
					'challenge_id' => $challenge_id,
					'progress'     => $new_progress,
					'created_at'   => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}

		$completed = $new_progress >= (int) $challenge['target'];

		// If target reached, mark complete and award bonus.
		if ( $completed ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completion update.
				$wpdb->prefix . 'wb_gam_challenge_log',
				array( 'completed_at' => current_time( 'mysql' ) ),
				array(
					'user_id'      => $user_id,
					'challenge_id' => $challenge_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);

			/**
			 * Fires when a member completes a challenge via the REST API.
			 *
			 * @param int   $user_id   User who completed the challenge.
			 * @param array $challenge Full challenge row.
			 */
			do_action( 'wb_gamification_challenge_completed', $user_id, $challenge );
		}

		return rest_ensure_response(
			array(
				'challenge_id' => $challenge_id,
				'user_id'      => $user_id,
				'progress'     => $new_progress,
				'target'       => (int) $challenge['target'],
				'completed'    => $completed,
			)
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Resolve the effective user ID from the request parameter.
	 *
	 * @param int $requested User ID from the request, 0 to use the current user.
	 * @return int Resolved user ID.
	 */
	private function resolve_user_id( int $requested ): int {
		if ( $requested > 0 ) {
			return $requested;
		}
		return is_user_logged_in() ? get_current_user_id() : 0;
	}

	/**
	 * Format a raw challenge DB row for REST response.
	 *
	 * @param array $row Raw row from the challenges table.
	 * @return array Formatted challenge data.
	 */
	private function prepare_challenge_row( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'title'        => $row['title'],
			'type'         => $row['type'],
			'action_id'    => $row['action_id'],
			'target'       => (int) $row['target'],
			'bonus_points' => (int) $row['bonus_points'],
			'period'       => $row['period'],
			'starts_at'    => $row['starts_at'] ?: null,
			'ends_at'      => $row['ends_at'] ?: null,
			'status'       => $row['status'],
		);
	}

	/**
	 * Retrieve the JSON schema for a challenge item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-challenge',
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'type'         => array(
					'type' => 'string',
					'enum' => array( 'individual', 'team' ),
				),
				'action_id'    => array( 'type' => 'string' ),
				'target'       => array( 'type' => 'integer' ),
				'bonus_points' => array( 'type' => 'integer' ),
				'period'       => array( 'type' => 'string' ),
				'starts_at'    => array( 'type' => array( 'string', 'null' ) ),
				'ends_at'      => array( 'type' => array( 'string', 'null' ) ),
				'progress'     => array( 'type' => 'integer' ),
				'progress_pct' => array( 'type' => 'number' ),
				'completed'    => array( 'type' => 'boolean' ),
			),
		);
	}
}
