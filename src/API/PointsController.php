<?php
/**
 * REST API: Points Controller
 *
 * Admin write endpoints for the points ledger.
 *
 * POST   /wb-gamification/v1/points/award     Manually award points to a user
 * DELETE /wb-gamification/v1/points/{id}      Admin revoke a specific point row
 *
 * Read endpoints (points total + paginated history) live in MembersController
 * at GET /members/{id}/points — no duplication here.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */

namespace WBGam\API;

use WBGam\Engine\Engine;
use WBGam\Engine\Event;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST API controller for admin points management.
 *
 * Handles POST /wb-gamification/v1/points/award and DELETE /wb-gamification/v1/points/{id}.
 *
 * @package WB_Gamification
 * @since   0.1.0
 */
class PointsController extends WP_REST_Controller {

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
	protected $rest_base = 'points';

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// POST /points/award.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/award',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'award' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => array(
						'user_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'points'  => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'maximum'           => 100000,
							'sanitize_callback' => 'absint',
						),
						'reason'  => array(
							'type'              => 'string',
							'default'           => 'manual_award',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		// DELETE /points/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
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

	// ── Callbacks ───────────────────────────────────────────────────────────────

	/**
	 * Manually award points to a member.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function award( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['user_id'];
		$points  = (int) $request['points'];
		$reason  = (string) $request['reason'];
		$note    = (string) $request['note'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$event = new Event(
			array(
				'action_id' => 'manual_award',
				'user_id'   => $user_id,
				'metadata'  => array(
					'points'     => $points,
					'reason'     => $reason,
					'note'       => $note,
					'awarded_by' => get_current_user_id(),
				),
			)
		);

		Engine::process( $event );

		return new WP_REST_Response(
			array(
				'awarded' => true,
				'user_id' => $user_id,
				'points'  => $points,
				'reason'  => $reason,
			),
			201
		);
	}

	/**
	 * Revoke a specific point ledger row.
	 *
	 * Hard-deletes the ledger row. The event record is preserved
	 * (events are immutable) — only the points side-effect is removed.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response on success, WP_Error on failure.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$row_id = (int) $request['id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Revoke operation; row fetched immediately before deletion, caching would be misleading.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, points FROM {$wpdb->prefix}wb_gam_points WHERE id = %d",
				$row_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Points row not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'wb_gam_points',
			array( 'id' => $row_id ),
			array( '%d' )
		);

		if ( ! $deleted ) {
			return new WP_Error( 'rest_delete_failed', __( 'Could not revoke points.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		// Bust the cached total for the affected user.
		wp_cache_delete( 'wb_gam_points_' . (int) $row['user_id'], 'wb_gamification' );

		/**
		 * Fires after a point row is revoked by an admin.
		 *
		 * @param int   $row_id  The deleted row ID.
		 * @param array $row     The deleted row data.
		 * @param int   $admin   Admin user ID who performed the action.
		 */
		do_action( 'wb_gamification_points_revoked', $row_id, $row, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $row_id,
				'user_id' => (int) $row['user_id'],
				'points'  => (int) $row['points'],
			),
			200
		);
	}

	/**
	 * Check if the current user has permission to manage points.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_permission_check(): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Honour the Abilities API if available.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'wb_gam_award_manual' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage points.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}
}
