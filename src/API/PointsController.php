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
						'user_id'    => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'points'     => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => -100000,
							'maximum'           => 100000,
							'description'       => 'Positive to award, negative to debit. Zero is rejected.',
						),
						'reason'     => array(
							'type'              => 'string',
							'default'           => 'manual_award',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'       => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'point_type' => array(
							'type'              => 'string',
							'default'           => '',
							'description'       => 'Optional point-type slug. Defaults to the primary type if omitted or unknown.',
							'sanitize_callback' => 'sanitize_key',
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

	/**
	 * Retrieve the JSON schema for a points response item.
	 *
	 * @return array JSON schema definition.
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wb-gamification-points',
			'type'       => 'object',
			'properties' => array(
				'awarded' => array(
					'type'        => 'boolean',
					'description' => 'Whether points were awarded (POST /award).',
				),
				'deleted' => array(
					'type'        => 'boolean',
					'description' => 'Whether the point row was deleted (DELETE /{id}).',
				),
				'id'      => array(
					'type'        => 'integer',
					'description' => 'Points ledger row ID.',
				),
				'user_id' => array(
					'type'        => 'integer',
					'description' => 'User ID affected.',
				),
				'points'  => array(
					'type'        => 'integer',
					'description' => 'Points value.',
				),
				'reason'  => array(
					'type'        => 'string',
					'description' => 'Reason for the award.',
				),
			),
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
		$user_id    = (int) $request['user_id'];
		$points     = (int) $request['points'];
		$reason     = (string) $request['reason'];
		$note       = (string) $request['note'];
		$type_input = (string) $request['point_type'];

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rest_user_invalid', __( 'Member not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}
		if ( 0 === $points ) {
			return new WP_Error(
				'rest_points_zero',
				__( 'Points must be non-zero (positive to award, negative to debit).', 'wb-gamification' ),
				array( 'status' => 400 )
			);
		}

		// Resolve the requested point type (falls back to primary type for unknown / empty input).
		$point_type = ( new \WBGam\Services\PointTypeService() )->resolve( $type_input );

		// Negative input → debit via PointsEngine::debit; positive → standard award path.
		if ( $points < 0 ) {
			$ok = \WBGam\Engine\PointsEngine::debit( $user_id, abs( $points ), 'manual_admin_deduct', '', $point_type );
			if ( ! $ok ) {
				return new WP_Error(
					'rest_points_debit_failed',
					__( 'Failed to debit points.', 'wb-gamification' ),
					array( 'status' => 500 )
				);
			}
			if ( '' !== $note ) {
				update_user_meta( $user_id, '_wb_gam_last_award_note', sanitize_text_field( $note ) );
			}
			return new WP_REST_Response(
				array(
					'awarded'    => false,
					'debited'    => true,
					'user_id'    => $user_id,
					'points'     => $points,
					'point_type' => $point_type,
					'reason'     => $reason,
				),
				201
			);
		}

		$event = new Event(
			array(
				'action_id' => 'manual_award',
				'user_id'   => $user_id,
				'metadata'  => array(
					'points'     => $points,
					'point_type' => $point_type,
					'reason'     => $reason,
					'note'       => $note,
					'awarded_by' => get_current_user_id(),
				),
			)
		);

		Engine::process( $event );

		if ( '' !== $note ) {
			update_user_meta( $user_id, '_wb_gam_last_award_note', sanitize_text_field( $note ) );
		}

		return new WP_REST_Response(
			array(
				'awarded'    => true,
				'debited'    => false,
				'user_id'    => $user_id,
				'points'     => $points,
				'point_type' => $point_type,
				'reason'     => $reason,
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
				"SELECT id, user_id, points, point_type FROM {$wpdb->prefix}wb_gam_points WHERE id = %d",
				$row_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'rest_not_found', __( 'Points row not found.', 'wb-gamification' ), array( 'status' => 404 ) );
		}

		$user_id    = (int) $row['user_id'];
		$points     = (int) $row['points'];
		$point_type = (string) ( $row['point_type'] ?? 'points' );

		// Atomic: delete ledger row + decrement materialised total in one
		// transaction. Without this, any failure between the DELETE and the
		// bump_user_total leaves wb_gam_user_totals permanently inflated.
		$wpdb->query( 'START TRANSACTION' );

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'wb_gam_points',
			array( 'id' => $row_id ),
			array( '%d' )
		);

		if ( ! $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'rest_delete_failed', __( 'Could not revoke points.', 'wb-gamification' ), array( 'status' => 500 ) );
		}

		// Decrement the materialised total — `bump_user_total` is the canonical
		// helper that every ledger mutation must call. The negative delta
		// applied here mirrors the row's stored value.
		\WBGam\Engine\PointsEngine::bump_user_total( $user_id, $point_type, -$points );

		$wpdb->query( 'COMMIT' );

		// Bust the per-type cache key matching what get_total reads.
		wp_cache_delete( \WBGam\Engine\PointsEngine::cache_key_total( $user_id, $point_type ), 'wb_gamification' );

		/**
		 * Fires after a point row is revoked by an admin.
		 *
		 * @param int   $row_id  The deleted row ID.
		 * @param array $row     The deleted row data.
		 * @param int   $admin   Admin user ID who performed the action.
		 */
		do_action( 'wb_gam_points_revoked', $row_id, $row, get_current_user_id() );

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
	 * Accepts manage_options (default WP admin gate) or the granular
	 * wb_gam_award_manual cap (registered via Capabilities). Both pass
	 * for administrators by default; site owners can grant
	 * wb_gam_award_manual to non-admin roles via a role-manager plugin.
	 *
	 * @return true|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function admin_permission_check(): bool|WP_Error {
		if ( \WBGam\Engine\Capabilities::user_can( 'wb_gam_award_manual' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage points.', 'wb-gamification' ),
			array( 'status' => 403 )
		);
	}
}
